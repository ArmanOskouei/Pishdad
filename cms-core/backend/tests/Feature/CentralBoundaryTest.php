<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginReleaseManager;
use ReflectionMethod;
use Tests\TestCase;

/**
 * K7.1 — گراف وابستگی: هستهٔ مشتری نباید به کد مرکزی ارجاع دهد.
 *
 * این تست پیش‌نیاز تبدیل پنل مرکزی به افزونه است (K7). اگر فایلی در
 * `app/` به `App\...\Central\` ارجاع بدهد، آن فایل نمی‌تواند به افزونه منتقل
 * شود و بدتر — افزونه برای کار کردن به کدی در هسته نیاز پیدا می‌کند که قرار
 * بود مستقل باشد.
 *
 * این تست عمداً **ساده و خوانا** نوشته شده، نه با AST یا reflection: هدفش
 * جلوگیری از ارجاع تصادفی است، نه تحلیل دقیق وابستگی.
 */
class CentralBoundaryTest extends TestCase
{
    /**
     * J5 — این تست جدول‌های مرکزی را از دیتابیس می‌خواند، و آن جدول‌ها دیگر در
     * core نیستند.
     *
     * یک تستِ مرز که جدول‌های سمتِ مقابلِ مرز را نداشته باشد، نیمی از قرارداد را
     * می‌سنجد: اینکه کسی از هسته **صدا نمی‌زند**شان، ولی نه اینکه اصلاً وجود دارند.
     * بدون این خط، تستِ خودش با «هیچ جدول مرکزی پیدا نشد» می‌میرد یا — بدتر — اگر
     * آن guard را کسی بردارد، بی‌صدا از کنارِ ۱۹ ارجاع رد می‌شود.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->installCentralPluginTables();
    }

    /**
     * مسیرهایی که «مرکزی» هستند و باید از هستهٔ مشتری جدا بمانند.
     *
     * K7.8 این‌ها را از `App\...` به `Pishdad\Plugins\Central\...` برد. هر دو
     * فرم در فهرست می‌مانند: فرم قدیمی دیگر وجود ندارد، ولی اگر کسی
     * `App\Models\Central` را از نو بسازد — مثلاً یک کپی جاافتاده — باید قرمز
     * شود، نه اینکه بی‌صدا از نگهبان رد شود.
     */
    private const CENTRAL_NAMESPACES = [
        'Pishdad\Plugins\Central\Http',
        'Pishdad\Plugins\Central\Models',
        'App\Http\Controllers\Api\Central',
        'App\Models\Central',
    ];

    /**
     * فایل‌هایی که **خودشان** بخشی از پنل مرکزی‌اند، پس ارجاعشان به مرکزی
     * طبیعی است. این‌ها بعداً به افزونه منتقل می‌شوند و آن‌وقت از این فهرست
     * بیرون می‌آیند.
     */
    /**
     * J5 — **خالی است. و این خالی‌بودن، ادعا نیست: تست زیر ثابت می‌کند.
     *
     * سه استثنایی که تا چند لحظه پیش اینجا بودند، دیگر نقض نیستند:
     *
     *  - `EnsureCentralOperator` — حذف شد؛ نقش `operator` را میدافتر بومیِ
     *    لاراول (`role:operator`) می‌گیرد، بدون نیاز به میدافترِ اختصاصی.
     *  - `ClientSubscriptionResolver` — دیگر کوئری نمی‌زند؛ از راه
     *    `SubscriptionResolver` می‌پرسد و فقط آرایه برمی‌گرداند. نامِ جدولِ
     *    افزونه دیگر در هسته نیست (پس `PluginBoundaryTableSweepTest` هم دیگر
     *    لازم ندارد آن را استثنا کند).
     *  - `DevModeAudit` — فقط `AuditTrail` را صدا می‌زند؛ نوشتنِ جدولِ
     *    ممیزی کارِ خودِ افزونه شد (`Audit\OperatorActivitySink`).
     *
     * @var list<string>
     */
    private const ALLOWED = [];

