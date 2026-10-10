<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * S-11 — روشن‌کردنِ فلگِ «۲FA اجباری» روی نقش‌ها.
 *
 * میدل‌ور `EnsureTwoFactorSetup` تا وقتی هیچ نقشی این فلگ را نداشته باشد
 * بی‌اثر است. این فرمان برای نصب‌های **موجود** است (سیدر آن را برای نصب‌های
 * تازه می‌گذارد):
 *
 *     php artisan pishdad:2fa:require --roles=owner,admin
 *
 * ⚠️ پیش از اجرا مطمئن شو کاربرانِ آن نقش‌ها می‌دانند چه شود: تا فعال‌کردنِ
 * ۲FA در پروفایل، بقیهٔ مسیرهای پnل ۴۰۳ می‌گیرد (مسیرهای درمان باز است).
 */
class RequireTwoFactor extends Command
{
    protected $signature = 'pishdad:2fa:require
        {--roles=owner,admin : فهرست نقش‌ها، جداشده با کاما}
        {--dry : فقط نشان بده چه می‌شود، تغییر نده}';

    protected $description = 'فلگِ «۲FA اجباری» را برای نقش‌های مشخص روشن می‌کند';

    public function handle(): int
    {
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('roles')))));
        if ($names === []) {
            $this->error('هیچ نقشی داده نشده.');

            return self::FAILURE;
        }

        $roles = Role::query()->whereIn('name', $names)->get();
        $missing = array_diff($names, $roles->pluck('name')->all());
        if ($missing !== []) {
            $this->warn('نقش‌های ناموجود نادیده گرفته شد: '.implode(', ', $missing));
        }

        foreach ($roles as $role) {
            $affected = User::query()->where('google2fa_enabled', false)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
                ->count();

            $this->line(sprintf(
                'نقش %s: %d کاربر بدون ۲FA — این‌ها تا فعال‌کردنِ ۲FA محدود می‌شوند.',
                $role->name,
                $affected,
            ));

            if ($this->option('dry') !== true) {
                $role->forceFill(['requires_2fa' => true])->save();
            }
        }

        if ($this->option('dry') === true) {
            $this->info('حالتِ نمایش: چیزی تغییر نکرد.');

            return self::SUCCESS;
        }

        $this->info('فلگ روشن شد. مسیرِ اضطراری: pishdad:2fa:unlock {email}');

        return self::SUCCESS;
    }
}
