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
        'App\\Http\\Controllers\\Api\\Central',
        'App\\Models\\Central',
        'App\\Services\\Central',
    ];

    /**
     * فایل‌هایی که **خودشان** بخشی از پنل مرکزی‌اند، پس ارجاعشان به مرکزی
     * طبیعی است. این‌ها بعداً به افزونه منتقل می‌شوند و آن‌وقت از این فهرست
     * بیرون می‌آیند.
     */
    private const ALLOWED = [
        'app/Http/Controllers/Api/Central',
        'app/Models/Central',
        'app/Services/Central',
        'app/Http/Middleware/EnsureCentralOperator.php',
        'app/Services/Subscription/ClientSubscriptionResolver.php',
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
     */
    public function test_no_core_file_queries_a_central_table_directly(): void
    {
        $violations = [];
        $tables = [
            'central_subscriptions', 'central_clients', 'central_invoices',
            'central_plans', 'central_payments', 'central_billing_events',
            'central_reviews', 'central_updates', 'central_kill_switches',
            'central_chabokan_deployments', 'central_hardware_units',
        ];

        foreach ($this->coreFiles() as $path) {
            if ($this->isCentral($path)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            foreach ($tables as $table) {
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
