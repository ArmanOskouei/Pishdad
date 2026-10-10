<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * S-11 — برگرداندنِ اجبارِ ۲FA برای کاربری که موقتاً معاف شده بود.
 *
 * جفتِ `pishdad:2fa:unlock`: بعد از اینکه کاربر ۲FA را فعال کرد، معافیت
 * باید برداشته شود تا سیاست دوباره کامل شود.
 */
class LockTwoFactor extends Command
{
    protected $signature = 'pishdad:2fa:lock {email : ایمیلِ کاربر}';

    protected $description = 'معافیتِ ۲FA را برای یک کاربر برمی‌دارد';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('کاربری با این ایمیل پیدا نشد.');

            return self::FAILURE;
        }

        if (! $user->two_factor_exempt) {
            $this->info('این کاربر معاف نبود.');

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_exempt' => false])->save();
        $this->info("معافیت {$user->email} برداشته شد.");

        return self::SUCCESS;
    }
}
