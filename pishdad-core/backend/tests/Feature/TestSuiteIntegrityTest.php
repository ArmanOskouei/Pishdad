<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * K7.19 — نگهبانِ فهرستِ تست‌ها.
 *
 * ## ⭐ چرا این تست وجود دارد
 *
 * `phpunit.agenta.xml` به‌جای `<directory>`، تک‌تک فایل‌ها را با `<file>` نام
 * می‌برد. دلیلش این است که پروژه روی Windows اجرا می‌شود و پوشه در کانتینر با
 * **9p** mount شده؛ روی 9p هر پیاده‌سازیِ `*DirectoryIterator` در PHP فقط ۴۷ از
 * ۹۰ فایل را می‌بیند، و PHPUnit برای `<directory>` دقیقاً از همان استفاده می‌کند.
 *
 * نتیجهٔ عملی این بود که اجرای پیش‌فرضِ پروژه **۱۹ تست از ۸۷۳** را اجرا می‌کرد و
 * سبز گزارش می‌داد. یعنی «تست‌ها سبز است» عملاً بی‌معنا بود.
 *
 * ## هزینهٔ این تصمیم، و چرا همین تست آن را جبران می‌کند
 *
 * `<file>` را نمی‌شود با wildcard نوشت (بررسی شد)، پس فهرست باید صریح باشد. و
 * اگر صریح باشد، یک روز کسی فایل تست اضافه می‌کند و فهرست را رگنر نمی‌کند ⇒ آن
 * تست **بی‌سروصدا اجرا نمی‌شود** — که دقیقاً همان بیماری است که داشتیم، فقط
 * کوچک‌تر.
 *
 * پس این تست همان چیزی را می‌سنجد که `phpunit` باید می‌دید. اگر فهرست با دیسک
 * نخواند، **قرمز** می‌شود؛ و راه‌حلش اجرای سازنده است:
 *
 * ```bash
 * docker compose exec -T app php /var/www/html/tools/build-phpunit-file-list.php
 * ```
 *
 * یعنی خطا «کمتر دیده شد» تبدیل می‌شود به خطای «فهرست کهنه است» — که دیده
 * می‌شود.
 *
 * ## چرا `glob` و نه `scandir`
 *
 * هر دو روی 9p کامل‌اند، ولی `glob` الگوی `*Test.php` را خودش اعمال می‌کند.
 * ضمناً همین تابع در `build-phpunit-file-list.php` استفاده می‌شود — پس این تست
 * عملاً همان محاسبه را تکرار می‌کند و اختلاف یعنی فهرست کهنه است.
 *
 * @see tools/build-phpunit-file-list.php
 */
