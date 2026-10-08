<?php

namespace Tests\Feature;

// fixtureهای اجرایی: کلاس‌های واقعی افزونه، نه mock. بدون این‌ها guardهای
// `PluginDispatcher` آزموده نمی‌شوند — تست یک stub را صدا می‌زند و سبز می‌شود.
require_once __DIR__.'/../Fixtures/PluginDemo/Http.php';

use App\Http\Controllers\PluginRouter;
use App\Models\Plugin;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * K5.4 — catch-all مسیرهای افزونه.
 *
 * این تست‌ها route را **خودشان** در `setUp()` ثبت می‌کنند، چون
 * `routes/api.php` در این تسک در دسترس نبود. ثبت دستی یک چیز را ثابت می‌کند که
 * تستِ صرفاً واحدی نمی‌تواند: اینکه قطعهٔ route اعلام‌شده در
 * `PluginRouter` واقعاً کار می‌کند — نه فقط اینکه کنترلر با ورودی دستی درست
 * جواب می‌دهد.
 *
 * **دو سطح آزمون، عمداً.** جدول (`PluginRouteTable::resolve`) و HTTP (کلاینت
 * تست). سطح جدول دقیق است و می‌تواند ورودی‌هایی بسازد که لایهٔ مسیریابی قبل از
 * کنترلر ردشان می‌کند؛ سطح HTTP می‌گوید mount واقعی چه می‌کند. ادعای امنیتی
 * روی سطح جدول است و تست‌های traversal همان را هدف می‌گیرند.
 */
class PluginRouterTest extends TestCase
{
    use RefreshDatabase;

    private PluginRouteTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        // همان چیزی که `PluginRouter` برای ثبت اعلام کرده، با همان prefix و
        // همان middleware group که `withRouting(api:)` به `routes/api.php`
        // می‌دهد.
        Route::prefix('api')->middleware('api')->group(function (): void {
            Route::match(PluginRouteTable::METHODS, 'v1/p/{slug}/{any?}', PluginRouter::class)
                ->where('slug', '[^/]+')
                ->where('any', '.*')
                ->name(PluginRouter::ROUTE_NAME)
                ->middleware('throttle:60,1');
        });

        // K7.8 — بستهٔ مرکزیِ واقعی را برمی‌داریم.
        //
        // این فایل دربارهٔ افزونه‌های **فرضی** است (`demo`, `my-plugin`) و چند
        // تستش کل جدول route را می‌سنجند — مثلاً
        // `test_an_inactive_plugin_serves_nothing` انتظار `[]` دارد و
        // `test_only_core_owned_middleware_is_accepted` انتظار دارد فقط
        // `demo` بماند.
        //
        // رکوردش از migration باقی است، و این تست‌ها باید فقط دربارهٔ چیزی
        // باشند که خودشان می‌سازند.

