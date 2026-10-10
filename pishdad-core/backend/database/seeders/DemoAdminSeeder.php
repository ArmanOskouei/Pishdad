<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\V1\UiSettingsController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sample admin for LOCAL DEVELOPMENT only.
 *
 * س-۰۱: قبلاً از `DatabaseSeeder` صدا زده می‌شد و در هر نصبی — از جمله
 * اجرای دستیِ `db:seed` روی سرور — یک اکانت `admin@example.com / Admin!123`
 * با نقش `admin` (همهٔ پرمیشن‌ها) و 2FA خاموش می‌ساخت. بیشتر: چون
 * `updateOrCreate` بود، اگر حسابی با آن ایمیل از قبل وجود داشت **رمزش را
 * بی‌صدا بازنویسی می‌کرد**. حالا فقط:
 *   - در محیط local/testing،
 *   - با `firstOrCreate` (هرگز ریستِ رمز ندارد)،
 *   - و 2FA همچنان خاموش (هشدارِ شفاف، نه پنهان).
 */
class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        // ظاهر پنل سراسری (کلید global) — این بخش به محیط وابسته نیست و
        // `PishdadSiteSeeder` هم آن را می‌نویسد؛ اینجا تکرارِ بی‌خطر است.
        Setting::set('ui', 'global', UiSettingsController::DEFAULTS);

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoAdminSeeder skipped: only for local/testing. No demo user was created.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'مدیر نمونه',
                'password' => Hash::make('Admin!123'),
                'role' => 'admin',
                'google2fa_enabled' => false,
            ]
        );
        $user->assignRole('admin');

        $this->command?->warn('Demo admin ready (LOCAL ONLY, 2FA off): admin@example.com / Admin!123');
    }
}
