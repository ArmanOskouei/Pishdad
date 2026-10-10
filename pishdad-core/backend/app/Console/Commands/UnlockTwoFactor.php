<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * S-11 — چراغِ قفلِ ۲FA برای یک کاربر.
 *
 * وقتی میدل‌ور سفت‌شده کسی را بیرون گذاشته (کاربرِ بدون دسترسی به
 * اینترنت/موبایل)، این فرمان از **CLI** — بدون نیاز به HTTP — همین یک
 * کاربر را معاف می‌کند:
 *
 *     php artisan pishdad:2fa:unlock oskouei.arman@gmail.com
 *
 * عمداً ۲FA را **غیرفعال** نمی‌کند (آن تصمیمِ خودِ کاربر است) و فلگِ نقش را
 * هم تغییر نمی‌دهد (بقیهٔ دارندگانِ نقش دست‌نخورده می‌مانند) — فقط
 * `users.two_factor_exempt` را true می‌کند تا کاربر بتواند وارد شود و
 * راهِ درست را در پروفایل پیش بگیرد.
 */
class UnlockTwoFactor extends Command
{
    protected $signature = 'pishdad:2fa:unlock {email : ایمیلِ کاربر}';

    protected $description = 'یک کاربر را از اجبارِ ۲FA معاف می‌کند (چراغِ قفلِ CLI)';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('کاربری با این ایمیل پیدا نشد.');

            return self::FAILURE;
        }

        if ($user->two_factor_exempt) {
            $this->info('این کاربر از قبل معاف بود.');

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_exempt' => true])->save();

        $roles = $user->roles()->pluck('name')->implode(', ');
        $this->warn("{$user->email} معاف شد (نقش‌ها: {$roles}). وقتی ۲FA را فعال کرد، معافیت را با pishdad:2fa:lock بردار.");

        return self::SUCCESS;
    }
}
