<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/**
 * Hidden superadmin (PLAN.md): excluded from listings (is_hidden),
 * non-deletable (User::deleting guard), mandatory TOTP 2FA.
 *
 * Credentials come from env so nothing secret is committed:
 *   SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $email = env('SUPERADMIN_EMAIL', 'superadmin@local');
        $password = env('SUPERADMIN_PASSWORD', 'ChangeMe!123');

        $google2fa = new Google2FA;
        $plainCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(10)))
            ->all();

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'سوپرادمین',
                'password' => Hash::make($password),
                'role' => 'super-admin',
                'is_super_admin' => true,
                'is_hidden' => true,
                'google2fa_secret' => $google2fa->generateSecretKey(),
                'google2fa_enabled' => true,
                'recovery_codes' => array_map(fn ($c) => Hash::make($c), $plainCodes),
            ]
        );
        $user->assignRole('super-admin');

        $this->command->warn('— Superadmin ready (hidden, 2FA enforced) —');
        $this->command->line("  email:    {$email}");
        $this->command->line('  password: '.(env('SUPERADMIN_PASSWORD') ? '(from env)' : 'ChangeMe!123  ← CHANGE IT, set SUPERADMIN_PASSWORD'));
        $this->command->line("  totp secret: {$user->google2fa_secret}");
        $this->command->line('  recovery codes (each single-use, shown once): '.implode(', ', $plainCodes));
    }
}
