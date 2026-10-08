<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * K7.8 — متد در `resolve()`، نه بعد از آن.
 *
 * ## چرا این تست جدا و mutation-verified است
 *
 * باگِ اولیه این بود که `resolve()` اولین regex هم‌خوان را برمی‌گرداند و
 * `PluginRouter` متد را **بعد** می‌سنجید. نتیجه: روی هر مسیری که هم `GET` و هم
 * `POST` داشت، فقط `GET` کار می‌کرد و `POST` ۴۰۴ می‌داد.
 *
 * تا K7.8 هیچ‌کدام از تست‌ها این را نمی‌دیدند چون تنها بستهٔ آزمایشیِ افزونه
 * (K7.13) روی هر مسیر **یک** متد داشت. یعنی یک نگهبانِ سبز که عملاً هیچ چیزی را
 * تضمین نمی‌کرد — همان الگوی نگهبانِ سبزی که عملاً هیچ چیزی را تضمین نمی‌کرد.
 *
 * اگر متد را از حلقهٔ `resolve()` برداریم، هر سه تست این فایل قرمز می‌شوند.
 */
class PluginRouteMethodTest extends TestCase
{
    use RefreshDatabase;

    private function table(): PluginRouteTable
    {
        Cache::forget(PluginRouteTable::CACHE_KEY);

        return app(PluginRouteTable::class);
    }

    private function install(array $routes): void
    {
        Plugin::query()->create([
            'name' => 'دو-متدی',
            'slug' => 'twomethod',
            'active' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => [
                'slug' => 'twomethod',
                'api' => ['prefix' => 'twomethod', 'routes' => $routes],
            ],
        ]);

        $this->table();
    }

    public function test_the_same_path_resolves_differently_per_method(): void
    {
        $this->install([
            ['method' => 'get', 'path' => 'items', 'handler' => 'Pishdad\\Plugins\\Twomethod\\Http\\C@index'],
            ['method' => 'post', 'path' => 'items', 'handler' => 'Pishdad\\Plugins\\Twomethod\\Http\\C@store'],
        ]);

        $t = $this->table();

        $get = $t->resolve('twomethod', 'items', 'GET');
        $post = $t->resolve('twomethod', 'items', 'POST');

        $this->assertNotNull($get, 'GET باید route داشته باشد.');
        $this->assertNotNull($post, 'POST باید route داشته باشد — همان مسیر، متدِ دیگر.');

        // کل هندلر را می‌سنجیم نه یک برشِ ثابت: `substr` با طولِ ثابت روی
        // `index`/`store` (طول متفاوت) نخستین تلاش را بی‌معنا کرد.
        $this->assertSame('Pishdad\\Plugins\\Twomethod\\Http\\C@index', $get['handler']);
        $this->assertSame('Pishdad\\Plugins\\Twomethod\\Http\\C@store', $post['handler']);
    }

    public function test_a_method_with_no_declared_route_does_not_borrow_another(): void
    {
        $this->install([
            ['method' => 'get', 'path' => 'items', 'handler' => 'Pishdad\\Plugins\\Twomethod\\Http\\C@index'],
        ]);

        $t = $this->table();

        $this->assertNotNull($t->resolve('twomethod', 'items', 'GET'));
        $this->assertNull(
            $t->resolve('twomethod', 'items', 'DELETE'),
            'DELETE اعلام نشده ⇒ نباید با GET جواب بگیرد.',
        );
    }

    public function test_omitting_the_method_keeps_the_old_first_match_behaviour(): void
    {
        $this->install([
            ['method' => 'get', 'path' => 'items', 'handler' => 'Pishdad\\Plugins\\Twomethod\\Http\\C@index'],
            ['method' => 'post', 'path' => 'items', 'handler' => 'Pishdad\\Plugins\\Twomethod\\Http\\C@store'],
        ]);

        $t = $this->table();

        $this->assertSame('GET', $t->resolve('twomethod', 'items')['method']);
    }
}
