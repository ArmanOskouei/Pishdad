<?php

namespace App\Services\Plugins;

use App\Models\Plugin;
use App\Search\SearchableProvider;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * نقطهٔ `core.service_provider` — رجیستری binding های هسته (K5.0).
 *
 * این کلاس همان چیزی است که اجازه می‌دهد یک پلاگین **پیاده‌سازی** یک
 * interface هسته را جایگزین کند، بدون اینکه هسته حتی نام پلاگین را بداند.
 * تا پیش از آن، `PaymentGatewayInterface` در `AppServiceProvider` به‌صورت
 * singleton هاردکد به درایور Stub وصل بود و هیچ راهی برای تعویضش نبود.
 *
 * چرا binding در سرویس است و نه در provider:
 * پلاگین‌ها در `AppServiceProvider::register()` بارگذاری نمی‌شوند (K5.3
 * هنوز نیامده) و اساساً نباید بشوند — بارگذاری همهٔ کد پلاگین در هر
 * درخواست گران است. پس binding فقط **داده** است و این رجیستری با یک
 * binding می‌ماند تا لحظه‌ای که واقعاً لازم شد.
 *
 * سه قاعده که عمداً fail-closed هستند:
 *
 * 1. **پیش‌فرض = هسته.** نبودِ بستهٔ فعال یعنی پیاده‌سازی پیش‌فرض هسته.
 * 2. **فقط افزونهٔ فعال و تأییدشده.** بستهٔ `unverified` یا غیرفعال حق
 *    جایگزینی ندارد.
 * 3. **کلاس ناامن ⇒ پیش‌فرض.** اگر کلاس اعلام‌شده وجود نداشته باشد، namespace
 *    اشتباه داشته باشد، یا interface را پیاده نکند، **خطا داده نمی‌شود** —
 *    به پیاده‌سازی پیش‌فرض برمی‌گردیم و لاگ می‌کنیم. یک پلاگین خراب نباید
 *    کل سایت را از کار بیندازد.
 */
class ServiceProviderRegistry
{
    /**
     * interface هایی که افزونه مجاز است جایگزینشان کند.
     *
     * ⚠️ این فهرست از قرارداد می‌آید، نه از اینجا. دو تعریف جدا یعنی جایی
     * هست که افزونه تأیید می‌شود ولی رجیستری ردش می‌کند (یا برعکس) و
     * هیچ‌کس نمی‌فهمد چرا. منبع واحد: `PluginPackageContract`.
     *
     * @var list<class-string>
     */
    public const OVERRIDABLE = PluginPackageContract::OVERRIDABLE_INTERFACES;

    /**
     * namespace اجباری کلاس افزونه — هم‌ریشه با قاعدهٔ K5.3.
     * کلاسی که خارج از این ریشه باشد، مال هسته است نه افزونه و رد می‌شود.
     */
    public const PLUGIN_NAMESPACE_PREFIX = PluginPackageContract::PLUGIN_NAMESPACE_PREFIX;

    public function __construct(private readonly Container $app) {}

