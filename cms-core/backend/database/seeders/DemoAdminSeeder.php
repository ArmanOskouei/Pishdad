<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\V1\UiSettingsController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sample admin for local development + default dark (amaliyat) UI settings.
 * Dev credentials: admin@example.com / Admin!123 (see README, change in prod).
 */
class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'مدیر نمونه',
                'password' => Hash::make('Admin!123'),
                'role' => 'admin',
                'google2fa_enabled' => false,
            ]
        );
        $user->assignRole('admin');

        // ظاهر پنل سراسری نصب است (کلید global) — همه مدیران یک قالب مشترک دارند.
        Setting::set('ui', 'global', UiSettingsController::DEFAULTS);

        $this->command->info('Demo admin ready: admin@example.com / Admin!123 (2FA off)');
    }
}
