<?php

namespace App\Services\Sms;

use App\Services\External\MissingCredentialException;
use InvalidArgumentException;

/**
 * انتخابِ درایورِ پیامک از روی کانفیگ — تنها جایی که نامِ درایور خوانده می‌شود.
 *
 * ⭐ چرا factory و نه `match` داخل کنترلر
 *
 * ۱. **fail-closed در یک نقطه.** هر جایی که درایور خواسته شود، همین یک مسیر
 *    طی می‌شود؛ پس نمی‌شود جایی «یادت رفت چک کنی کلید هست یا نه».
 * ۲. **نامِ ناشناخته لو نمی‌رود.** `SMS_DRIVER=kavenegar` تا وقتی کلاسش
 *    نباشد یک خطای فارسیِ روشن می‌دهد، نه `Error: class not found` وسطِ
 *    یک درخواستِ تأییدِ شماره.
 * ۳. **قابلِ تست بدون HTTP.** تست می‌تواند `SMS_DRIVER=panel` بگذارد و
 *    assert کند exception می‌خورد — بدون اینکه به اینترنت وصل شود.
 */
final class SmsDriverFactory
{
    /** درایورهایی که هیچ چیزی بیرون نمی‌فرستند. */
    public const OFFLINE_DRIVERS = ['null', 'log'];

    public const PANEL = 'panel';

    /**
     * ⭐ `SMS_DRIVER` ناشناخته ⇒ خودِ درایور بی‌خبر `null` می‌شود.
     *
     * این عمداً «پیش‌فرضِ امن» است: `SMS_DRIVER=panl` (یک تایپ) نباید سایت را
     * بیندازد، ولی نباید هم وانمود کند پیامک کار می‌کند. پس به `null` می‌افتد
     * و `pishdad:doctor` آن را گزارش می‌کند.
     */
    public static function make(?string $driver = null): SmsSenderInterface
    {
        $driver = $driver ?? (string) config('sms.driver', 'null');
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'null', '', 'none', 'off' => new NullSmsDriver,
            'log' => new LogSmsDriver,
            self::PANEL => new PanelSmsDriver, // خودش در سازنده fail-closed است
            default => new NullSmsDriver,
        };
    }

    /**
     * آیا درایورِ انتخاب‌شده واقعاً پیام را به گوشی می‌رساند؟
     *
     * مصرف‌کننده‌ها (`ProfileController::smsRequest`) باید با همین تصمیم بگیرند
     * که آیا «ارسال شد» بگویند یا صادقانه بگویند چیزی نرفته.
     */
    public static function delivers(?string $driver = null): bool
    {
        return self::make($driver)->delivers();
    }

    /**
     * کلید(های) جاافتادهٔ درایورِ انتخاب‌شده — برای `pishdad:doctor`.
     *
     * عمداً exception نمی‌دهد: `doctor` باید بتواند **بدون ساختنِ درایور** گزارش
     * بدهد.
     *
     * @return list<string>
     */
    public static function missingKeys(?string $driver = null): array
    {
        $driver = strtolower(trim($driver ?? (string) config('sms.driver', 'null')));

        if ($driver !== self::PANEL) {
            return [];
        }

        return PanelSmsDriver::missingKeys();
    }

    /**
     * تلاش برای ساخت؛ به‌جای exception، وضعیت را برمی‌گرداند.
     *
     * @return array{0: ?SmsSenderInterface, 1: ?MissingCredentialException}
     */
    public static function tryMake(?string $driver = null): array
    {
        try {
            return [self::make($driver), null];
        } catch (MissingCredentialException $e) {
            return [null, $e];
        } catch (InvalidArgumentException $e) {
            return [null, $e];
        }
    }
}