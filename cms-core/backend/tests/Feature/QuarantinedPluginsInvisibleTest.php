<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginReleaseManager;
use Tests\TestCase;

/**
 * J11 — بسته‌های قرنطینه‌شده باید برای **هستهٔ رایگان نادیدنی** باشند.
 *
 * ## این تست دقیقاً چه چیزی را تضمین می‌کند
 *
 * J11 دو افزونه (`central`, `subscription`) را به `plugins/_quarantine/` برد.
 * ولی «انتقال فایل» به‌تنهایی تضمین نمی‌کند که کشفِ افزونه‌ها هم آن‌ها را نبیند:
 * `PluginReleaseManager::sourceSlugs()` روی `plugins/` اسکن می‌کند و اگر روزی
 * پوشه اشتباهی برگردد یا کسی `_quarantine` را داخل یک slug بگذارد، همه‌چیز
 * بی‌صدا دوباره فعال می‌شود. این تست همان دروازه است.
 *
 * ## چرا mutation قابل اثبات است
 *
 * اگر کسی `plugins/_quarantine/central` را به `plugins/central` برگرداند،
 * `sourceSlugs()` بلافاصله `central` را برمی‌گرداند (چون ریشهٔ PSR-4 دارد) و
 * این تست قرمز می‌شود. یعنی ادعای «نادیدنی» واقعاً سنجیده می‌شود، نه ادعا.
 */
class QuarantinedPluginsInvisibleTest extends TestCase
{
    private const QUARANTINED = ['central', 'subscription'];

    public function test_the_quarantined_plugin_directories_do_not_exist_in_the_scan_path(): void
    {
        foreach (self::QUARANTINED as $slug) {
            $this->assertDirectoryDoesNotExist(
                base_path('plugins/'.$slug),
                "بستهٔ قرنطینه‌شده «{$slug}» نباید زیر plugins/ باشد (اسکن هسته همین‌جا را می‌خواند).",
            );
        }
    }

    public function test_the_quarantined_plugins_were_moved_not_deleted(): void
    {
        foreach (self::QUARANTINED as $slug) {
            $this->assertDirectoryExists(
                base_path('plugins/_quarantine/'.$slug),
                "بستهٔ «{$slug}» باید در قرنطینه باشد، نه حذف‌شده.",
            );
        }
    }

    public function test_the_plugin_scanner_never_returns_a_quarantined_slug(): void
    {
        $slugs = app(PluginReleaseManager::class)->sourceSlugs();

        foreach (self::QUARANTINED as $slug) {
            $this->assertNotContains(
                $slug,
                $slugs,
                "اسکنِرِ افزونه‌های داخلی «{$slug}» را دید — یعنی قرنطینه نشت کرده.",
            );

            $this->assertNull(
                app(PluginReleaseManager::class)->sourceDir($slug),
                "sourceDir(«{$slug}») نباید مسیری برگرداند.",
            );

            $this->assertNull(
                app(PluginReleaseManager::class)->sourceAutoloadRoot($slug),
                "ریشهٔ PSR-4 «{$slug}» نباید پیدا شود.",
            );

            $this->assertFalse(
                app(PluginReleaseManager::class)->isSourceSlug($slug),
                "«{$slug}» نباید یک slugِ افزونهٔ داخلی شمرده شود.",
            );
        }

        // خودِ پوشهٔ قرنطینه هم نباید به‌عنوان یک slug دیده شود.
        $this->assertNotContains('_quarantine', $slugs);
    }

    public function test_no_quarantined_manifest_is_discovered_on_disk(): void
    {
        $manifests = array_map(
            static fn (string $p): string => basename(dirname($p)),
            glob(base_path('plugins/*/manifest.json')) ?: [],
        );

        foreach (self::QUARANTINED as $slug) {
            $this->assertNotContains(
                $slug,
                $manifests,
                "مانیفست «{$slug}» هنوز زیر plugins/*/ کشف می‌شود.",
            );
        }
    }

    /**
     * J12 — لایهٔ هستهٔ اشتراکِ دور هم نباید در مسیر runtime باشد.
     */
    public function test_the_remote_subscription_layer_is_quarantined(): void
    {
        foreach (['RemoteSubscriptionResolver.php', 'Transport.php', 'HttpTransport.php'] as $file) {
            $this->assertFileDoesNotExist(
                base_path('app/Services/Subscription/'.$file),
                "«{$file}» نباید در هسته بماند — بخشی از لایهٔ اشتراکِ قرنطینه‌شده است (J12).",
            );

            $this->assertFileExists(
                base_path('plugins/_quarantine/core-subscription/'.$file),
                "«{$file}» باید در قرنطینه باشد، نه حذف‌شده.",
            );
        }
    }

    public function test_the_core_subscription_resolver_still_resolves_to_null(): void
    {
        $this->assertInstanceOf(
            \App\Services\Subscription\NullSubscriptionResolver::class,
            (new \ReflectionMethod(\App\Services\Subscription\SubscriptionResolver::class, 'impl'))->invoke(null),
            'پیش‌فرضِ resolverِ اشتراک باید NullSubscriptionResolver باشد (نصبِ تازه = بدون مسیرِ دور).',
        );
    }
}
