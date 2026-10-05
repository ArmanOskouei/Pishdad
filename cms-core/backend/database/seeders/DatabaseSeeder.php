<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesPermissionsSeeder::class,
            SuperAdminSeeder::class,
            DemoAdminSeeder::class,
            DefaultPagesSeeder::class,
            // `F4.1.B` — قالب‌های درون‌ساخت **از کد** (`Q5`). باید بعد از
            // `RolesPermissionsSeeder` بیاید چون `themes.review_status` و
            // سیاستِ review در آن تعریف شده.
            SiteThemeSeeder::class,
        ]);
    }
}