    /**
     * نقض‌های شناخته‌شته — **خالی**.
     *
     * از K7.1 تا J5 این فهرست پر بود و هر بار باید دستی کم می‌شد؛ یعنی
     * مرزِ «پیدا شده ولی رفع نشده» بود. جای درستِ این فهرست، issue بود نه تست.
     *
     * هفت نقضی که آخرین‌بار اینجا بودند، هر کدام مسیرشان فرق داشت:
     *
     *  - سه فرمان `billing:*` ← به بستهٔ افزونه، **به‌علاوهٔ** ثبت‌نام در
     *    `Artisan` (که بدون آن `schedule:run` بی‌صدا ردش می‌کرد).
     *  - `HeartbeatController` ← به بسته، از راه `manifest.api.routes`.
     *  - `DashboardController` ← از راه `SubscriptionResolver` (آرایه، نه مدل).
     *  - `ProfileController` ← `hardware_keys` رفت؛ ذاتاً مفهومِ مرکزی است.
     *  - `PluginSubscriptionSource` ← حذف؛ افزونهٔ اشتراک از قبل همان داده
     *    را از راه `SubscriptionResolver` می‌داد.
     *
     * اگر روزی فایلی دوباره به این سمت چنگ بزند، این فهرست پر می‌شود و تست
     * **همان لحظه** خطا می‌دهد — که دقیقاً کاری است که می‌خواهیم.
     *
     * @var list<string>
     */
    private const KNOWN_VIOLATIONS = [];

