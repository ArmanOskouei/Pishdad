<?php

namespace Tests\Feature;

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
    /** مسیرهایی که «مرکزی» هستند و باید از هستهٔ مشتری جدا بمانند. */
    private const CENTRAL_NAMESPACES = [
        'App\Http\Controllers\Api\Central',
        'App\Models\Central',
    ];

    /**
     * فایل‌هایی که **خودشان** بخشی از پنل مرکزی‌اند، پس ارجاعشان به مرکزی
     * طبیعی است. این‌ها بعداً به افزونه منتقل می‌شوند و آن‌وقت از این فهرست
     * بیرون می‌آیند.
     */
    private const ALLOWED = [
        'app/Http/Controllers/Api/Central',
        'app/Models/Central',
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

        foreach (glob(base_path('app/Models/Central/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\Central\\'.basename($file, '.php');

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
}
