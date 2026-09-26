<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginReleaseManager;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * K5.2 — هستهٔ نصب کد افزونه: ساختار نسخه + استخراج atomic.
 *
 * این تنها جایی است که کدِ ناشناسِ بستهٔ افزونه روی دیسک می‌نشیند، پس تست‌ها
 * اینجا «فایل روی دیسک» می‌سنجند، نه «مقدار برگشتی متد»:
 *
 *  ۱. fixture ها runtime زیر `storage/framework/testing/` ساخته می‌شوند و
 *     `tearDown` همه را پاک می‌کند ⇒ `storage/app/plugins` واقعی دست‌نخورده.
 *  ۲. ریشهٔ sandbox از constructor تزریق می‌شود، پس تست‌ها به هیچ config
 *     سراسری وابسته نیستند و می‌توانند عمداً ساختار بدنه بسازند.
 *  ۳. تست traversal یک **canary بیرون از ریشه** می‌سازد و می‌پرسد آیا فایل آنجا
 *     نوشته شد. بدون canary، «نتیجه‌اش install ناموفق بود» چیزی را ثابت نمی‌کند
 *     چون هر شکستی — حتی یک typo — همان نتیجه را می‌دهد.
 *
 * ## این تست‌ها با mutation راستی‌آزما شده‌اند
 *
 * ادعای «لایهٔ دفاعی دوم» بدون آزمونِ جهش بی‌ارزش است، پس هر ادعا با تغییرِ عمدیِ
 * پیاده‌سازی و دیدنِ قرمز شدنِ تست سنجیده شده است:
 *
 * | تغییرِ عمدی                                                    | تستِ قرمز                                                    |
 * |----------------------------------------------------------------|---------------------------------------------------------------|
 * | جایگزینیِ کلِ حلقهٔ نوشتن با `ZipArchive::extractTo()`          | هر سه تستِ امنیتی: traversal، symlink، allowlist                |
 * | حذفِ `isAllowedPath()`                                          | فقط `test_a_file_outside_the_allowed_paths_is_skipped_not_extracted` |
 * | حذفِ تشخیصِ `S_IFLNK`                                           | فقط `test_a_symlink_entry_is_rejected_and_nothing_is_installed` |
 * | حذفِ لایهٔ ۱ (`normalizePath`) از `safeName()`                  | هیچ‌کدام — traversal **سبز می‌ماند** چون لایهٔ ۳ می‌گیردش        |
 *
 * سطر آخر مهم‌ترین است: یعنی دو لایهٔ مسیر **مستقل از هم** کافی‌اند، و به همین
 * دلیل تستِ traversal عمداً به کدِ خطا محکم نمی‌شود — الزام «هیچ بایتی بیرون از
 * ریشه ننشیند» است، نه «کدام لایه گرفت».
 *
 * ## آنچه mutation ثابت *نکرد*
 *
 * بازبینیِ پس از استخراج (`verifyStaging`) ثابت شده که **اجرا می‌شود** — بدونِ آن
 * `verified` صفر می‌ماند و تستِ شمارش قرمز می‌شود — ولی ثابت نشده که *چیزی را
 * بگیرد*، چون ساختنِ وضعیتی که حلقهٔ نوشتن از آن رد می‌شود و بازبینی می‌گیرد،
 * نیازمندِ یک باگِ دوم است. این صادقانه‌ترین چیزی است که می‌شود گفت.
 */
class PluginInstallerTest extends TestCase
{
    private PluginReleaseManager $releases;

    private PluginInstaller $installer;

    /** ریشهٔ sandbox که به هر دو کلاس تزریق می‌شود. */
    private string $sandbox;

    /** همسایهٔ بیرون از ریشه — مقصد حملهٔ path traversal. */
    private string $outside;

    private string $token;

    private const SLUG = 'blog';

    private const VERSION = '1.0.0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = bin2hex(random_bytes(5));

        $root = storage_path('framework/testing/plugin-installer/'.$this->token);
        $outside = storage_path('framework/testing/plugin-installer-outside-'.$this->token);

        mkdir($root, 0777, true);
        mkdir($outside, 0777, true);

