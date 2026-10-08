<?php

namespace Tests\Feature;

use App\Http\Controllers\Install\EnsureTwoFactorSetup;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\InstallGuard;
use App\Http\Controllers\Install\InstallJournal;
use App\Http\Controllers\Install\Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/install-'.uniqid());
        $this->envFile = $this->dir.DIRECTORY_SEPARATOR.'.env-test';
        mkdir($this->dir, 0755, true);

        config([
            'installer.path' => $this->dir,
            'installer.env_path' => $this->envFile,
            'installer.guard_enabled' => false,
            'installer.enforce_2fa_setup' => false,
        ]);
        // E20: زبانِ پیش‌فرضِ نصب فارسی است (فراخوانیِ مستقیمِ Preflight هم).
        App::setLocale('fa');

        // سیم‌پیچیِ ایزولاسیون: ژورنال/قفل هرگز نباید به مسیر واقعی برود.
        $this->assertSame($this->dir, InstallJournal::basePath());
        $this->assertSame($this->envFile, InstallController::envPath());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function token(): string
    {
        return InstallJournal::token();
    }

    private function markPriorSteps(string ...$steps): void
    {
        foreach ($steps as $step) {
            InstallJournal::markStepDone($step);
        }
    }

    /** @return array<string, mixed> */
    private function testDbConfig(): array
    {
        return [
            'host' => env('DB_HOST', 'db'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'pishdad_test'),
            'username' => env('DB_USERNAME', 'cms'),
            'password' => env('DB_PASSWORD', 'cmssecret'),
        ];
    }

    // ─── preflight ───────────────────────────────────────────────

    /**
     * E49 — چک‌های دیتابیس در گام ۱ نباید نصب را قفل کنند.
     *
     * نامِ دیتابیس را **گام ۲** می‌گیرد و گام ۲ بعد از این گام است. وقتی اینجا
     * `fail` بود، هر نصبِ تازه‌ای که مشخصات را در `.env` نگذاشته بود — از جمله
     * نصبِ داکری — در گام ۱ قفل می‌شد و به همان گامی که مقدار را می‌گیرد
     * نمی‌رسید. باگ با یک نصبِ تازهٔ واقعی گزارش شد.
     */
    public function test_database_checks_are_pending_and_do_not_block_before_the_database_step(): void
    {
        $checks = Preflight::run(['database' => '', 'host' => 'db', 'username' => 'cms', 'password' => 'x']);
        $byKey = collect($checks)->keyBy('key');

        $this->assertSame('pending', $byKey['db_connection']['status']);
        $this->assertSame('pending', $byKey['db_collation_privilege']['status']);

        // مهم‌تر از خودِ وضعیت: این وضعیت نباید گام را ببندد.
        $this->assertFalse(
            Preflight::blockingFailures($checks),
            'a fresh install must be able to reach the database step',
        );
    }

    /** فقط `fail` بلاک می‌کند؛ `pass` و `warn` و `pending` نه. */
    public function test_only_fail_blocks_the_preflight_step(): void
    {
        $row = static fn (string $status): array => [
            'key' => 'probe', 'label' => 'probe', 'status' => $status, 'detail' => '', 'remedy' => '',
        ];

        $this->assertFalse(Preflight::blockingFailures([$row('pass')]));
        $this->assertFalse(Preflight::blockingFailures([$row('warn')]));
        $this->assertFalse(Preflight::blockingFailures([$row('pending')]));
        $this->assertTrue(Preflight::blockingFailures([$row('fail')]));
        $this->assertTrue(Preflight::blockingFailures([$row('pass'), $row('pending'), $row('fail')]));
        $this->assertFalse(Preflight::blockingFailures([$row('pass'), $row('warn'), $row('pending')]));
    }

    /**
     * remedyِ مجوزِ collation باید **مالکیتِ دیتابیس** را بگوید.
     *
     * متنِ پیشین `GRANT CREATE ON SCHEMA public` را پیشنهاد می‌داد که این مجوز را
     * نمی‌دهد؛ کاربر را دنبالِ راهِ اشتباه می‌فرستاد. چیزی که واقعاً کار می‌کند
     * مالکیتِ دیتابیس است (تجربی تأیید شد).
     */
    public function test_the_collation_remedy_names_ownership_not_a_schema_grant(): void
    {
        foreach (['en', 'fa'] as $locale) {
            App::setLocale($locale);
            $text = __('install.checks.db_collation_privilege.remedy_fail', [
                'database' => 'cms', 'username' => 'cms',
            ]);

            $this->assertStringContainsString('OWNER TO', $text, "[{$locale}] the remedy must name the ownership fix");
            $this->assertStringContainsString('cms', $text, "[{$locale}] the remedy must carry the real names");
            // نامِ مجوزِ غلط فقط داخلِ نفی می‌آید («این کار را نمی‌کند»)، نه
            // به‌عنوان توصیه — وگرنه کاربر دنبالِ راهِ اشتباه می‌رود.
            $negation = $locale === 'fa' ? 'نمی‌دهد' : 'does not';
            $this->assertStringContainsString($negation, $text, "[{$locale}] the wrong grant must only appear as a negation");
        }
    }

    /** هر وضعیتی که کد تولید می‌کند باید برچسبِ ترجمه‌شده داشته باشد (وگرنه صفحه ۵۰۰ می‌دهد). */
    public function test_every_check_status_has_a_translated_label(): void
    {
        foreach (['pass', 'warn', 'pending', 'fail'] as $status) {
            App::setLocale('fa');
            $this->assertNotSame('install.status.'.$status, __('install.status.'.$status), "fa: {$status}");

            App::setLocale('en');
            $this->assertNotSame('install.status.'.$status, __('install.status.'.$status), "en: {$status}");
        }
    }

    public function test_preflight_page_renders_200_without_web_middleware(): void
    {
        $middlewares = Route::getRoutes()->getByName('install.preflight')?->gatherMiddleware() ?? [];
        $this->assertNotContains('web', $middlewares, 'install routes must NOT use the web group (APP_KEY independence)');

        // حتی با APP_KEY خالی هم باید ۲۰۰ بدهد.
        config(['app.key' => '']);

        $this->get('/install/preflight')
            ->assertOk()
            ->assertSee('پیش‌نیازها', false)
            ->assertSee('راه‌حل', false); // ستون remedy جدول
    }

    public function test_preflight_json_reports_real_sodium_runtime_check(): void
    {
        $response = $this->getJson('/install/preflight')->assertOk();
        $checks = $response->json('data.checks');
        $this->assertIsArray($checks);

        $sodium = collect($checks)->firstWhere('key', 'sodium_runtime');
        $this->assertNotNull($sodium, 'sodium check must exist');
        $this->assertSame('pass', $sodium['status']);
        // تست واقعی roundtrip، نه صرفاً extension_loaded.
        $this->assertStringNotContainsString('extension_loaded', strtolower($sodium['detail'].$sodium['label']));
        $this->assertMatchesRegularExpression('/roundtrip|secretbox|compat|native/iu', $sodium['detail']);

        $result = Preflight::sodiumRuntime();
        $this->assertTrue($result['ok']);
        $this->assertNotSame('none', $result['backend']);
    }

    public function test_preflight_enforces_php_83_baseline(): void
    {
        $checks = Preflight::run();
        $php = collect($checks)->firstWhere('key', 'php_version');
        $this->assertNotNull($php);
        $this->assertStringContainsString('۸.۳', $php['label'].$php['detail'].$php['remedy']);
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
        $this->assertSame('pass', $php['status']);
    }

    public function test_collation_check_is_real_and_cleans_up_probe(): void
    {
        InstallJournal::put('db', $this->testDbConfig());
        $checks = Preflight::run();

        $collation = collect($checks)->firstWhere('key', 'db_collation_privilege');
        $this->assertSame('pass', $collation['status'], $collation['detail']);

        $leftovers = collect(DB::select("SELECT collname FROM pg_collation WHERE collname LIKE 'cms\\_probe\\_%'"))
            ->filter(fn ($row) => str_starts_with($row->collname, 'cms_probe_'));
        $this->assertCount(0, $leftovers, 'probe collations must be dropped');
    }

    /**
     * E49 — وقتی اتصال برقرار نمی‌شود، چکِ مجوز `pending` می‌دهد نه `fail` دوم.
     *
     * اندازه‌گیری اصلاً ممکن نیست (همان خطای اتصال)، پس دو `fail` روی صفحه فقط
     * یک مشکل را دو بار نشان می‌داد. `fail` اتصال به‌تنهایی جلوی ادامه را
     * می‌گیرد و چکِ مجوز در گامِ «پایگاه داده» با اتصالِ واقعی سنجیده می‌شود.
     */
    public function test_preflight_fails_gracefully_without_database(): void
    {
        $checks = Preflight::run([
            'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'nope', 'password' => 'nope',
        ]);

        $conn = collect($checks)->firstWhere('key', 'db_connection');
        $collation = collect($checks)->firstWhere('key', 'db_collation_privilege');
        $this->assertSame('fail', $conn['status']);
        $this->assertNotSame('', $conn['remedy']);
        $this->assertSame('pending', $collation['status'], 'بدون اتصال، مجوز سنجیده نشده — نه رد شده.');
        $this->assertTrue(Preflight::blockingFailures($checks));
    }

    public function test_install_pages_have_no_skip_button(): void
    {
        $this->markPriorSteps('preflight', 'database', 'app_key', 'migrate');

        foreach (['/install/preflight', '/install/database', '/install/superadmin'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('رد کردن', false);
        }
    }

    /**
     * E33 — پیش‌فرضِ فرمِ گام ۲ `Pishdad` است، نه مقدارِ `.env`.
     *
     * رگرسیونِ ثبت‌شده: پیش‌پرکردن از `Preflight::dbConfig()` می‌آمد و آن
     * `.env` را هم می‌خواند. پس روی هر محیطی که `.env` مقدار داشت (مثل
     * `cms`/`pishdad_test`)، فرم همان را نشان می‌داد و `Pishdad` هرگز دیده
     * نمی‌شد — یعنی کاربر باید نامِ دیتابیس را حدس می‌زد.
     *
     * `.env` منبعِ درستی برای این کار نیست: نصب‌کننده **خودش** `.env` را
     * می‌نویسد. منبعِ درستِ «ادامه دادنِ نصبِ نیمه‌کاره» ژورنال است.
     *
     * تست، محیطِ آزمون را که `DB_DATABASE=pishdad_test` دارد به‌عنوان همان
     * «محیطی با مقدارِ .env» استفاده می‌کند.
     *
     * E51 — ولی داخلِ کانتینرِ داکر پیش‌فرض‌ها داکری‌اند (`db`/`cms`)، چون
     * `127.0.0.1` آنجا یعنی خودِ کانتینر. پس انتظار بر اساسِ `/.dockerenv`
     * شاخه‌بندی می‌شود تا در هر دو محیط سبز بماند.
     */
    public function test_the_database_form_defaults_to_pishdad_not_the_env_value(): void
    {
        $this->markPriorSteps('preflight');

        $html = $this->get('/install/database')->assertOk()->getContent();

        // E51: داخلِ کانتینر پیش‌فرضِ داکری، بیرون پیش‌فرضِ ساده.
        $expected = file_exists('/.dockerenv') ? 'cms' : 'Pishdad';

        foreach (['database', 'username'] as $field) {
            $this->assertMatchesRegularExpression(
                '/id="db-'.$field.'"[^>]*value="'.$expected.'"/',
                $html,
                "فیلد «{$field}» باید پیش‌فرضِ {$expected} داشته باشد، نه مقدارِ .env.",
            );
        }

        // مقدارِ `.env` محیطِ آزمون نباید در فرم دیده شود.
        $this->assertStringNotContainsString('pishdad_test', $html);
        if ($expected !== 'cms') {
            $this->assertDoesNotMatchRegularExpression('/id="db-username"[^>]*value="cms"/', $html);
        }
    }

    /** ورودی‌های پنلِ زندهٔ راهنما هم باید همان پیش‌فرض را نشان دهند (E51: داکرآگاه). */
    public function test_the_live_guide_inputs_mirror_the_pishdad_default(): void
    {
        $this->markPriorSteps('preflight');

        $html = $this->get('/install/database')->assertOk()->getContent();

        $expected = file_exists('/.dockerenv') ? 'cms' : 'Pishdad';

        foreach (['g-database', 'g-username'] as $field) {
            $this->assertMatchesRegularExpression(
                '/id="'.$field.'"[^>]*value="'.$expected.'"/',
                $html,
                "ورودیِ «{$field}» باید هم‌خوانِ فرم باشد.",
            );
        }
    }

    /**
     * E34 — نوارِ مراحل فقط گام‌های **قابل‌رفتن** را لینک می‌کند.
     *
     * `ensureStepReachable()` گام i را وقتی باز می‌گذارد که همهٔ گام‌های پیش از
     * آن `done` باشند. اگر نوار گامِ دست‌نیافتنی را لینک کند، کاربر به ۴۰۴
     * می‌خورد؛ و اگر گامِ `done` را لینک نکند، راهی برای **دیدنِ دوبارهٔ**
     * گام‌های قبلی نمی‌ماند (شکایتِ اصلی: از گام ۲ راهی به گام ۱ نبود).
     */
    public function test_the_step_rail_links_only_reachable_steps(): void
    {
        $railLinkFor = function (string $html): array {
            preg_match_all('/<li class="[^"]*"[^>]*>\s*<a href="([^"]+)"/', $html, $m);

            return array_map(
                static fn (string $u): string => (string) parse_url($u, PHP_URL_PATH),
                $m[1],
            );
        };

        // نصبِ تازه: هیچ گامی تمام نیست ⇒ فقط گامِ اولِ همیشه‌باز لینک است.
        $fresh = $railLinkFor($this->get('/install/preflight')->assertOk()->getContent());
        $this->assertContains('/install/preflight', $fresh);
        $this->assertNotContains('/install/superadmin', $fresh, 'گامِ دست‌نیافتنی نباید لینک شود.');

        // با تمام‌شدنِ گام ۱، گام ۲ هم باز می‌شود — و گام ۱ برای بازبینی می‌ماند.
        $this->markPriorSteps('preflight');
        $after = $railLinkFor($this->get('/install/database')->assertOk()->getContent());
        $this->assertContains('/install/preflight', $after, 'گامِ انجام‌شده باید برای بازبینی قابل‌رفتن بماند.');
        $this->assertContains('/install/database', $after);
    }

    // ─── journal / resume / lock ─────────────────────────────────

    public function test_start_redirects_to_first_incomplete_step(): void
    {
        $this->get('/install')->assertRedirect(route('install.preflight'));

        $this->markPriorSteps('preflight', 'database');
        $this->get('/install')->assertRedirect(route('install.prepare'));
    }

    public function test_posts_require_install_token(): void
    {
        $this->post('/install/preflight', [])->assertStatus(419);
        $this->post('/install/preflight', ['install_token' => 'wrong'])->assertStatus(419);

        // با توکن درست و preflight سبز، ادامه می‌دهد.
        $this->post('/install/preflight', ['install_token' => $this->token()])
            ->assertRedirect(route('install.database'));
        $this->assertTrue(InstallJournal::isStepDone('preflight'));
    }

    public function test_step_order_cannot_be_skipped_via_url(): void
    {
        $this->get('/install/superadmin')->assertNotFound();
        $this->get('/install/migrate')->assertNotFound();
    }

    public function test_lock_blocks_install_routes_but_opens_done(): void
    {
        InstallJournal::writeLock(['email' => 'x@example.com']);

        $this->get('/install')->assertNotFound();
        $this->get('/install/preflight')->assertNotFound();
        $this->get('/install/done')->assertOk()->assertSee('نصب کامل شد', false);
        // E22 — برگهٔ اطلاعات: مشخصات دیتابیس + لینک پنل/سایت.
        $this->get('/install/done')->assertOk()
            ->assertSee('برگهٔ اطلاعات نصب', false)
            ->assertSee('/admin/login', false);
    }

    public function test_guard_redirects_app_to_installer_when_not_installed(): void
    {
        config(['installer.guard_enabled' => true]);
        // «نصبِ واقعاً خالی» در تست با `RefreshDatabase` قابل ساخت نیست، پس
        // درزِ `installed_probe` صریحاً خاموشش می‌کند — همان کاری که یک
        // دیتابیس خالیِ واقعی می‌کرد.
        config(['installer.installed_probe' => fn (): bool => false]);

        $guard = new InstallGuard;
        $next = fn () => response('ok');

        $this->assertSame('ok', $guard->handle(Request::create('/install/preflight', 'GET'), $next)->getContent());

        $redirect = $guard->handle(Request::create('/', 'GET'), $next);
        $this->assertSame(302, $redirect->getStatusCode());
        $this->assertStringEndsWith('/install', (string) $redirect->headers->get('Location'));

        $json = $guard->handle(Request::create('/api/v1/pages', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), $next);
        $this->assertSame(503, $json->getStatusCode());
    }

    /**
     * ⭐ رگرسیونِ خاموشیِ خودساخته — این تست روی نسخهٔ اولِ `InstallGuard`
     * قرمز می‌شد و محصول را ۵۰۳ می‌کرد.
     *
     * ریشه: `isInstalled()` فقط `install.lock` را می‌پرسید، ولی آن فایل را
     * **فقط** `pishdad:install` می‌نویسد. هر نصبِ واقعی که با `git clone` +
     * `docker compose` بالا آمده — یعنی دقیقاً مسیری که README وعده می‌دهد —
     * هرگز آن فایل را نمی‌سازد. نتیجه: سایتِ سالم و مهاجرت‌کرده ۵۰۳ می‌داد و
     * همه‌چیز را به `/install` می‌فرستاد، با پیامی که هیچ سرنخی نداشت.
     *
     * اینجا قفل می‌شود که **نبودِ قفل به‌تنهایی دلیل نصب‌نبودن نیست**.
     */
    public function test_guard_does_not_blackout_an_existing_install_without_a_lock_file(): void
    {
        config(['installer.guard_enabled' => true]);
        InstallJournal::forget();

        // probe واقعی: این دیتابیس مهاجرت اجرا کرده ⇒ نصبِ ازپیش‌موجود است.
        config(['installer.installed_probe' => null]);
        $this->assertFileDoesNotExist(InstallJournal::lockPath(), 'پیش‌فرضِ این تست نبودِ قفل است');

        $guard = new InstallGuard;
        $next = fn () => response('ok');

        $json = $guard->handle(
            Request::create('/api/v1/pages', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
            $next
        );
        $this->assertSame(
            200,
            $json->getStatusCode(),
            'نصبِ ازپیش‌موجود نباید ۵۰۳ بگیرد — نبودِ install.lock دلیل نصب‌نبودن نیست.'
        );

        // و مسیر /install هم باید بسته بماند (چون نصب انجام شده).
        $this->expectException(NotFoundHttpException::class);
        $guard->handle(Request::create('/install/preflight', 'GET'), $next);
    }

    public function test_guard_blocks_installer_after_lock(): void
    {
        config(['installer.guard_enabled' => true]);
        InstallJournal::writeLock();
        $guard = new InstallGuard;
        $next = fn () => response('ok');

        $this->expectException(NotFoundHttpException::class);
        $guard->handle(Request::create('/install/preflight', 'GET'), $next);
    }

    /**
     * ⭐ رگرسیون E21 — صفحهٔ موفقیت بعدِ قفل باید از گارد رد شود.
     *
     * `finalize` به `/install/done` ریدایرکت می‌کند و `done()` عمداً فقط
     * بعد از نصب معنا دارد؛ گارد نباید آن را هم ۴۰۴ کند.
     */
    public function test_guard_lets_done_page_through_after_lock(): void
    {
        config(['installer.guard_enabled' => true]);
        InstallJournal::writeLock();
        $guard = new InstallGuard;
        $next = fn () => response('ok');

        $this->assertSame('ok', $guard->handle(Request::create('/install/done', 'GET'), $next)->getContent());
        $this->assertSame('ok', $guard->handle(Request::create('/install/lang/en', 'GET'), $next)->getContent());

        $this->expectException(NotFoundHttpException::class);
        $guard->handle(Request::create('/install/preflight', 'GET'), $next);
    }

    /**
     * ⭐ رگرسیون E17 — گارد نباید نصبِ درحال‌انجام را بعد از گام ۳ بکشد.
     *
     * گام ۳ (`appKeyGenerate`) خودش `APP_KEY` را در `.env` می‌نویسد؛ از
     * ریکوئستِ بعد، `looksAlreadyInstalled()` صرفاً به‌خاطر ست‌بودن کلید
     * `true` می‌داد و گارد کل `/install` (از جمله `/install/migrate`) را
     * ۴۰۴ می‌کرد. با ژورنالِ بدون‌قفل و گامِ ناتمام، مسیر نصب باید باز بماند.
     */
    public function test_guard_keeps_installer_open_after_app_key_step(): void
    {
        config(['installer.guard_enabled' => true]);
        // probe واقعی (نه درزِ تست) + کلیدِ ست‌شده مثلِ بعدِ گام ۳.
        config(['installer.installed_probe' => null]);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->markPriorSteps('preflight', 'database', 'app_key');

        $guard = new InstallGuard;
        $next = fn () => response('ok');

        $this->assertSame(
            'ok',
            $guard->handle(Request::create('/install/migrate', 'GET'), $next)->getContent()
        );
    }

    /**
     * E20 — نصب دوزبانه: `?lang=en` انگلیسی می‌کند (جهت LTR)، سوییچر به
     * فارسی برمی‌گرداند و انتخاب در ژورنال + کوکی می‌ماند.
     */
    public function test_installer_switches_language_en_and_back(): void
    {
        $this->get('/install/preflight?lang=en')
            ->assertOk()
            ->assertSee('Step 1', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Database', false);
        $this->assertSame('en', InstallJournal::get('lang')['code'] ?? null);

        $res = $this->get('/install/lang/fa?back=install/preflight');
        $res->assertRedirect('/install/preflight');
        // کوکیِ نصب رمزنگاری نمی‌شود (web middleware نداریم)؛ پس هدر خام چک می‌شود.
        $this->assertStringContainsString('install_lang=fa', (string) $res->headers->get('set-cookie'));

        // پیش‌فرض (بدون Accept-Language انگلیسی) فارسی می‌ماند.
        $this->get('/install/preflight')->assertOk()->assertSee('گام ۱', false);
    }

    /**
     * E19 — اندپوینت‌های مرحله‌ای prepare: کلید و مهاجرت جدا، JSON، و
     * توکن نامعتبر ۴۱۹ می‌دهد.
     */
    public function test_prepare_phased_endpoints_return_json_progress(): void
    {
        $token = $this->token();
        $db = $this->testDbConfig();
        $this->post('/install/preflight', ['install_token' => $token])
            ->assertRedirect(route('install.database'));
        $this->post('/install/database', array_merge(['install_token' => $token], $db))
            ->assertRedirect(route('install.prepare'));

        $this->postJson('/install/prepare/key', ['install_token' => $token])->assertOk();
        $this->postJson('/install/prepare/migrate', ['install_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.next', route('install.superadmin'));

        $this->postJson('/install/prepare/key', ['install_token' => 'nope'])->assertStatus(419);
        $this->postJson('/install/prepare/migrate', ['install_token' => 'nope'])->assertStatus(419);
    }

    // ─── superadmin + 2FA ────────────────────────────────────────

    /**
     * E30 — گامِ مدیر، محتوای ازپیش‌ساختهٔ سایت را هم می‌سازد.
     *
     * رگرسیونِ ثبت‌شده: `PishdadSiteSeeder` هیچ‌جا در کدِ تولیدی صدا زده
     * نمی‌شد، پس نصبِ واقعی با سایتِ **خالی** بالا می‌آمد در حالی که صفحه‌ها و
     * تصویرها آماده در مخزن بودند. این تست نگهبانِ همان وصل‌شدن است.
     */
    public function test_the_admin_step_seeds_the_ready_made_site_content(): void
    {
        $this->markPriorSteps('preflight', 'database', 'app_key', 'migrate');

        $this->post('/install/superadmin', [
            'install_token' => $this->token(),
            'name' => 'سوپرادمین',
            'email' => 'boss@example.com',
            'password' => 'Strong!1234',
            'password_confirmation' => 'Strong!1234',
        ])->assertRedirect(route('install.finalize'));

        $owner = \App\Models\User::query()->where('email', 'boss@example.com')->firstOrFail();

        // صفحهٔ خانه باید منتشرشده و مالِ نخستین مدیر باشد، وگرنه سایت خالی می‌ماند.
        $home = \App\Models\Page::query()->where('slug', 'home')->first();
        $this->assertNotNull($home, 'صفحهٔ خانه از محتوای آماده ساخته نشد.');
        $this->assertSame($owner->id, $home->user_id);
        $this->assertSame(\App\Models\Page::STATUS_PUBLISHED, $home->status);
        $this->assertNotEmpty($home->blocks, 'صفحهٔ خانه بدونِ بلوک ساخته شد.');

        // بیش از یک صفحه: سایت واقعی چند صفحه دارد، نه فقط خانه.
        $this->assertGreaterThan(1, \App\Models\Page::query()->count());

        // تصویرهای محتوا روی دیسکِ `public` و با رکوردِ media ثبت شده‌اند.
        $this->assertGreaterThan(
            0,
            \App\Models\Media::query()->where('disk', 'public')->count(),
            'تصویرهای محتوای آماده ثبت نشدند.',
        );
    }

    public function test_first_admin_created_inline_without_2fa(): void
    {
        $this->markPriorSteps('preflight', 'database', 'app_key', 'migrate');

        $response = $this->post('/install/superadmin', [
            'install_token' => $this->token(),
            'name' => 'سوپرادمین',
            'email' => 'boss@example.com',
            'password' => 'Strong!1234',
            'password_confirmation' => 'Strong!1234',
        ]);
        $response->assertRedirect(route('install.finalize'));

        $user = \App\Models\User::query()->where('email', 'boss@example.com')->firstOrFail();
        // نخستین مدیر یک مدیرِ عادی است (نقش owner) — نه حسابِ مخفی/مستثنا.
        $this->assertTrue($user->hasRole('owner'));
        $this->assertFalse((bool) $user->google2fa_enabled);
        $this->assertNull($user->google2fa_secret);

        $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'Strong!1234'])
            ->assertOk()
            ->assertJsonPath('two_factor_setup_required', false)
            ->assertJsonPath('two_factor_required', false);
    }

    public function test_first_admin_validation_never_flashes_passwords(): void
    {
        $this->markPriorSteps('preflight', 'database', 'app_key', 'migrate');

        $response = $this->post('/install/superadmin', [
            'install_token' => $this->token(),
            'name' => 'x',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);
        $response->assertStatus(422)->assertSee('role="alert"', false)->assertDontSee('short');

        // رندر دوباره فرم هم رمزی نشان نمی‌دهد.
        $this->get('/install/superadmin')->assertOk()->assertDontSee('short');
    }

    // ─── CLI ─────────────────────────────────────────────────────

    public function test_cms_install_refuses_when_locked(): void
    {
        InstallJournal::writeLock();

        $exit = Artisan::call('pishdad:install', ['--no-interaction' => true]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('قبلاً کامل شده', Artisan::output());
    }

    public function test_cms_install_full_run_end_to_end(): void
    {
        $db = $this->testDbConfig();

        $exit = Artisan::call('pishdad:install', [
            '--db-host' => (string) $db['host'],
            '--db-port' => (string) $db['port'],
            '--db-database' => (string) $db['database'],
            '--db-username' => (string) $db['username'],
            '--db-password' => (string) $db['password'],
            '--admin-name' => 'سوپرادمین',
            '--admin-email' => 'cli-boss@example.com',
            '--admin-password' => 'Strong!1234',
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue(InstallJournal::isInstalled());
        $this->assertNull(InstallJournal::firstIncompleteStep());
        $this->assertNotNull(\App\Models\User::query()->where('email', 'cli-boss@example.com')->first());

        // .env واقعی دست نخورده؛ فقط فایل موقت تست نوشته شده.
        $this->assertFileExists($this->envFile);
        $this->assertStringContainsString('DB_DATABASE', (string) file_get_contents($this->envFile));
    }

    /**
     * E11 — واک‌تروی کاملِ وب: preflight → database → app_key → migrate →
     * superadmin → finalize → done، با resume از ژورنال و «سوختنِ» توکن پس از
     * قفل. تا پیش از این هر گام جدا تست می‌شد ولی توالی واقعی HTTP نبود.
     */
    public function test_full_web_install_walkthrough_locks_and_burns_token(): void
    {
        $token = $this->token();

        $this->get('/install')->assertRedirect(route('install.preflight'));

        // ۱) preflight
        $this->post('/install/preflight', ['install_token' => $token])
            ->assertRedirect(route('install.database'));

        // ۲) database (اتصال واقعی) → آماده‌سازی خودکار (E18)
        $db = $this->testDbConfig();
        $this->post('/install/database', array_merge(['install_token' => $token], $db))
            ->assertRedirect(route('install.prepare'));
        $this->get('/install')->assertRedirect(route('install.prepare'));

        // ۲ب) prepare — پیام موفقیت اتصال + اجرای خودکار کلید و مهاجرت‌ها.
        $this->get('/install/prepare')->assertOk()->assertSee('اتصال به دیتابیس برقرار شد', false);
        // شبیه‌سازی نصبِ تازه: کلیدی در کانفیگ نیست تا prepare بسازدش.
        config(['app.key' => '']);
        $this->post('/install/prepare', ['install_token' => $token])
            ->assertRedirect(route('install.superadmin'));
        $this->assertStringContainsString('APP_KEY=base64:', (string) file_get_contents($this->envFile));

        // مسیرهای جدای قدیمی هنوز مستقیم کار می‌کنند (سازگاری API/فراخوانی مستقیم).
        $this->post('/install/app-key', ['install_token' => $token])
            ->assertRedirect(route('install.migrate'));
        $this->post('/install/migrate', ['install_token' => $token])
            ->assertRedirect(route('install.superadmin'));

        // ۵) superadmin اینلاین (بدون 2FA، ورود اول اجبار می‌کند).
        $this->post('/install/superadmin', [
            'install_token' => $token,
            'name' => 'سوپرادمین وب',
            'email' => 'web-boss@example.com',
            'password' => 'Strong!1234',
            'password_confirmation' => 'Strong!1234',
        ])->assertRedirect(route('install.finalize'));

        // ۶) finalize → قفل + done
        $this->post('/install/finalize', ['install_token' => $token])
            ->assertRedirect(route('install.done'));

        $this->assertTrue(InstallJournal::isInstalled());
        $this->assertNull(InstallJournal::firstIncompleteStep());
        $this->get('/install/done')->assertOk()->assertSee('web-boss@example.com', false);
        $this->get('/install/preflight')->assertNotFound();

        // توکن سوخت: حتی با توکنِ درست، مسیرِ نصب دیگر در دسترس نیست.
        $this->post('/install/superadmin', [
            'install_token' => $token,
            'name' => 'مهاجم',
            'email' => 'attacker@example.com',
            'password' => 'Strong!1234',
            'password_confirmation' => 'Strong!1234',
        ])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);

        // سوپرادمین نصب‌شده در ورود اول، راه‌اندازی 2FA را اجبار می‌کند.
        $this->postJson('/api/v1/auth/login', ['email' => 'web-boss@example.com', 'password' => 'Strong!1234'])
            ->assertOk()
            ->assertJsonPath('two_factor_setup_required', false)
            ->assertJsonPath('two_factor_required', false);
    }

    private function authedRequest(\App\Models\User $user, string $uri, string $method = 'GET'): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
