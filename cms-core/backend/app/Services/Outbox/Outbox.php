<?php

namespace App\Services\Outbox;

use App\Mail\OutboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * F1.3 — **یک** outbox برای email + SMS + (آینده) push.
 *
 * ## چرا outbox و نه `Mail::to()->queue()`
 *
 * صفِ لاراول (`jobs`) دقیقاً همان کار را می‌کند، ولی دو چیز ندارد که این پروژه
 * لازم دارد:
 *
 *  ۱) **تحویلِ قابل اثبات.** اگر worker بمیرد وسط ارسال، `jobs` سطر را نگه
 *     می‌دارد و کسی نمی‌فهمد. اینجا `claim_token` + `claimed_at` هست، پس
 *     «گیرکرده» یک وضعیتِ **قابل مشاهده** است نه یک سکوت.
 *  ۲) **یک صف برای سه کانال.** SMS در این پروژه از هیچ provider استانداردی
 *     لاراول نمی‌آید. جدولِ جدا یعنی سه جای claim، سه جای retry، سه جای
 *     گیرکردگی.
 *
 * ## ⭐ چرا `enqueue()` داخل تراکنشِ فراخواننده می‌ماند
 *
 * الگوی Transactional Outbox: نوشتنِ سطر و کارِ اصلی در **یک** تراکنش. اگر
 * تراکنش rollback شود، پیام هم نمی‌رود (کاربر عضو نشده ولی ایمیلِ خوش‌آمد رفته).
 * اگر پیام اول از اپ می‌افتاد و بعد تراکنش fail می‌شد، ایمیلی می‌رفت برای
 * حسابی که وجود ندارد. هر دو حالت بدند و فقط با یک تراکنش می‌شود هر دو را بست.
 *
 * ## ⭐ `dedupe_key` تنها چیزی است که tick دوم را بی‌اثر می‌کند
 *
 * `F0.6` بند ۴: سرویس بیرونی چند بار پشت‌سرهم می‌تواند صدا بزند. claim
 * اتمیک جلوی «دو tick همزمان» را می‌گیرد، ولی نه «دو بار enqueue» را — و آن
 * دومی بی‌صدا و بسیار محتمل است (retry خودِ فراخواننده). قیدِ یکتا این را
 * می‌بندد: enqueue دوم **همان** سطر است، نه یک سطر تازه.
 */
