<?php

namespace App\Services\Notifications;

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

    public static function configured(): bool
    {
        return trim((string) config('telegram.bot_token', '')) !== '';
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
        $token = trim((string) config('telegram.bot_token', ''));

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
            throw new RuntimeException('اتصال به تلگرام برقرار نشد: '.$e->getMessage(), 0, $e);
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
}
