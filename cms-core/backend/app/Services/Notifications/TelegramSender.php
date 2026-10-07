<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * WF-M15 — تنها نقطه‌ای که با Bot API تلگرام حرف می‌زند.
 *
 * ## ⭐ چرا استاتیک و نه یک درایور مثل SMS
 *
 * برخلاف پیامک، تلگرام **یک** provider دارد (Bot API خودِ تلگرام) و قراردادش
 * پایدار است. یک factory و سه درایور فقط پیچیدگی می‌آورد برای چیزی که انتخاب
 * ندارد. ولی مرزِ «تنظیم‌شده / تنظیم‌نشده» را نگه می‌دارد: `configured()`.
 *
 * ## ⭐ `send()` عمداً throw می‌کند
 *
 * در `Outbox::send()` این متد صدا زده می‌شود؛ استثنا یعنی سطر `failed` یا
 * retry می‌شود و **دیده** می‌شود. اگر فقط `Log::warning` بزنیم، پیامی که
 * هرگز نرسیده در آمار «ارسال‌شده» می‌نشیند — همان دروغی که `MissingCredential`
 * برای جلوگیری از آن ساخته شد. (گیتِ *نبودِ مقصد* در
 * `NotificationDispatcher::destination` انجام می‌شود، نه اینجا.)
 */
final class TelegramSender
{
    public const DEPENDENCY = 'تلگرام';

    /** کلید(های) محیطیِ لازم — همان اسمی که در `.env.example` آمده. */
    public const REQUIRED_KEYS = ['TELEGRAM_BOT_TOKEN'];

    /**
     * E73 — محلِ نگهداریِ توکنِ پنل.
     *
     * توکن را می‌شود هم در `.env` گذاشت (مسیرِ قدیمی) و هم از پنل ذخیره کرد.
     * اولویت با `.env` است: چیزی که اپراتور در محیط قفل کرده نباید با یک
     * کلیک در پنل عوض شود. نبودِ هر دو یعنی کانال خاموش (fail-soft).
     */
    public const SETTINGS_GROUP = 'integrations';

    public const SETTINGS_KEY = 'telegram_bot_token';

    /** فرمتِ توکنِ BotFather: `digits:body`. */
    public const TOKEN_PATTERN = '/^\d{5,}:[A-Za-z0-9_-]{20,}$/';

    public static function configured(): bool
    {
        return self::token() !== '';
    }

    /** توکنِ مؤثر: اول `.env`، بعد تنظیمِ پنل. */
    public static function token(): string
    {
        $fromEnv = trim((string) config('telegram.bot_token', ''));

        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromPanel = Setting::get(self::SETTINGS_GROUP, self::SETTINGS_KEY, '');

        return is_string($fromPanel) ? trim($fromPanel) : '';
    }

    /**
     * ذخیره/پاک‌سازیِ توکنِ پنل. `null`/خالی یعنی پاک‌سازی (برگشت به `.env`).
     *
     * عمداً بدون cache: راز نباید در کشِ تنظیمات بنشیند و خواندن همیشه تازه
     * است — همان چیزی که `configured()` در هر درخواست می‌خواهد.
     */
    public static function setToken(?string $token): void
    {
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            Setting::query()->where('group', self::SETTINGS_GROUP)->where('key', self::SETTINGS_KEY)->delete();

            return;
        }

        Setting::set(self::SETTINGS_GROUP, self::SETTINGS_KEY, $token);
    }

    /**
     * شکلِ نمایشیِ بی‌خطر برای API (هرگز توکنِ کامل به مرورگر نمی‌رود).
     *
     * `123456:ABC...WXYZ` ⇒ `123456:AB***WXYZ`. کوتاه‌تر از الگو ⇒ فقط طول.
     */
    public static function masked(): ?string
    {
        $token = self::token();

        if ($token === '') {
            return null;
        }

        if (preg_match('/^(\d+:[A-Za-z0-9_-]{2})[A-Za-z0-9_-]*([A-Za-z0-9_-]{4})$/', $token, $m) === 1) {
            return $m[1].'***'.$m[2];
        }

        return '*** ('.mb_strlen($token).' نویسه)';
    }

    /**
     * کلید(های) جاافتاده — برای `pishdad:doctor` بدون تماسِ شبکه‌ای.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        return self::configured() ? [] : self::REQUIRED_KEYS;
    }

    /**
     * @throws RuntimeException وقتی توکن نیست، تماس قطع شود، یا تلگرام پیام را نپذیرد.
     */
    public static function send(string $chatId, string $text): void
    {
        $token = self::token();

        if ($token === '') {
            throw new RuntimeException('توکن ربات تلگرام تنظیم نشده است (TELEGRAM_BOT_TOKEN).');
        }

        $chatId = trim($chatId);

        if ($chatId === '') {
            throw new \InvalidArgumentException('شناسهٔ گفتگوی تلگرام خالی است.');
        }

        $base = rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');

        try {
            $res = Http::timeout((int) config('telegram.timeout', 10))
                ->asJson()
                ->post($base.'/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable $e) {
            // ربات/شبکه قطع است — retry معنی دارد (وضعیت گذراست).
            // پیامِ cURL نشانیِ کاملِ درخواست (شاملِ توکن) را دارد؛ همان را
            // به کاربر/لاگ نده — توکن فقط در جای خودش می‌ماند.
            throw new RuntimeException('اتصال به تلگرام برقرار نشد: '.self::scrubToken($e->getMessage()), 0, $e);
        }

        if (! $res->successful()) {
            throw new RuntimeException('تلگرام خطا داد (HTTP '.$res->status().'): '.mb_substr((string) $res->body(), 0, 300));
        }

        // Bot API حتی وقتی پیام را رد می‌کند HTTP 200 می‌دهد و `ok=false`
        // می‌گذارد (مثلاً chat_id غلط). فقط HTTP را ملاک گرفتن یعنی پیامِ
        // ناموفق «ارسال‌شده» ثبت می‌شود.
        if ($res->json('ok') === false) {
            throw new RuntimeException('تلگرام پیام را نپذیرفت: '.mb_substr((string) $res->body(), 0, 300));
        }
    }

    /**
     * توکن را از پیامِ خطا پاک می‌کند (`/bot<token>/` ⇒ `/bot***`).
     *
     * cURL نشانیِ کاملِ درخواست را در پیامش می‌گذارد و آن نشانی توکن را دارد؛
     * بدون این، هر خطای اتصال توکن را در پاسخِ API (و لاگ) لو می‌داد.
     */
    public static function scrubToken(string $message): string
    {
        return (string) preg_replace('#/bot[^/\s]+#', '/bot***', $message);
    }
}
