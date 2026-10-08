<?php

/**
 * L-B12 / K7.19 — سازندهٔ فهرستِ فایل‌های تست برای `phpunit.agenta.xml`.
 *
 * ## ⭐ چرا این فایل وجود دارد — و چرا نباید حذفش کرد
 *
 * `phpunit.agenta.xml` به‌جای `<directory>tests/Feature</directory>`، تک‌تک
 * فایل‌ها را با `<file>` نام می‌برد. این کار عجیب به نظر می‌رسد ولی **تنها**
 * راهی است که روی این محیط کلِ تست‌ها را می‌بیند. دلیلش فنی است:
 *
 * **این پروژه روی Windows اجرا می‌شود** و پوشهٔ پروژه در کانتینر با
 * **9p** mount شده:
 *
 * ```
 * 9p rw,noatime,... /var/www/html
 * ```
 *
 * روی 9p، `scandir` و `glob` و `readdir` هر ۹۰ فایل را می‌بینند، ولی **هر**
 * پیاده‌سازیِ `*DirectoryIterator` در PHP فقط **۴۷** فایل می‌بیند — و آن ۴۷ تا یک
 * بازهٔ الفبایی پیوسته‌اند (`P` تا `W`)، نه یک زیرمجموعهٔ معنادار. یعنی
 * هرچه نامش با `P` شروع شود دیده می‌شود و هرچه کوچک‌تر باشد، گم می‌شود.
 *
 * این رفتار **قطعی** است (نه flaky): سه بار پشت‌سرهم ۴۷، و با `touch` کردنِ
 * همهٔ فایل‌ها باز هم ۴۷. همان فایل‌ها روی overlay خودِ کانتینر (`/tmp`) با
 * همان کد PHP عدد ۹۰ را می‌دهند — پس مقصر PHP نیست، **9p است**.
 *
 * حالِ بد ماجرا: PHPUnit برای پیمایش `<directory>` از کامپوننت
 * `phpunit/php-file-iterator` استفاده می‌کند که داخلش
 * `RecursiveDirectoryIterator` است. یعنی:
 *
 * | فرمان                                   | تعداد تست |
 * |-----------------------------------------|-----------|
 * | `phpunit tests/Feature`                  | **۱۹**    |
 * | `phpunit --testsuite Feature`            | **۱۹**    |
 * | `phpunit` (هر دو testsuite)              | **۳۰**    |
 * | با `<file>` صریح (این فایل‌ساز)          | **۸۶۲**   |
 *
 * یعنی پیش‌فرضِ پروژه **۹۸٪ تست‌ها را بی‌صدا حذف می‌کرد و سبز گزارش می‌داد**.
 * هیچ‌کس متوجه نمی‌شد. «تست‌ها سبز است» عملاً بی‌معنا بود.
 *
 * ## نگه‌داشتن فهرست در کنترل نسخه، به‌جای تولید در هر اجرا
 *
 * `<file>` را نمی‌شود با wildcard نوشت (بررسی شد: «No tests executed!»)، پس
 * فهرست باید صریح باشد. و اگر در هر اجرا تولید شود، یک روز کسی فایل را عوض
 * می‌کند و بی‌سروصدا از اجرا می‌افتد. پس:
 *
 *  ۱. این اسکریپت فهرست را **داخل `phpunit.agenta.xml` می‌نویسد**،
 *  ۲. و `TestSuiteIntegrityTest` بررسی می‌کند که فهرست با دیسک بخواند.
 *
 * یعنی افزودن/حذف تست بدون رگ��نریکردن، **تست قرمز** می‌دهد — نه اجرای ناقص.
 *
 * ## اجرا
 *
 * ```bash
 * docker compose exec -T app php /var/www/html/tools/build-phpunit-file-list.php
 * ```
 *
 * @see tests/Feature/TestSuiteIntegrityTest.php
 */
$root = dirname(__DIR__);
$configPath = $root.'/phpunit.agenta.xml';
$featureDir = $root.'/tests/Feature';
$unitDir = $root.'/tests/Unit';

/**
 * همهٔ کانفیگ‌های PHPUnit که بلوکِ تولیدشده دارند.
 *
 * چرا چند تا: پروژه علاوه بر `phpunit.agenta.xml`، فایلِ `phpunit.xml` را هم
 * دارد که **پیش‌فرضِ `php artisan test`** است. آن هم روی 9p همان باگ را داشت
 * (۱۹ تست از ۸۷۷). `phpunit.agent{b,c,d}.xml` نسخه‌های موازیِ ایجنت‌ها هستند با
 * دیتابیسِ جدا (`pishdad_test_b/c/d`). اگر فقط یکی را درست می‌کردیم، دقیقاً همان
 * «نیمی از تست‌ها» از درِ دیگری برمی‌گشت.
 */