    /**
     * binding های اعلام‌شده توسط افزونه‌های فعال.
     *
     * ⚠️ این متد **دیتابیس می‌خواند**. در مرحلهٔ `register()` هر برنامهٔ
     * لاراول صدا زده می‌شود و آن موقع ممکن است schema هنوز مهاجرت نرفته
     * باشد — صدا زدن مستقیمش باعث می‌شد `php artisan migrate` خودش کرش کند.
     * برای همین `bind()` وضعیت را lazy می‌خواند و این متد فقط ابزار
     * بازرسی است.
     *
     * @return array<class-string, class-string> interface => کلاس افزونه
     */
    public function declaredBindings(): array
    {
        try {
            $plugins = $this->activePluginManifests();
        } catch (\Throwable $e) {
            // نبودِ جدول یا مهاجرت‌نرفته یعنی «افزونه‌ای نیست» — نه خطا.
            Log::notice('plugin.core_service_provider.registry_unavailable', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $bindings = [];

        foreach ($plugins as $slug => $manifest) {
            foreach ($this->serviceProviderDeclarations($manifest) as $decl) {
                $interface = $decl['interface'] ?? null;
                $class = $decl['class'] ?? null;

                if (! is_string($interface) || ! is_string($class)) {
                    $this->reject($slug, 'اعلان ناقص است (interface یا class ندارد).');

                    continue;
                }

                if (! $this->judge($slug, $interface, $class)) {
                    continue;
                }

                $bindings[$interface] = $class;
            }
        }

        return $bindings;
    }

    /**
     * ⭐ I7 — providerهای جستجوی افزونه: **افزایشی**، نه جایگزینی.
     *
     * `declaredBindings()` یک تک‌خانه است: هر که زودتر بیاید، مالِ بقیه می‌شود.
     * برای درگاه پرداخت همین درست است، ولی برای جستو غلط — دو افزونه باید
     * هر دو در نتایج باشند. پس اینجا فهرست برمی‌گردد و مصرف‌کننده
     * (`App\Search\SearchProviderRegistry`) همه را با هم اجرا می‌کند.
     *
     * عمداً همهٔ guardهای `judge()` را از همان مسیر می‌گیرد (فعال + تأییدشده،
     * ریشهٔ اجباری، کلاس باید وجود داشته باشد و رابط را پیاده کند) تا «سخت‌گیری»
     * یک جا تعریف شود؛ اگر اینجا نسخهٔ سبک‌تری از داوری می‌نوشتیم، یک روز
     * افزونه‌ای می‌توانست از راه جستو هر چیزی را جایگزین کند.
     *
     * @return list<class-string>
     */
    public function declaredSearchProviders(): array
    {
        try {
            $plugins = $this->activePluginManifests();
        } catch (\Throwable $e) {
            Log::notice('plugin.core_service_provider.registry_unavailable', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];

        foreach ($plugins as $slug => $manifest) {
            foreach ($this->serviceProviderDeclarations($manifest) as $decl) {
                if (($decl['interface'] ?? null) !== SearchableProvider::class) {
                    continue;
                }

                $class = $decl['class'] ?? null;
                if (! is_string($class)) {
                    $this->reject($slug, 'اعلان provider جستو ناقص است (class ندارد).');

                    continue;
                }

                if ($this->judge($slug, SearchableProvider::class, $class)) {
                    $out[] = $class;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * آیا افزونه این interface را جایگزین کرده؟
     */
    public function hasOverride(string $interface): bool
    {
        return array_key_exists($interface, $this->declaredBindings());
    }

    /**
     * کلاس جایگزین افزونه، یا null اگر هسته باید پیش‌فرضش را بگیرد.
     *
     * @return class-string|null
     */
    public function resolveOverride(string $interface): ?string
    {
        return $this->declaredBindings()[$interface] ?? null;
    }

    /**
     * ساخت binding قابل استفاده در container.
     *
     * `default` تابع سازندهٔ پیاده‌سازی هسته است. اگر افزونه چیزی اعلام
     * نکرده باشد، همان اجرا می‌شود — پس فراخوانی این متد همیشه کار می‌کند و
     * caller لازم نیست fallback را خودش بنویسد.
     */
    public function bind(string $interface, callable $default): void
    {
        // وضعیت عمداً lazy خوانده می‌شود، نه در لحظهٔ `bind()`. دلیلش در
        // `declaredBindings()` توضیح داده شده.
        $this->app->singleton($interface, function ($app) use ($interface, $default) {
            $override = null;
            try {
                $override = $this->resolveOverride($interface);
            } catch (\Throwable $e) {
                Log::error('plugin.core_service_provider.lookup_failed', [
                    'interface' => $interface,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($override === null) {
                return $default();
            }

            try {
                $instance = $app->make($override);

                if (! $instance instanceof $interface) {
                    throw new \RuntimeException("کلاس {$override}، {$interface} را پیاده نمی‌کند.");
                }

                Log::info('plugin.core_service_provider.override_applied', [
                    'interface' => $interface,
                    'class' => $override,
                ]);

                return $instance;
            } catch (\Throwable $e) {
                // بستهٔ افزونه خراب است: سایت باید با پیاده‌سازی هسته بالا بیاید.
                Log::error('plugin.core_service_provider.override_failed', [
                    'interface' => $interface,
                    'class' => $override,
                    'error' => $e->getMessage(),
                ]);

                return $default();
            }
        });
    }

    /**
     * اعلان‌های `core.service_provider` از یک مانیفست.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceProviderDeclarations(array $manifest): array
    {
        $points = $manifest['panel']['extensions'] ?? $manifest['extensions'] ?? [];
        if (! is_array($points)) {
            return [];
        }

        // `panel.extensions` می‌تواند آرایهٔ اعلان‌ها باشد یا کلید=>اعلان.
        if (array_is_list($points) === false) {
            $points = array_values(array_filter(
                $points,
                fn ($p) => is_array($p) && ($p['point'] ?? null) === 'core.service_provider'
            ));
        }

        $out = [];
        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }
            if (($point['point'] ?? null) !== 'core.service_provider') {
                continue;
            }
            $out[] = $point;
        }

        return $out;
    }

    /**
     * داوری یک اعلان: آیا سالم است یا نه.
     *
     * هر رد شدن لاگ می‌شود، چون اعلان ناسالمِ بی‌سروصدا بدترین حالت است —
     * نویسنده فکر می‌کند ثبت شده ولی هیچ اتفاقی نمی‌افتد.
     */
    private function judge(string $slug, string $interface, string $class): bool
    {
        if (! in_array($interface, self::OVERRIDABLE, true)) {
            $this->reject($slug, "«{$interface}» در فهرست قابل‌جایگزینی هسته نیست.");

            return false;
        }

        if (! str_starts_with($class, self::PLUGIN_NAMESPACE_PREFIX)) {
            $this->reject($slug, "کلاس «{$class}» خارج از ریشهٔ اجباری ".self::PLUGIN_NAMESPACE_PREFIX.' است.');

            return false;
        }

        if (! class_exists($class)) {
            // تا وقتی K5.3 بارگذار PSR-4 نیامده، این حالت **طبیعی** است نه
            // خطای بسته. ولی بالا سطح نگه داشته می‌شود تا بعداً معلوم شود
            // چرا افزونه‌ای که فکر می‌کرد فعال است، بی‌اثر مانده.
            $this->reject($slug, "کلاس «{$class}» هنوز بارگذاری نشده (K5.3).", 'notice');

            return false;
        }

        if (! is_subclass_of($class, $interface)) {
            $this->reject($slug, "کلاس «{$class}» رابط «{$interface}» را پیاده نمی‌کند.");

            return false;
        }

        return true;
    }

    /**
     * مانیفست افزونه‌هایی که واقعاً اجازهٔ اثرگذاری دارند.
     *
     * اینجا عمداً `ManifestRegistry::activeManifests()` صدا زده نمی‌شود،
     * چون آن فقط `active` را چک می‌کند. جایگزینی یک interface هسته از
     * افزودن منوی پنل خیلی پرقدرت‌تر است، پس شرط سخت‌گیرانه‌تری لازم دارد:
     * هم `active` و هم `review_status = approved`.
     *
     * ناسازگاری این دو منبع عمداً به این تسک محدود شده و ثبت نشده — تغییر
     * رفتار `activeManifests()` سه مصرف‌کنندهٔ دیگر دارد و باید جداگانه و
     * با تست انجام شود.
     *
     * @return array<string, array<string, mixed>>
     */
    private function activePluginManifests(): array
    {
        return Plugin::query()
            ->where('active', true)
            ->where('review_status', Plugin::REVIEW_APPROVED)
            ->pluck('manifest', 'slug')
            ->filter(fn ($m) => is_array($m))
            ->all();
    }

    private function reject(string $slug, string $reason, string $level = 'warning'): void
    {
        Log::{$level}('plugin.core_service_provider.rejected', [
            'slug' => $slug,
            'reason' => $reason,
        ]);
    }
}