        // canonical: تا `realpath()` در کلاس‌های تست هویت باشد و مقایسه‌های
        // مسیر (`currentDir` در برابر `path` برگشتی) به symlink‌های مسیرِ پروژه
        // گیر نکنند.
        $this->sandbox = (string) realpath($root);
        $this->outside = (string) realpath($outside);

        $this->releases = new PluginReleaseManager($this->sandbox);
        // قفل کوتاه: تست «نصب همزمان» نباید منتظر بماند.
        $this->installer = new PluginInstaller($this->releases, 0.25);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);
        $this->removeTree($this->outside);

        parent::tearDown();
    }

    // ── ۱) بستهٔ سالم ───────────────────────────────────────────────────────

    public function test_it_installs_a_conforming_package_into_the_versioned_release_layout(): void
    {
        // یک‌بار ساخته می‌شود و همه‌جا بازاستفاده می‌شود: `addFromString` زمانِ
        // ویرایش هر ورودی را در ZIP می‌نویسد، پس دو فراخوانیِ `packageZip()` در دو
        // ثانیهٔ متفاوت **بایت‌های متفاوتی** می‌دهند و دو مسیرِ متفاوت. مسیرِ
        // نسخه محتوا‌محور است، پس تست باید با یک فایلِ واحد کار کند.
        $zip = $this->packageZip();
        $result = $this->install($zip);

        $this->assertTrue($result['ok'], $result['message']);

        $label = $this->hash8Of($zip);
        $this->assertSame("1.0.0-{$label}", $result['release']);

        $expected = $this->releases->releasePath(self::SLUG, self::VERSION, $label);

        $this->assertSame($expected, $result['path']);
        $this->assertFileExists($expected.'/manifest.json');
        $this->assertFileExists($expected.'/Laravel/src/Models/Post.php');
        $this->assertFileExists($expected.'/Laravel/routes/api.php');
        $this->assertFileExists($expected.'/Laravel/database/migrations/2026_01_01_000001_create_blog_posts_table.php');
        $this->assertFileExists($expected.'/Laravel/config/plugin.php');
        $this->assertFileExists($expected.'/Next.js/panel/Widget.tsx');
        $this->assertFileExists($expected.'/Next.js/blocks/hero.json');

        // ریشهٔ PSR-4 دقیقاً همان چیزی است که `PluginAutoloader::register()` می‌خواهد.
        $this->assertSame($expected.'/Laravel/src', $result['autoload_root']);

        // ریشهٔ PSR-4 باید واقعاً پوشهٔ کلاس‌ها باشد، نه یک رشتهٔ فرضی.
        $this->assertDirectoryExists($result['autoload_root']);

        // تا وقتی نسخه‌ای فعال نشده، `autoloadRoot()` عمداً `null` است: وگرنه
        // بارگذار یک ریشهٔ وجود‌نداشتنی یا نسخهٔ غیرفعال ثبت می‌کرد.
        $this->assertNull($this->releases->autoloadRoot(self::SLUG));

        $this->releases->activate(self::SLUG, self::VERSION, $label);
        $this->assertSame($expected.'/Laravel/src', $this->releases->autoloadRoot(self::SLUG));
        $this->assertSame(['blog' => 'blog/releases/1.0.0-'.$label.'/Laravel/src'], $this->releases->autoloadPackages());
    }

    public function test_the_verification_pass_covers_every_extracted_file(): void
    {
        $result = $this->install($this->packageZip());

        $this->assertTrue($result['ok'], $result['message']);
        // اگر بازبینی پس از استخراج اجرا نشود، این عدد صفر می‌ماند و تستِ
        // traversal عملاً چیزی را ثابت نکرده است.
        $this->assertSame(count($result['extracted']), $result['verified']);
        $this->assertSame(7, $result['verified']);
    }

    // ── ۲) path traversal — مهم‌ترین تست این فایل ────────────────────────────

    public function test_a_traversal_entry_never_writes_a_file_outside_the_release_root(): void
    {
        // پنج سطح بالا از پوشهٔ staging ⇒ `storage/framework/testing/`.
        // مسیر عمداً **مجاز به نظر می‌رسد** (`Laravel/src/…` اولش، که یکی از
        // `ALLOWED_PATHS` است) و بعد فرار می‌کند. اگر allowlist تنها نگهبان
        // بود، همین رد می‌شد و تست دربارهٔ لایهٔ `realpath` چیزی نمی‌گفت.
        $escape = 'Laravel/src/'.str_repeat('../', 5).'plugin-installer-outside-'.$this->token.'/Evil.php';

        $result = $this->install($this->packageZip([$escape => '<?php // pwned']));

        // ترتیبِ assertionها عمدی است: **اول نتیجهٔ امنیتی**، بعد جزئیات.
        //
        // این تست دو لایه را می‌سنجد و عمداً pin نمی‌کند کدام‌یک اول گرفته:
        //  - لایهٔ ۱ (`PluginPackageContract::normalizePath`) ⇒ `install.path_traversal`
        //  - لایهٔ ۳ (`realpath` + `isAtOrInside`) ⇒ `install.escaped_root`
        //
        // هر دو با mutation ثابت شده‌اند که مستقل از دیگری کافی‌اند: با حذفِ
        // لایهٔ ۱، تست همین‌جا با `install.escaped_root` می‌ماند و canary
        // همچنان سالم است؛ با حذفِ لایهٔ ۳، لایهٔ ۱ می‌گیرد. پس الزامِ واقعی
        // «هیچ بایتی بیرون از ریشه ننشیند» است، نه «کدام لایه گرفت».
        $this->assertFileDoesNotExist($this->outside.'/Evil.php');
        $this->assertFileDoesNotExist($this->sandbox.'/Evil.php');
        $this->assertDirectoryDoesNotExist($this->releases->releaseDir(self::SLUG).'/Evil.php');

        $this->assertFalse($result['ok']);
        $this->assertContains($result['code'], ['install.path_traversal', 'install.escaped_root']);

        // و هیچ نسخه‌ای هم ساخته نشده.
        $this->assertSame([], $this->releases->releases(self::SLUG));
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
    }

    public function test_an_absolute_path_entry_is_rejected_the_same_way(): void
    {
        $result = $this->install($this->packageZip(['/etc/cron.d/pwned' => 'x']));

        $this->assertFalse($result['ok']);
        $this->assertSame('install.path_traversal', $result['code']);
        $this->assertFileDoesNotExist($this->outside.'/pwned');
    }

    public function test_it_refuses_a_release_root_that_is_a_symlink_pointing_outside_the_base(): void
    {
        // ساختارِ از پیش ساخته‌شدهٔ خصمانه: `releases` یک symlink به بیرون از ریشه.
        // اگر ریشه را فقط `mkdir` کنیم و `realpath` نگیریم، کل بسته بیرون از
        // sandbox نوشته می‌شود.
        mkdir($this->sandbox.'/'.self::SLUG, 0777, true);
        $planted = @symlink($this->outside, $this->sandbox.'/'.self::SLUG.'/releases');

        $result = $this->install($this->packageZip());

        $this->assertFalse($result['ok']);
        // اگر symlink روی این فایل‌سیستم ساخته نشد، تست چیزی را ثابت نمی‌کند
        // و بهتر است صریح بگوید تا اینکه سبزِ الکی بدهد.
        $this->assertTrue($planted, 'symlink باید روی این پلتفرم ساخته می‌شد');
        $this->assertSame('install.storage_unavailable', $result['code']);
        $this->assertFileDoesNotExist($this->outside.'/manifest.json');
    }

    // ── ۳) symlink در ZIP ────────────────────────────────────────────────────

    public function test_a_symlink_entry_is_rejected_and_nothing_is_installed(): void
    {
        $zipPath = $this->zip([
            'manifest.json' => $this->manifestJson(),
            'Laravel/src/Ok.php' => '<?php',
            'Laravel/src/Link.php' => '/etc/passwd',
        ], symlink: 'Laravel/src/Link.php');

        $result = $this->install($zipPath);

        $this->assertFalse($result['ok']);
        $this->assertSame('install.symlink_entry', $result['code']);

        // symlink نباید به‌صورت فایل متنیِ حاوی مسیر هم نوشته شود.
        $this->assertSame([], $this->releases->releases(self::SLUG));
        $this->assertFileDoesNotExist(
            $this->releases->releasePath(self::SLUG, self::VERSION, $this->hash8Of($zipPath)).'/Laravel/src/Link.php'
        );
    }

    // ── ۴) فایل غیرمجاز ─────────────────────────────────────────────────────

    public function test_a_file_outside_the_allowed_paths_is_skipped_not_extracted(): void
    {
        $result = $this->install($this->packageZip([
            'Laravel/routes/web.php' => '<?php // هرگز نباید روی دیسک بنشیند',
            'Laravel/app/Models/Post.php' => '<?php',
            'package.json' => '{}',
            'Laravel/src/Kernel.php' => '<?php',
        ]));

        $this->assertTrue($result['ok'], $result['message']);

        $base = $result['path'];

        $this->assertFileExists($base.'/Laravel/src/Kernel.php');
        $this->assertFileDoesNotExist($base.'/Laravel/routes/web.php');
        $this->assertFileDoesNotExist($base.'/Laravel/app/Models/Post.php');
        $this->assertFileDoesNotExist($base.'/package.json');

        $skipped = array_column($result['skipped'], 'path');
        sort($skipped);
        $this->assertSame(
            ['Laravel/app/Models/Post.php', 'Laravel/routes/web.php', 'package.json'],
            $skipped
        );
        foreach ($result['skipped'] as $skip) {
            $this->assertSame('not_allowed', $skip['reason']);
        }
    }

    // ── ۵) activate ──────────────────────────────────────────────────────────

    public function test_activate_moves_the_pointer_to_the_new_release(): void
    {
        $oldZip = $this->packageZip();
        $old = $this->install($oldZip);
        $this->assertTrue($old['ok'], $old['message']);

        $newZip = $this->packageZip(version: '2.0.0');
        $new = $this->install($newZip, version: '2.0.0');
        $this->assertTrue($new['ok'], $new['message']);
        $this->assertNotSame($old['path'], $new['path']);

        // قبل از activate هیچ نسخه‌ای فعال نیست.
        $this->assertNull($this->releases->currentDir(self::SLUG));

        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($oldZip));
        $this->assertSame(realpath($old['path']), $this->releases->currentDir(self::SLUG));
        $this->assertSame($old['path'].'/Laravel/src', $this->releases->autoloadRoot(self::SLUG));

        $this->releases->activate(self::SLUG, '2.0.0', $this->hash8Of($newZip));
        $this->assertSame(realpath($new['path']), $this->releases->currentDir(self::SLUG));
        $this->assertSame($new['path'].'/Laravel/src', $this->releases->autoloadRoot(self::SLUG));

        // نسخهٔ قبلی هنوز روی دیسک است و برای rollback آماده است.
        $this->assertDirectoryExists($old['path']);
        $this->assertSame(realpath($old['path']), $this->releases->previousDir(self::SLUG));

        // فایلِ اشاره‌گر واقعاً روی دیسک است و محتوایش قابل‌خواندن — این همان
        // چیزی است که موج بعدی (`PluginAutoloader`) در `boot()` می‌خواند.
        $pointer = $this->releases->pointerPath(self::SLUG);
        $this->assertFileExists($pointer);
        $this->assertSame('2.0.0-'.$this->hash8Of($newZip), json_decode((string) file_get_contents($pointer), true)['release']);
    }

    public function test_activate_refuses_a_release_that_is_not_usable(): void
    {
        $installed = $this->install($this->packageZip());
        $this->assertTrue($installed['ok'], $installed['message']);

        // نسخه‌ای که manifest ندارد ⇒ قابل استفاده نیست ⇒ نباید فعال شود.
        $orphan = $this->releases->releasePath(self::SLUG, '9.9.9', 'deadbeef');
        mkdir($orphan.'/Laravel/src', 0777, true);
        file_put_contents($orphan.'/Laravel/src/Stray.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->releases->activate(self::SLUG, '9.9.9', 'deadbeef');
    }

    // ── ۶) rollback ──────────────────────────────────────────────────────────

    public function test_rollback_returns_to_the_release_that_was_active_before(): void
    {
        $v1Zip = $this->packageZip();
        $v2Zip = $this->packageZip(version: '2.0.0');

        $v1 = $this->install($v1Zip);
        $v2 = $this->install($v2Zip, version: '2.0.0');
        $this->assertTrue($v1['ok'] && $v2['ok']);

        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($v1Zip));
        $this->releases->activate(self::SLUG, '2.0.0', $this->hash8Of($v2Zip));

        $rolled = $this->releases->rollback(self::SLUG);

        $this->assertSame(realpath($v1['path']), $rolled);
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
        $this->assertSame($v1['path'].'/Laravel/src', $this->releases->autoloadRoot(self::SLUG));

        // rollback معمولاً یک‌طرفه است: تاریخ خالی شد، پس تکرارش null می‌دهد
        // و افزونه را به نسخهٔ شکسته برنمی‌گرداند.
        $this->assertNull($this->releases->rollback(self::SLUG));
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
    }

    public function test_rollback_without_any_history_changes_nothing(): void
    {
        $this->assertNull($this->releases->rollback('never-installed'));
    }

    // ── ۷) استخراج/جابجایی ناموفق، نسخهٔ فعال را خراب نمی‌کند ───────────────

    public function test_a_failed_swap_leaves_the_previous_active_release_intact(): void
    {
        $v1Zip = $this->packageZip();
        $v1 = $this->install($v1Zip);
        $this->assertTrue($v1['ok'], $v1['message']);
        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($v1Zip));

        // یک symlinkِ شکسته (مقصدِ ناموجود) روی مسیرِ نسخه کاشته شده. این
        // یعنی «فضایِ نصب» از بیرون دستکاری شده و ما اصلاً نمی‌دانیم آن
        // symlink به کجا اشاره می‌کند یا فردا به کجا خواهد اشاره کرد.
        //
        // `rename()` پوشه را روی آن شکست می‌دهد (ENOTDIR) و `file_exists()` روی
        // پیوندِ شکسته false است، پس بدون قاعدهٔ `is_link` مسیر «آزاد» به نظر
        // می‌رسید و جابجایی شکست می‌خورد. قاعدهٔ ما زودتر وfail-closedتر می‌ایستد:
        // پیش از هر نوشتنی. نتیجهٔ قابل‌مشاهده برای کاربر یکی است.
        $v2Zip = $this->packageZip(version: '2.0.0');
        $target = $this->releases->releasePath(self::SLUG, '2.0.0', $this->hash8Of($v2Zip));

        $planted = @symlink($this->outside.'/nowhere', $target);
        $this->assertTrue($planted, 'symlink باید روی این پلتفرم ساخته می‌شد');

        $v2 = $this->install($v2Zip, version: '2.0.0');

        $this->assertFalse($v2['ok']);
        $this->assertSame('install.target_occupied', $v2['code']);
        $this->assertNull($v2['path']);

        // نسخهٔ فعال قبلی سالم مانده و اشاره‌گر هنوز به آن است.
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
        $this->assertFileExists($v1['path'].'/manifest.json');
        $this->assertFileExists($v1['path'].'/Laravel/src/Models/Post.php');
        $this->assertSame(
            '<?php // سالم',
            file_get_contents($v1['path'].'/Laravel/src/Models/Post.php')
        );

        // نه staging نمانده، نه نسخهٔ نیمه‌کاره، نه چیزی بیرون از ریشه، و
        // symlinkِ کاشته‌شده هم دست‌نخورده (ما چیزی را که نساختیم پاک نمی‌کنیم).
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
        $this->assertCount(1, $this->releases->releases(self::SLUG));
        $this->assertTrue(is_link($target));
        $this->assertFileDoesNotExist($this->outside.'/nowhere');
    }

    public function test_a_directory_that_is_not_a_release_is_never_overwritten(): void
    {
        $v1Zip = $this->packageZip();
        $v1 = $this->install($v1Zip);
        $this->assertTrue($v1['ok'], $v1['message']);
        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($v1Zip));

        // پوشه‌ای بی‌manifest روی مسیرِ نسخهٔ جدید. تنها راهی که یک نسخه
        // می‌تواند اینجا باشد `rename()` موفقِ خودِ ماست، و آن نسخه کامل است؛
        // پس این یا دستکاریِ بیرونی است یا خرابیِ دیسک، و در هر دو حالت
        // حذفِ خودکارِ بایت‌هایی که نساخته‌ایم درست نیست.
        $v2Zip = $this->packageZip(version: '2.0.0');
        $target = $this->releases->releasePath(self::SLUG, '2.0.0', $this->hash8Of($v2Zip));
        mkdir($target.'/Laravel/src', 0777, true);
        file_put_contents($target.'/Laravel/src/Precious.php', '<?php // کار دستِ اپراتور');

        $result = $this->install($v2Zip, version: '2.0.0');

        $this->assertFalse($result['ok']);
        $this->assertSame('install.target_occupied', $result['code']);
        $this->assertFileExists($target.'/Laravel/src/Precious.php');
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
    }

    public function test_it_refuses_to_reinstall_over_the_corrupt_active_release(): void
    {
        $v1Zip = $this->packageZip();
        $v1 = $this->install($v1Zip);
        $this->assertTrue($v1['ok'], $v1['message']);
        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($v1Zip));

        // کسی manifestِ نسخهٔ فعال را پاک کرده. بازنویسیِ همان مسیر یعنی
        // نوشتن روی چیزی که الان کدِ در حالِ اجرا از آن می‌خواند.
        unlink($v1['path'].'/manifest.json');

        $result = $this->install($v1Zip);

        $this->assertFalse($result['ok']);
        $this->assertSame('install.active_release_corrupt', $result['code']);
    }

    public function test_an_incomplete_extraction_never_creates_a_release_directory(): void
    {
        $v1Zip = $this->packageZip();
        $v1 = $this->install($v1Zip);
        $this->assertTrue($v1['ok'], $v1['message']);
        $this->releases->activate(self::SLUG, '1.0.0', $this->hash8Of($v1Zip));

        // بسته‌ای که `manifest.json` ندارد ⇒ بازبینیِ سلامت شکست می‌خورد، حتی
        // اگر همهٔ فایل‌های دیگرش بی‌نقص نوشته شده باشند. این بدترین شکلِ
        // «استخراجِ ناقص» است: فایل‌ها سرِ جایشان هستند ولی بسته قابل‌شناسایی
        // نیست و نباید هرگز به مسیرِ زنده برسد.
        $incompleteZip = $this->zip([
            'Laravel/src/Models/Post.php' => '<?php // نیمه‌کاره',
        ]);
        $newTarget = $this->releases->releasePath(self::SLUG, '2.0.0', $this->hash8Of($incompleteZip));

        $result = $this->install($incompleteZip, version: '2.0.0');

        $this->assertFalse($result['ok']);
        $this->assertSame('install.manifest_missing', $result['code']);

        $this->assertDirectoryDoesNotExist($newTarget);
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
        $this->assertCount(1, $this->releases->releases(self::SLUG));

        // نسخهٔ فعال قبلی هنوز همان است و دست‌نخورده.
        $this->assertSame(realpath($v1['path']), $this->releases->currentDir(self::SLUG));
        $this->assertSame('<?php // سالم', file_get_contents($v1['path'].'/Laravel/src/Models/Post.php'));
    }

    public function test_a_manifest_that_disagrees_with_the_requested_identity_aborts(): void
    {
        $zip = $this->zip([
            'manifest.json' => $this->manifestJson(slug: 'evil', version: '1.0.0'),
            'Laravel/src/Ok.php' => '<?php',
        ]);

        $result = $this->install($zip);

        $this->assertFalse($result['ok']);
        $this->assertSame('install.manifest_mismatch', $result['code']);
        $this->assertSame([], $this->releases->releases(self::SLUG));
    }

    // ── ۸) purge ─────────────────────────────────────────────────────────────

    public function test_purge_removes_every_release_and_the_pointer(): void
    {
        $v2Zip = $this->packageZip(version: '2.0.0');

        $v1 = $this->install($this->packageZip());
        $v2 = $this->install($v2Zip, version: '2.0.0');
        $this->assertTrue($v1['ok'] && $v2['ok']);

        $this->releases->activate(self::SLUG, '2.0.0', $this->hash8Of($v2Zip));
        $this->assertNotNull($this->releases->currentDir(self::SLUG));

        $this->releases->purge(self::SLUG);

        $this->assertNull($this->releases->currentDir(self::SLUG));
        $this->assertNull($this->releases->previousDir(self::SLUG));
        $this->assertNull($this->releases->autoloadRoot(self::SLUG));
        $this->assertSame([], $this->releases->releases(self::SLUG));
        $this->assertDirectoryDoesNotExist($v1['path']);
        $this->assertDirectoryDoesNotExist($v2['path']);
        $this->assertDirectoryDoesNotExist($this->releases->releaseDir(self::SLUG));
    }

    public function test_purge_on_a_plugin_that_was_never_installed_is_a_no_op(): void
    {
        $this->releases->purge('never-installed');

        $this->assertSame([], $this->releases->releases('never-installed'));
    }

    // ── ۹) دو نصب همزمان ────────────────────────────────────────────────────

    public function test_a_concurrent_install_cannot_enter_and_leaves_no_partial_files(): void
    {
        $zip = $this->packageZip();
        $v1 = $this->install($zip);
        $this->assertTrue($v1['ok'], $v1['message']);

        // گیرندهٔ قفل = همان کاری که یک درخواستِ همزمان انجام می‌دهد: قفل را
        // می‌گیرد و تا وقتی خودش رها نکند کسی داخل نمی‌شود.
        $held = $this->releases->acquireLock(self::SLUG, 0.0);
        $this->assertIsResource($held, 'قفل باید گرفته شود وگرنه این تست چیزی را ثابت نمی‌کند');

        try {
            $blocked = $this->install($this->packageZip(version: '3.0.0'), version: '3.0.0');

            $this->assertFalse($blocked['ok']);
            $this->assertSame('install.locked', $blocked['code']);
            $this->assertNull($blocked['path']);
            $this->assertSame([], $blocked['extracted']);
            $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
        } finally {
            $this->releases->releaseLock($held);
        }

        // قفل هر slug جداست: قفلِ `blog` نباید `shop` را متوقف کند.
        $other = $this->install($this->packageZip(slug: 'shop'), slug: 'shop');
        $this->assertTrue($other['ok'], $other['message']);

        // پس از رها شدن قفل، برندهٔ واقعی نسخهٔ کامل است و باز نصب هم no-op است.
        $again = $this->install($zip);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['already_installed']);
        $this->assertSame($v1['path'], $again['path']);

        // «بدون فایل ناقص» یعنی روی دیسک دقیقاً همان هفت فایل باشد، نه بیشتر.
        $this->assertCount(7, $this->walk($v1['path']));
        $this->assertSame([], $this->releases->stagingEntries(self::SLUG));
    }

    // ── ۱۰) idempotent ───────────────────────────────────────────────────────

    public function test_reinstalling_the_same_version_is_a_no_op(): void
    {
        $zip = $this->packageZip();

        $first = $this->install($zip);
        $this->assertTrue($first['ok'], $first['message']);
        $this->assertFalse($first['already_installed']);

        $marker = $first['path'].'/Laravel/src/Marker.php';
        file_put_contents($marker, '<?php // نشانه');

        $second = $this->install($zip);

        $this->assertTrue($second['ok']);
        $this->assertTrue($second['already_installed']);
        $this->assertSame($first['path'], $second['path']);
        $this->assertSame([], $second['extracted']);
        // نصب دوباره نباید چیزی را بازنویسی یا پاک کند.
        $this->assertFileExists($marker);
        $this->assertCount(8, $this->walk($first['path']));
    }

    public function test_a_hash_that_does_not_match_the_bytes_on_disk_is_refused(): void
    {
        $zipA = $this->packageZip();
        $this->assertTrue($this->install($zipA)['ok']);

        // سناریوی واقعی: بین تحلیلِ بسته و نصبِ آن، فایل عوض شده (آپلودِ دوباره،
        // جایگزینیِ فایل، مسیرِ اشتباه). هشِ بستهٔ اول با بایت‌های روی دیسکِ
        // بستهٔ دوم نمی‌خواند ⇒ نباید چیزی زیر برچسبِ بستهٔ اول بنشیند.
        $zipB = $this->packageZip(['Laravel/src/Older.php' => '<?php // متفاوت']);
        $this->assertNotSame($this->hash8Of($zipA), $this->hash8Of($zipB));

        $result = $this->install($zipB, hash: $this->hash8Of($zipA));

        $this->assertFalse($result['ok']);
        $this->assertSame('install.hash_mismatch', $result['code']);
        $this->assertNull($result['path']);

        // حتی هشی که اصلاً شبیه فایل نیست هم باید رد شود — اینجا «هشِ دروغین»
        // کاملاً مصنوعی است و به mtime بستگی ندارد، پس قطعاً ثابت می‌کند که
        // بررسی اجرا می‌شود.
        $bogus = $this->install($this->packageZip(), hash: '00000000');

        $this->assertFalse($bogus['ok']);
        $this->assertSame('install.hash_mismatch', $bogus['code']);
    }

    // ── ۱۱) ورودی‌هایی که می‌توانستند از پوشه فرار کنند ───────────────────────

    public function test_a_version_that_could_escape_the_release_directory_is_rejected(): void
    {
        foreach (['../../evil', '..', '1.0.0/../../evil', "1.0.0\0evil", '', 'v/../..'] as $bad) {
            try {
                $this->releases->releasePath(self::SLUG, $bad, 'abcd1234');
                $this->fail("نسخهٔ «{$bad}» نباید مسیر بسازد.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDirectoryDoesNotExist($this->outside.'/evil');
    }

    public function test_a_slug_that_could_escape_the_base_directory_is_rejected(): void
    {
        foreach (['../evil', 'blog/../../evil', 'BLOG!', '', '..'] as $bad) {
            try {
                $this->releases->releasePath($bad, '1.0.0', 'abcd1234');
                $this->fail("slug «{$bad}» نباید مسیر بسازد.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_case_insensitive_collision_between_two_entries_aborts(): void
    {
        // روی لینوکس این دو هر دو نوشته می‌شوند؛ روی هاست ویندوزی دومی بی‌صدا
        // اولی را overwrite می‌کند. هستهٔ CMS روی هاست ویندوزی هم اجرا می‌شود.
        $result = $this->install($this->packageZip([
            'Laravel/src/Models/post.php' => '<?php // کوچک',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertSame('install.case_collision', $result['code']);
        $this->assertSame([], $this->releases->releases(self::SLUG));
    }

    // ── کمک‌کارها ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $extra  فایل‌های اضافه روی بستهٔ سالم
     * @return array{ok: bool, code: string, message: string, path: ?string, release: ?string, autoload_root: ?string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, already_installed: bool}
     */
    private function install(string $zipPath, ?string $hash = null, string $slug = self::SLUG, string $version = self::VERSION): array
    {
        return $this->installer->install($zipPath, $slug, $version, $hash);
    }

    /**
     * بستهٔ سالم + فایل‌های اضافه.
     *
     * @param  array<string, string>  $extra
     */
    private function packageZip(array $extra = [], string $version = '1.0.0', string $slug = self::SLUG): string
    {
        return $this->zip(array_merge([
            'manifest.json' => $this->manifestJson(slug: $slug, version: $version),
            'Laravel/src/Models/Post.php' => '<?php // سالم',
            'Laravel/routes/api.php' => '<?php',
            'Laravel/database/migrations/2026_01_01_000001_create_blog_posts_table.php' => '<?php',
            'Laravel/config/plugin.php' => '<?php',
            'Next.js/panel/Widget.tsx' => 'export const Widget = () => null;',
            'Next.js/blocks/hero.json' => '{}',
        ], $extra));
    }

    private function manifestJson(string $slug = self::SLUG, string $version = '1.0.0'): string
    {
        return (string) json_encode([
            'name' => 'پلاگین بلاگ',
            'slug' => $slug,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, string>  $files
     * @param  string|null  $symlink  نام ورودی‌ای که باید symlink نوشته شود
     */
    private function zip(array $files, ?string $symlink = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plugin-install').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        if ($symlink !== null) {
            $zip->addFromString($symlink, '/etc/passwd');
            // external_attributes: UNIX mode در ۱۶ بیت بالا. S_IFLNK = 0xA000.
            $zip->setExternalAttributesName(
                $symlink,
                ZipArchive::OPSYS_UNIX,
                (0120777 << 16) | 0xA000
            );
        }

        $zip->close();

        return $path;
    }

    private function hash8Of(string $zipPath): string
    {
        return substr(hash_file('sha256', $zipPath), 0, 8);
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
            unlink($path);

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

        rmdir($path);
    }
}
