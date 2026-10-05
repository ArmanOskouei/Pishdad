<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Plugins\PluginRouteTable;
use App\Services\Subscription\ClientSubscriptionResolver;
use App\Services\Subscription\NullSubscriptionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * K7.2 — هسته باید **بدون** افزونهٔ مرکزی هم کار کند.
 *
 * ## چرا این تست مهم‌تر از یک تست معمولی است
 *
 * چون یک انتشارِ عمومی یعنی نبودِ `plugins/central/`، و آن نبود با شبیه‌سازی
 * ثابت شد که سایت عمومی را ۵۰۰ می‌داد. یعنی یک تصمیمِ محصولِ درست
 * («این افزونه منتشر نشود») هر مشتریِ تازه را می‌شکست.
 *
 * و بدتر: این خرابی **در نصبِ خودمان دیده نمی‌شود** چون افزونه هست. پس
 * بدون این تست، می‌شود ماه‌ها زندگی کرد و بعد فهمید نسخهٔ عمومی سایت نمی‌آید.
 *
 * ## روش
 *
 * پوشهٔ افزونه **واقعاً کنار گذاشته می‌شود** — نه اینکه با یک mock وانمود کنیم
 * نیست. چون کلاس‌های PHP با `class_exists` کش می‌شوند و یک autoloadِ از پیش
 * بارگذاری‌شده، تست را دروغ می‌گوید.
 *
 * ## چرا هر تست در پروسهٔ جدا اجرا می‌شود؟
 *
 * جابه‌جایی پوشه فقط مسیر فایل را عوض می‌کند. اگر تستِ دیگری در همان پروسه
 * پیش از این تست کلاس افزونه را بارگذاری کرده باشد، `class_exists` همچنان
 * `true` می‌ماند و این تست دروازه را اشتباهی «باز» می‌بیند. تنها راه قابل‌اعتماد
 * برای سنجش «افزونه واقعاً غیرقابل‌بارگذاری است»، هر تست در پروسهٔ تازه اجرا
 * می‌شود — ویژگی `#[RunInSeparateProcess]` در PHPUnit 11 فقط روی متد است، نه کلاس.
 */
class PublicInstallWithoutCentralPluginTest extends TestCase
{
    use RefreshDatabase;

    private string $pluginDir;

    private string $stashed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginDir = base_path('plugins/central');
        $this->stashed = base_path('plugins/_central_stashed_for_test');

