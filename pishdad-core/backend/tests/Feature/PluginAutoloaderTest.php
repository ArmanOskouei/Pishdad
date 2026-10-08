<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginPackageContract;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * K5.3 — بارگذار PSR-4 افزونه‌ها.
 *
 * این تست‌ها عمداً **کد واقعی را include می‌کنند** و بعد بررسی می‌کنند که کدام
 * مسیرها اجازهٔ اجرا دارند. تستی که فقط آرایهٔ ثبت‌شده‌ها را چک کند هیچ چیز را
 * ثابت نمی‌کند: کلاسی که include نشده، هرگز اجرا نشده.
 *
 * fixture ها runtime و زیر `storage/framework/testing/` ساخته می‌شوند، نه
 * فایل commit‌شده در `tests/`:
 *
 *  ۱. `tests/` ریشهٔ PSR-4 خود composer است، پس فایل commit‌شده آن را
 *     composer لود می‌کند نه این کلاس ⇒ تست «بارگذار می‌کند» چیزی را ثابت
 *     نمی‌کرد (کلاس از قبل در حافظه بود).
 *  ۲. هر تست namespace یکتا می‌گیرد، چون PHP کلاس بارگذاری‌شده را نمی‌تواند
 *     از حافظه خارج کند؛ بدون namespace یکتا، تست‌ها به ترتیب اجرا وابسته
 *     می‌شدند.
 *  ۳. هیچ فایل دائمی جدیدی ساخته نمی‌شود و `tearDown` همه را پاک می‌کند.
 */
class PluginAutoloaderTest extends TestCase
{
    private PluginAutoloader $loader;

    private string $sandbox;

    private string $outside;

    private string $ns;

    private string $token;

    /**
     * کلید سراسری که هر فایل «محروم» موقع include پر می‌کند.
     *
     * چرا این‌طور: معیار واقعیِ نشت، **include شدن فایل** است، نه `class_exists`.
     * یک نام کلاس هیچ‌وقت نمی‌تواند `..` داشته باشد، پس تستی که فقط
     * `class_exists(...) === false` می‌گیرد با حذف قاعدهٔ نام هم سبز می‌ماند — در
     * حالی که فایل بیرون از ریشه اجرا شده است. برای همین هر payload مخرب به یک
     * فایل واقعی بیرون از ریشه وصل است که include شدنش خودش را لو می‌دهد.
     */
    private string $breach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = bin2hex(random_bytes(5));

        // ریشهٔ ثبت‌شونده و همسایهٔ بیرون از آن. `outside` عمداً **زیر**
        // `sandbox` نیست، وگرنه «بیرون از ریشه» عملاً بی‌معنا می‌شد.
        $this->sandbox = storage_path('framework/testing/plugin-autoloader/'.$this->token);
        $this->outside = storage_path('framework/testing/plugin-autoloader-outside-'.$this->token);
        $this->ns = 'Pishdad\\Plugins\\Fixture'.$this->token;
        $this->breach = 'plugin_autoloader_breach_'.$this->token;

        mkdir($this->sandbox, 0777, true);
        mkdir($this->outside, 0777, true);