        $this->table = app(PluginRouteTable::class);
        $this->table->flush();
    }

    // ── ۱) resolve و شکل خروجی ────────────────────────────────────────────

    public function test_it_resolves_a_declared_route_of_an_active_plugin(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $route = $this->table->resolve('demo', 'ping');

        $this->assertNotNull($route, 'precondition: مسیر اعلام‌شده باید resolve شود.');
        $this->assertSame('demo', $route['slug']);
        $this->assertSame('GET', $route['method'], 'متد باید نرمال به حروف بزرگ شود.');
        $this->assertSame('ping', $route['path']);
        $this->assertSame('Pishdad\\Plugins\\Demo\\Http\\PingController@show', $route['handler']);
        $this->assertSame([], $route['params']);
    }

    public function test_it_fills_named_params_from_the_path(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'posts/{id}/comments/{comment}', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PostController@show'],
        ]);

        $route = $this->table->resolve('demo', 'posts/42/comments/abc-9');

        $this->assertNotNull($route);
        $this->assertSame(['id' => '42', 'comment' => 'abc-9'], $route['params']);
    }

    public function test_a_plugin_can_own_the_root_of_its_prefix(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => '', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\RootController@show'],
        ]);

        $this->assertNotNull($this->table->resolve('demo', ''), 'مسیر تهی یعنی ریشهٔ prefix افزونه.');
        $this->assertNotNull($this->table->resolve('demo', '/'), 'اسلش انتهایی همان ریشه است، نه مسیری دیگر.');
    }

    public function test_the_studly_namespace_is_derived_from_the_slug_exactly_like_the_autoloader(): void
    {
        $this->plugin('my-plugin', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\MyPlugin\\Http\\PingController@show'],
        ]);

        $route = $this->table->resolve('my-plugin', 'ping');

        $this->assertNotNull($route, 'همان `Str::studly`ای که `PluginAutoloader::registerPackages()` به کار می‌برد.');
        $this->assertSame('Pishdad\\Plugins\\MyPlugin\\Http\\PingController@show', $route['handler']);
    }

    // ── ۲) بدون کوئری در هر درخواست ────────────────────────────────────────

    /**
     * سربارِ درخواستِ گرم باید صفر کوئری باشد — **شرط ۱ تسک**.
     *
     * جدول یک بار در پنجرهٔ TTL ساخته می‌شود و بعد از آن از کش خوانده می‌شود.
     * اگر روزی کسی `Cache::remember` را بردارد و به کوئری مستقیم برگردد، همین
     * تست قرمز می‌شود.
     */
    public function test_a_warm_table_answers_without_touching_the_database(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $this->assertNotNull($this->table->resolve('demo', 'ping'), 'precondition: پنجرهٔ کش باید گرم شود.');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->assertNotNull($this->table->resolve('demo', 'ping'));
            $this->assertSame([], DB::getQueryLog(), 'درخواستِ دوم نباید هیچ کوئری بزند.');
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_flush_forces_a_rebuild(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);
        $this->assertNotNull($this->table->resolve('demo', 'ping'));

        $this->table->flush();
        $this->assertNull(Cache::get(PluginRouteTable::CACHE_KEY), 'جدول باید واقعاً از کش پاک شود.');

        $this->assertNotNull($this->table->resolve('demo', 'ping'), 'پس از flush باید از نو ساخته شود.');
    }

    // ── ۳) fail-closed ─────────────────────────────────────────────────────

    public function test_an_unknown_slug_resolves_to_nothing(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $this->assertNull($this->table->resolve('ghost', 'ping'), 'slug ناشناس نباید هیچ fallback‌ای بگیرد.');
        $this->assertNull($this->table->resolve('', 'ping'));
        $this->assertNull($this->table->resolve('DEMO', 'ping'), 'تفاوت حروف بزرگ یعنی افزونهٔ دیگر.');
    }

    public function test_an_inactive_plugin_serves_nothing(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ], active: false);

        $this->assertNull($this->table->resolve('demo', 'ping'), 'غیرفعال‌سازی باید همان لحظه routeها را ببرد.');
        $this->assertSame([], $this->table->all());
    }

    /**
     * شکافی که فقط `active` آن را نمی‌بیند.
     *
     * `ReviewController::reject()` فقط `review_status` را عوض می‌کند و
     * `active` را دست نمی‌زند، پس افزونه‌ای که فعال شده و بعداً رد شده با یک
     * چکِ صرفاً `active` همچنان route سرو می‌کند. جدول route آخرین دروازه است
     * و باید fail-closed باشد.
     */
    public function test_a_review_rejected_plugin_serves_nothing_even_while_active(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ], reviewStatus: Plugin::REVIEW_REJECTED);

        $this->assertNull($this->table->resolve('demo', 'ping'));
    }

    public function test_a_pending_but_never_approved_plugin_still_serves_its_declared_routes(): void
    {
        // فعال‌سازی در `PluginController::activate()` تأیید را لازم دارد، پس
        // رسیدن به این ترکیب یعنی داده دستکاری شده. جدول وضعیت بازبینی را
        // نمی‌شکند: فقط «ردشده» را می‌بندد چون تنها حالتی است که فعال‌بودنِ
        // باقی‌مانده و قابلیت اجرای کد با هم تناقض دارند.
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ], reviewStatus: Plugin::REVIEW_PENDING);

        $this->assertNotNull($this->table->resolve('demo', 'ping'));
    }

    public function test_a_path_the_plugin_did_not_declare_resolves_to_nothing(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $this->assertNull($this->table->resolve('demo', 'pong'));
        $this->assertNull($this->table->resolve('demo', 'ping/extra'));
        $this->assertNotNull($this->table->resolve('demo', 'ping/'), 'اسلش انتهایی همان مسیر است.');
    }

    public function test_a_manifest_without_an_api_block_contributes_nothing(): void
    {
        $this->plugin('demo', ['permissions' => [['module' => 'posts']]]);

        $this->assertSame([], $this->table->all(), 'افزونهٔ بدون API باید اصلاً وارد جدول نشود.');
        $this->assertNull($this->table->resolve('demo', ''));
    }

    // ── ۴) traversal و بدشکلی، قبل از هر lookup ────────────────────────────

    /**
     * شرط سخت تسک: slug یا مسیر بدشکل **قبل از هر lookup** رد می‌شود.
     *
     * معیار این تست فقط `null` گرفتن نیست: کش باید هنوز **سرد** بماند. اگر روزی
     * کسی اعتبارسنجی را بعد از `all()` ببرد، جدول ساخته می‌شود و ورودی بدشکل
     * یک کوئری و یک regex روی کلیدی که خودش از ورودی کاربر ساخته شده می‌گیرد.
     */
    public function test_a_hostile_slug_is_rejected_without_ever_building_the_table(): void
    {
        foreach ([
            '..',
            '../../etc',
            '..%2f..%2fetc',
            'a/b',
            'a\\b',
            "a\0b",
            'Demo',
            'demo.php',
            '_demo',
            str_repeat('a', 65),
        ] as $slug) {
            $this->assertNull($this->table->resolve($slug, 'ping'), "slug «{$slug}» باید رد می‌شد.");
            $this->assertNull(Cache::get(PluginRouteTable::CACHE_KEY), "lookup نباید برای «{$slug}» اتفاق افتاده باشد.");
        }
    }

    public function test_a_hostile_path_is_rejected_without_ever_building_the_table(): void
    {
        foreach ([
            '..',
            '../admin',
            '../../etc/passwd',
            '..%2f..%2fetc',
            '%2e%2e%2f%2e%2e',
            'a/../../b',
            'a//b',
            "/a\x00b",
            'a\\b',
            'a b',
            'a.b',
            'a?b',
            'a%2Fb',
            str_repeat('a/', 9).'b',
        ] as $path) {
            $this->assertNull($this->table->resolve('demo', $path), "مسیر «{$path}» باید رد می‌شد.");
            $this->assertNull(Cache::get(PluginRouteTable::CACHE_KEY), "lookup نباید برای «{$path}» اتفاق افتاده باشد.");
        }
    }

    public function test_is_valid_path_agrees_with_the_resolver(): void
    {
        $this->assertTrue(PluginRouteTable::isValidPath(''));
        $this->assertTrue(PluginRouteTable::isValidPath('posts/42'));
        $this->assertFalse(PluginRouteTable::isValidPath('../admin'));
        $this->assertFalse(PluginRouteTable::isValidPath('a//b'));
    }

    /**
     * traversal نباید بتواند از **پارامترِ اعلام‌شده** هم بیرون بزند.
     *
     * الگوی `posts/{id}` با `posts/a/b` نباید بخواند، چون پارامتر یک segment
     * کامل است نه بخشی از یک segment؛ وگرنه یک مسیر اعلامی می‌توانست به‌ناچار
     * عمق‌های بیشتری را ببلعد.
     */
    public function test_a_declared_param_cannot_be_escaped_with_a_traversal_segment(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'posts/{id}', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PostController@show'],
        ]);

        $this->assertNotNull($this->table->resolve('demo', 'posts/42'));
        $this->assertNull($this->table->resolve('demo', 'posts/42/edit'));
        $this->assertNull($this->table->resolve('demo', 'posts/..'));
        $this->assertNull($this->table->resolve('demo', 'posts/..%2F..'));
        $this->assertNull($this->table->resolve('demo', 'posts/..%2Fadmin%2Fusers'));
    }

    // ── ۵) اعلان بد → هرگز وارد جدول نمی‌شود ───────────────────────────────

    /**
     * هندلر باید داخل `Pishdad\Plugins\{StudlySlug}\` و **یک سطح زیر آن** باشد.
     *
     * هر مورد یک راه واقعیِ دور زدن است: ریشهٔ `App\` (اختیار هسته)، ریشهٔ
     * خام `Pishdad\Plugins\` (کل بسته‌های دیگر)، و namespace افزونهٔ همسایه.
     */
    public function test_a_handler_outside_the_mandatory_plugin_namespace_is_refused(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'App\\Http\\Controllers\\Api\\V1\\Admin\\ManagerController@index'],
            ['method' => 'get', 'path' => 'b', 'handler' => 'Pishdad\\Plugins\\DemoController@index'],
            // دقیقاً روی ریشهٔ بسته، یک سطح زیر `Pishdad\Plugins\Demo\`.
            ['method' => 'get', 'path' => 'c', 'handler' => 'Pishdad\\Plugins\\Demo@index'],
            ['method' => 'get', 'path' => 'd', 'handler' => 'Pishdad\\Plugins\\Evil\\EvilController@run'],
            ['method' => 'get', 'path' => 'e', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController'],
            ['method' => 'get', 'path' => 'f', 'handler' => 'Pishdad\\Plugins\\Demo\\..\\Evil@run'],
            ['method' => 'get', 'path' => 'g', 'handler' => 'Pishdad\\Plugins\\\\Ghost@run'],
            ['method' => 'get', 'path' => 'h', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@__construct'],
            ['method' => 'get', 'path' => 'i', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show@extra'],
        ]);

        $this->assertSame([], $this->table->all(), 'هیچ‌کدام نباید وارد جدول شده باشند.');
    }

    /**
     * الگوی مسیر اعلامی نباید بتواند یک regex تزریق کند.
     *
     * هر segment یا الفبای ثابت است یا دقیقاً یک `{name}`، پس متاکاراکترهای
     * regex در هیچ شکلی به الگو نمی‌رسند. تست می‌گیرد که یک الگوی مخرب **کل
     * route را دور می‌ریزد** و اینکه بعد از رد شدنش، یک الگوی سالم کنارش کار
     * می‌کند.
     */
    public function test_a_declared_path_cannot_inject_regex(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'a.*', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\A@show'],
            ['method' => 'get', 'path' => '(a|b)', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\B@show'],
            ['method' => 'get', 'path' => '{id}{id}', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\C@show'],
            ['method' => 'get', 'path' => '{id}/{id}', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\D@show'],
            ['method' => 'get', 'path' => '..', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\E@show'],
            ['method' => 'get', 'path' => str_repeat('seg/', 9).'end', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\F@show'],
            ['method' => 'get', 'path' => 'ok', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\Ok@show'],
        ]);

        $table = $this->table->all();

        $this->assertCount(1, $table['demo']['routes'], 'فقط route سالم باید مانده باشد.');
        $this->assertNull($this->table->resolve('demo', 'anything/at/all'), 'الگوی تزریق‌شده نباید خوانده باشد.');
        $this->assertNull($this->table->resolve('demo', 'aa'), '`a.*` نباید به الگوی «هرچیزی» تبدیل شده باشد.');
        $this->assertNotNull($this->table->resolve('demo', 'ok'), 'رد یک route نباید بقیه را از کار بیندازد.');
    }

    public function test_a_method_outside_the_contract_is_refused(): void
    {
        $this->plugin('demo', [
            ['method' => 'TRACE', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\A@show'],
            ['method' => 'get', 'path' => 'b', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\B@show'],
        ]);

        $table = $this->table->all();

        $this->assertCount(1, $table['demo']['routes']);
        $this->assertSame('b', $table['demo']['routes'][0]['path']);
    }

    /**
     * `api.prefix` باید دقیقاً برابر slug باشد — یک منبع حقیقت.
     *
     * اختلاف یعنی جایی که افزونه می‌تواند زیر slug دیگری سرو شود، و همان چیزی
     * است که کل `prefix` قراردادی معماری را بی‌اعتبار می‌کرد. نابرابری، کل
     * routeهای آن افزونه را می‌برد نه فقط یکی را.
     */
    public function test_api_prefix_must_equal_the_slug_or_every_route_is_dropped(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\A@show'],
        ], apiPrefix: 'other');

        $this->plugin('ghost', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Ghost\\Http\\A@show'],
        ], apiPrefix: 'demo');

        $this->assertSame([], $this->table->all(), 'نه prefix نابرابر و نه ادعای مالکیت slug دیگر.');
    }

    // ── ۶) میدلور: فقط چیزی که هسته مالکش است ─────────────────────────────

    /**
     * اصل «میدلور» معماری: افزونه رشته اعلام می‌کند، هسته resolve می‌کند.
     *
     * اگر پذیرشِ هر رشته‌ای بود، یک افزونه می‌توانست کلاس میدلور دلخواهش را به
     * pipeline هسته تزریق کند و مرز امنیتی اصلاً شکل نمی‌گرفت. `perm:` هم فقط
     * پرمیشنِ **خودِ همین افزونه** را می‌پذیرد، چون شکل نامی را
     * `ManifestRegistry::manifestPermissions()` می‌سازد.
     *
     * `devmode.gate` در `bootstrap/app.php` ثبت شده
     * و باز هم رد می‌شود: فهرستِ مجاز عمداً از «هر alias هسته» باریک‌تر است.
     * چیزی که هسته برای مسیرهای خودش لازم دارد، مجوز افزونه نیست.
     */
    public function test_only_core_owned_middleware_is_accepted(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\A@show', 'middleware' => ['auth:sanctum', 'auth:sanctum', 'perm:plugin:demo:posts.view', 'throttle:60,1', 'throttle:30,2']],
        ]);
        $this->plugin('bad', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad\\Http\\A@show', 'middleware' => ['App\\Http\\Middleware\\EnsurePermission']],
        ]);
        $this->plugin('bad2', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad2\\Http\\A@show', 'middleware' => ['auth:basic']],
        ]);
        $this->plugin('bad3', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad3\\Http\\A@show', 'middleware' => ['perm:plugin:other:posts.view']],
        ]);
        $this->plugin('bad4', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad4\\Http\\A@show', 'middleware' => ['perm:users.edit']],
        ]);
        $this->plugin('bad5', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad5\\Http\\A@show', 'middleware' => ['throttle:plugin']],
        ]);
        $this->plugin('bad6', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad6\\Http\\A@show', 'middleware' => ['perm:plugin:bad6:posts']],
        ]);
        // alias ناشناخته ولی با آرگومان: تنها راهی که یک فهرستِ بسته را از «هر
        // رشته‌ای» جدا می‌کند.
        $this->plugin('bad7', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad7\\Http\\A@show', 'middleware' => ['subscribers:whatever']],
        ]);
        $this->plugin('bad8', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad8\\Http\\A@show', 'middleware' => ['devmode.gate']],
        ]);
        // aliasِ حذف‌شده: اگر نامِ قدیمی برگردد، همین تست می‌افتد.
        //
        // نکتهٔ امنیتی: نگهبانِ نقش حالا **عمومی** است
        // (`role:<role>`) تا افزونهٔ بعدی بدون ویرایش هسته، routeهای خودش را
        // محافظت کند.
        // همین‌جا چهار حالتِ مرزی قفل می‌شود؛ اگر کسی اعتبارسنجی را شل کند،
        // هر کدام یک راهِ دور زدن است.
        $this->plugin('bad9', [
            // نقش بدون آرگومان: نگهبان نقش خالی می‌گیرد و همه را رد می‌کند —
            // بدتر از نبودنش چون دیباگ‌کردنش سخت است. باید در جدول نیاید.
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad9\\Http\\A@show', 'middleware' => ['role']],
        ]);
        $this->plugin('bad9b', [
            // نقشِ ممنوعه: `admin` یعنی «هر مشتریِ لاگین‌کرده». اگر تزریق می‌شد،
            // هر بستهٔ بازاری می‌توانست نگهبانِ هسته را دور بزند.
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad9b\\Http\\A@show', 'middleware' => ['role:admin']],
        ]);
        $this->plugin('bad9c', [
            // wildcard: `role:*` یعنی «هر نقشی که داشتم».
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad9c\\Http\\A@show', 'middleware' => ['role:*']],
        ]);
        $this->plugin('bad9d', [
            // aliasِ قدیمی. حذف شد تا نامِ افزونهٔ خصوصی در build عمومی نباشد؛
            // اگر برگردد، همین تست می‌افتد.
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad9d\\Http\\A@show', 'middleware' => ['retired.operator']],
        ]);
        // یک میدلور خراب کل routeهای آن افزونه را می‌برد، نه فقط خودش را.
        $this->plugin('bad10', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'Pishdad\\Plugins\\Bad10\\Http\\A@show', 'middleware' => ['auth:sanctum', 'evil:1']],
            ['method' => 'get', 'path' => 'b', 'handler' => 'Pishdad\\Plugins\\Bad10\\Http\\B@show', 'middleware' => ['auth:sanctum']],
        ]);

        $table = $this->table->all();

        $this->assertSame(
            ['auth:sanctum', 'perm:plugin:demo:posts.view', 'throttle:60,1', 'throttle:30,2'],
            $table['demo']['routes'][0]['middleware'],
            'میدلور تکراری باید یکی شود: دو بار اجرا شدنش رفتار متفاوتی است.'
        );

        $this->assertSame(['demo', 'bad10'], array_keys($table), 'فقط `demo` و بسته‌ای که همهٔ میدلورهایش معتبر است بمانند.');

        $this->assertSame(['b'], array_column($table['bad10']['routes'], 'path'), 'یک میدلور خراب فقط همان route را می‌برد، نه کل بسته را.');
    }

    /**
     * ⭐ L-B12 — نگهبانِ نقش عمومی است و **نقش از مانیفست می‌آید**.
     *
     * این تست جفتِ fail-closedهای بالاست: آنجا بدها رد می‌شوند، اینجا خوب‌ها
     * پذیرفته می‌شوند. لازم است چون یک فیلترِ بیش‌ازحد سخت هم «امن» است و هم
     * افزونه‌ها را بی‌صدا از کار انداخته.
     */
    public function test_a_declared_role_is_accepted(): void
    {
        $this->plugin('demo', [
            [
                'method' => 'get',
                'path' => 'ops',
                'handler' => 'Pishdad\\Plugins\\Demo\\Http\\A@show',
                'middleware' => ['auth:sanctum', 'role:operator', 'throttle:60,1'],
            ],
        ]);

        $table = $this->table->all();

        $this->assertArrayHasKey('demo', $table, 'یک نقشِ معتبر نباید بسته را رد کند.');
        $this->assertSame(
            ['auth:sanctum', 'role:operator', 'throttle:60,1'],
            $table['demo']['routes'][0]['middleware'],
            'نگهبانِ نقش باید دقیقاً همان‌طور که اعلام شده عبور کند — نه بازنویسی‌شده.',
        );
    }

    public function test_a_duplicate_route_keeps_the_first_and_is_logged(): void
    {
        Log::spy();

        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\First@show'],
            ['method' => 'GET', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\Second@show'],
        ]);

        $table = $this->table->all();

        $this->assertCount(1, $table['demo']['routes']);
        $this->assertSame('Pishdad\\Plugins\\Demo\\Http\\First@show', $table['demo']['routes'][0]['handler']);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'plugin.route_rejected' && $context['reason'] === 'route_duplicate';
        });
    }

    public function test_a_rejected_declaration_is_never_silent(): void
    {
        Log::spy();

        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'a', 'handler' => 'App\\Evil@show'],
        ]);

        $this->assertSame([], $this->table->all());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'plugin.route_rejected'
                && $context['reason'] === 'handler_rejected'
                && $context['slug'] === 'demo';
        });
    }

    // ── ۷) HTTP: همان چیزی که کاربر می‌بیند ────────────────────────────────

    /**
     * K7.11 — مسیر resolve‌شده حالا **اجرا** می‌شود، نه اینکه ۵۰۱ بدهد.
     *
     * تا این‌جا `PluginRouteTable` اعلان را می‌خواند و `PluginAutoloader` کلاس‌ها
     * را بارگذاری می‌کرد، ولی هیچ‌کدام به هم وصل نبودند. یعنی افزونه می‌توانست
     * مسیر **اعلام** کند و هیچ مسیری **اجرا** نمی‌شد — و K7.8/K7.9 (انتقال ۶۱
     * route مرکزی) روی زیرساختی نوشته می‌شدند که هرگز اجرا نمی‌شد.
     *
     * کلاس‌های `Demo\…` در همین فایل تعریف شده‌اند و `PluginDispatcher` آن‌ها را
     * از namespace اجباری `Pishdad\Plugins\Demo\` پیدا می‌کند — یعنی تست مسیر
     * واقعی را می‌پیماید، نه یک mock را.
     */
    public function test_a_resolved_route_actually_runs_plugin_code(): void
    {
        $this->plugin('demo', [
            ['method' => 'post', 'path' => 'callback/{id}', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\CallbackController@store'],
        ]);

        $response = $this->postJson('/api/v1/p/demo/callback/7');

        $response->assertOk();
        $this->assertSame('demo', $response->json('plugin'));
        $this->assertSame('7', $response->json('id'), 'پارامتر مسیر باید به متد هندلر برسد.');
    }

    /**
     * `@method` **اجباری** است — و این امنیتی است، نه محدودیتِ سلیقه‌ای.
     *
     * منطقش در `PluginRouteTable::compileHandler()` است: بدون `@` هیچ نام متدی
     * وجود ندارد، پس یا باید از روی متد HTTP حدس زد (که یعنی یک بسته می‌تواند
     * متدی را که اصلاً قصد فراخوانی‌اش را نداشت اجرا کند) یا باید `@` را صریح
     * نوشت. حدس زدن بدتر است.
     *
     * همچنین `__`-پیشوند ممنوع است (`:520`) تا `__destruct` و `__clone` از بیرون
     * قابل فراخوانی نباشند. این تست هر دو قاعده را قفل می‌کند.
     */
    public function test_a_handler_without_a_method_is_rejected(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController'],
        ]);

        Log::spy();

        // ۴۰۴، نه ۵۰۰: اعلان اصلاً ثبت نشد، پس «مسیر وجود ندارد» درست است.
        $this->getJson('/api/v1/p/demo/ping')->assertStatus(404);

        Log::shouldHaveReceived('warning')->withArgs(function (string $m, array $c): bool {
            return $m === 'plugin.route_rejected' && ($c['reason'] ?? '') === 'handler_rejected';
        });
    }

    /**
     * متدِ `__`-دار از بیرون قابل فراخوانی نیست.
     */
    public function test_a_dunder_handler_method_is_rejected(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'destruct', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@__destruct'],
        ]);

        $this->getJson('/api/v1/p/demo/destruct')->assertStatus(404);
    }

    /**
     * هندلر باید داخل namespace اجباری همان افزونه باشد.
     *
     * این تنها جایی است که جلوی یک بستهٔ مخرب را می‌گیرد که می‌خواهد به کلاسِ
     * هسته دست بزند. `PluginAutoloader` همین قاعده را هنگام ثبت دارد ولی روی رشتهٔ
     * **اعلام‌شده**؛ `PluginDispatcher` روی رشتهٔ **واقعیِ فراخوانی** هم می‌سنجد.
     */
    public function test_a_handler_outside_the_plugin_namespace_is_rejected_at_declaration(): void
    {
        $this->plugin('demo', [
            // یک کلاس واقعی و داخل هسته — نه یک نام ساختگی.
            ['method' => 'get', 'path' => 'escape', 'handler' => 'App\\Http\\Controllers\\Api\\V1\\Admin\\PluginController@index'],
        ]);

        Log::spy();

        $this->getJson('/api/v1/p/demo/escape')->assertStatus(404);

        Log::shouldHaveReceived('warning')->withArgs(function (string $m, array $c): bool {
            return $m === 'plugin.route_rejected' && ($c['reason'] ?? '') === 'handler_rejected';
        });
    }

    /**
     * خطای افزونه نباید جزئیاتش را به کاربر نشت دهد.
     *
     * یک استثنا می‌تواند connection string یا مسیر فایل داشته باشد و مسیر فایل
     * ساختار دیسک را لو می‌دهد. جزئیات باید فقط در لاگ برود.
     */
    public function test_a_throwing_handler_does_not_leak_details_to_the_client(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'boom', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\ThrowingController@show'],
        ]);

        Log::spy();

        $response = $this->getJson('/api/v1/p/demo/boom');

        $response->assertStatus(500);
        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET-LEAK', (string) $body);
        $this->assertStringNotContainsString('/var/www/html', (string) $body);
        $this->assertStringNotContainsString('pg_password', (string) $body);

        Log::shouldHaveReceived('error')->withArgs(function (string $m, array $c): bool {
            return $m === 'plugin.dispatch_failed' && ($c['slug'] ?? '') === 'demo';
        });
    }

    /**
     * یک افزونهٔ غیرفعال هیچ مسیری ندارد — حتی اگر اعلانش در کش مانده باشد.
     */
    public function test_a_deactivated_plugin_runs_nothing(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $this->getJson('/api/v1/p/demo/ping')->assertOk();

        Plugin::query()->where('slug', 'demo')->update(['active' => false]);
        PluginRouteTable::flush();

        $this->getJson('/api/v1/p/demo/ping')->assertStatus(404);
    }

    public function test_the_404_message_does_not_distinguish_why(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $unknown = $this->getJson('/api/v1/p/ghost/ping');
        $wrongPath = $this->getJson('/api/v1/p/demo/pong');
        $wrongMethod = $this->postJson('/api/v1/p/demo/ping');

        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame(404, $wrongPath->getStatusCode());
        $this->assertSame(404, $wrongMethod->getStatusCode());
        $this->assertSame($unknown->json(), $wrongPath->json(), 'مسیر نخواندن نباید از slug ناشناس قابل تشخیص باشد.');
        $this->assertSame($unknown->json(), $wrongMethod->json(), 'متد نخواندن هم نباید ۴۰۵ بدهد.');
    }

    /**
     * کنترلر خودش مرز است، حتی وقتی لایهٔ مسیریابی اصلاً جلویش را نگرفته.
     *
     * لایهٔ مسیریابی چند ورودیِ traversal را **قبل از رسیدن به کنترلر** رد
     * می‌کند، و آن‌وقت یک تست HTTP مبتنی بر مسیر، چیزی را ثابت نمی‌کند. اینجا
     * کنترلر مستقیم صدا زده می‌شود تا ثابت شود وقتی ورودی بد *می‌رسد*، خودش هم
     * می‌بندد و هیچ fallback‌ای ندارد.
     */
    public function test_the_controller_refuses_hostile_input_even_when_routing_lets_it_through(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $controller = app(PluginRouter::class);

        foreach ([
            ['../../admin', 'ping'],
            ['..', ''],
            ['demo', '../../etc/passwd'],
            ['demo', 'ping/../..'],
            ["demo\0", 'ping'],
            ['demo', "ping\0"],
        ] as [$slug, $any]) {
            // URI عمداً بی‌ربط و بی‌خطر است: هدف این تست خودِ آرگومان‌های کنترلر
            // است، و `Request::create()` یک slug با NUL را پیش از رسیدن به آن
            // رد می‌کند — که دقیقاً همان دلیلی است که این سطح آزمون لازم است.
            $response = $controller(Request::create('/api/v1/p/probe', 'GET'), $slug, $any);

            $this->assertSame(404, $response->getStatusCode(), "«{$slug}» باید ۴۰۴ می‌داد.");
        }
    }

    public function test_a_deactivated_plugin_stops_being_reachable_over_http_after_a_flush(): void
    {
        $this->plugin('demo', [
            ['method' => 'get', 'path' => 'ping', 'handler' => 'Pishdad\\Plugins\\Demo\\Http\\PingController@show'],
        ]);

        $this->getJson('/api/v1/p/demo/ping')->assertOk();

        Plugin::query()->where('slug', 'demo')->update(['active' => false]);
        $this->table->flush();

        $this->getJson('/api/v1/p/demo/ping')->assertStatus(404);
    }

    // ── کمکی ──────────────────────────────────────────────────────────────

    private function plugin(string $slug, array $routes, bool $active = true, string $reviewStatus = Plugin::REVIEW_APPROVED, ?string $apiPrefix = null): Plugin
    {
        $plugin = Plugin::create([
            'name' => $slug,
            'slug' => $slug,
            'active' => $active,
            'review_status' => $reviewStatus,
            'manifest' => [
                'slug' => $slug,
                'api' => [
                    'prefix' => $apiPrefix ?? $slug,
                    'routes' => $routes,
                ],
            ],
        ]);

        $this->table->flush();

        return $plugin;
    }
}