final class Outbox
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PUSH = 'push';

    // ------------------------------------------------------------------
    // enqueue
    // ------------------------------------------------------------------

    /**
     * ⭐ تنها راهِ افزودنِ ایمیل به صف. `Mail::to()->send()` مستقیم یعنی پیامی
     * که نه retry می‌خورد، نه دیده می‌شود، و نه با تراکنشِ فراخواننده یکی است.
     *
     * @param  string  $dedupeKey  باید **پایدار** باشد: `welcome:{userId}`.
     *                              اگر `uniqid()` باشد قید یکتا هیچ چیزی را
     *                              dedupe نمی‌کند و کل ادعای idempotency توخالی است.
     * @return int|null شناسهٔ سطر، یا null اگر قبلاً enqueue شده بود.
     */
    public static function email(
        string $recipient,
        string $dedupeKey,
        string $subject,
        string $body,
        ?string $notificationId = null,
    ): ?int {
        return self::enqueue(self::CHANNEL_EMAIL, $recipient, $dedupeKey, [
            'subject' => $subject,
            'body' => $body,
        ], $notificationId);
    }

    public static function sms(string $recipient, string $dedupeKey, string $text, ?string $notificationId = null): ?int
    {
        return self::enqueue(self::CHANNEL_SMS, $recipient, $dedupeKey, [
            'text' => $text,
        ], $notificationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function enqueue(
        string $channel,
        string $recipient,
        string $dedupeKey,
        array $payload,
        ?string $notificationId = null,
    ): ?int {
        /**
         * ⭐ `insertOrIgnore` و **نه** گرفتنِ `QueryException`.
         *
         * در Postgres، خطای unique کلِ تراکنش را **abort** می‌کند (`25P02`) و
         * هر فراخوانیِ بعدی تا پایان تراکنش رد می‌شود. یعنی «catch کن و ادامه
         * بده» در این پایگاه‌داده کار نمی‌کند — و تراکنشِ فراخواننده هم هست
         * (الگوی transactional outbox). عملاً `enqueue` دوم کلِ کارِ درخواست
         * را می‌کُشت.
         *
         * `ON CONFLICT DO NOTHING` دقیقاً همان چیزی است که می‌خواهیم و هیچ
         * خطایی هم تولید نمی‌کند. ضمناً «بی‌صدا» محدود است: روی Postgres فقط
         * تعارضِ unique را رد می‌کند، پس `NOT NULL` و قیدِ خارجی همچنان
         * استثنا می‌دهند و یک باگِ واقعی پنهان نمی‌شود.
         *
         * تنها uniqueِ درگیر `dedupe_key` است (`id` سریال است و تعارض
         * نمی‌کند)، پس این «دقیقاً همان ردِ تکراری» است.
         */
        $inserted = DB::table('notification_deliveries')->insertOrIgnore([
            'channel' => $channel,
            'recipient' => $recipient,
            'dedupe_key' => $dedupeKey,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'notification_id' => $notificationId,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return null; // قبلاً در صف بود — یعنی همان پیامِ منطقی.
        }

        return (int) DB::table('notification_deliveries')
            ->where('dedupe_key', $dedupeKey)
            ->value('id');
    }

    // ------------------------------------------------------------------
    // drain
    // ------------------------------------------------------------------

    /**
     * ⭐ ادعای اتمیکِ کارِ آماده.
     *
     * الگو: **داخل یک تراکنش** `SELECT … FOR UPDATE SKIP LOCKED` می‌زنیم و بعد
     * همان سطرها را `processing` می‌کنیم. قفلِ سطر تا `commit` نگه داشته می‌شود،
     * پس tick همزمانِ دوم همان سطرها را نمی‌بیند (`SKIP LOCKED` رد می‌کند نه
     * منتظر می‌ماند — وگرنه یک tickِ کند، کل صف را قفل می‌کرد).
     *
     * چرا `UPDATE` تنها کافی نیست: `UPDATE` اتمیک است ولی **شناسهٔ ردیف‌های
     * تغییرکرده** را برنمی‌گرداند. و `UPDATE … LIMIT` در Postgres مجاز نیست.
     * پس انتخاب و ادعا باید دو قدم باشند — و آن دو قدم فقط وقتی امن‌اند که در یک
     * تراکنش با قفل سطر باشند.
     *
     * @return list<object> سطرهای اداشده
     */
    public static function claim(int $batch): array
    {
        $token = (string) Str::uuid();
        $claimedAt = now();

        return DB::transaction(function () use ($batch, $token, $claimedAt): array {
            $rows = DB::table('notification_deliveries')
                ->where('status', self::STATUS_PENDING)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($batch)
                ->lock('for update skip locked')
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $ids = $rows->pluck('id')->all();

            DB::table('notification_deliveries')
                ->whereIn('id', $ids)
                ->update([
                    'status' => self::STATUS_PROCESSING,
                    // ⭐ `attempts` در همان ادعا یکی زیاد می‌شود، نه در انتهای
                    // ارسال. اگر tick بعد از ارسال بمیرد، این سطر باید
                    // «یک تلاش مصرف‌شده» را نشان بدهد — وگرنه بازپس‌گیریِ
                    // سطرِ گیرکرده بی‌نهایت retry می‌کرد.
                    'attempts' => DB::raw('attempts + 1'),
                    'claim_token' => $token,
                    'claimed_at' => $claimedAt,
                    'updated_at' => now(),
                ]);

            /**
             * ⭐ مقادیرِ **بعد از** ادضا برگردانده می‌شوند، نه سطرِ خامِ SELECT.
             *
             * `SELECT` قبل از `UPDATE` اجرا شده پس `claimed_at`/`claim_token`
             * روی آن هنوز `null` است. برگرداندنِ سطرِ خام یعنی `deliver()`
             * با `claim_token = null` نوشته می‌کند ⇒ شرطِ `where claim_token`
             * هیچ‌وقت مطابقت نمی‌کند ⇒ هر پیام بی‌صدا در `processing` گیر می‌ماند.
             * (و `attempts` هم یکی کم می‌آمد، چون نوشتنِ DB بعد از خواندنِ قبلی است.)
             */
            return $rows->map(fn (object $r): object => (object) [
                'id' => $r->id,
                'channel' => $r->channel,
                'recipient' => $r->recipient,
                'payload' => $r->payload,
                'attempts' => (int) $r->attempts + 1,
                'claim_token' => $token,
                'claimed_at' => $claimedAt,
            ])->all();
        });
    }

    /**
     * ⭐ تحویلِ یک سطرِ اداشده.
     *
     * نوشتنِ نتیجه **به خودِ سطرِ اداشده** محدود است (`where claim_token`).
     * اگر tick دیگری سطر را بازپس گرفته و ادعا کرده باشد، این نوشتن هیچ سطری را
     * نمی‌بلعد — وگرنه tick قدیمی نتیجهٔ کارِ tick جدید را پاک می‌کرد.
     *
     * @param  object  $row  سطرِ برگشتیِ `claim()`
     */
    public static function deliver(object $row): bool
    {
        try {
            self::send($row);

            self::markSent($row);

            return true;
        } catch (Throwable $e) {
            self::markFailed((int) $row->id, (string) $row->claim_token, (int) $row->attempts, $e);

            return false;
        }
    }

    /**
     * سطرهایی که یک tick مرده رها کرده.
     *
     * بدون این، یک kill -9 وسط ارسال یعنی یک پیام که هرگز نمی‌رود و هرگز هم
     * retry نمی‌شود — و هیچ نشانه‌ای هم در پنل نیست. شرطِ `attempts` اینجا مهم
     * است: سطری که تلاش‌هایش تمام شده **برنمی‌گردد**، وگرنه بازپس‌گیری آن را
     * برای همیشه در چرخه نگه می‌داشت.
     */
    public static function reapStale(int $staleSeconds, int $maxAttempts): int
    {
        return DB::table('notification_deliveries')
            ->where('status', self::STATUS_PROCESSING)
            ->where('claimed_at', '<', now()->subSeconds($staleSeconds))
            ->where('attempts', '<', $maxAttempts)
            ->update([
                'status' => self::STATUS_PENDING,
                'claim_token' => null,
                'claimed_at' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** پاک‌کردنِ سطرهای تمام‌شده. جدا از `drain` چون `Q7` هیچ cron ندارد. */
    public static function prune(int $retentionDays): int
    {
        return DB::table('notification_deliveries')
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_FAILED])
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->delete();
    }

    // ------------------------------------------------------------------

    /**
     * ⭐ تنها نقطه‌ای که به بیرون دست می‌زند.
     *
     * یک `switch` بسته، نه `match` با پیش‌فرضِ بی‌صدا: کانالِ ناشناخته باید
     * **خطا** بدهد تا در `failed` بیفتد و دیده شود. سکوت یعنی پیامی که گم شد و
     * هیچ‌کس نمی‌داند.
     */
    private static function send(object $row): void
    {
        $payload = self::decode($row->payload);

        switch ((string) $row->channel) {
            case self::CHANNEL_EMAIL:
                /**
                 * `send()` و نه `queue()`: خودِ صفِ لاراول یک صفِ دوم است و
                 * `Q7` می‌گوید یک صف، نه دو تا. تحویل از اینجا بیرون می‌رود.
                 */
                Mail::to((string) $row->recipient)->send(
                    new OutboundEmail(
                        (string) ($payload['subject'] ?? ''),
                        (string) ($payload['body'] ?? ''),
                    )
                );

                return;

            case self::CHANNEL_SMS:
                // providerِ پیامک در این فاز وجود ندارد (`Q7`: fail-closed).
                // پس عمداً خطا می‌دهیم تا سطر `failed` شود و **دیده** شود،
                // نه اینکه `sent` بخورد و پیام هرگز نرسد.
                throw new \RuntimeException('کانال SMS هنوز provider ندارد (F4.2).');

            default:
                throw new \InvalidArgumentException("کانالِ ناشناخته: {$row->channel}");
        }
    }

    private static function markSent(object $row): void
    {
        DB::table('notification_deliveries')
            ->where('id', $row->id)
            ->where('claim_token', $row->claim_token)
            ->update([
                'status' => self::STATUS_SENT,
                'sent_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private static function markFailed(int $id, string $claimToken, int $attempts, Throwable $e): void
    {
        $max = (int) config('outbox.max_attempts', 3);
        $exhausted = $attempts >= $max;

        $update = [
            'status' => $exhausted ? self::STATUS_FAILED : self::STATUS_PENDING,
            'claim_token' => null,
            'claimed_at' => null,
            // ⭐ بُریده: پاسخِ provider می‌تواند چند کیلوبایت HTML باشد.
            'last_error' => mb_substr($e->getMessage(), 0, 500),
            'updated_at' => now(),
        ];

        if ($exhausted) {
            $update['failed_at'] = now();

            Log::error('outbox.delivery.failed', ['id' => $id, 'attempts' => $attempts]);
        } else {
            $update['available_at'] = now()->addSeconds(self::backoffSeconds($attempts));
        }

        DB::table('notification_deliveries')
            ->where('id', $id)
            ->where('claim_token', $claimToken)
            ->update($update);
    }

    /**
     * `min(base × 2^(n-1), cap)` — نمایی با سقف.
     *
     * سقف لازم است: بدون آن، تلاشِ هشتم یعنی ۱۰۰۰۰ ثانیه (بیش از دو ساعت) و
     * پیام تا آن موقع بی‌معنا شده.
     */
    public static function backoffSeconds(int $attempts): int
    {
        $base = max(1, (int) config('outbox.retry_base_seconds', 60));
        $cap = max($base, (int) config('outbox.retry_cap_seconds', 3600));

        return (int) min($cap, $base * (2 ** max(0, $attempts - 1)));
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
