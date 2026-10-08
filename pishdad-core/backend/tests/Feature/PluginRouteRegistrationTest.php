<?php

namespace Tests\Feature;

use App\Http\Controllers\PluginRouter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * K100 — route افزونه واقعاً در `routes/api.php` ثبت شده است.
 *
 * ## چرا این تست هست
 *
 * تا این‌جا `PluginRouterTest::setUp()` خودش route را ثبت می‌کرد. یعنی کل
 * تست‌های dispatch روی routeای اجرا می‌شدند که **فقط در محیط تست وجود داشت** و
 * در فایل واقعی نبود — پس اگر کسی خط را از `routes/api.php` پاک می‌کرد، **هیچ
 * تستی قرمز نمی‌شد.**
 *
 * این دقیقاً همان شکلی از نگهبانی است که پروژه از آن ضربه خورده: تست سبز،
 * قابلیت غایب. یک تست که خودش مسیر را می‌سازد نمی‌تواند وجودش را ثابت کند.
 *
 * این تست هیچ routeای نمی‌سازد و فقط می‌پرسد آیا فایل واقعی، route را دارد.
 */
class PluginRouteRegistrationTest extends TestCase
{
    public function test_the_plugin_catch_all_is_registered_in_the_real_routes_file(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->values();

        $this->assertContains(
            PluginRouter::ROUTE_NAME,
            $names->all(),
            'route افزونه در routes/api.php ثبت نشده — افزونه‌ها در محیط واقعی هیچ مسیری ندارند.'
        );
    }

    public function test_the_registered_route_uses_the_documented_path(): void
    {
        $uri = null;
        $methods = null;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === PluginRouter::ROUTE_NAME) {
                $uri = $route->uri();
                $methods = $route->methods();

                break;
            }
        }

        $this->assertNotNull($uri, 'route افزونه ثبت نشده است.');
        $this->assertSame('api/v1/p/{slug}/{any?}', $uri, 'مسیر route افزونه باید همان باشد که توصیف شده.');
        $this->assertContains('POST', (array) $methods, 'افزونه باید بتواند POST بدهد.');
    }

    public function test_the_registered_route_is_throttled(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== PluginRouter::ROUTE_NAME) {
                continue;
            }

            $this->assertContains(
                'throttle:60,1',
                $route->gatherMiddleware(),
                'route افزونه باید throttle داشته باشد — بدون آن هر افزونه می‌تواند بی‌نهایت درخواست بزند.'
            );

            return;
        }

        $this->fail('route افزونه ثبت نشده است.');
    }

    /**
     * route کش باید معتبر بماند — معماری روی همین ادعا بنا شده.
     */
    public function test_route_cache_succeeds_with_the_plugin_route_registered(): void
    {
        $this->artisan('route:cache')->assertExitCode(0);

        $this->artisan('route:clear');
    }

    /**
     * ⭐ K1.6.5 — **دو catch-all وجود دارد و هرکدام مالِ یک چیز متفاوت است.**
     *
     * این تست برای یک سؤالی نوشته شد که در TASKS.md مبهم مانده بود: «dispatch صفحه
     * چطور به `[...path]/page.tsx` در فرانت می‌رسد؟» جوابش این است: **نمی‌رسد.**
     *
     *  • `/api/v1/p/{slug}/{any?}` → `PluginRouter` — مالِ افزونه‌ها. dispatch از
     *    راه `PluginRouteTable` + `PluginDispatcher`.
     *  • `/api/v1/site/pages/{path}` → `SitePageController` — مالِ صفحه‌های عمومیِ
     *    سایت، و همان چیزی است که فرانت در `app/[...path]/page.tsx` صدا می‌زند.
     *
     * یعنی `[...path]/page.tsx` پشتِ `PluginRouter` نیست. اگر روزی کسی فکر کند
     * هست، یک افزونه می‌تواند صفحهٔ عمومی سایت را بگیرد یا برعکس — و هیچ‌کدام از
     * تست‌های dispatch این را نمی‌دید، چون همه فقط `/p/...` را نگاه می‌کنند.
     *
     * ضمناً **فقط یک** catch-all افزونه باید ثبت شده باشد: دوتا یعنی یکی از آن‌ها
     * مرز اول را گرفته و بی‌سروصدا برنده شده، که به ترتیب ثبت وابسته می‌شود.
     */
    public function test_exactly_one_plugin_catch_all_and_the_public_one_is_not_it(): void
    {
        $plugin = [];
        $public = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === PluginRouter::ROUTE_NAME) {
                $plugin[] = $route;

                continue;
            }

            if (str_starts_with($route->uri(), 'api/v1/site/pages')) {
                $public[] = $route;
            }
        }

        $this->assertCount(
            1,
            $plugin,
            'باید دقیقاً یک catch-all افزونه ثبت شده باشد. بیشتر از یکی یعنی کدامشان برنده است به ترتیب ثبت بستگی دارد.'
        );

        $this->assertNotEmpty(
            $public,
            'مسیر صفحهٔ عمومی سایت پیدا نشد — `[...path]/page.tsx` در فرانت همین را صدا می‌زند و باید پشتش باشد.'
        );

        foreach ($public as $route) {
            $uses = (string) ($route->getAction('uses') ?? '');

            $this->assertStringNotContainsString(
                'PluginRouter',
                $uses,
                'مسیرِ صفحهٔ عمومیِ سایت نباید به PluginRouter برسد — آن catch-all مالِ افزونه است و dispatch کد افزونه اجرا می‌کند.'
            );
        }
    }
}
