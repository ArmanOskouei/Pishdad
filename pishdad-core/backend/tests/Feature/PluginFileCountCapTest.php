<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginReleaseManager;
use Tests\TestCase;
use ZipArchive;

/**
 * K5.10 — سقف `file_count` و بودجهٔ opcache.
 *
 * این فایل سه چیز را قفل می‌کند که به‌راحتی در یک refactor بی‌صدا از کار می‌افتند:
 *
 *  ۱. **مرزِ دقیق.** بستهٔ دقیقاً روی سقف نصب می‌شود و یکی بالاتر رد می‌شود. تستی که
 *     فقط «بالای سقف رد شد» را بسنجد عملاً هیچ چیز دربارهٔ مرز نمی‌گوید.
 *  ۲. **رد پیش از هر اثرِ جانبی.** بستهٔ بزرگ باید پیش از آنکه حتی *یک بایت* بنشیند
 *     رد شود. یک canary داخلِ همان بسته می‌گذارد و بعد کلِ sandbox را می‌گردد.
 *  ۳. **جمعیتِ درست شمرده می‌شود.** سقف روی *کل فهرست* است، نه فقط فایل‌های پذیرفته‌شده.
 *     این تنها جایی است که `install.too_many_files` و `install.too_many_entries` از هم
 *     جدا می‌شوند، پس حذفِ هر کدام را فقط همین فایل می‌گیرد.
 *
 * ## چرا سقفِ تعداد با سقفِ بایت یکی نیست
 *
 * `MAX_UNCOMPRESSED_BYTES` (۲۰۰ مگابایت) جلوی *حجم* را می‌گیرد و هیچ جلویی از تعداد
 * فایل نمی‌گیرد: بستهٔ ۵۰۰۱ تایی با یک بایت محتوا در هر فایل، چند کیلوبایت حجمِ باز
 * دارد و از هر دو سقفِ بایتی سالم رد می‌شود. یعنی سقفِ تعداد افزونهٔ ناموجود نیست،
 * و `test_the_count_cap_catches_what_the_byte_cap_cannot` همین را نشان می‌دهد.
 *
 * ## چرا نگهبانِ opcache شکلِ رفتاری ندارد
 *
 * این را سنجیدم، نه حدس زدم: در SAPIِ `cli` — همان جایی که PHPUnit اجرا می‌شود —
 * `opcache_reset()` **نا‌اثر است** (پیش و پس از فراخوانی، شمارِ اسکریپت‌های کش‌شده و
 * حافظهٔ مصرفی دقیقاً یکسان ماند) و آسیبِ واقعیِ آن فقط زیر FPM و در حافظهٔ *مشترکِ*
 * workerها ظاهر می‌شود. پس هیچ assertِ رفتاری در این فرایند نمی‌تواند آن را بگیرد و
 * guard آگاهانه شکلِ متنِ منبع می‌گیرد. عیبش صریح است — شکلِ متن به بازنویسیِ سالم
 * حساس است — و مزیتش این است که در هر محیطی اجرا می‌شود.
 */
class PluginFileCountCapTest extends TestCase
{
    private PluginReleaseManager $releases;

    private PluginInstaller $installer;

    /** ریشهٔ sandbox، canonical — تزریق می‌شود تا به config سراسری وابسته نباشیم. */
    private string $sandbox;

    private string $token;

    private const SLUG = 'blog';

    private const VERSION = '1.0.0';

    /**
     * تعداد ورودی‌های *زائد* در تستِ جمعیت.
     *
     * ۲۰۰۱ است نه یک عددِ گردِ نزدیکِ سقف، تا معلوم باشد این تست سقف را از روی
     * *تعدادِ کل* می‌سنجد و نه از روی نسبتِ زائد به مجاز.
     */
    private const JUNK_ENTRIES = 2001;

    /**
     * `opcache.max_accelerated_files` در `docker/php/php.ini`.
     *
     * فقط وقتی به کار می‌آید که افزونه در محیطِ اجرا نباشد — تست نباید بی‌دلیل رد
     * شود. وقتی هست، مقدارِ واقعی خوانده می‌شود و این فقط نگهبانِ fallback است.
     */
    private const DEPLOYMENT_OPCACHE_SCRIPT_TABLE = 20000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = bin2hex(random_bytes(5));

