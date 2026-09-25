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
        ]);
    }
}
