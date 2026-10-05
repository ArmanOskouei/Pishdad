<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\ServiceProviderRegistry;
use App\Services\Subscription\ClientSubscriptionResolver;
use App\Services\Subscription\NullSubscriptionSource;
use App\Services\Subscription\SubscriptionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\TestDouble\StubSubscriptionSourceForTest;
use Tests\TestCase;

/**
 * K7.2/K7.3 — تستِ سرتاسریِ «افزونه منبعِ وضعیتِ اشتراک را ثبت می‌کند و هسته
 * همان را resolve می‌کند».
 *
 * ## چرا این تست، «VERIFY-or-close» بود و نه یک تستِ تازه
 *
 * `K7.2` (رابط + پیش‌فرض) و `K7.4` (حذفِ ۳۰ روز) پیاده شده بودند، ولی `K7.3`
 * — «ثبتِ پیاده‌سازی از سمتِ پلاگین» — هیچ مسیرِ کدی نداشت:
 * `ClientSubscriptionResolver::sources()` افزونهٔ مرکزی را با یک
 * `class_exists` هاردکد صدا می‌زد و `SubscriptionSource` اصلاً در فهرست
 * `core.service_provider` نبود. یعنی یک بستهٔ سالم و فعال می‌توانست اعلام کند و
 * هیچ اتفاقی نمی‌افتاد — همان «نصب شد ولی بی‌اثر بود».
 *
 * ## چه چیزی اینجا **نمی‌آزماید**
 *
 * این تست یک بستهٔ واقعی روی دیسک نمی‌سازد. آن بخش را `PluginRouterTest` و
 * `CentralPluginRouteTest` می‌سنجند (بارگذاریِ PSR-4 و dispatch). اینجا فقط
 * **نقطهٔ اتصال** سنجیده می‌شود: اعلان در مانیفست ⇒ رجیستری ⇒ container ⇒
 * مصرف‌کنندهٔ واقعی در هسته. جدا کردنشان یعنی وقتی یکی قرمز شد، معلوم است کدام
 * لایه شکسته — وگرنه یک تستِ بزرگ فقط می‌گوید «یک‌جا خراب است».
 */
class PluginSubscriptionSourceBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_plugin_the_core_answers_active(): void
    {
        $this->assertFalse(app(ServiceProviderRegistry::class)->hasOverride(SubscriptionSource::class));

        // پیش‌فرضِ K7.2: نبودِ افزونه یعنی «سایتِ فعالِ بی‌اشتراک»، نه ۵۰۰ و نه
        // «معلق».
        $this->assertSame('active', ClientSubscriptionResolver::publicStatus()['status']);
    }

    public function test_a_plugin_may_declare_subscription_source(): void
    {
        // قرارداد باید این رابط را بپذیرد، وگرنه نویسندهٔ بسته در مرحلهٔ اعتبارسنجی
        // رد می‌شود و اصلاً به رجیستری نمی‌رسد.
        $errors = PluginPackageContract::validateDeclaration('core.service_provider', [
            'interface' => SubscriptionSource::class,
            'class' => StubSubscriptionSourceForTest::class,
        ]);

        $this->assertSame([], $errors, 'اعلانِ معتبرِ SubscriptionSource نباید خطا بدهد.');
    }

    public function test_the_registry_reports_the_plugin_implementation(): void
    {
        $this->installPlugin(SubscriptionSource::class, StubSubscriptionSourceForTest::class);

        $this->assertTrue(app(ServiceProviderRegistry::class)->hasOverride(SubscriptionSource::class));
        $this->assertSame(
            StubSubscriptionSourceForTest::class,
            app(ServiceProviderRegistry::class)->resolveOverride(SubscriptionSource::class)
        );
    }

    /**
     * ⭐ تستِ سرتاسریِ K7.3 — مصرف‌کنندهٔ واقعی، نه فقط رجیستری.
     *
     * پیش از این، همین وضعیت `active` می‌داد چون رجیستری اصلاً در مسیرِ
     * `sources()` نبود. یعنی تست‌های بالا می‌توانستند همه سبز باشند و محصول
     * همچنان بی‌اثر.
     */
    public function test_the_core_resolves_the_plugin_declared_source(): void
    {
        $this->installPlugin(SubscriptionSource::class, StubSubscriptionSourceForTest::class);

        $status = ClientSubscriptionResolver::publicStatus();

        $this->assertSame('suspended', $status['status']);
        $this->assertSame('وضعیت از افزونه آمد (تست).', $status['message']);
    }

    public function test_the_container_binding_also_returns_the_plugin_source(): void
    {
        $this->installPlugin(SubscriptionSource::class, StubSubscriptionSourceForTest::class);

        $registry = app(ServiceProviderRegistry::class);
        $registry->bind(SubscriptionSource::class, fn () => new NullSubscriptionSource);
        $this->app->forgetInstance(SubscriptionSource::class);

        $this->assertInstanceOf(
            StubSubscriptionSourceForTest::class,
            app(SubscriptionSource::class),
            'bindingِ رجیستری باید پیاده‌سازیِ افزونه را بدهد، نه پیش‌فرضِ هسته.'
        );
    }

    public function test_an_unapproved_plugin_cannot_become_the_source(): void
    {
        $this->installPlugin(
            SubscriptionSource::class,
            StubSubscriptionSourceForTest::class,
            review: Plugin::REVIEW_PENDING
        );

        $this->assertFalse(app(ServiceProviderRegistry::class)->hasOverride(SubscriptionSource::class));
        $this->assertSame('active', ClientSubscriptionResolver::publicStatus()['status']);
    }

    public function test_an_inactive_plugin_cannot_become_the_source(): void
    {
        $this->installPlugin(
            SubscriptionSource::class,
            StubSubscriptionSourceForTest::class,
            active: false
        );

        $this->assertSame('active', ClientSubscriptionResolver::publicStatus()['status']);
    }

    public function test_a_class_outside_the_plugin_namespace_cannot_become_the_source(): void
    {
        $this->installPlugin(SubscriptionSource::class, NullSubscriptionSource::class);

        $this->assertFalse(app(ServiceProviderRegistry::class)->hasOverride(SubscriptionSource::class));
        $this->assertSame('active', ClientSubscriptionResolver::publicStatus()['status']);
    }

    private function installPlugin(
        string $interface,
        string $class,
        bool $active = true,
        string $review = Plugin::REVIEW_APPROVED,
    ): Plugin {
        // `plugins.user_id` کلید خارجی واقعی است، پس کاربر باید واقعاً وجود
        // داشته باشد — عدد 1 در دیتابیس تست وجود ندارد.
        $user = User::query()->firstOrCreate(
            ['email' => 'source-owner@example.test'],
            ['name' => 'Source Owner', 'password' => Hash::make('secret')]
        );

        $manifest = [
            'name' => 'Source Plugin',
            'slug' => 'sourceplug',
            'version' => '1.0.0',
            'panel' => [
                'extensions' => [
                    [
                        'point' => 'core.service_provider',
                        'interface' => $interface,
                        'class' => $class,
                    ],
                ],
            ],
        ];

        return Plugin::query()->create([
            'user_id' => $user->id,
            'name' => $manifest['name'],
            'slug' => $manifest['slug'],
            'version' => $manifest['version'],
            'manifest' => $manifest,
            'active' => $active,
            'review_status' => $review,
        ]);
    }
}
