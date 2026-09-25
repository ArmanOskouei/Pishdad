<?php

namespace App\Services\Chabokan;

/**
 * اینترفیس اتصال چابکان (تسک ۳.۳).
 * در MVP فقط StubChabokanDriver پیاده شده (خواندن از config/chabokan.php،
 * بدون تماس واقعی/شارژ). اتصال واقعی مرحله ۵: کلاس جدید ApiChabokanDriver
 * همین اینترفیس را پیاده می‌کند + تعویض binding در AppServiceProvider.
 */
interface ChabokanClientInterface
{
    public function driverName(): string;

    /** موجودی کیف پول: ['balance'=>int, 'currency'=>string, 'low_balance'=>bool]. */
    public function wallet(): array;

    /** لیست سرویس‌ها: [{name, status, ...}]. */
    public function services(): array;

    /**
     * اجرای اکشن روی سرویس (start|stop|restart|resize).
     * روی Stub فقط اعتبارسنجی + پاسخ نمایشی؛ واقعی در ApiDriver (TODO مرحله ۵).
     */
    public function serviceAction(string $name, string $action, array $options = []): array;
}
