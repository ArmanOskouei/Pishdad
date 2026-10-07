<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ManualBillingDriver;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\ServiceProviderRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\TestDouble\StubGatewayForTest;
use Tests\TestCase;

/**
 * K5.0 — نقطهٔ `core.service_provider`.
 *
 * هدف این تست‌ها این است که هسته بدون دانستن نام افزونه، پیاده‌سازی
 * interface را تعویض‌پذیر کند — و در عین حال **پیش‌فرض بسته بماند** وقتی
 * افزونه غایب، غیرفعال، تأییدنشده یا خراب است.
 */
class ServiceProviderRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ServiceProviderRegistry
    {
        return app(ServiceProviderRegistry::class);
    }

    /** @return array<string, mixed> */
    private function manifestWithBinding(string $interface, string $class): array
    {
        return [
            'name' => 'Test Plugin',
            'slug' => 'tp',
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
    }

    private function installPlugin(array $manifest, bool $active = true, string $review = 'approved'): Plugin
    {
        // `plugins.user_id` کلید خارجی واقعی است، پس کاربر باید واقعاً وجود
        // داشته باشد — عدد 1 در دیتابیس تست وجود ندارد.
        $user = User::query()->firstOrCreate(
            ['email' => 'plugin-owner@example.test'],
            ['name' => 'Plugin Owner', 'password' => Hash::make('secret')]
        );

        // فقط `review_status` ست می‌شود. `review_approved` یک accessor
        // محاسبه‌شده است، نه ستون — و همین رجیستری به آن تکیه می‌کند.
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

    /**
     * رجیستری و قرارداد **دو منبع حقیقت نباید** داشته باشند.
     *
     * اگر این‌ها جدا شوند، جایی پیش می‌آید که افزونه از اعتبارسنجی رد می‌شود
     * ولی رجیستری کلاسش را نمی‌شناسد (یا برعکس) — و هیچ پیام خطایی هم دیده
     * نمی‌شود، چون هر دو طرف سالم‌اند. این همان بی‌صدایی است که B30 را
     * ساخته.
     */
    public function test_registry_and_contract_agree_on_overridable_interfaces(): void
    {
        $this->assertSame(
            PluginPackageContract::OVERRIDABLE_INTERFACES,
            ServiceProviderRegistry::OVERRIDABLE,
            'فهرست interface‌های قابل‌جایگزینی در قرارداد و رجیستری یکی نیست.'
        );
    }

    public function test_registry_and_contract_agree_on_the_namespace_prefix(): void
    {
        $this->assertSame(
            PluginPackageContract::PLUGIN_NAMESPACE_PREFIX,
            ServiceProviderRegistry::PLUGIN_NAMESPACE_PREFIX
        );
    }

    /**
     * قرارداد باید **همان** چیزی را رد کند که رجیستری رد می‌کند، وگرنه نویسنده
     * بسته‌اش را سبز می‌بیند و بعد می‌فهمد بی‌اثر بوده.
     */
    public function test_contract_rejects_what_the_registry_rejects(): void
    {
        $bad = PluginPackageContract::validateDeclaration('core.service_provider', [
            'interface' => Container::class,
            'class' => 'Pishdad\\Plugins\\Evil\\Thing',
        ]);
        $codes = array_column($bad, 'code');

        $this->assertContains('core.service_provider.interface_not_overridable', $codes, 'رابط غیرقابل‌override باید در مرحلهٔ اعتبارسنجی رد شود.');

        $bad = PluginPackageContract::validateDeclaration('core.service_provider', [
            'interface' => PaymentGatewayInterface::class,
            'class' => 'App\\Services\\Billing\\ManualBillingDriver',
        ]);
        $this->assertContains('core.service_provider.bad_plugin_namespace', array_column($bad, 'code'));

        $good = PluginPackageContract::validateDeclaration('core.service_provider', [
            'interface' => PaymentGatewayInterface::class,
            'class' => StubGatewayForTest::class,
        ]);
        $this->assertSame([], $good, 'اعلان درست نباید خطا بدهد.');
    }

    public function test_no_plugins_means_core_defaults_everywhere(): void
    {
        $bindings = $this->registry()->declaredBindings();

        $this->assertSame([], $bindings, 'با هیچ افزونه‌ای نباید هیچ override‌ای وجود داشته باشد.');
        $this->assertFalse($this->registry()->hasOverride(PaymentGatewayInterface::class));
    }

    public function test_container_still_resolves_core_defaults_with_no_plugins(): void
    {
        $this->assertInstanceOf(
            PaymentGatewayInterface::class,
            app(PaymentGatewayInterface::class),
            'بدون افزونه باید درایور پیش‌فرض هسته برگردد.'
        );
    }

    public function test_active_approved_plugin_can_override_a_core_interface(): void
    {
        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            StubGatewayForTest::class
        ));

        $this->assertTrue($this->registry()->hasOverride(PaymentGatewayInterface::class));
        $this->assertSame(StubGatewayForTest::class, $this->registry()->resolveOverride(PaymentGatewayInterface::class));
    }

    /**
     * تست تعیین‌کننده: فقط دیدن آرایه کافی نیست، باید ثابت شود container
     * واقعاً **پیاده‌سازی افزونه** را می‌سازد و نه پیش‌فرض هسته.
     *
     * binding از قبل در `AppServiceProvider::register()` ثبت شده، پس فقط
     * کافی است singleton را از container بخواهیم.
     */
    public function test_container_actually_returns_the_plugin_implementation(): void
    {
        $gateway = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(
            ManualBillingDriver::class,
            $gateway,
            'پیش از نصب افزونه باید درایور پیش‌فرض هسته برگردد.'
        );

        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            StubGatewayForTest::class
        ));

        // binding باید دوباره ثبت شود تا وضعیت تازهٔ افزونه را ببیند.
        $this->registry()->bind(PaymentGatewayInterface::class, fn () => new ManualBillingDriver);
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        $this->assertInstanceOf(
            StubGatewayForTest::class,
            app(PaymentGatewayInterface::class),
            'با افزونهٔ فعال و تأییدشده، container باید پیاده‌سازی افزونه را بدهد.'
        );
        $this->assertSame('stub-for-test', app(PaymentGatewayInterface::class)->gatewayName());
    }

    /**
     * بستهٔ افزونه خراب نباید سایت را از کار بیندازد. کلاس اعلام‌شده در ریشهٔ
     * درست است ولی اصلاً وجود ندارد، پس باید به پیش‌فرض هسته برگردد.
     */
    public function test_broken_plugin_falls_back_to_core_default(): void
    {
        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            'Pishdad\\Plugins\\Ghost\\Missing'
        ));

        $this->registry()->bind(PaymentGatewayInterface::class, fn () => new ManualBillingDriver);
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        $this->assertInstanceOf(
            ManualBillingDriver::class,
            app(PaymentGatewayInterface::class),
            'افزونهٔ خراب باید به پیاده‌سازی پیش‌فرض هسته برگردد.'
        );
    }

    public function test_inactive_plugin_cannot_override(): void
    {
        $this->installPlugin(
            $this->manifestWithBinding(PaymentGatewayInterface::class, StubGatewayForTest::class),
            active: false
        );

        $this->assertFalse($this->registry()->hasOverride(PaymentGatewayInterface::class));
    }

    /**
     * مهم‌ترین قاعده: افزونهٔ «فعال ولی تأییدنشده» نباید بتواند درگاه پرداخت
     * را عوض کند. `activeManifests()` این را چک نمی‌کند، پس رجیستری خودش
     * سخت‌گیرانه‌تر است.
     */
    public function test_unapproved_plugin_cannot_override_even_though_it_is_active(): void
    {
        foreach (['unverified', 'pending', 'rejected'] as $review) {
            // `Plugin` نرم‌افزاری حذف می‌شود، پس `delete()` کافی نیست و slug
            // تکراری به خطای یکتایی می‌خورد. رکورد قبلی واقعاً پاک می‌شود.
            Plugin::query()->forceDelete();

            $manifest = $this->manifestWithBinding(
                PaymentGatewayInterface::class,
                StubGatewayForTest::class
            );
            $manifest['slug'] = 'tp-'.$review;

            $this->installPlugin($manifest, review: $review);

            $this->assertFalse(
                $this->registry()->hasOverride(PaymentGatewayInterface::class),
                "افزونه با review_status={$review} نباید بتواند interface هسته را جایگزین کند."
            );
        }
    }

    public function test_interface_outside_the_allowlist_is_rejected(): void
    {
        $this->installPlugin($this->manifestWithBinding(
            Container::class,
            StubGatewayForTest::class
        ));

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_class_outside_the_mandatory_namespace_is_rejected(): void
    {
        // کلاسی که واقعاً وجود دارد ولی ریشهٔ اجباری را ندارد — یعنی مال
        // هسته است و افزونه نباید بتواند خودش را جای افزونه جا بزند.
        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            ManualBillingDriver::class
        ));

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_class_that_does_not_exist_is_rejected(): void
    {
        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            'Pishdad\\Plugins\\Ghost\\Nope'
        ));

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_class_that_does_not_implement_the_interface_is_rejected(): void
    {
        $this->installPlugin($this->manifestWithBinding(
            PaymentGatewayInterface::class,
            ServiceProviderRegistry::class
        ));

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_malformed_declaration_is_ignored(): void
    {
        $this->installPlugin([
            'name' => 'Bad Plugin',
            'slug' => 'bp',
            'version' => '1.0.0',
            'panel' => [
                'extensions' => [
                    ['point' => 'core.service_provider'],
                    ['point' => 'core.service_provider', 'interface' => PaymentGatewayInterface::class],
                    'not-an-array',
                ],
            ],
        ]);

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_manifest_without_panel_extensions_is_safe(): void
    {
        $this->installPlugin(['name' => 'Bare', 'slug' => 'bare', 'version' => '1.0.0']);

        $this->assertSame([], $this->registry()->declaredBindings());
    }

    public function test_bind_falls_back_to_the_default_callable(): void
    {
        $called = false;

        $this->registry()->bind(PaymentGatewayInterface::class, function () use (&$called) {
            $called = true;

            return new ManualBillingDriver;
        });

        $this->assertInstanceOf(PaymentGatewayInterface::class, app(PaymentGatewayInterface::class));
        $this->assertTrue($called, 'بدون افزونه باید تابع پیش‌فرض صدا زده شود.');
    }
}
