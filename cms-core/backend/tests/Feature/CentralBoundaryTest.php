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
    private const ALLOWED = [
        'app/Http/Middleware/EnsureCentralOperator.php',
        'app/Services/Subscription/ClientSubscriptionResolver.php',
        // K4.5 — تنها نقطهٔ تماس هسته با جدول ردپای مرکزی. عمداً یک adapter مرزی
        // است: سرویس‌های دیگر فقط `DevModeAudit` را می‌شناسند و مستقیم به
        // مرکزی ارجاع نمی‌دهند. وقتی K7.8 مرکز را به افزونه منتقل کرد، **فقط همین
        // یک ورودی** باید از این فهرست بیرون بیاید.
        'app/Services/Plugins/DevModeAudit.php',
    ];

    /**
     * نقض‌های **فعلی** — فهرست عمداً خالی نیست.
     *
     * K7.1 کارش این بود که نقض‌ها را *پیدا* کند و جلوی افزودنشان را بگیرد؛
     * حذفشان کار K7.2 تا K7.8 است. این فهرست نقض‌های شناخته‌شده است تا
     * مهاجرت پیش برود و بعد از آن باید **خالی شود**.
     *
     * @var list<string>
     */
    private const KNOWN_VIOLATIONS = [
        'app/Console/Commands/BillingPurge.php',
        'app/Console/Commands/BillingRemind.php',
        'app/Console/Commands/BillingSuspend.php',
        'app/Http/Controllers/Api/Internal/HeartbeatController.php',
        'app/Http/Controllers/Api/V1/Admin/DashboardController.php',
        'app/Http/Controllers/Api/V1/Admin/ProfileController.php',
        'app/Http/Controllers/Api/V1/Admin/SubscriptionController.php',
        'app/Http/Controllers/Api/V1/SubscriptionStatusController.php',
        'app/Http/Controllers/Api/Webhooks/PaymentWebhookController.php',
        'app/Services/Billing/InvoiceService.php',
    ];

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

        foreach (glob(base_path('plugins/central/Laravel/src/Models/*.php')) ?: [] as $file) {
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
        'App\\Services\\Chabokan\\ChabokanClientInterface',
        // K7.8 — جدول `settings` و مدل‌های `Plugin`/`Theme` در هسته می‌مانند
        // (K7.12: فقط جداول `central_*` ثابت ماندند، نه همه‌چیز). صفحهٔ
        // gateway-settings و صف بازبینی هر دو به همین‌ها نیاز دارند.
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

    public function test_the_central_plugin_does_not_reach_into_shared_core(): void
    {
        $violations = [];

        foreach (glob(base_path('plugins/central/Laravel/src/**/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/\bApp\\\\[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)+/', $source, $hits);

            foreach (array_unique($hits[0]) as $fqcn) {
                $allowed = false;
                foreach (self::PLUGIN_ALLOWED_CORE as $exception) {
                    if ($fqcn === $exception || str_starts_with($fqcn, $exception.'\\')) {
                        $allowed = true;
                        break;
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
     * فهرست استثناهای افزونه هم نباید بزرگ شود.
     *
     * سقف ۱۰ است در برابر ۳۱ فایلِ منتقل‌شده. یعنی **کمتر از یک‌سوم** وابستگی‌ها
     * مجازند — و اگر روزی به ۱۱ رسید، تقریباً قطعی است که مرز دارد آب می‌شود،
     * نه اینکه فقط یک موردِ مجازِ تازه اضافه شده باشد.
     */
    public function test_the_plugin_allowlist_has_not_swallowed_the_boundary(): void
    {
        $this->assertLessThanOrEqual(
            10,
            count(self::PLUGIN_ALLOWED_CORE),
            'فهرست استثناهای افزونه بزرگ شده — یعنی مرز عملاً حذف شده است.'
        );
    }

    /**
     * افزونهٔ مرکزی باید **واقعاً** روی دیسک باشد و loader آن را ببیند.
     *
     * بدون این تست، یک انتقال ناقص که `plugins/central/` را جا گذاشته بود
     * همهٔ تست‌های بالا را سبز نگه می‌داشت — چون هیچ‌کدام بارگذاری را
     * نمی‌سنجیدند. ولی هر request می‌افتاد و پنل مرکزی ۴۰۴ می‌داد.
     */
    public function test_the_central_plugin_is_loadable_from_source(): void
    {
        $releases = app(PluginReleaseManager::class);

        $this->assertTrue(
            $releases->isSourceSlug('central'),
            'بستهٔ مرکزی زیر plugins/central/Laravel/src پیدا نشد — loader آن را نمی‌بیند.'
        );

        $this->assertNotNull(
            $releases->sourceAutoloadRoot('central'),
            'ریشهٔ PSR-4 بستهٔ مرکزی قابل استخراج نیست.'
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
        $releases = app(PluginReleaseManager::class);
        $root = $releases->sourceAutoloadRoot('central');

        $this->assertNotNull($root, 'ریشهٔ PSR-4 بستهٔ مرکزی پیدا نشد.');

        $manifest = json_decode(
            (string) file_get_contents($releases->sourceDir('central').'/manifest.json'),
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
