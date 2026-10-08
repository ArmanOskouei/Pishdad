<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * J13 — «PushSubscription» یعنی **وب‌پوش/اعلان مرورگر**، نه فروش اشتراک.
 *
 * ## تلهٔ نام که این تست می‌گیرد
 *
 * J11/J12 پوشه/فایل‌هایی با «subscription» در نام را قرنطینه می‌کنند. اگر کسی
 * با یک `grep -i subscription` کورکورانه عمل کند، `PushSubscription` را هم با
 * خودش می‌برد — و یک قابلیتِ عمومیِ کاملاً بی‌ربط (اعلان مرورگر) بی‌صدا می‌مُرد.
 *
 * این تست ثابت می‌کند اجزای وب‌پوش دست‌نخورده‌اند و به دامنهٔ اشتراک/مرکزی گره
 * نخورده‌اند:
 *  - مدل و کنترلر و سرویس‌ها وجود دارند،
 *  - مسیرهای `/api/v1/push/*` ثبت‌اند،
 *  - جدولش `push_subscriptions` است (نه `subscription_*`)،
 *  - در قرنطینه نیست.
 */
class PushSubscriptionSurvivesQuarantineTest extends TestCase
{
    public function test_the_push_subscription_model_and_controller_still_exist(): void
    {
        $this->assertTrue(class_exists(PushSubscription::class), 'مدل PushSubscription حذف شده.');
        $this->assertTrue(class_exists(PushSubscriptionController::class), 'کنترلر PushSubscription حذف شده.');
    }

    public function test_the_push_subscription_table_is_not_a_billing_table(): void
    {
        $this->assertSame('push_subscriptions', (new PushSubscription)->getTable());
    }

    public function test_the_web_push_migration_and_services_are_present(): void
    {
        $this->assertFileExists(
            base_path('database/migrations/2026_10_20_000001_create_push_subscriptions_table.php'),
            'مهاجرت جدول وب‌پوش گم شده.',
        );

        foreach ([
            'app/Services/Push/PushSender.php',
            'app/Services/Push/VapidKeys.php',
            'app/Services/Push/PushEncryptor.php',
        ] as $file) {
            $this->assertFileExists(base_path($file), "سرویس وب‌پوش گم شده: {$file}");
        }
    }

    public function test_the_push_routes_are_still_registered(): void
    {
        $uris = array_map(
            static fn ($route): string => $route->uri(),
            Route::getRoutes()->getRoutes(),
        );

        foreach ([
            'api/v1/push/public-key',
            'api/v1/push/subscribe',
            'api/v1/push/unsubscribe',
            'api/v1/push/subscriptions',
            'api/v1/push/subscribe/auth',
        ] as $uri) {
            $this->assertContains(
                $uri,
                $uris,
                "مسیر وب‌پوش «{$uri}» ثبت نشده — یعنی قرنطینه آن را با خودش برده.",
            );
        }
    }

    public function test_push_is_not_in_the_quarantine_directory(): void
    {
        $this->assertDirectoryDoesNotExist(
            base_path('plugins/_quarantine/push'),
            'وب‌پوش ربطی به اشتراک/مرکزی ندارد و نباید قرنطینه شود (J13).',
        );
    }

    /**
     * جداییِ نام‌ها: لایهٔ اشتراک نباید هیچ ارجاعی به PushSubscription داشته باشد،
     * و وب‌پوش نباید در `Services/Subscription` باشد.
     *
     * E70: از زمان حذف پنل مرکزی (E27) پوشهٔ `Services/Subscription` دیگر وجود
     * ندارد، پس حلقهٔ زیر صفر بار می‌چرخید و تست بدون هیچ assertion می‌ماند
     * (risky). assert صریحِ نبودِ پوشه هم جدایی را قفل می‌کند و هم risky را.
     */
    public function test_the_subscription_layer_does_not_absorb_push(): void
    {
        $this->assertDirectoryDoesNotExist(
            base_path('app/Services/Subscription'),
            'لایهٔ اشتراک با E27 حذف شد؛ بازگشتش بدون دلیل ممنوع.',
        );

        foreach (glob(base_path('app/Services/Subscription/*.php')) ?: [] as $file) {
            $this->assertStringNotContainsString(
                'PushSubscription',
                (string) file_get_contents($file),
                basename($file).' به PushSubscription ارجاع می‌دهد — دو دامنهٔ متفاوت‌اند.',
            );
        }

        $this->assertTrue(
            class_exists(PushSubscription::class),
            'وب‌پوش باید مستقل از لایهٔ اشتراک زنده بماند.',
        );
    }
}