        $this->loader = new PluginAutoloader;
    }

    protected function tearDown(): void
    {
        // هوک `spl_autoload_register` سراسری است؛ تا وقتی ثبتی نماند، خودش را
        // باز می‌کند. این حلقه فقط برای تست‌هایی است که عمداً نیمه‌کاره رها
        // می‌کنند.
        foreach (array_keys($this->loader->registeredSlugs()) as $slug) {
            $this->loader->unregister($slug);
        }

        unset($GLOBALS[$this->breach]);

        $this->removeTree($this->sandbox);
        $this->removeTree($this->outside);

        parent::tearDown();
    }

    // ── ۱) بارگذاری درست ───────────────────────────────────────────────────

    public function test_it_loads_a_class_from_the_registered_psr4_root(): void
    {
        $this->writeClass($this->sandbox, 'Alpha/Thing.php', $this->ns.'\\Alpha', 'Thing', 'alpha-thing');
        $this->writeClass($this->sandbox, 'Alpha/Deep/Nested/Leaf.php', $this->ns.'\\Alpha\\Deep\\Nested', 'Leaf', 'leaf');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertFalse(class_exists($this->ns.'\\Alpha\\Thing', false), 'precondition: کلاس نباید از قبل در حافظه باشد.');

        $class = $this->ns.'\\Alpha\\Thing';
        $this->assertTrue(class_exists($class), 'کلاس داخل ریشهٔ ثبت‌شده باید بارگذاری شود.');
        $this->assertSame('alpha-thing', (new $class)->marker());

        $deep = $this->ns.'\\Alpha\\Deep\\Nested\\Leaf';
        $this->assertTrue(class_exists($deep), 'چند سطح زیرپوشه باید PSR-4 درست باشد.');
        $this->assertSame('leaf', (new $deep)->marker());
    }

    public function test_it_reports_what_it_registered(): void
    {
        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertTrue($this->loader->isRegistered('probe'));
        $this->assertFalse($this->loader->isRegistered('other'));
        $this->assertSame(['probe' => $this->ns.'\\'], $this->loader->registeredSlugs());
    }

    // ── ۲) namespace اشتباه ────────────────────────────────────────────────

    /**
     * فایل **واقعاً وجود دارد** و زیر ریشهٔ ثبت‌شده است؛ فقط نام کلاسش به ریشهٔ
     * namespace این افزونه نمی‌خورد. یک بارگذاری که پیشوندها را نادیده بگیرد
     * اینجا فایل را include می‌کند و تست قرمز می‌شود.
     */
    public function test_it_refuses_a_class_of_another_plugins_namespace(): void
    {
        $this->writeClass($this->sandbox, 'Pishdad/OtherPlugin/Thing.php', 'Pishdad\\OtherPlugin', 'Thing', 'stolen');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertFileExists($this->sandbox.'/Pishdad/OtherPlugin/Thing.php', 'precondition: فایل باید واقعاً زیر ریشه باشد.');
        $this->assertFalse(
            class_exists('Pishdad\\OtherPlugin\\Thing'),
            'کلاسی که به پیشوند namespace این افزونه نمی‌خورد نباید بارگذاری شود.'
        );
    }

    /**
     * قاعدهٔ ۳: هیچ namespace ایی جز ریشهٔ افزونه ثبت نمی‌شود. حتی اگر فایلش
     * زیر ریشه باشد.
     */
    public function test_it_refuses_core_namespaces_even_when_the_file_is_inside_the_root(): void
    {
        $this->writeClass($this->sandbox, 'App/Services/Plugins/Impostor.php', 'App\\Services\\Plugins', 'Impostor', 'impostor');
        $this->writeClass($this->sandbox, 'Illuminate/Support/Fake.php', 'Illuminate\\Support', 'Fake', 'fake');
        $this->writeClass($this->sandbox, 'Database/Models/Evil.php', 'Database\\Models', 'Evil', 'evil');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertFalse(class_exists('App\\Services\\Plugins\\Impostor'), 'App\\ هرگز از ریشهٔ افزونه بارگذاری نمی‌شود.');
        $this->assertFalse(class_exists('Illuminate\\Support\\Fake'), 'Illuminate\\ هرگز از ریشهٔ افزونه بارگذاری نمی‌شود.');
        $this->assertFalse(class_exists('Database\\Models\\Evil'), 'Database\\ هرگز از ریشهٔ افزونه بارگذاری نمی‌شود.');
    }

    public function test_registering_a_prefix_outside_the_plugin_root_throws(): void
    {
        foreach (['App\\', 'Illuminate\\Support\\', 'Database\\Models\\', 'Pishdad\\Core\\', 'Pishdad\\', ''] as $prefix) {
            try {
                $this->loader->register('probe', $this->sandbox, $prefix);
                $this->fail("ثبت پیشوند «{$prefix}» باید رد می‌شد.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('پیشوند namespace', $e->getMessage());

                if ($prefix !== '') {
                    $this->assertStringContainsString($prefix, $e->getMessage());
                }
            }
        }

        $this->assertSame([], $this->loader->registeredSlugs(), 'هیچ ثبت ناموفقی نباید در فهرست بماند.');
    }

    /**
     * قاعدهٔ ۳ در سطح include، نه فقط در سطح `class_exists`.
     *
     * تست بالا می‌گوید «کلاس `App\…` بارگذاری نمی‌شود» که درست ولی ناکافی است: یک
     * بارگذار بدون قاعدهٔ prefix، `substr($class, strlen($prefix))` را به مسیر تبدیل
     * می‌کند و نتیجه **تکهٔ بریده** است، نه رشتهٔ تهی. بسته می‌تواند فایلی به
     * دقیقاً همان نام بفرستد (`ontract.php` در این تست) و کدش موقع
     * `class_exists('App\…')` در مسیر هسته اجرا شود — بدون آنکه کلاسی تعریف کند.
     *
     * precondition ها عمداً assert می‌شوند: اگر روزی طول prefix عوض شود و تکهٔ
     * بریده نامعتبر شود، تست باید با پیام روشن قرمز شود نه اینکه بی‌صدا بی‌اثر شود.
     */
    public function test_it_never_includes_a_core_namespace_class_even_when_the_mangled_file_ships_with_the_plugin(): void
    {
        $prefix = $this->ns.'\\';
        $this->loader->register('probe', $this->sandbox, $prefix);

        // نامی در ریشهٔ واقعی هسته، ولی **ناموجود** — و همین لازم است: کلاسی که از
        // قبل در حافظه باشد اصلاً autoloader را صدا نمی‌زند و تست بی‌اثر می‌شود.
        $target = PluginPackageContract::class.'Ghost';
        $mangled = substr($target, strlen($prefix));

        $this->assertNotSame('', $mangled, 'precondition: نام هدف باید از prefix بلندتر باشد، وگرنه تکهٔ بریده تهی می‌شود.');
        $this->assertSame(
            1,
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $mangled),
            "precondition: تکهٔ بریده «{$mangled}» باید یک مسیر نسبی معتبر باشد تا فقط قاعدهٔ prefix جلویش را بگیرد."
        );

        $this->writeBreachFile($this->sandbox, $mangled.'.php');

        $this->assertFalse(class_exists($target, false), 'precondition: کلاس نباید از قبل در حافظه باشد.');

        $this->assertFalse(class_exists($target));
        $this->assertArrayNotHasKey(
            $this->breach,
            $GLOBALS,
            'نام کلاسی خارج از prefix نباید به مسیر فایل داخل بستهٔ افزونه تبدیل شود.'
        );
    }

    // ── ۳) نام کلاس بدشکل / traversal ──────────────────────────────────────

    /**
     * قاعدهٔ ۲: نام کلاس نباید به مسیر تبدیل شود.
     *
     * **یافتهٔ مهمی که مسیر تست را عوض کرد.** اندازه‌گیری شد که موتور PHP نامی را
     * که شناسهٔ معتبر نباشد اصلاً به autoloader نمی‌دهد: `..`، `/`، `.`، `-`، `$` و
     * NUL همگی **قبل** از رسیدن به کد ما `false` می‌شوند. یعنی traversal با `..` در
     * نام کلاس، برخلاف ظاهر خطرناکش، مسیر زنده‌ای ندارد و تستی که فقط
     * `class_exists === false` بگیرد در آن مورد چیزی ثابت نمی‌کند.
     *
     * چیزی که موتور **می‌فرستد** و برای همین قاعدهٔ ما زنده است: segment تهی
     * (`Pishdad\Plugins\X\\Breach`). این نام از `str_starts_with` قرارداد
     * عبور می‌کند، `class_exists` آن را به autoloader می‌دهد، و `substr` تکهٔ
     * `\Breach` را می‌دهد که با حذف قاعدهٔ نام به `{root}//Breach.php` تبدیل
     * می‌شود — یعنی کدِ بستهٔ افزونه در مسیر هسته اجرا می‌شود. همین payload
     * بار‌بر است و breach آن بررسی می‌شود؛ بقیه به‌عنوان قرارداد نام حفظ شده‌اند.
     */
    public function test_it_refuses_malformed_class_names_and_never_traverses_out_of_the_root(): void
    {
        $this->writeClass($this->sandbox, 'Alpha/Thing.php', $this->ns.'\\Alpha', 'Thing', 'alpha-thing');
        $this->writeBreachFile($this->sandbox, 'Breach.php');
        $this->writeBreachFile($this->outside, 'Breach.php');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        // شاهد: همین فایل با include دستی پرچم را پر می‌کند. بدون این، ممکن است
        // تست فقط به این دلیل سبز بماند که پرچم هرگز پر نمی‌شود.
        include $this->sandbox.'/Breach.php';
        $this->assertArrayHasKey($this->breach, $GLOBALS, 'precondition: شاهد باید include دستی را لو بدهد.');
        unset($GLOBALS[$this->breach]);

        $sibling = 'plugin-autoloader-outside-'.$this->token;
        $emptySegment = $this->ns.'\\\\Breach';

        $this->assertTrue(
            $this->engineDelivers($emptySegment),
            'precondition: موتور باید نام با segment تهی را به autoloader برساند — این تنها payload زندهٔ این تست است.'
        );

        // بخش زنده: با حذف قاعدهٔ نام، `{root}//Breach.php` پیدا و include می‌شود.
        $this->assertFalse(class_exists($emptySegment), 'segment تهی باید رد شود.');
        $this->assertArrayNotHasKey($this->breach, $GLOBALS, 'segment تهی نباید به فایل بسته تبدیل شود.');

        // بخش قراردادی: این‌ها هرگز به autoloader نمی‌رسند، پس فقط رفتار
        // نهایی را تثبیت می‌کنند (و روزی که موتور سخت‌گیری را بردارد، همین‌ها
        // حلقهٔ آخر را می‌گیرند).
        foreach ([
            $this->ns.'\\..\\..\\'.$sibling.'\\Breach' => 'جدا شدن با .. به همسایه',
            $this->ns.'/../../'.$sibling.'/Breach' => 'جداکنندهٔ / به‌جای \\',
            $this->ns.'\\..\\..\\..\\..\\..\\..\\etc\\Breach' => 'بالا رفتن تا ریشهٔ فایل‌سیستم',
            $this->ns.'\\Alpha\\..\\Alpha\\Thing' => 'بازگشت به همان فایل داخل ریشه با ..',
            $this->ns.'\\Alpha/Thing' => 'مخلوط کردن / و \\',
            $this->ns.'\\.' => 'پوشهٔ جاری',
            $this->ns.'\\A-B' => 'خط تیره در نام',
        ] as $class => $why) {
            $this->assertFalse(class_exists($class), "باید رد می‌شد — {$why}: «{$class}»");
        }

        // نام سالم بعد از همهٔ این‌ها باید هنوز کار کند: رد شدن، مسیر را خراب
        // نکرده باشد.
        $this->assertTrue(class_exists($this->ns.'\\Alpha\\Thing'), 'نام سالم باید بعد از رد شدن نام‌های بد هم بارگذاری شود.');
    }

    // ── ۴) خروج فایل از ریشه (symlink) ─────────────────────────────────────

    /**
     * قاعدهٔ ۴: فایل باید **واقعاً** زیر ریشه باشد.
     *
     * تنها راه دور زدن بررسی متنِ مسیر، symlink است: نام فایل کاملاً بی‌گناه است
     * ولی `realpath` بیرون از ریشه می‌افتد. برای همین تست symlink واقعی می‌سازد
     * و معیارش include شدن فایلِ لنگر است، نه `class_exists` — چون فایل لنگر
     * اصلاً کلاسی به نام درخواستی تعریف نمی‌کند و تست مبتنی بر کلاس، بی‌صدا از
     * کنارِ یک include موفق رد می‌شد.
     */
    public function test_it_refuses_a_symlink_that_points_outside_the_root(): void
    {
        $target = $this->writeBreachFile($this->outside, 'Alpha/Escape.php');
        $link = $this->sandbox.'/Alpha/Escape.php';

        mkdir(dirname($link), 0777, true);
        $this->assertTrue(symlink($target, $link), 'symlink باید ساخته می‌شد.');
        $this->assertFileExists($link, 'precondition: فایل باید از مسیر داخل ریشه قابل دسترس باشد.');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertFalse(class_exists($this->ns.'\\Alpha\\Escape'));
        $this->assertArrayNotHasKey(
            $this->breach,
            $GLOBALS,
            'symlink به بیرون از ریشه نباید include شود — بررسی فقط روی متن مسیر کافی نبود.'
        );
    }

    // ── ۵) unregister ──────────────────────────────────────────────────────

    /**
     * غیرفعال‌کردن افزونه باید کدش را از دسترس خارج کند، نه فقط یک flag را عوض
     * کند.
     *
     * نکته: کلاسی که یک بار include شده دیگر از حافظه نمی‌رود (PHP این قابلیت
     * را ندارد) و بررسی مجددش همیشه true می‌دهد. پس معیار درست، کلاسی است که
     * **هنوز** بارگذاری نشده: باید دیگر پیدا نشود.
     */
    public function test_unregister_stops_resolving_classes_that_are_not_loaded_yet(): void
    {
        $this->writeClass($this->sandbox, 'Alpha/First.php', $this->ns.'\\Alpha', 'First', 'first');
        $this->writeClass($this->sandbox, 'Alpha/Second.php', $this->ns.'\\Alpha', 'Second', 'second');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertTrue(class_exists($this->ns.'\\Alpha\\First'), 'precondition: کلاس اول باید بارگذاری می‌شد.');

        $this->loader->unregister('probe');

        $this->assertFalse($this->loader->isRegistered('probe'));
        $this->assertSame([], $this->loader->registeredSlugs());
        $this->assertFalse(
            class_exists($this->ns.'\\Alpha\\Second'),
            'بعد از unregister، کلاس بارگذاری‌نشدهٔ همان افزونه نباید پیدا شود.'
        );
    }

    public function test_unregistering_an_unknown_slug_is_a_no_op(): void
    {
        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');
        $this->loader->unregister('never-registered');

        $this->assertSame(['probe'], array_keys($this->loader->registeredSlugs()));
    }

    /**
     * بعد از آخرین `unregister` نباید هیچ callback خودکار باقی بماند: هزاران
     * کلاس هسته در هر درخواست بارگذاری می‌شوند و callback بی‌استفاده یعنی سربار
     * دائمی روی مسیر گرم اپ.
     */
    public function test_the_autoload_hook_is_removed_with_the_last_registration(): void
    {
        $baseline = $this->autoloadFunctionCount();

        $this->loader->register('a', $this->sandbox, $this->ns.'\\A\\');
        $this->assertSame($baseline + 1, $this->autoloadFunctionCount(), 'ثبت اولین افزونه باید یک hook بسازد.');

        $this->loader->register('b', $this->sandbox, $this->ns.'\\B\\');
        $this->assertSame($baseline + 1, $this->autoloadFunctionCount(), 'افزونهٔ دوم نباید hook دوم بسازد.');

        $this->loader->unregister('a');
        $this->assertSame($baseline + 1, $this->autoloadFunctionCount(), 'تا وقتی یکی مانده، hook باید بماند.');

        $this->loader->unregister('b');
        $this->assertSame($baseline, $this->autoloadFunctionCount(), 'با آخرین unregister باید hook باز شود.');
    }

    // ── ۶) ثبت دوباره ─────────────────────────────────────────────────────

    public function test_reregistering_a_slug_replaces_the_previous_root(): void
    {
        $this->writeClass($this->sandbox, 'Alpha/Thing.php', $this->ns.'\\Alpha', 'Thing', 'from-sandbox');
        $this->writeClass($this->outside, 'Alpha/Thing.php', $this->ns.'\\Alpha', 'Thing', 'from-outside');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');
        $this->loader->register('probe', $this->outside, $this->ns.'\\');

        $this->assertCount(1, $this->loader->registeredSlugs(), 'یک slug نباید دو ثبت داشته باشد.');

        $class = $this->ns.'\\Alpha\\Thing';
        $this->assertTrue(class_exists($class), 'کلاس باید از ریشهٔ جدید بارگذاری شود.');
        $this->assertSame('from-outside', (new $class)->marker(), 'باید ریشهٔ جدید باشد، نه قبلی.');
    }

    /**
     * ثبت دوباره باید **هم** ریشهٔ فایل و **هم** prefix را جابه‌جا کند. ریشهٔ قبلی
     * نباید باقی بماند: همان namespace با دو مسیر فایل مختلف یعنی اینکه «کدام
     * نسخه اجرا شد» به ترتیب درج بستگی کند.
     *
     * جای فایل‌ها دقیقاً معنای prefix را نشان می‌دهد: prefix یعنی «ریشهٔ فایل از
     * این نقطه شروع می‌شود». پس `ns\Gamma\` ⇒ `{root}/Only.php` ولی
     * `ns\` ⇒ `{root}/Gamma/Only.php`.
     */
    public function test_reregistering_a_slug_with_another_namespace_moves_both_root_and_prefix(): void
    {
        $this->writeClass($this->sandbox, 'Beta/Only.php', $this->ns.'\\Beta', 'Only', 'beta');
        $this->writeClass($this->outside, 'Only.php', $this->ns.'\\Gamma', 'Only', 'gamma');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');
        $this->loader->register('probe', $this->outside, $this->ns.'\\Gamma\\');

        $this->assertSame(['probe' => $this->ns.'\\Gamma\\'], $this->loader->registeredSlugs());
        $this->assertTrue(class_exists($this->ns.'\\Gamma\\Only'), 'prefix جدید باید کار کند.');
        $this->assertFalse(
            class_exists($this->ns.'\\Beta\\Only'),
            'prefix قبلی باید کاملاً کنار رفته باشد.'
        );
    }

    // ── ۷) خطاهای پیکربندی ────────────────────────────────────────────────

    public function test_a_missing_root_throws_a_readable_error(): void
    {
        $missing = $this->sandbox.'/no-such-dir';

        try {
            $this->loader->register('probe', $missing, $this->ns.'\\');
            $this->fail('ریشهٔ ناموجود باید exception می‌داد.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($missing, $e->getMessage(), 'پیام باید مسیر را نشان دهد تا قابل جست‌وجو باشد.');
            $this->assertStringContainsString('ریشهٔ افزونه', $e->getMessage());
        }

        $this->assertSame([], $this->loader->registeredSlugs());
    }

    public function test_a_root_that_is_a_file_throws(): void
    {
        $file = $this->writeClass($this->sandbox, 'NotADir.php', $this->ns, 'NotADir', 'x');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ریشهٔ افزونه');

        $this->loader->register('probe', $file, $this->ns.'\\');
    }

    /**
     * خودِ ریشهٔ `Pishdad\Plugins\` هم یک prefix معتبر است: بسته‌ای که PSR-4 آن
     * کل ریشه را می‌پوشاند (مثل `{root}/Gamma/Only.php` برای `ns\Gamma\Only`).
     *
     * این تصمیم عمداً pin شده: اگر روزی کسی ریشهٔ خام را رد کند، بسته‌ای که کل
     * namespace را می‌پوشاند بی‌صدا از کار می‌افتد — همان کلاس از دست رفتنِ
     * بی‌صدا که این کلاس برای بستنش ساخته شده.
     */
    public function test_the_bare_plugin_root_is_a_valid_prefix(): void
    {
        // prefix خام یعنی هر segment بعد از `Pishdad\Plugins\` جزء مسیر است.
        $tail = substr($this->ns, strlen('Pishdad\\Plugins\\'));

        $this->writeClass($this->sandbox, $tail.'/Gamma/Only.php', $this->ns.'\\Gamma', 'Only', 'gamma');

        $this->loader->register('probe', $this->sandbox, 'Pishdad\\Plugins\\');

        $this->assertSame(['probe' => 'Pishdad\\Plugins\\'], $this->loader->registeredSlugs());
        $this->assertTrue(class_exists($this->ns.'\\Gamma\\Only'));
    }

    public function test_an_empty_slug_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->loader->register('   ', $this->sandbox, $this->ns.'\\');
    }

    // ── رفتارهای جانبی ─────────────────────────────────────────────────────

    /** قاعدهٔ ۵: کلاس ناموجود یعنی سکوت، نه exception. */
    public function test_a_missing_class_is_silent_and_the_next_one_still_loads(): void
    {
        $this->writeClass($this->sandbox, 'Alpha/Thing.php', $this->ns.'\\Alpha', 'Thing', 'alpha-thing');

        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertFalse(class_exists($this->ns.'\\Ghost\\Missing'));
        $this->assertFalse(class_exists($this->ns.'\\Alpha\\Missing'));

        $this->assertTrue(class_exists($this->ns.'\\Alpha\\Thing'), 'نبودِ یک کلاس نباید loader را از کار بیندازد.');
    }

    public function test_register_packages_maps_slug_to_a_studly_namespace_and_skips_broken_ones(): void
    {
        $this->writeClass($this->sandbox, 'my-plugin/src/Svc.php', 'Pishdad\\Plugins\\MyPlugin', 'Svc', 'svc');

        $registered = $this->loader->registerPackages($this->sandbox, [
            'my-plugin' => 'my-plugin/src',
            'ghost' => 'ghost/src',
        ]);

        $this->assertSame(1, $registered, 'بسته‌ای که دیسکش نیست باید نادیده گرفته شود، نه اینکه کل ثبت را بیندازد.');
        $this->assertSame(['my-plugin' => 'Pishdad\\Plugins\\MyPlugin\\'], $this->loader->registeredSlugs());
        $this->assertTrue(class_exists('Pishdad\\Plugins\\MyPlugin\\Svc'));
    }

    public function test_register_packages_with_a_missing_root_is_a_no_op(): void
    {
        $registered = $this->loader->registerPackages($this->sandbox.'/nope', ['a' => 'a']);

        $this->assertSame(0, $registered, 'نبودِ ریشهٔ نصب یعنی «افزونه‌ای نصب نیست»، نه خطا.');
        $this->assertSame([], $this->loader->registeredSlugs());
    }

    public function test_it_never_shadows_the_composer_loader(): void
    {
        $this->loader->register('probe', $this->sandbox, $this->ns.'\\');

        $this->assertTrue(class_exists(PluginAutoloader::class), 'کلاس‌های composer باید مثل قبل بارگذاری شوند.');
        $this->assertTrue(class_exists(PluginPackageContract::class));
    }

    // ── کمکی ──────────────────────────────────────────────────────────────

    private function writeClass(string $root, string $relative, string $namespace, string $class, string $marker): string
    {
        $path = $root.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, "<?php\n\nnamespace {$namespace};\n\nclass {$class}\n{\n    public function marker(): string\n    {\n        return '{$marker}';\n    }\n}\n");

        return $path;
    }

    /**
     * فایلی که include شدنش خودش را لو می‌دهد: هیچ کلاسی تعریف نمی‌کند (پس
     * `class_exists` معیار درستی نیست) ولی در بدنه‌اش یک پرچم سراسری می‌گذارد.
     * تنها راه تشخیص «این فایل اجرا شد».
     *
     * عمداً **بدون کلاس** است: یک کلاس باعث می‌شد include دوم (که در تست به‌عنوان
     * شاهد انجام می‌دهیم) کل پروسه را با «Cannot redeclare class» بکشد و به‌جای یک
     * assertion شکسته، کل suite می‌افتاد.
     */
    private function writeBreachFile(string $root, string $relative): string
    {
        $path = $root.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, "<?php\n\n\$GLOBALS['{$this->breach}'] = true;\n");

        return $path;
    }

    /**
     * آیا موتور PHP اصلاً این نام را به یک autoloader می‌رساند؟
     *
     * این یک ابزار سنجش است، نه ادعا: بدون آن، یک payload که موتور اصلاً تحویل
     * نمی‌دهد (مثل `..`) تست را سبز نگه می‌دارد بی‌آنکه چیزی را ثابت کند.
     */
    private function engineDelivers(string $class): bool
    {
        $delivered = false;
        $probe = function (string $seen) use (&$delivered, $class): void {
            if ($seen === $class) {
                $delivered = true;
            }
        };

        spl_autoload_register($probe);

        try {
            class_exists($class);
        } finally {
            spl_autoload_unregister($probe);
        }

        return $delivered;
    }

    private function autoloadFunctionCount(): int
    {
        $functions = spl_autoload_functions();

        return $functions === false ? 0 : count($functions);
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
