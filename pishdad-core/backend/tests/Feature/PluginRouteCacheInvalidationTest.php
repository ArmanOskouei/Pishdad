<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * K99: saving or deleting a plugin must drop the cached route table immediately.
 *
 * `PluginRouteTable` caches the compiled table for 30 seconds. With no caller for `flush()`,
 * deactivating a plugin left its routes served until the TTL expired — so the architecture
 * document's "deactivation is immediate" did not actually hold. `Plugin::booted()` now calls
 * `PluginRouteTable::flush()` next to `ManifestRegistry::flushCache()`.
 *
 * The assertions below are about the *dispatch decision* changing, not merely about a cache
 * key disappearing: a deactivated plugin must stop resolving on the very next lookup.
 */
class PluginRouteCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mirrors the `plugin()` helper in `PluginRouterTest`, including the
     * `Pishdad\Plugins\…` handler namespace that `PluginAutoloader` produces. A handler outside
     * that namespace is rejected by `compileRoutes()`, which would make this whole test pass
     * vacuously against an empty table.
     */
    private function seedPlugin(bool $active = true): Plugin
    {
        $plugin = Plugin::create([
            'name' => 'demo',
            'slug' => 'demo',
            'active' => $active,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => [
                'slug' => 'demo',
                'api' => [
                    'prefix' => 'demo',
                    'routes' => [
                        ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
                    ],
                ],
            ],
        ]);

        PluginRouteTable::flush();

        return $plugin;
    }

    private function table(): PluginRouteTable
    {
        return app(PluginRouteTable::class);
    }

    public function test_saving_a_plugin_flushes_the_route_table(): void
    {
        $plugin = $this->seedPlugin();
        $this->table()->all();

        $this->assertNotNull(
            Cache::get(PluginRouteTable::CACHE_KEY),
            'پیش‌شرط: جدول باید داخل کش باشد وگرنه این تست چیزی را نمی‌سنجد'
        );

        // Through the model instance, not a query-builder mass update: `Builder::update()`
        // does not fire Eloquent events, so it would never reach the flush closure.
        $plugin->update(['version' => '1.0.1']);

        $this->assertNull(
            Cache::get(PluginRouteTable::CACHE_KEY),
            'ذخیرهٔ پلاگین باید جدول route را باطل کند'
        );
    }

    public function test_deleting_a_plugin_flushes_the_route_table(): void
    {
        $plugin = $this->seedPlugin();
        $this->table()->all();
        $this->assertNotNull(Cache::get(PluginRouteTable::CACHE_KEY), 'پیش‌شرط');

        // Same reason as above — `Builder::delete()` skips the model events too.
        $plugin->delete();

        $this->assertNull(
            Cache::get(PluginRouteTable::CACHE_KEY),
            'حذف پلاگین باید جدول route را باطل کند'
        );
    }

    public function test_deactivated_plugin_stops_resolving(): void
    {
        $plugin = $this->seedPlugin();

        $this->assertNotNull(
            $this->table()->resolve('demo', 'ping'),
            'پیش‌شرط: پلاگین فعال باید route را resolve کند'
        );

        $plugin->update(['active' => false]);

        // The whole point of the fix: no waiting out a 30-second TTL.
        $this->assertNull(
            $this->table()->resolve('demo', 'ping'),
            'پلاگین غیرفعال‌شده نباید بلافاصله پس از غیرفعال‌سازی route بدهد'
        );
    }

    public function test_reactivating_a_plugin_brings_the_route_back(): void
    {
        $plugin = $this->seedPlugin(active: false);
        $this->assertNull($this->table()->resolve('demo', 'ping'), 'پیش‌شرط: پلاگین غیرفعال route ندارد');

        $plugin->update(['active' => true]);

        $this->assertNotNull(
            $this->table()->resolve('demo', 'ping'),
            'فعال‌سازی دوباره باید route را بلافاصله برگرداند، نه بعد از پایان TTL'
        );
    }
}