        if (is_dir($this->pluginDir)) {
            if (! @rename($this->pluginDir, $this->stashed)) {
                $this->markTestSkipped('rename failed — the directory is in use.');
            }
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stashed) && ! is_dir($this->pluginDir)) {
            @rename($this->stashed, $this->pluginDir);
        }

        parent::tearDown();
    }

    private function withoutPlugin(): void
    {
        // کش کلاس‌ها باید پاک شود، وگرنه `class_exists` از حافظه جواب می‌دهد و
        // تست وانمود می‌کند افزونه نیست در حالی که هست.
        $this->app->forgetInstance(PluginRouteTable::class);
    }

    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_plugin_directory_really_is_gone(): void
    {
        $this->assertFalse(
            is_dir($this->pluginDir),
            'پیش‌شرط تست: پوشهٔ افزونه باید کنار گذاشته شده باشد، وگرنه این تست هیچیز را ثابت نمی‌کند.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_public_status_still_answers_without_the_plugin(): void
    {
        $this->withoutPlugin();

        $answer = ClientSubscriptionResolver::publicStatus();

        $this->assertArrayHasKey('status', $answer);
        $this->assertArrayHasKey('message', $answer);
        $this->assertSame('active', $answer['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_null_source_reports_active_not_suspended(): void
    {
        $answer = (new NullSubscriptionSource)->status();

        // «معلق» بدون پنلِ مرکزی یعنی دروغ: هیچ چیزی نیست که بتواند تعلیق کند.
        $this->assertSame('active', $answer['status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_null_source_uses_the_install_brand(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'site', 'key' => 'global'],
            ['value' => json_encode(['title' => 'فروشگاه نمونه']), 'updated_at' => now(), 'created_at' => now()],
        );

        $answer = (new NullSubscriptionSource)->status();

        $this->assertStringContainsString('فروشگاه نمونه', $answer['message']);
    }

    /**
     * ⭐ مهم‌ترین یکی: مسیرهایی که هر بازدیدکنندهٔ سایت می‌زند.
     *
     * قبلاً `/api/v1/site/status` با نبودِ افزونه ۵۰۰ می‌داد. اگر کسی دوباره
     * importِ مستقیم بگذارد، همین تست قرمز می‌شود.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_public_site_endpoints_answer_without_the_plugin(): void
    {
        $this->withoutPlugin();

        foreach (['/api/v1/site/status', '/api/v1/site/chrome', '/api/v1/site/pages'] as $uri) {
            $response = $this->getJson($uri);

            $this->assertNotSame(
                500,
                $response->status(),
                "{$uri} بدون افزونهٔ مرکزی نباید ۵۰۰ بدهد.",
            );
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_site_status_reports_active_without_the_plugin(): void
    {
        $this->withoutPlugin();

        $response = $this->getJson('/api/v1/site/status');

        $response->assertOk();
        $this->assertSame('active', $response->json('data.status'));
    }

    /**
     * J12 — نبودِ سرویسِ اشتراک نباید «اشتراک نداری» بگوید.
     *
     * نسخهٔ قدیمیِ `SubscriptionStatusController` با هر نبودِ رکورد یک ۴۰۴ با
     * پیامِ «اشتراکی یافت نشد» می‌داد — یعنی یک نصبِ رایگانِ کاملاً سالم به
     * کاربر دروغ می‌گفت. حالا نبودِ سرویس یعنی `available=false`، وضعیت
     * `active`، و پیامِ مالِ هسته (`SubscriptionStatusMessage`) — نه ۴۰۴.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_subscription_status_is_honest_without_any_plugin(): void
    {
        $this->withoutPlugin();

        $response = $this->getJson('/api/v1/subscription-status?site_id=site-1');

        $response->assertOk();
        $this->assertFalse($response->json('data.available'), 'نبودِ سرویس باید صریحاً available=false باشد.');
        $this->assertSame('active', $response->json('data.status'), 'نبودِ سرویس یعنی محدودیت نیست، نه تعلیق.');
        $this->assertStringNotContainsString('یافت نشد', (string) $response->json('data.message'));
    }

    /**
     * ⭐⭐ ادعای اصلیِ J5: نصبِ تازهٔ هسته **صفر** جدولِ `central_*` دارد.
     *
     * تا پیش از J5 این ادعا غلط بود: چهار migration در
     * `database/migrations/` این جدول‌ها را می‌ساختند، پس هر نصبِ تازه — حتی
     * عمومی که `plugins/central/` روی دیسکش نیست — ۱۹ جدولِ افزونه را در
     * دیتابیسش داشت. آن جدول‌ها را هیچ‌کدام نمی‌خواندند (کد مرکزی از
     * `ClientSubscriptionResolver` رد می‌شود) و در عوض schema با بسته‌های موجود
     * روی دیسک ناهمخوان بود.
     *
     * ## چرا `Schema::getTables()` و نه `information_schema` دستی
     *
     * چون نگهبان باید دقیقاً همان چیزی را بسنجد که لارavel می‌بیند. یک پرسشِ
     * دستی که فیلترِ کمی متفاوت دارد، می‌تواند همین ادعا را بی‌صدا بی‌ارزش کند.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_fresh_core_install_contains_no_central_table(): void
    {
        $this->withoutPlugin();

        /** @var list<string> $central */
        $central = array_values(array_filter(
            array_map(static fn (array $t): string => (string) $t['name'], Schema::getTables()),
            static fn (string $name): bool => str_starts_with($name, 'central_'),
        ));

        $this->assertSame(
            [],
            $central,
            "نصبِ تازهٔ هسته نباید هیچ جدولِ `central_*` بسازد. جدول‌های افزونهٔ مرکزی\n"
            ."باید فقط با فعال‌سازیِ افزونه ساخته شوند — یعنی از راه `PluginMigrator`.\n"
            .'جدول‌های باقی‌مانده: '.implode('، ', $central)
        );
    }

    /**
     * ⭐ و نیمهٔ دیگرِ ادعا: `migrations` خودِ هسته هم نباید دربارهٔ این جدول‌ها
     * چیزی بداند.
     *
     * اگر یک migration در core جدولِ مرکزی بسازد ولی بعداً پاک شود، جدول روی
     * نصب‌های موجود می‌ماند و نصب تازه ندارد — یعنی schema بین دو نصب فرق
     * می‌کند و هیچ‌کس نمی‌فهمد چرا. این تست دقیقاً همان را می‌گیرد.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_core_migration_file_still_creates_a_central_table(): void
    {
        $offenders = [];

        foreach (File::files(database_path('migrations')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            // ⚠️ **کامنت‌ها حذف می‌شوند، و این شرطِ درستیِ گارد است.**
            //
            // نسخهٔ اول روی متنِ خام می‌جست و همین تست خودش را قرمز کرد: توضیحِ
            // J5 داخلِ docblockِ `2026_09_26_000001` نامِ جدول را نقل می‌کند و آن
            // نقل‌قول «DDL» شمرده می‌شد.
            //
            // و راهِ فرارِ آسان — کوتاه‌کردنِ docblock — **همان راهی است که یک
            // گارد را از کار می‌اندازد**: نویسنده‌ها یاد می‌گیرند که توضیح را
            // مبهم بنویسند تا تست سبز شود. پس گارد باید کد را بخواند، نه prose
            // را. `token_get_all` تنها راهِ درستِ تفکیکِ این دو از هم است.
            $code = $this->withoutComments($source);

            // `create`/`table`/`drop` یعنی DDL روی جدولِ مرکزی. صرفِ
            // `hasTable`/`dropIfExists` نیست: guardِ idempotent و مسیرِ
            // مهاجرتِ دادهٔ نصب‌های موجود (`2026_10_05`) هر دو مجازند.
            if (preg_match('/Schema::(?:create|table|drop)\(\s*[\'"]central_/i', $code) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این migrationهای هسته هنوز روی جدولِ `central_*` DDL می‌کنند:\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * متنِ PHP بدونِ کامنت‌ها و رشته‌ها، برای جست‌وجوی الگو.
     *
     * چرا رشته‌ها هم حذف می‌شوند: یک نامِ جدول داخلِ رشته (مثل `DB::raw` یا متنِ
     * خطا) نباید به‌حساب DDL نوشته شود. چیزی که می‌ماند ساختار است — همان چیزی که
     * `Schema::` در آن یک فراخوانیِ واقعی است.
     */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $out .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                $out .= ' ';

                continue;
            }

            $out .= $token[1];
        }

        return $out;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_for_site_id_returns_null_rather_than_throwing(): void
    {
        $this->withoutPlugin();

        $this->assertNull(
            ClientSubscriptionResolver::forSiteId('site-1'),
            'نبودِ افزونه یعنی «نمی‌دانیم»، نه خطا.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_for_user_returns_null_rather_than_throwing(): void
    {
        $this->withoutPlugin();

        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ])->fresh();

        $this->assertNull(ClientSubscriptionResolver::forUser($user));
    }

    /**
     * ⭐ و یک ادعای قوی‌تر: هیچ مسیرِ عمومیِ سایت نباید به مدلِ مرکزی چنگ بزند.
     *
     * این تست از تست بالا قوی‌تر است چون همهٔ endpointهای `site/*` را می‌گردد،
     * نه فقط سه‌تایی که الان می‌دانیم. یک endpointِ تازهٔ عمومی که فراموش شود
     * همین‌جا گرفته می‌شود.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_public_site_controller_imports_the_central_namespace(): void
    {
        $offenders = [];

        foreach (File::files(app_path('Http/Controllers/Api/V1/Site')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            foreach (['Pishdad\Plugins\Central', 'App\Models\Central'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file->getFilename().' → '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "کنترلرهای عمومی سایت نباید به افزونهٔ مرکزی ارجاع دهند:\n".implode("\n", $offenders),
        );
    }
}
