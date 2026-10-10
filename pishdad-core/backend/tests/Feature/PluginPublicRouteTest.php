<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * S-12 فاز ۱ — حداقلِ احراز هویت برای routeهای نوشتنِ عمومیِ افزونه.
 *
 * قاعده:
 *   - `public: true` صریح + نوشتن + بستهٔ approved ⇒ مجاز.
 *   - همان با بستهٔ pending/rejected ⇒ دور ریخته می‌شود.
 *   - `public: "true"` (رشته) ⇒ رد، مثلِ true حساب نمی‌شود.
 *   - `public` غایب ⇒ legacy: public ضمنی + هشدار (سازگاری عقب‌رو).
 *   - `auth:sanctum` ⇒ مهم نباشد `public` چیست، احراز هویت برنده است.
 */
class PluginPublicRouteTest extends TestCase
{
    use RefreshDatabase;

    private function table(): PluginRouteTable
    {
        return app(PluginRouteTable::class);
    }

    private function compile(string $slug, array $routes, string $review = 'approved'): array
    {
        $manifest = ['api' => ['prefix' => $slug, 'routes' => $routes]];

        $reflection = new \ReflectionClass($this->table());
        $method = $reflection->getMethod('compileRoutes');
        $method->setAccessible(true);

        return $method->invoke($this->table(), $slug, $manifest, $review);
    }

    public function test_explicit_public_write_is_allowed_for_an_approved_plugin(): void
    {
        $routes = $this->compile('demo', [[
            'method' => 'POST', 'path' => '/callback', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\CallbackController@store',
            'public' => true,
        ]]);

        $this->assertCount(1, $routes);
        $this->assertTrue($routes[0]['public']);
    }

    public function test_public_write_is_rejected_for_a_pending_plugin(): void
    {
        $routes = $this->compile('demo', [[
            'method' => 'POST', 'path' => '/callback', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\CallbackController@store',
            'public' => true,
        ]], 'pending');

        $this->assertSame([], $routes);
    }

    public function test_string_true_is_not_a_valid_public_declaration(): void
    {
        $routes = $this->compile('demo', [[
            'method' => 'GET', 'path' => '/ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show',
            'public' => 'true',
        ]]);

        $this->assertSame([], $routes);
    }

    public function test_missing_public_stays_backwards_compatible_with_a_warning(): void
    {
        $routes = $this->compile('demo', [[
            'method' => 'POST', 'path' => '/callback', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\CallbackController@store',
        ]]);

        $this->assertCount(1, $routes);
        $this->assertFalse($routes[0]['public']); // ضمنی، نه صریح.
    }

    public function test_auth_route_does_not_need_the_public_flag(): void
    {
        $routes = $this->compile('demo', [[
            'method' => 'POST', 'path' => '/write', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\WriteController@store',
            'middleware' => ['auth:sanctum'],
        ]]);

        $this->assertCount(1, $routes);
        $this->assertFalse($routes[0]['public']);
    }

    public function test_phase_two_rejects_routes_that_declare_neither(): void
    {
        config(['plugins.require_declared_public' => true]);

        $routes = $this->compile('demo', [[
            'method' => 'GET', 'path' => '/silent', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\SilentController@index',
        ]]);

        $this->assertSame([], $routes);
    }

    public function test_cache_key_moved_to_v2(): void
    {
        // شکلِ entry تغییر کرده (`public` اضافه شده) ⇒ کشِ گرم باید باطل شود.
        $this->assertSame('plugin:routes:table:v2', PluginRouteTable::CACHE_KEY);
        Cache::forget(PluginRouteTable::CACHE_KEY);
    }
}
