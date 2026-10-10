<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesPermissionsSeeder::class,
            // S-01: `DemoAdminSeeder` از مسیرِ پیش‌فرض خارج شد — یک اکانت
            // admin@example.com / Admin!123 با نقش admin و 2FA خاموش می‌ساخت
            // و چون `updateOrCreate` بود رمزِ حسابِ موجود را هم ریست می‌کرد.
            // نصب‌کنندهٔ وب هیچ‌وقت آن را صدا نمی‌زند؛ فقط برای توسعهٔ محلی
            // دستی: `php artisan db:seed --class=DemoAdminSeeder`
            DefaultPagesSeeder::class,
            FormSeeder::class,
            // `F4.1.B` — قالب‌های درون‌ساخت **از کد** (`Q5`). باید بعد از
            // `RolesPermissionsSeeder` بیاید چون `themes.review_status` و
            // سیاستِ review در آن تعریف شده.
            SiteThemeSeeder::class,
            // E79 — `db:seed` (و `migrate --seed`) باید همان نتیجهٔ نصب‌کنندهٔ
            // وب را بدهد: هشت صفحهٔ منتشر + هویت سایت + هدر/فوتر + ظاهر پنل.
            //
            // قبلاً اینجا نمی‌آمد، پس هر کسی که دنبالِ دستی seed می‌زد سایتی
            // خالی با سه صفحهٔ پیش‌نویس می‌دید — درست خلافِ نسخهٔ منتشرشده.
            //
            // ترتیب بی‌خطر است: `DefaultPagesSeeder` صفحهٔ منتشرشده را هرگز
            // بازنویسی نمی‌کند و `PishdadSiteSeeder` هم صفحهٔ منتشرِ مدیر را
            // دست‌نخورده می‌گذارد.
            PishdadSiteSeeder::class,
        ]);
    }
}