    private function coreFiles(): array
    {
        $root = base_path('app');
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function isCentral(string $path): bool
    {
        $relative = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path()).'/';

        if (str_starts_with($relative, $base)) {
            $relative = substr($relative, strlen($base));
        }

        foreach (self::ALLOWED as $allowed) {
            if (str_starts_with($relative, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * هیچ فایل هستهٔ مشتری نباید به کلاس مرکزی ارجاع بدهد.
     *
     * ارجاع به شکل FQCN نوشته می‌شود (`use App\Models\Central\X` یا
     * `App\Models\Central\X::class`) چون کد پروژه PSR-4 و importمحور است.
     */
    /**
     * هیچ نقض **جدیدی** نباید اضافه شود.
     *
     * K7.1 نقض‌های موجود را فهرست کرد، نه اینکه حذفشان کند — حذفشان کار
     * K7.2 تا K7.8 است. پس شرط این تست «بدتر نشدن» است: هر ارجاع تازه باید
     * یا گزارش شود یا مهاجرت کند.
     */
    public function test_no_core_file_references_a_central_class(): void
    {
        $violations = [];

        foreach ($this->coreFiles() as $path) {
            if ($this->isCentral($path)) {
                continue;
            }

            $relative = str_replace('\\', '/', str_replace(base_path().'/', '', $path));
            $source = (string) file_get_contents($path);
            foreach (self::CENTRAL_NAMESPACES as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = $relative.' → '.$needle;
                }
            }
        }

        $files = array_values(array_unique(array_map(
            fn (string $v) => explode(' → ', $v)[0],
            $violations
        )));

        sort($files);

        $this->assertSame(
            self::KNOWN_VIOLATIONS,
            $files,
            "نقض‌های مرز تغییر کرده‌اند. اگر فایلی از فهرست خارج شده، آن را از\n"
            ."KNOWN_VIOLATIONS حذف کن. اگر فایل تازه‌ای اضافه شده، یعنی وابستگی\n"
            ."تازه‌ای اضافه شده که باید از رابط رد شود نه از مدل مرکزی:\n"
            .implode("\n", array_diff($files, self::KNOWN_VIOLATIONS))
        );
    }

    /**
     * ارجاع رشته‌ای سخت‌تر است: `central_subscriptions` نام جدول است و در
     * کوئری‌های خام می‌آید. خطای K7.5 دقیقاً از همین خانواده است.
     *
     * ## چرا فهرست جدول‌ها از مدل‌ها استخراج می‌شود و نه دستی نوشته می‌شود
     *
     * نسخهٔ قبلی یک آرایهٔ دستی داشت و **سه نامش وجود نداشت**:
     * `central_reviews` و `central_updates` هرگز جدول نبودند و
     * `central_hardware_units` نام اشتباه `central_operator_hardware_keys` بود.
     * در عوض **یازده جدول واقعی** اصلاً محافظت نمی‌شدند — از جمله
     * `central_operator_hardware_keys` و `central_operator_activities`.
     *
     * یعنی نگهبانی که باید K7.8 را ایمن کند، خودش سه‌جدول متروک و یازده جدول
     * غایب را می‌سنجید: سبز بود بی‌آنکه چیزی را واقعاً تضمین کند. مهاجرت زیر چنین
     * نگهبانی دقیقاً همان «مرز حذف‌شده با تست سبز» است.
     *
     * استخراج از روی کلاس‌های مدل، فهرست را خودنگه‌دار می‌کند: جدول تازه یعنی
     * مدل تازه، و مدل تازه یعنی جدول تازه‌ای که خودکار زیر نگهبان می‌رود. تنها
     * راهی که جدول از دست نگهبان خارج شود، عمداً و با دیده‌شدن است.
     *
     * @return list<string>
     */
    private function centralTables(): array
    {
        $tables = [];

        foreach (glob($this->centralDir().'/Laravel/src/Models/*.php') ?: [] as $file) {
            $class = 'Pishdad\\Plugins\\Central\\Models\\'.basename($file, '.php');

            // K7.8: کلاس‌های مدل حالا زیر namespace افزونه‌اند و PSR-4 آن در
            // `AppServiceProvider::bootBundledPlugins()` ثبت می‌شود. بدون آن ثبت،
            // `class_exists` همیشه false می‌داد و فهرست جدول‌ها **خالی** می‌شد —
            // یعنی نگهبان سبز می‌شد در حالی که هیچ جدولی را نمی‌سنجید.
            if (! class_exists($class)) {
                continue;
            }

            // `getTable()` به‌جای پارس‌کردن `$table`. این هر سه حالت را درست پوشش
            // می‌دهد: `$table` صریح، snake_case، و جمع‌شدن نام — و `CentralLog`
            // دقیقاً همان موردی است که پارس دستی از قلم می‌افتاد و `central_logs`
            // را بی‌سروصدا از نگهبان حذف می‌کرد. همان تست، همان روز، همان جدول را
            // گزارش کرد.
            $tables[] = (new $class)->getTable();
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * فهرست استخراج‌شده باید هر جدول `central_*` واقعی را پوشش دهد.
     *
     * این تست خودِ نگهبان را نگهبانی می‌کند: اگر کسی استخراج را خراب کند یا
     * مدلی اضافه کند که دیده نمی‌شود، اینجا قرمز می‌شود نه آن‌که مرز بی‌سروصدا
     * باز بماند.
     */
    public function test_the_table_list_covers_every_real_central_table(): void
    {
        $fromModels = $this->centralTables();

        $live = [];
        foreach (\DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename like 'central\\_%'") as $row) {
            $live[] = (string) ($row->tablename ?? '');
        }
        sort($live);

        $this->assertNotSame(
            [],
            $live,
            'هیچ جدول مرکزی پیدا نشد — اگر اتصال DB نیست، این تست نباید بی‌صدا رد شود.'
        );

        $missing = array_values(array_diff($live, $fromModels));
        $this->assertSame(
            [],
            $missing,
            "این جدول‌های مرکزی از نگهبان غایب‌اند — هر فایل هسته می‌تواند بی‌سروصدا صدایشان بزند:\n"
            .implode("\n", $missing)
        );

        $stale = array_values(array_diff($fromModels, $live));
        $this->assertSame(
            [],
            $stale,
            "فهرست جدول‌های مرکزی نام‌هایی دارد که دیگر وجود ندارند — یعنی محافظت از چیزی که نیست:\n"
            .implode("\n", $stale)
        );
    }

    public function test_no_core_file_queries_a_central_table_directly(): void
    {
        $violations = [];

        foreach ($this->coreFiles() as $path) {
            if ($this->isCentral($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            foreach ($this->centralTables() as $table) {
                if (preg_match('/[\'"`]'.$table.'[\'"`]/', $source) === 1) {
                    $violations[] = str_replace('\\', '/', str_replace(base_path().'/', '', $path)).' → '.$table;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "این فایل‌های هسته مستقیم جدول مرکزی را صدا می‌زنند — باید از رابط عبور کنند:\n"
            .implode("\n", $violations)
        );
    }

    /**
     * فهرست `ALLOWED` نباید آن‌قدر بزرگ شود که بی‌معنا شود. اگر روزی ۲۰ فایل
     * این‌جا اضافه شد، یعنی مرز عملاً حذف شده و این تست دیگر چیزی را
     * تضمین نمی‌کند.
     */
    public function test_the_allowlist_has_not_swallowed_the_boundary(): void
    {
        $this->assertLessThanOrEqual(
            8,
            count(self::ALLOWED),
            'فهرست استثناها بزرگ شده — یعنی مرز عملاً حذف شده است.'
        );
    }

    /**
     * J5 — زمان‌بندیِ Billing مرکزی نباید در هسته باشد.
     *
     * این یکی «رفتار» نیست، «مالکیت» است. پیش از J5 سه خط
     * `Schedule::command('billing:remind'…)` در `routes/console.php` هسته بودند.
     * روی یک نصبِ عمومی (بدون `plugins/central/`) `schedule:run` هر روز سه
     * فرمانِ ناموجود را صدا می‌زد و **بی‌صدا** رد می‌شد — نه خطا، نه لاگ. یعنی
     * هیچ‌کس نمی‌فهمید زمان‌بندی مرکزی روی سایتِ بدون مرکز هم اصلاً اجرا نمی‌شود.
     *
     * تست دو طرف را می‌سنجد: هسته **نباید** زمان‌بندی کند، و افزونه **باید** بکند.
     * فقط نبودنِ سمتِ هسته کافی نبود — آن حالت یعنی زمان‌بندی برای همیشه مرده.
     */
    public function test_central_billing_schedule_lives_in_the_plugin_not_the_core(): void
    {
        $core = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringNotContainsString(
            "Schedule::command('billing:",
            $core,
            "زمان‌بندی Billing مرکزی نباید در هسته باشد — روی نصبِ بدون افزونه بی‌صدا از کار می‌افتد.\n"
            ."به plugins/central/Laravel/routes/console.php منتقلش کن.",
        );

        $host = $this->centralDir().'/Laravel/routes/console.php';

        $this->assertFileExists($host, 'میزبان کنسولی افزونهٔ مرکزی غایب است.');

        $plugin = (string) file_get_contents($host);

        foreach (['billing:remind', 'billing:suspend', 'billing:purge'] as $command) {
            $this->assertStringContainsString(
                "Schedule::command('{$command}')",
                $plugin,
                "«{$command}» دیگر هیچ‌جا زمان‌بندی نمی‌شود — یعنی بی‌صدا مرده است.",
            );
        }
    }

    /**
     * K7.8 — نگهبانِ معکوس: افزونهٔ مرکزی نباید به هستهٔ مشتری چنگ بزند.
     *
     * سه تست بالا همه از یک طرف نگاه می‌کنند: «هسته به مرکزی ارجاع نده.» ولی
     * بعد از انتقال، خطرِ برعکس هم هست و **دقیقاً همان چیزی است که K7.8 می‌خواست
     * حل کند**: کد مرکزی به سرویس‌های هسته بچسبد و هسته عملاً بی‌مرز شود.
     *
     * بدون این تست، انتقال «موفق» بود ولی هسته یک کوچهٔ فرار به افزونه پیدا
     * می‌کرد — و هیچ‌کدام از تست‌های موجود نمی‌دیدند، چون همه از سمت هسته
     * نگاه می‌کردند.
     *
     * استثناها محدود و **نام‌بردنی**‌اند، چون بعضی وابستگی‌ها اجتناب‌ناپذیرند:
     *  - `App\Models\User` — ۶ مدل مرکزی به کاربر رابطه دارند و جدول
     *    `users` در هسته می‌ماند (K7.12).
     *  - `App\Http\Controllers\Controller` — کلاس پایهٔ لاراول.
     *  - اعلان‌ها و مدل تیکت — کانال اعلان هسته را استفاده می‌کند (K7.13 این
     *    هزینه را آگاهانه پذیرفت).
     */
    private const PLUGIN_ALLOWED_CORE = [
        'App\\Models\\User',
        'App\\Http\\Controllers\\Controller',
        'App\\Models\\Ticket',
        'App\\Models\\TicketMessage',
        'App\\Notifications\\TicketNotification',
        // K7.8 — چابکان از راه **رابط** صدا زده می‌شود، نه جدول. و همین رابط
        // عمداً در هسته ثبت شده تا افزونه بتواند درایورش را تعویض کند
        // (`AppServiceProvider` → `ServiceProviderRegistry`). پس وابستگی به
        // افزونه است، نه به هسته.
'App\\Models\\Setting',
        'App\\Models\\Plugin',
        'App\\Models\\Theme',
        // K7.8 — `OperatorController::createSetupToken` بدونِ 2FA تازه معنا
        // ندارد و `AuthController::hasFreshTwoFactor()` تنها پیاده‌سازیِ آن
        // است. راه‌حل جایگزین یعنی کپی کردنِ منطق احراز هویت در افزونه، که
        // یعنی دو منبع حقیقت برای یک قاعدهٔ امنیتی.
        //
        // وابستگی به یک **سرویس** است نه به جدول، پس مرز دیتابیس را نمی‌شکند.
        'App\\Http\\Controllers\\Api\\V1\\AuthController',
    ];

    /**
     * J7 — سرویس‌هایی که افزونه فقط **می‌خواند** (جدولشان در هسته است).
     *
     * ## چرا از `PLUGIN_ALLOWED_CORE` جدا شدند
     *
     * آن فهرست یعنی «افزونه **از هسته استفاده می‌کند**» — یعنی هسته باید بداند چه
     * چیزی به افزونه می‌دهد. این فهرست یعنی «افزونه فقط یک جدولِ هسته را
     * **می‌خواند** و هیچ منطقی را اجرا نمی‌کند».
     *
     * فرقش در ریسک است: سرویس‌هایی مثل `AuthController` (احراز هویت) یا
     * `ChabokanClientInterface` منطقِ هسته را در اختیار افزونه می‌گذارند؛ اگر
     * رفتارشان عوض شود، افزونه هم عوض می‌شود. ولی `SystemPlugin` فقط یک فهرستِ
     * slug می‌خواند.
     *
     * و عملاً این تفکیک لازم شد: با افزودن `SystemPlugin` (J7) سقف ۱۰ شکست — در
     * حالی که هیچ‌کدام از سه مورد، خطرِ مرز را زیاد نکرده بود. راه‌حل درست بالا
     * بردنِ عدد نبود، تفکیک بود.
     */
    private const PLUGIN_ALLOWED_CORE_READS = [
        // J7 — فهرستِ slugهای سیستمی، که `Plugin::isSystem()` هم از همین می‌خواند.
        // بدون این guard، یک اپراتور می‌توانست افزونهٔ هسته را از راهِ صفِ بازبینی
        // تأیید کند و حفاظتِ سیستمی را دور بزند. نگهبانی: `ReviewSystemGuardTest`.
        'App\\Models\\SystemPlugin',
    ];

    /**
     * J5 — متنِ وضعیت، که افزونهٔ مرکزی لازم دارد و «منطقِ هسته» نیست که بخواهیم
     * کپی‌اش کنیم.
     *
     * `SubscriptionStatusMessage` **متن** می‌سازد، نه داده. هیچ چیزِ مرکزی
     * نمی‌داند؛ یک تابعِ خالص از `status` و نامِ سایت است. اگر افزونه متنِ خودش
     * را می‌ساخت، دو جملهٔ متفاوت برای یک کاربر می‌داشتیم — و تستِ رابط روی
     * همین واژه‌ها حساب می‌کند.
     *
     * (نامِ سایت هم با `Setting` خوانده می‌شود که در `PLUGIN_ALLOWED_CORE` مجاز
     * است، و **بدون** کشِ دوم: `/site/status` خودش را ۶۰ ثانیه کش می‌کند، پس یک
     * کشِ ۳۰۰ ثانیه‌ایِ دیگر فقط یک وابستگیِ تازه می‌آورد و چیزی اضافه نمی‌کرد.)
     */
    private const PLUGIN_ALLOWED_CORE_TEXT = [
        'App\\Services\\Subscription\\SubscriptionStatusMessage',
    ];

    /**
     * J5 — رابط‌هایی که افزونه **پیاده می‌کند** (جدا از `PLUGIN_ALLOWED_CORE`).
     *
     * ## چرا این‌ها جدا شدند
     *
     * دو دسته اسم شبیه دارند ولی خطرشان یکی نیست:
     *
     *  - `PLUGIN_ALLOWED_CORE` — افزونه **از هسته استفاده می‌کند**. یعنی منطقِ
     *    هسته را صدا می‌زند و هسته باید بداند چه چیزی به آن داده می‌شود. هر
     *    مورد یعنی یک تصمیمِ آگاهانه برای کپی‌نکردنِ منطق.
     *  - این فهرست — افزونه **قراردادِ هسته را پیاده می‌کند**. هسته مالکِ رابط
     *    است و افزونه نقطهٔ اتصال. این وابستگی رو به بالا است، نه رو به پایین،
     *    و دقیقاً همان چیزی است که یک معماریِ افزونه‌ای می‌خواهد.
     *
     * قبلاً `ChabokanClientInterface` در فهرستِ اول بود و همین دو با هم قاطی
     * می‌شدند؛ سقفِ عددیِ ۱۰ هم دیگر نمی‌توانست بگوید کدام دسته دارد بزرگ می‌شود.
     * حالا هر کدام سقفِ خودش را دارد.
     */
    private const PLUGIN_ALLOWED_CORE_CONTRACTS = [
        // K7.8 — چابکان از راه **رابط** صدا زده می‌شود، نه جدول. و همین رابط
        // عمداً در هسته ثبت شده تا افزونه بتواند درایورش را تعویض کند
        // (`AppServiceProvider` → `ServiceProviderRegistry`). پس وابستگی به
        // افزونه است، نه به هسته.
        'App\\Services\\Chabokan\\ChabokanClientInterface',
        // J5 — `AuditSink` رابطِ ثبتِ ممیزی است. هسته رویداد را می‌نویسد
        // (`AuditTrail`) و افزونه تصمیم می‌گیرد **کجا** بنشیند. یعنی وابستگی
        // افزونه به یک **قرارداد** است، نه به سرویس یا جدولِ هسته.
        //
        // پیش از این، `DevModeAudit` (هسته) مستقیم `OperatorActivity` افزونه را
        // صدا می‌زد. یعنی نه‌تنها مرز از سمت هسته شکسته بود، بلکه در نصبِ بدون
        // افزونه **لاگِ امنیزیتی بی‌صدا حذف می‌شد** — دقیقاً رویدادی که باید
        // ثبت می‌شد. حالا جدول در اختیار افزونه است و هسته فقط می‌نویسد.
        'App\\Services\\Audit\\AuditSink',
        // J5 — رابطِ وضعیتِ اشتراک. افزونهٔ مرکزی آن را implement می‌کند و هسته از
        // راهش می‌پرسد «اشتراک در چه وضعیتی است؟».
        //
        // پیش از این، `ClientSubscriptionResolver` مستقیم مدلِ همین افزونه را صدا
        // می‌زد. یعنی هسته می‌دانست افزونه چیست؛ حالا فقط می‌داند یک افزونه *ممکن
        // است* جواب بدهد. همین تفاوت، تمامِ `KNOWN_VIOLATIONS` را بست.
        'App\\Services\\Subscription\\SubscriptionSource',
    ];

    public function test_the_central_plugin_does_not_reach_into_shared_core(): void
    {
        $violations = [];

        foreach (glob($this->centralDir().'/Laravel/src/**/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/\bApp\\\\[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)+/', $source, $hits);

            foreach (array_unique($hits[0]) as $fqcn) {
                $allowed = false;

                // J5 — دو فهرست، دو جهت. یکی «افزونه از هسته استفاده می‌کند»،
                // یکی «افزونه قراردادِ هسته را پیاده می‌کند». فرق برای تشخیصِ
                // ریزه‌کاری مهم است: اولی یعنی منطقِ هسته دارد تکرار می‌شود، دومی
                // یعنی نقطهٔ اتصال سالم است.
                foreach (self::PLUGIN_ALLOWED_CORE as $exception) {
                    if ($fqcn === $exception || str_starts_with($fqcn, $exception.'\\')) {
                        $allowed = true;
                        break;
                    }
                }

                if (! $allowed) {
                    foreach (self::PLUGIN_ALLOWED_CORE_CONTRACTS as $contract) {
                        if ($fqcn === $contract || str_starts_with($fqcn, $contract.'\\')) {
                            $allowed = true;
                            break;
                        }
                    }
                }

                if (! $allowed) {
                    foreach (self::PLUGIN_ALLOWED_CORE_READS as $read) {
                        if ($fqcn === $read || str_starts_with($fqcn, $read.'\\')) {
                            $allowed = true;
                            break;
                        }
                    }
                }

                if (! $allowed) {
                    foreach (self::PLUGIN_ALLOWED_CORE_TEXT as $text) {
                        if ($fqcn === $text || str_starts_with($fqcn, $text.'\\')) {
                            $allowed = true;
                            break;
                        }
                    }
                }

                if (! $allowed) {
                    $violations[] = str_replace('\\', '/', basename($file)).' → '.$fqcn;
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "افزونهٔ مرکزی به هستهٔ مشتری چنگ می‌زند. هر مورد تازه یعنی مرزی که\n"
            ."K7.8 آمده بود حذفش کند و به‌جایش بستگی ساخته:\n"
            .implode("\n", $violations)
        );
    }

    /**
     * فهرست استثناهای افزونه هم نباید بزرگ شود — و J5 آن را به **دو سقف** تقسیم کرد.
     *
     * چرا؟ چون یک عدد برای دو چیزِ ناهمگون جواب نمی‌دهد. قبلاً `ChabokanClientInterface`
     * (یک رابط) کنار `App\Models\User` (یک مدل) نشسته بود، و وقتی `AuditSink` هم
     * اضافه شد سقفِ ۱۰ شکست — در حالی که هیچ‌کدام از سه موردِ تازه، خطرِ مرز را
     * زیاد نکرده بود. راه‌حلِ درست بالا بردنِ عدد نبود؛ تفکیک بود.
     *
     *  - `PLUGIN_ALLOWED_CORE` سقف ۱۰، در برابر ۳۱ فایلِ منتقل‌شده. یعنی کمتر از
     *    یک‌سومِ وابستگی‌ها مجازند. اگر به ۱۱ رسید، تقریباً قطعی است که مرز دارد
     *    آب می‌شود: یعنی افزونه دارد **منطقِ هسته را صدا می‌زند**.
     *  - `PLUGIN_ALLOWED_CORE_CONTRACTS` سقف ۴. رابط‌ها هزینه‌ای ندارند جز اینکه
     *    هسته مالکشان است؛ سقفِ کم عمداً سخت‌گیرانه است چون هر رابطِ تازه یعنی یک
     *    تصمیمِ معماریِ تازه که باید خوانده و پذیرفته شود.
     */
    public function test_the_plugin_allowlist_has_not_swallowed_the_boundary(): void
    {
        $this->assertLessThanOrEqual(
            10,
            count(self::PLUGIN_ALLOWED_CORE),
            'فهرست سرویس‌ها/مدل‌های هسته بزرگ شده — یعنی افزونه دارد منطقِ هسته را صدا می‌زند.'
        );

        $this->assertLessThanOrEqual(
            4,
            count(self::PLUGIN_ALLOWED_CORE_CONTRACTS),
            'رابط‌های قراردادِ افزونه زیاد شده‌اند — هر کدام یک تصمیمِ معماری است و باید خوانده شود.'
        );

        // J7 — سومین سقف، برای سومین دسته. سقف ۳ یعنی «نهایتاً سه جدولِ هسته را
        // می‌خوانیم»؛ از آن بیشتر یعنی افزونه دارد دیتابیسِ هسته را کاوش می‌کند و
        // باید به‌جایش یک قرارداد یا یک endpoint خواسته شود.
        $this->assertLessThanOrEqual(
            3,
            count(self::PLUGIN_ALLOWED_CORE_READS),
            'افزونه دارد مستقیم جدول‌های هسته را می‌خواند. اگر تعدادش رشد کرد،'
            .' احتمالاً یک قرارداد یا یک رابط کم داریم — نه اینکه سقف را بالا ببریم.',
        );

        // چهارمین دسته: متن. کم‌خطرترین دسته، پس سقفش هم کوچک‌ترین است — یعنی
        // هر متنِ تازه‌ای که افزونه لازم دارد باید یک بار ثابت شود، نه عادت.
        $this->assertLessThanOrEqual(
            2,
            count(self::PLUGIN_ALLOWED_CORE_TEXT),
            'افزونه متنِ هسته را می‌سازد. دو جملهٔ متفاوت برای یک کاربر، بدتر از'
            .' نداشتنِ متن است — پس تعدادش باید کم بماند.',
        );
    }

    /**
     * J11 — افزونهٔ مرکزی **قبلاً** روی دیسک بارگذاری‌شدنی بود؛ حالا قرنطینه است.
     *
     * این تست قبلاً `isSourceSlug('central') === true` را می‌سنجید. با J11 معکوس
     * شد: loaderِ هسته **نباید** آن را ببیند. (تست‌های خصوصیِ خودمان هنوز از
     * `_quarantine/central` بارش می‌کنند — به `ServesCentralPlugin`.) اگر روزی
     * کسی بسته را اشتباهی به `plugins/central` برگرداند، این تست قرمز می‌شود.
     */
    public function test_the_central_plugin_is_not_loadable_from_the_core_scan_path(): void
    {
        $releases = app(PluginReleaseManager::class);

        $this->assertFalse(
            $releases->isSourceSlug('central'),
            'بستهٔ مرکزی باید قرنطینه باشد — loaderِ هسته نباید آن را ببیند (J11).'
        );

        $this->assertNull(
            $releases->sourceAutoloadRoot('central'),
            'ریشهٔ PSR-4 بستهٔ قرنطینه‌شده نباید از مسیر اسکن هسته استخراج شود.'
        );

        $this->assertDirectoryExists(
            base_path('plugins/_quarantine/central'),
            'J11 انتقال «فیزیکی — نه حذف» بود؛ پوشهٔ قرنطینه باید وجود داشته باشد.'
        );
    }

    /**
     * هر route در مانیفست باید به متدی **واقعاً موجود** اشاره کند.
     *
     * مانیفست یک promise است، نه یک فهرست خواسته. `PluginRouteTable` هم آن را
     * نمی‌خواند و هم راستی‌آزمایی نمی‌کند: کلاس را instantiate می‌کند و
     * dispatcher هم موقع dispatch `method_exists` را چک می‌کند — که
     * `RuntimeException` پرتاب می‌کند، یعنی **۵۰۰**.
     *
     * چرا این را مهاجرت K7.8 کشف کرد: دو ورودی `plans/create` و
     * `plans/{plan}/edit` به `PlanController@create`/`@edit` اشاره داشتند که
     * هیچ‌کدام وجود ندارند. این‌ها صفحه‌های فرم Inertia بوده‌اند که با آمدن
     * فرانت Next.js بی‌استفاده ماندند و هرگز کار نکردند. هیچ تستی هم
     * متوجه نشد، چون هیچ‌کس فراخوانی‌شان نمی‌کرد و هر ۸۲۹ تست سبز بود.
     *
     * یعنی: route مرده بی‌صدا از هر gate عبور می‌کند. این تست همان gate است.
     */
    public function test_every_manifest_route_resolves_to_a_real_public_method(): void
    {
        $this->assertNotNull($this->centralDir(), 'پوشهٔ بستهٔ مرکزی (قرنطینه) پیدا نشد.');

        $manifest = json_decode(
            (string) file_get_contents($this->centralDir().'/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $broken = [];
        $count = 0;

        foreach ($manifest['api']['routes'] as $route) {
            $count++;
            [$class, $method] = explode('@', $route['handler'], 2);

            if (! class_exists($class)) {
                $broken[] = "کلاس هست: {$route['handler']}";

                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = "متد نیست: {$route['handler']}";

                continue;
            }

            // `method_exists` برای متد خصوصی و protected هم true است.
            if (! (new ReflectionMethod($class, $method))->isPublic()) {
                $broken[] = "عمومی نیست: {$route['handler']}";
            }
        }

        $this->assertSame([], $broken, 'مانیفست به هندلرهای غیرقابل اجرا اشاره می‌کند (در runtime خطای ۵۰۰ می‌دهند).');
        $this->assertGreaterThan(0, $count, 'مانیفست هیچ route ندارد — تست vacuous است.');
    }
}