$configPaths = [];

foreach (glob($root.'/phpunit*.xml') ?: [] as $path) {
    // فقط کانفیگ‌هایی که نشانهٔ تولیدشده دارند — وگرنه فایلی مثل
    // `phpunit.xml.dist` یا یک کانفیگ دستیِ بی‌ربط را خراب می‌کنیم.
    $body = (string) file_get_contents($path);

    if (str_contains($body, 'BEGIN:GENERATED:Feature')) {
        $configPaths[] = $path;
    }
}

sort($configPaths, SORT_STRING);

/**
 * فایل‌های تستِ یک پوشه را بدون تکیه بر `*DirectoryIterator`.
 *
 * ⭐ `glob` و `scandir` روی 9p هر دو کامل‌اند (برخلاف iterator) — این تنها
 * دلیلی است که این تابع به‌جای `scandir` از `glob` استفاده می‌کند: `glob`
 * الگوی `*Test.php` را هم خودش فیلتر می‌کند و نیازی به فیلتر دستی نیست.
 *
 * @return list<string> مسیرهای نسبتِ جداشده با `/`
 */
$discover = static function (string $dir) use ($root): array {
    if (! is_dir($dir)) {
        return [];
    }

    // `glob` نه `DirectoryIterator` — روی mount نوع 9p این تنها راهِ کامل است.
    $found = glob($dir.'/*Test.php') ?: [];

    $relative = [];

    foreach ($found as $path) {
        if (! is_file($path)) {
            continue;
        }

        $relative[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
    }

    sort($relative, SORT_STRING);

    return $relative;
};

$featureFiles = $discover($featureDir);
$unitFiles = $discover($unitDir);

$render = static function (array $files): string {
    if ($files === []) {
        return "            <!-- (no test files) -->\n";
    }

    $out = '';

    foreach ($files as $file) {
        // ۸ فاصله برای `<file>` و یک فاصله برای خودِ تگ، تا با بقیهٔ XML هم‌تراز باشد.
        $out .= '            <file>'.htmlspecialchars($file, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</file>\n";
    }

    return $out;
};

/**
 * بلوکِ هر `<testsuite>` را با فهرستِ تازه جایگزین می‌کند.
 *
 * الگو **غیرحریص** (`.*?`) است تا از `BEGIN:Unit` تا نزدیک‌ترین `END:Unit`
 * برود، نه تا آخرین `END` فایل. بدون آن، بلوکِ Unit می‌توانست کلِ Feature را
 * هم ببلعد.
 */
$patternFor = static fn (string $name): string => '/[ \t]*<!-- BEGIN:GENERATED:'.preg_quote($name, '/').' -->.*?<!-- END:GENERATED:'.preg_quote($name, '/').' -->/s';

$written = 0;

foreach ($configPaths as $configPath) {
    $config = (string) file_get_contents($configPath);

    foreach ([['Unit', $unitFiles], ['Feature', $featureFiles]] as [$name, $files]) {
        $pattern = $patternFor($name);

        if (preg_match($pattern, $config) !== 1) {
            fwrite(STDERR, "marker block not found for testsuite '{$name}' in {$configPath}\n");
            fwrite(STDERR, "add these two lines inside the <testsuite> and re-run:\n");
            fwrite(STDERR, "  <!-- BEGIN:GENERATED:{$name} -->\n  <!-- END:GENERATED:{$name} -->\n");
            exit(1);
        }

        $replacement = "            <!-- BEGIN:GENERATED:{$name} -->\n"
            .$render($files)
            .'            '."<!-- END:GENERATED:{$name} -->";

        // `preg_replace` interprets `$1`/`\1` in the replacement as backreferences,
        // so a literal `$` in the generated XML (none today, but a file could be
        // named with one) must be escaped.
        $config = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $config, 1);
    }

    if (file_put_contents($configPath, $config) === false) {
        fwrite(STDERR, "cannot write {$configPath}\n");
        exit(1);
    }

    $written++;
}

if ($written === 0) {
    fwrite(STDERR, "no phpunit config with generated markers found in {$root}\n");
    exit(1);
}

printf(
    "updated %d config(s)\n  Unit   : %d file(s)\n  Feature: %d file(s)\n",
    $written,
    count($unitFiles),
    count($featureFiles),
);

foreach ($configPaths as $configPath) {
    echo '  - '.str_replace('\\', '/', substr($configPath, strlen($root) + 1))."\n";
}
