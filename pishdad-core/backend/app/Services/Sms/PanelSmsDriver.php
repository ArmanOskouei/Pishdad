<?php

namespace App\Services\Sms;

use App\Services\External\MissingCredentialException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * درایور درگاهِ واقعیِ پیامک (E3).
 *
 * ## وضعیتِ این کلاس
 *
 * ⚠️ **نوشته شده ولی هرگز اجرا نشده** — چون کلیدهای واقعی نداریم. یعنی
 * قراردادِ HTTP این درایور با هیچ درگاهی راستی‌آزمایی نشده است. اگر روزی
 * کلید گرفتید، **اول** همین را با `--dry-run` و یک شمارهٔ آزمایشی امتحان
 * کنید، بعد `pishdad:doctor` را نگاه کنید.
 *
 * چرا باز هم نوشته شد: چون نداشتنش یعنی `SMS_DRIVER=panel` اصلاً معنا ندارد و
 * مالکِ سایت هر بار یک درایور جدید می‌نویسد و آن را هم تست نمی‌کند. داشتنش
 * یعنی مرزِ قرارداد از همین الان مشخص است.
 *
 * قالبِ request عمداً `application/x-www-form-urlencoded` است چون رایج‌ترین
 * فرمِ درگاه‌های ایرانی (`kavenegar`/`melipayamak`-style) همین است. اگر
 * درگاهِ شما JSON می‌خواهد، فقط `body()` را عوض کنید — بقیهٔ کلاس دست‌نخورده.
 */
class PanelSmsDriver implements SmsSenderInterface
{
    public const DEPENDENCY = 'پیامک';

    /**
     * کلیدهای محیطیِ لازم، دقیقاً به همان اسمی که در `.env.example` آمده.
     *
     * @var list<string>
     */
    public const REQUIRED_KEYS = ['SMS_PANEL_URL', 'SMS_PANEL_API_KEY', 'SMS_PANEL_SENDER'];

    private ?string $lastError = null;

    public function driverName(): string
    {
        return 'panel';
    }

    public function delivers(): bool
    {
        return true;
    }

    /**
     * @throws MissingCredentialException وقتی یکی از کلیدهای `SMS_PANEL_*` خالی است.
     */
    public function __construct()
    {
        $missing = self::missingKeys();
        if ($missing !== []) {
            throw new MissingCredentialException(self::DEPENDENCY, $this->driverName(), $missing);
        }
    }

    /**
     * کلید(های) جاافتاده — برای `pishdad:doctor` بدون اینکه درایور بسازیم.
     *
     * ⚠️ عمداً از `config()` می‌خواند نه از `env()`: با `config:cache` هیچ
     * `env()`‌ای بیرون از فایل‌های کانفیگ جواب نمی‌دهد، پس اگر `env()` بخوانیم
     * `doctor` روی یک نصبِ کش‌شده **همیشه** «کلید هست» می‌گوید در حالی که در
     * واقعیت نیست — یعنی همان دروغی که این کلاس برای جلوگیری از آن ساخته شده.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        $map = [
            'SMS_PANEL_URL' => (string) config('sms.panel.url', ''),
            'SMS_PANEL_API_KEY' => (string) config('sms.panel.api_key', ''),
            'SMS_PANEL_SENDER' => (string) config('sms.panel.sender', ''),
        ];

        return array_values(array_keys(array_filter(
            $map,
            static fn (string $value): bool => trim($value) === '',
        )));
    }

    public function send(string $to, string $text): bool
    {
        $url = rtrim((string) config('sms.panel.url'), '/').'/sms/send';

        try {
            $res = Http::timeout((int) config('sms.timeout', 10))
                ->acceptJson()
                ->asForm()
                ->post($url, [
                    'api_key' => (string) config('sms.panel.api_key'),
                    'sender' => (string) config('sms.panel.sender'),
                    'to' => $to,
                    'text' => $text,
                ]);
        } catch (\Throwable $e) {
            // پنل خراب/قطع است — retry معنی دارد (وضعیت گذراست).
            $this->lastError = 'اتصال به درگاه پیامک برقرار نشد: '.$e->getMessage();

            return false;
        }

        if (! $res->successful()) {
            $this->lastError = 'درگاه پیامک خطا داد (HTTP '.$res->status().'): '.mb_substr((string) $res->body(), 0, 300);

            return false;
        }

// پاسخِ موفق لزوماً یعنی تحویل نیست؛ درگاه‌های رایج `{"status":200}`
        // را در بدنه می‌دهند و اگر پیام را رد کرده باشند `status` را چیز دیگری
        // می‌گذارند (۴۰۱، ۵۰۰، …) در حالی که HTTP همچنان 200 است. پس:
        //
        //  - اگر بدنه `status` دارد ⇒ فقط `200` یعنی پذیرفته شد.
        //  - اگر بدنه `status` ندارد ⇒ فقط HTTP ملاک است. این حالت **ضعیف‌تر**
        //    است و عمداً در لاگ ثبت می‌شود تا کسی بعداً نداند واقعاً چه شد.
        $json = $res->json();
        $status = is_array($json) && array_key_exists('status', $json) ? (int) $json['status'] : null;

        if ($status !== null && $status !== 200) {
            $this->lastError = 'درگاه پیامک پیام را نپذیرفت: '.mb_substr((string) $res->body(), 0, 300);

            return false;
        }

        if ($status === null) {
            Log::warning('sms.panel_response_without_status', [
                'to' => $to,
                'note' => 'درگاه بدنه‌ای بدون کلید status برگرداند؛ تحویل قطعی تأیید نشد.',
            ]);
        }

        $this->lastError = null;

        Log::info('sms.panel_sent', ['to' => $to]);

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}