final class TestSuiteIntegrityTest extends TestCase
{
    /**
     * همهٔ کانفیگ‌هایی که باید هم‌خوان بمانند.
     *
     * ⭐ چرا همه و نه فقط `phpunit.agenta.xml`: هر کانفیگ یک مسیرِ اجرای متفاوت
     * است — `phpunit.xml` پیش‌فرضِ `php artisan test` است،
     * `phpunit.agent{b,c,d}.xml` برای اجرای موازیِ ایجنت‌ها با دیتابیسِ جدا.
     * اگر فقط یکی را می‌سنجیدیم، کانفیگِ دیگر می‌توانست کهنه بماند و دوباره
     * «نیمی از تست‌ها» از همان در برگردد — که کل دلیل وجودی این تست است.
     *
     * @return list<string>
     */
    private function configPaths(): array
    {
        $paths = [];

        foreach (glob(dirname(__DIR__, 2).'/phpunit*.xml') ?: [] as $path) {
            // فقط کانفیگ‌های تولیدشده — یک کانفیگ دستیِ بی‌ربط را نمی‌سنجیم.
            if (str_contains((string) file_get_contents($path), 'BEGIN:GENERATED:Feature')) {
                $paths[] = $path;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<string> مسیرهای نسبتِ فایل‌های تستِ واقعی روی دیسک */
    private function onDisk(string $suite): array
    {
        $dir = dirname(__DIR__).'/'.$suite;

        if (! is_dir($dir)) {
            return [];
        }

        // ⭐ `glob` و نه `DirectoryIterator` — روی mount نوع 9p فقط `glob` کامل است.
        $found = glob($dir.'/*Test.php') ?: [];

        $relative = [];

        foreach ($found as $path) {
            if (is_file($path)) {
                $relative[] = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
            }
        }

        sort($relative, SORT_STRING);

        return $relative;
    }

    /** @return list<string> فهرستِ ثبت‌شده در XML */
    private function inConfig(string $configPath, string $suite): array
    {
        $this->assertFileExists($configPath);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($configPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, basename($configPath).' معتبر نیست (XML خراب).');

        foreach ($xml->testsuites->testsuite as $testsuite) {
            if ((string) $testsuite['name'] !== $suite) {
                continue;
            }

            $files = [];

            foreach ($testsuite->file ?? [] as $file) {
                $files[] = html_entity_decode((string) $file, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }

            sort($files, SORT_STRING);

            return $files;
        }

        $this->fail("testsuite «{$suite}» در کانفیگ پیدا نشد.");
    }

    /** ⭐ اگر هیچ کانفیگی پیدا نشود، تست باید **قرمز** شود، نه بی‌صدا سبز. */
    public function test_at_least_one_config_is_guarded(): void
    {
        $this->assertNotEmpty(
            $this->configPaths(),
            'هیچ phpunit*.xml با نشانهٔ تولیدشده پیدا نشد — یعنی نگهبان عملاً '
            .'هیچ‌چیز را نمی‌سنجد و «سبز» بی‌معنا است.',
        );
    }

    public function test_unit_suite_file_list_matches_disk(): void
    {
        foreach ($this->configPaths() as $configPath) {
            $this->assertSame(
                $this->onDisk('Unit'),
                $this->inConfig($configPath, 'Unit'),
                'فهرست فایل‌های tests/Unit در '.basename($configPath).' کهنه است — '
                .'سازنده را اجرا کن: php /var/www/html/tools/build-phpunit-file-list.php',
            );
        }
    }

    public function test_feature_suite_file_list_matches_disk(): void
    {
        foreach ($this->configPaths() as $configPath) {
            $this->assertSame(
                $this->onDisk('Feature'),
                $this->inConfig($configPath, 'Feature'),
                'فهرست فایل‌های tests/Feature در '.basename($configPath).' کهنه است — '
                .'سازنده را اجرا کن: php /var/www/html/tools/build-phpunit-file-list.php',
            );
        }
    }

    /**
     * ⭐ نگهبانِ خودِ ریشه: اگر کسی برگردد سراغ `<directory>`، همه‌چیز دوباره
     * بی‌صدا می‌شکند. این تست جلوی آن را می‌گیرد — روی **همهٔ** کانفیگ‌ها.
     */
    public function test_suites_do_not_use_directory_elements(): void
    {
        foreach ($this->configPaths() as $configPath) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($configPath);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $this->assertNotFalse($xml);

            $directories = 0;

            foreach ($xml->testsuites->testsuite as $testsuite) {
                $directories += count($testsuite->directory ?? []);
            }

            $this->assertSame(
                0,
                $directories,
                basename($configPath).' نباید از <directory> استفاده کند: روی mount '
                .'نوع 9p هر *DirectoryIterator در PHP فقط ۴۷ از ۹۰ فایل را می‌بیند و '
                .'PHPUnit برای <directory> از همان استفاده می‌کند. از <file> استفاده '
                .'کن و با tools/build-phpunit-file-list.php رگنر کن.',
            );
        }
    }

    /**
     * فایل‌های ثبت‌شده باید واقعاً وجود داشته باشند — وگرنه PHPUnit بی‌سروصدا
     * از آن‌ها می‌گذرد و باز همان «نیمی از تست‌ها» اتفاق می‌افتد.
     */
    public function test_every_listed_file_exists(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ($this->configPaths() as $configPath) {
            foreach (['Unit', 'Feature'] as $suite) {
                foreach ($this->inConfig($configPath, $suite) as $file) {
                    $this->assertFileExists(
                        $root.'/'.$file,
                        'فایلِ ثبت‌شده در '.basename($configPath)." وجود ندارد: {$file}",
                    );
                }
            }
        }
    }
}