        // sandbox در `sys_get_temp_dir()` است، نه `storage/framework/testing/`.
        //
        // این انتخاب سلیقه نیست و اندازه‌گیری شد: روی bind-mountِ این استقرار
        // (virtiofs/Docker Desktop) نوشتنِ ۳۰۰۰ فایل و بعد `readdir` همان پوشه،
        // ۶۲ فایل را از قلم می‌اندازد — و **در پیمایشِ دوم هم همان ۶۲ تا غایب‌اند**.
        // `PluginInstaller::verifyStaging()` درست همین کار را می‌کند (درختِ
        // نوشته‌شده را می‌گیرد و با فهرستِ انتظاری مقایسه می‌کند) ⇒ یک بستهٔ سالمِ
        // بزرگ روی این فایل‌سیستم با `install.incomplete` رد می‌شود. روی tmpfs/overlay
        // همین بسته در ۰٫۷ ثانیه و بدون خطا نصب می‌شود.
        $root = sys_get_temp_dir().'/plugin-file-count/'.$this->token;
        mkdir($root, 0777, true);

        $this->sandbox = (string) realpath($root);
        $this->releases = new PluginReleaseManager($this->sandbox);
        $this->installer = new PluginInstaller($this->releases, 0.25);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);

        parent::tearDown();
    }

    // ── مرزِ سقف ────────────────────────────────────────────────────────────

    /**
     * روی سقف باید نصب شود. اگر این قرمز شود یعنی کسی `>` را به `>=` تبدیل کرده و
     * سقفِ اعلام‌شده یک فایل کم‌تر از واقعیت است.
     */
    public function test_a_package_exactly_at_the_file_cap_installs(): void
    {
        $result = $this->install($this->zip($this->capFiles()));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertCount(PluginPackageContract::MAX_FILES, $result['extracted']);
        // `verified` از بازبینیِ پس از استخراج می‌آید نه از شمارشِ فهرست: اگر آن
        // بازبینی اجرا نشود صفر می‌ماند و این تست عملاً چیزی را ثابت نمی‌کند.
        $this->assertSame(PluginPackageContract::MAX_FILES, $result['verified']);

        $base = (string) $result['path'];
        $this->assertFileExists($base.'/'.PluginPackageContract::MANIFEST);
        $this->assertFileExists($base.'/'.PluginPackageContract::BACKEND_ROOT.'/src/F0.php');
        $this->assertFileExists(
            $base.'/'.PluginPackageContract::BACKEND_ROOT.'/src/F'.(PluginPackageContract::MAX_FILES - 2).'.php'
        );
    }

    /** یکی بالاتر از سقف: رد، با همان کدی که از قبل برای همین جمعیت بوده. */
    public function test_one_entry_above_the_file_cap_is_rejected(): void
    {
        $result = $this->install($this->zip($this->capFiles(extra: 1)));

        $this->assertFalse($result['ok']);
        $this->assertSame('install.too_many_files', $result['code']);
        $this->assertNull($result['path']);
        $this->assertSame([], $result['extracted']);
        $this->assertSame(0, $result['verified']);
        $this->assertSame([], $this->releases->releases(self::SLUG));
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
    }

    /**
     * بسته‌ای با ورودی‌های *زائد* — همان‌هایی که ردِ نرم می‌خورند و در شمارشِ
     * فایل‌های پذیرفته‌شده اصلاً نیستند.
     *
     * اینجا دو جمعیت از هم جدا می‌شوند و هر کدام کدِ خودش را دارد:
     *
     *  - `Laravel/src/…` پذیرفته می‌شود ⇒ در `install.too_many_files` شمرده می‌شود.
     *  - `docs/…` ردِ نرم می‌خورد ⇒ فقط در `install.too_many_entries` شمرده می‌شود.
     *
     * اگر شرط دوم حذف شود، بستهٔ دوم بی‌سروصدا نصب می‌شود؛ یعنی دقیقاً همان چیزی که
     * `PluginPackageValidator` از قبل (`:121`) رد می‌کند و دو لایه دیگر نامستقل
     * نیستند.
     */
    public function test_the_cap_also_counts_entries_that_would_only_be_skipped(): void
    {
        // ۵۰۰۰ ورودی در حالتِ اول: ۱ زائد کمتر از ۲۰۰۱ یعنی یکی بیشتر از حالتِ دوم.
        $atCap = $this->install($this->zip($this->skewedFiles(
            PluginPackageContract::MAX_FILES - self::JUNK_ENTRIES - 1,
        )));

        $this->assertTrue($atCap['ok'], $atCap['message']);
        $this->assertSame(PluginPackageContract::MAX_FILES, count($atCap['extracted']) + count($atCap['skipped']));
        $this->assertCount(self::JUNK_ENTRIES, $atCap['skipped']);
        // فقط پذیرفته‌شده‌ها روی دیسک نشستند.
        $base = (string) $atCap['path'];
        $this->assertFileExists($base.'/'.PluginPackageContract::MANIFEST);
        $this->assertFileDoesNotExist($base.'/docs/note0.md');

        $overCap = $this->install($this->zip($this->skewedFiles(
            PluginPackageContract::MAX_FILES - self::JUNK_ENTRIES,
        )));

        $this->assertFalse($overCap['ok']);
        $this->assertSame('install.too_many_entries', $overCap['code']);
        $this->assertNull($overCap['path']);
        // فقط عدد سنجیده می‌شود؛ متنِ فارسی عمداً مقایسه نمی‌شود چون نشانه‌های
        // نامرئیِ RTL آن را شکننده می‌کنند.
        $this->assertStringContainsString((string) PluginPackageContract::MAX_FILES + 1, $overCap['message']);
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
    }

    // ── چرا سقفِ تعداد افزونهٔ ناموجودِ سقفِ بایت است ────────────────────────

    /**
     * ۵۰۰۱ فایل، هر کدام **یک بایت**.
     *
     * یعنی بسته هم زیرِ `MAX_ZIP_BYTES` است و هم حجمِ بازش زیرِ
     * `MAX_UNCOMPRESSED_BYTES` — پس هیچ‌کدام از سقف‌های بایتی لمس نمی‌شوند و تنها
     * چیزی که می‌تواند این بسته را رد کند، سقفِ تعداد است. اگر این تست قرمز شود یعنی
     * سقفِ تعداد عملاً بی‌اثر شده، یا به چیزی تغییر کرده که دیگر اینجا را نمی‌گیرد.
     */
    public function test_the_count_cap_catches_what_the_byte_cap_cannot(): void
    {
        $files = $this->capFiles(extra: 1, oneByteEach: true);
        $zip = $this->zip($files);

        $this->assertLessThan(PluginPackageContract::MAX_UNCOMPRESSED_BYTES, array_sum(array_map('strlen', $files)));
        $this->assertLessThan(PluginPackageContract::MAX_ZIP_BYTES, (int) filesize($zip));

        $result = $this->install($zip);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            $result['code'],
            ['install.too_many_files', 'install.too_many_entries'],
            'بسته‌ای که زیرِ هر دو سقفِ بایتی است باید فقط به خاطرِ سقفِ تعداد رد شود.'
        );
    }

    // ── رد پیش از هر اثرِ جانبی ─────────────────────────────────────────────

    /**
     * مهم‌ترین تستِ رفتاریِ این فایل.
     *
     * نسخهٔ فعالِ سالم از قبل نصب و فعال است؛ حالا نسخهٔ دوم که از سقف رد می‌شود
     * می‌آید. سه چیز جداگانه سنجیده می‌شود، چون «نصب ناموفق بود» به‌تنهایی هیچ‌چیز
     * را ثابت نمی‌کند — یک typo در تست همان نتیجه را می‌دهد:
     *
     *  ۱. مسیرِ نسخهٔ دوم اصلاً ساخته نشد.
     *  ۲. staging خالی است، یعنی پوشهٔ نیمه‌کار نمانده.
     *  ۳. کلِ sandbox پوییده می‌شود: هیچ مسیری به نسخهٔ رد‌شده نمی‌رسد و تنها canary
     *     موجود، همانِ نسخهٔ فعالِ قبلی است که بایت‌به‌بایت دست‌نخورده مانده.
     *
     * ## آنچه این تست **نمی‌تواند** بگیرد
     *
     * ترتیبِ «بررسی پیش از نوشتن» را. دلیلش ساختاری است: `abort()` پوشهٔ staging را
     * پاک می‌کند، پس اگر نوشتن انجام شده باشد یا نشده باشد، **وضعیتِ نهاییِ دیسک
     * دقیقاً یکی است**. با جهشِ عمدی (بردنِ شرط به بعد از حلقهٔ نوشتن) این تست سبز
     * ماند — تنها چیزی که عوض می‌شد کارِ بیهوده و ۵۰۰۰ نوشتنِ موقت بود، نه دیسک.
     * پس ترتیب را تستِ بعدی، ساختاری، قفل می‌کند.
     */
    public function test_rejection_leaves_no_extraction_side_effect(): void
    {
        $v1Zip = $this->zip([
            PluginPackageContract::MANIFEST => $this->manifestJson(self::VERSION),
            PluginPackageContract::BACKEND_ROOT.'/src/Canary.php' => '<?php // v1',
        ]);

        $v1 = $this->install($v1Zip);
        $this->assertTrue($v1['ok'], $v1['message']);
        $this->releases->activate(self::SLUG, self::VERSION, $this->hash8Of($v1Zip));

        // یکی بالاتر از سقف، و canary داخلش — تا اگر نوشتنی انجام شود، دیده شود.
        $overCapZip = $this->zip($this->capFiles(canary: '<?php // v2', version: '2.0.0'));
        $overCapTarget = $this->releases->releasePath(self::SLUG, '2.0.0', $this->hash8Of($overCapZip));

        $result = $this->install($overCapZip, '2.0.0');

        $this->assertFalse($result['ok']);
        $this->assertSame('install.too_many_files', $result['code']);

        // ۱) هیچ نسخه‌ای ساخته نشد.
        $this->assertDirectoryDoesNotExist($overCapTarget);
        $this->assertCount(1, $this->releases->releases(self::SLUG));

        // ۲) نه staging نماند، نه فایلِ قفل.
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));

        // ۳) کلِ sandbox پوییده شد.
        $walked = $this->walk($this->sandbox);
        $canaries = array_values(array_filter($walked, fn ($p) => str_ends_with($p, '/Canary.php')));

        $this->assertCount(1, $canaries, 'فقط canaryِ نسخهٔ فعال باید روی دیسک باشد.');
        $this->assertStringStartsWith(
            self::SLUG.'/releases/'.self::VERSION.'-',
            $canaries[0],
            'canaryِ نسخهٔ رد‌شده نباید جایی بنشیند.'
        );
        // نسخهٔ فعالِ قبلی دست‌نخورده — نه محتوا، نه اشاره‌گر.
        $this->assertSame('<?php // v1', file_get_contents($v1['path'].'/'.PluginPackageContract::BACKEND_ROOT.'/src/Canary.php'));
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
        foreach ($walked as $path) {
            $this->assertStringNotContainsString('2.0.0', $path, 'مسیری از بستهٔ رد‌شده ساخته شده است: '.$path);
        }
    }

    /**
     * قفلِ ترتیب: هر دو سقفِ تعداد **پیش از** نخستین نوشتن بررسی می‌شوند.
     *
     * این تنها شکلِ ممکن برای گرفتنِ ادعای «پیش از استخراج» است، و دلیلش را با
     * جهش ثابت کردم: با بردنِ شرط به بعد از حلقهٔ نوشتن، همهٔ تست‌های رفتاری سبز
     * ماندند — چون `abort()` staging را پاک می‌کند و وضعیتِ نهاییِ دیسک در هر دو
     * حالت یکی است.
     *
     * یعنی این تست شکلِ متن است و عیبش صریح: به جابه‌جاییِ کد حساس می‌شود حتی وقتی
     * بی‌خطر است. در عوض چیزی را می‌گیرد که هیچ assertِ رفتاری در این کلاس نمی‌تواند.
     */
    public function test_both_count_caps_are_checked_before_anything_is_written(): void
    {
        $source = (string) file_get_contents(app_path('Services/Plugins/PluginInstaller.php'));

        $firstWrite = strpos($source, '$this->writeEntry(');
        $this->assertIsInt($firstWrite, 'حلقهٔ نوشتن باید در `extractIntoStaging()` باشد.');

        foreach (['install.too_many_files', 'install.too_many_entries'] as $code) {
            $at = strpos($source, $code);

            $this->assertIsInt($at, 'کد «'.$code.'» دیگر در `PluginInstaller` نیست.');
            $this->assertLessThan(
                $firstWrite,
                $at,
                '«'.$code.'» بعد از نخستین نوشتن بررسی می‌شود؛ یعنی سقف پس از استخراج اعمال می‌شود، نه پیش از آن.'
            );
        }
    }

    // ── بودجهٔ opcache ──────────────────────────────────────────────────────

    /**
     * K5.10 — سقفِ یک بسته باید در جدولِ اسکریپت‌های opcache جا شود.
     *
     * این تنها invariantی است که از این تسک *امروز* قابل‌دفاع است: اگر حتی یک بسته
     * از کلِ جدول بزرگ‌تر باشد، فایل‌هایش هرگز کش نمی‌شوند و آن بی‌صدا اتفاق می‌افتد.
     *
     * اما دربارهٔ *مجموعِ* پلاگین‌های زنده چیزی **نمی‌گوید** — و آن همان شکافِ بازی است
     * که در `PluginPackageContract::MAX_FILES` صریح نوشته شده. برای بستنش باید بودجهٔ
     * aggregate هنگام *فعال‌سازی* enforce شود، که امروز هیچ‌جا enforce نمی‌شود.
     */
    public function test_the_per_package_cap_fits_inside_the_shared_opcache_script_table(): void
    {
        $configured = (int) ini_get('opcache.max_accelerated_files');
        $table = $configured > 0 ? $configured : self::DEPLOYMENT_OPCACHE_SCRIPT_TABLE;

        $this->assertGreaterThan(0, $table, 'سقفِ اسکریپتِ opcache باید یک عددِ معتبر باشد.');
        $this->assertLessThanOrEqual(
            $table,
            PluginPackageContract::MAX_FILES,
            'یک بستهٔ کامل نباید از کلِ جدولِ اسکریپت‌های opcache بزرگ‌تر باشد؛ وگرنه فایل‌هایش بی‌صدا کش نمی‌شوند.'
        );
    }

    /** نگهبانِ صریحِ قیدِ K5.10: `opcache_reset()` کشِ هسته را هم پاک می‌کند. */
    public function test_the_installer_never_touches_the_opcache(): void
    {
        $source = (string) file_get_contents(app_path('Services/Plugins/PluginInstaller.php'));

        $this->assertStringNotContainsString(
            'opcache_',
            $source,
            'PluginInstaller نباید opcache را صدا بزند: opcache_reset() کشِ هسته را هم پاک می‌کند، پس یک رگرسیونِ کاراییِ کلِ اپ است نه یک درمان. مسیرِ نسخه محتوا‌محور است، پس چیزی برای بی‌اعتبار کردن وجود ندارد.'
        );
    }

    // ── کمپیلِ بسته‌ها ───────────────────────────────────────────────────────

    /**
     * دقیقاً روی سقف: `MAX_FILES - 1` فایلِ مجاز به‌علاوهٔ `manifest.json`.
     *
     * `extra` و `canary` هر دو یک ورودیِ **مجاز** اضافه می‌کنند، چون ورودیِ زائد به
     * `install.too_many_entries` می‌خورد و مرزِ شرطِ اول را دیگر نمی‌سنجد.
     *
     * @return array<string, string>
     */
    private function capFiles(
        int $extra = 0,
        ?string $canary = null,
        bool $oneByteEach = false,
        string $version = self::VERSION,
    ): array {
        $files = [];
        $body = $oneByteEach ? 'x' : '<?php // سالم';

        for ($i = 0; $i < PluginPackageContract::MAX_FILES - 1 + $extra; $i++) {
            $files[PluginPackageContract::BACKEND_ROOT.'/src/F'.$i.'.php'] = $body;
        }

        if ($canary !== null) {
            $files[PluginPackageContract::BACKEND_ROOT.'/src/Canary.php'] = $canary;
        }

        $files[PluginPackageContract::MANIFEST] = $this->manifestJson($version);

        return $files;
    }

    /**
     * `$allowed` فایلِ پذیرفته‌شده به‌علاوهٔ `JUNK_ENTRIES` ورودیِ بیرون از بستهٔ مجاز.
     *
     * @return array<string, string>
     */
    private function skewedFiles(int $allowed, string $version = self::VERSION): array
    {
        $files = [];

        for ($i = 0; $i < $allowed; $i++) {
            $files[PluginPackageContract::BACKEND_ROOT.'/src/F'.$i.'.php'] = '<?php // سالم';
        }

        for ($i = 0; $i < self::JUNK_ENTRIES; $i++) {
            $files['docs/note'.$i.'.md'] = 'x';
        }

        $files[PluginPackageContract::MANIFEST] = $this->manifestJson($version);

        return $files;
    }

    // ── کمکی ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plugin-file-count').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function manifestJson(string $version = self::VERSION): string
    {
        return (string) json_encode([
            'name' => 'پلاگین بلاگ',
            'slug' => self::SLUG,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function install(string $zipPath, string $version = self::VERSION): array
    {
        return $this->installer->install($zipPath, self::SLUG, $version);
    }

    private function hash8Of(string $zipPath): string
    {
        return substr((string) hash_file('sha256', $zipPath), 0, 8);
    }

    /** @return list<string> مسیرهای نسبت */
    private function walk(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $out = [];
        $stack = [$root];

        while ($stack !== []) {
            $dir = array_pop($stack);
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir.'/'.$entry;
                if (is_dir($full)) {
                    $stack[] = $full;

                    continue;
                }
                $out[] = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
            }
        }

        sort($out);

        return $out;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
