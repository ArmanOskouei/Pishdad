<?php

/**
 * تولید `database/seeders/data/pishdad-pages.php` از محتوای زندهٔ سایت.
 *
 * ## چرا این ابزار وجود دارد
 *
 * محتوای سایت از پنل ویرایش می‌شود، نه داخل کد. پس برای اینکه بستهٔ خام
 * همان محتوا را داشته باشد، باید گاهی محتوای *زنده* را به کد تبدیل کرد.
 *
 * ## اجرا
 *
 *     php artisan tinker --execute="require 'tools/export-site-content.php';"
 *     یا مستقیم: php tools/export-site-content.php
 *
 * ⚠️ **خروجی را بازبینی کن.** این ابزار هرچه در دیتابیس منتشر شده را
 * می‌نویسد، پس اگر دادهٔ آزمایشی وارد سایت شده باشد، به کد هم راه پیدا
 * می‌کند. تست `PishdadSiteSeederTest` دوباره چک می‌کند که فایل حاصل
 * هیچ شناسه یا secret نداشته باشد.
 *
 * فقط محتوای *عمومی* استخراج می‌شود: slug/title/blocks/meta/is_single.
 *
 * ‎⚠️ عمداً این‌ها وارد فایل نمی‌شوند چون وابسته به نصب‌اند:
 *   - `id` صفحه (در هر نصب فرق می‌کند ⇒ لینک‌ها باید با slug حل شوند)
 *   - `user_id`
 *   - `logo_media_id` / `favicon_media_id` / `og_image_media_id`
 *   - کلیدهای VAPID و seal که secret هستند
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = $root.'/database/seeders/data';

if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$pages = App\Models\Page::query()->orderBy('id')->get();

$rows = [];

foreach ($pages as $p) {
    $rows[] = [
        'slug' => $p->slug,
        'title' => $p->title,
        'is_single' => (bool) $p->is_single,
        'blocks' => $p->blocks ?? [],
        'meta' => $p->meta ?? [],
    ];
}

$body = var_export($rows, true);

$php = "<?php\n\n"
    ."/**\n"
    ." * محتوای صفحات پیشداد.\n"
    ." *\n"
    ." * ⚠️ **فایل تولیدی** — از روی محتوای زنده ساخته شده و دستی ویرایش نشود.\n"
    ." * برای تغییر محتوا، صفحه را در پنل ویرایش کنید و بعد این فایل را\n"
    ." * دوباره تولید کنید: `php tools/export-site-content.php`\n"
    ." *\n"
    ." * فقط محتوای عمومی اینجاست: slug / title / blocks / meta / is_single.\n"
    ." * هیچ شناسهٔ کاربر، رمز، کلید یا شناسهٔ رسانه‌ای وارد نشده، چون\n"
    ." * همه‌شان به نصب مشخص وابسته‌اند.\n"
    ." *\n"
    ." * @return array<int, array{slug: string, title: string, is_single: bool, blocks: array, meta: array}>\n"
    ." */\n\n"
    ."return ".$body.";\n";

file_put_contents($dir.'/pishdad-pages.php', $php);

echo 'wrote '.count($rows).' pages → database/seeders/data/pishdad-pages.php ('.strlen($php)." bytes)\n";