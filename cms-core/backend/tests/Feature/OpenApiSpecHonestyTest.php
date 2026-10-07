<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * J6 — سندِ OpenAPIِ هسته نباید دربارهٔ هسته **دروغ** بگوید.
 *
 * ## مسئله‌ای که این تست می‌گیرد
 *
 * `openapi.yaml` دستی نگه داشته می‌شود و هیچ چیزی آن را با routeهای واقعی
 * مقایسه نمی‌کند. نتیجه‌اش این شد که J5 مسیرِ `/api/internal/heartbeat` را از
 * هسته برداشت ولی سند هنوز آن را مستند می‌کرد — یعنی سند یک endpointِ ناموجود را
 * وعده می‌داد.
 *
 * جهتِ مقابل هم ممکن است: یک routeِ تازه در `routes/api.php` اضافه می‌شود و کسی
 * سند را به‌روز نمی‌کند. آن‌وقت سند ناقص است و `gen:types` نوعی نمی‌سازد که UI
 * بخواهد.
 *
 * ## چرا این تست «پوشش» را چک نمی‌کند
 *
 * چون نمی‌شود. مسیرهای افزونه‌ها از راه catch-all سرو می‌شوند و در جدولِ route
 * لاراول ثبتِ ایستا ندارند، پس «همهٔ routeهای واقعی» قابلِ استخراج نیست.
 *
 * به‌جایش **دقیقاً همان چیزی** را می‌سنجد که J5 خراب کرد: مسیرهایی که هسته
 * مالکشان است. ملکیت، معیارِ قابل‌بررسی است؛ پوشش، نه.
 */
class OpenApiSpecHonestyTest extends TestCase
{
    private const SPEC = 'openapi/openapi.yaml';

    /**
     * مسیرهایی که هسته **قبلاً** مالکشان بود و به افزونه منتقل شدند.
     *
     * ⚠️ عمداً فقط مالکیتِ هسته. مسیرهای زیر prefixِ افزونه (`api/v1/p/<slug>/*`)
     * از راه catch-all سرو می‌شوند و در جدولِ route لاراول ثبتِ ایستا ندارند، پس
     * نه می‌توان آن‌ها را آنجا یافت و نه مالکِ سندِ هسته‌اند.
     *
     * با حذفِ «پنل مرکزی» دیگر هیچ افزونه‌ای در `plugins/` نیست، پس نه prefixی
     * برای افزونه می‌ماند و نه مسیری که سندِ هسته مالکش باشد. یعنی این فهرست
     * حالا **همهٔ** موردِ قابل‌بررسی است: هر مسیری که سند از افزونه‌ها می‌گیرد
     * باید در یکی از مانیفست‌ها باشد — و مانیفستی نیست.
     */
    private const CORE_OWNED = [
        'post' => '/api/v1/internal/heartbeat',
    ];

    private function specPaths(): array
    {
        $path = base_path(self::SPEC);
        $this->assertFileExists($path, 'سندِ OpenAPI پیدا نشد: '.self::SPEC);

        $found = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            // Top-level path keys only: two spaces, a slash, ending in a colon.
            if (preg_match('/^ {2}(\/\S+):\s*$/', $line, $m) === 1) {
                $found[$m[1]] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * هیچ مسیری که هسته مالکش نیست نباید در سندِ هسته باشد.
     *
     * @testWith ["/api/internal/heartbeat"]
     *           ["/api/v1/internal/heartbeat"]
     */
    public function test_a_core_route_that_moved_is_not_still_documented(string $uri): void
    {
        $this->assertNotContains(
            $uri,
            $this->specPaths(),
            "«{$uri}» در هسته سرو نمی‌شود ولی هنوز در سند هست."
            .' J5 این مسیرها را به افزونه منتقل کرد؛ سند هم باید منتقل شود.'
        );
    }

    /**
     * جهتِ مثبتِ تست‌های بالا: سند نباید **تهی** شود تا همه سبز شوند.
     *
     * «هیچ مسیرِ ناموجودی مستند نشده» با یک سندِ کاملاً خالی هم سبز است. پس باید
     * ثابت کنیم سند هنوز **مسیرهایِ واقعی** را دارد، و آن مسیرها در جدولِ route
     * هم وجود دارند — وگرنه یک نفر می‌توانست همه‌چیز را پاک کند و «پاکیزگی» را
     * جای «دقت» بنشاند.
     *
     * ⚠️ این تست عمداً هیچ مسیرِ مشخصی را نام نمی‌برد. مسیرهایِ افزونه‌ای
     * (`api/v1/p/<slug>/*`) در جدولِ route ثبتِ ایستا ندارند و با حذفِ «پنل مرکزی»
     * دیگر افزونه‌ای هم برای افزودن به فهرست وجود ندارد. پس تنها مالکیتِ
     * قابل‌بررسی، **هسته** است — و معیار، خودِ جدولِ route.
     */
    public function test_the_spec_still_documents_live_core_routes(): void
    {
        $documented = array_map($this->normalise(...), $this->specPaths());

        $this->assertNotEmpty(
            $documented,
            'سندِ هسته نباید خالی باشد. «هیچ مسیرِ ناموجودی مستند نشده» با سندِ خالی'
            .' هم سبز می‌شود، و آن دیگر دقت نیست.'
        );

        $live = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $live[] = $route->uri();
        }

        $matchers = array_map($this->patternToRegex(...), $live);

        $backed = array_filter(
            $documented,
            static function (string $path) use ($matchers): bool {
                foreach ($matchers as $matcher) {
                    if (preg_match($matcher, $path) === 1) {
                        return true;
                    }
                }

                return false;
            }
        );

        $this->assertNotEmpty(
            $backed,
            "هیچ‌کدام از مسیرهای مستندشده در جدولِ routeِ هسته نیستند:\n"
            .implode("\n", $documented)
        );
    }

    /**
     * هر مسیرِ مستندشده باید در یکی از این دو جا واقعاً وجود داشته باشد:
     * جدولِ routeِ لاراول، یا مانیفستِ یک افزونه.
     *
     * ## چرا از جدولِ route می‌خوانیم و نه از متنِ `routes/api.php`
     *
     * نسخهٔ اولِ این تست مسیرها را با regex از سورس بیرون می‌کشید و **غلط** جواب
     * داد: ۴۰ مسیرِ سالم را «یتیم» گزارش کرد. دو دلیل:
     *
     *  ۱. مسیرها داخل گروه تعریف شده‌اند (`Route::prefix('v1')->group(…)`) پس متنِ
     *     سورس فقط `/auth/login` را نشان می‌دهد، نه `/api/v1/auth/login`.
     *  ۲. نامِ پارامتر فرق می‌کند: مانیفست می‌گوید `plans/{plan}` و سند می‌گوید
     *     `plans/{id}`. این دو **همان مسیرند**.
     *
     * یعنی بازسازیِ مسیر از روی سورس، عملاً دوباره‌نویسیِ routerِ لاراول بود — و
     * هر چیزی که خودت بازسازی کنی، تست را به حدِ «همان حدسِ قبلی» محدود می‌کند.
     * خودِ router همین را حل کرده؛ از او می‌پرسیم.
     */
    public function test_every_documented_path_is_owned_by_core_or_a_plugin(): void
    {
        $patterns = [];

        // هسته — از جدولِ واقعیِ route، که گروه‌ها و prefixها را حل کرده.
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $patterns[] = $route->uri();
        }

        // افزونه‌ها — از مانیفست، چون catch-all در جدولِ route ثبتِ ایستا ندارد.
        // J11 — بسته‌های قرنطینه‌شده هم اینجا خوانده می‌شوند تا مسیرهای مستندشده
        // در همین مخزن خصوصی «یتیم» گزارش نشوند. در بیلدِ رایگان، سند هم باید
        // مسیرهای آن افزونه‌ها را نداشته باشد (خارج از دامنهٔ این تغییر).
        $manifests = array_merge(
            glob(base_path('plugins/*/manifest.json')) ?: [],
            glob(base_path('plugins/_quarantine/*/manifest.json')) ?: [],
        );

        foreach ($manifests as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $prefix = $data['api']['prefix'] ?? null;

            if (! is_string($prefix)) {
                continue;
            }

            foreach ($data['api']['routes'] ?? [] as $route) {
                if (is_string($route['path'] ?? null)) {
                    $patterns[] = 'api/v1/p/'.$prefix.'/'.$route['path'];
                }
            }
        }

        $matchers = array_map($this->patternToRegex(...), $patterns);

        // نرمال‌سازی **قبل** از closure انجام می‌شود تا closure بتواند `static`
        // بماند و `$this` نداشته باشد — یا اگر نگیریم، اصلاً کار نمی‌کند.
        $candidates = array_map($this->normalise(...), $this->specPaths());

        $orphans = array_values(array_filter(
            $candidates,
            static function (string $path) use ($matchers): bool {
                foreach ($matchers as $matcher) {
                    if (preg_match($matcher, $path) === 1) {
                        return false;
                    }
                }

                return true;
            }
        ));

        $this->assertSame(
            [],
            $orphans,
            "این مسیرها در سند هستند ولی نه در جدولِ routeِ هسته‌اند و نه در مانیفستِ"
            ." هیچ افزونه‌ای:\n".implode("\n", $orphans)
            ."\n\nیا سند را به‌روز کن، یا اگر route حذف شده، حذفش را از سند هم پاک کن."
        );
    }

    /**
     * یک الگوی مسیر را به regex تبدیل می‌کند تا **مقدارِ مشخص** هم مطابقت کند.
     *
     * این لازم است چون مسیرها دو شکل دارند:
     *   - الگو: `admin/layouts/{area}`  (در جدولِ route)
     *   - مقدار: `admin/layouts/header`  (در سند و در UI)
     *
     * این‌ها یک مسیرند. اگر فقط نرمال‌سازیِ `{x}` → `{}` کنیم، هیچ‌وقت پیدایشان
     * نمی‌کند و تست به‌اشتباه یک مسیرِ سالم را یتیم گزارش می‌کند.
     *
     * `{x}` هر بخشِ غیرخالی را می‌پذیرد، ولی **نمی‌تواند** خالی باشد و نمی‌تواند
     * `/` داشته باشد — وگرنه `{x}` با `.*` فرقی نمی‌کند و مهارِ تست از کار می‌افتد.
     */
    private function patternToRegex(string $pattern): string
    {
        $normalised = $this->normalise($pattern, keepBrace: true);

        $escaped = preg_quote($normalised, '#');
        $withParams = str_replace(['\{any\}', '\{.+?\}'], '[^/]+', $escaped);

        return '#^'.$withParams.'$#';
    }

    /**
     * نامِ پارامتر را یکسان می‌کند تا `{id}` و `{plan}` یکی حساب شوند.
     *
     * $keepBrace حالتِ «الگو» را نگه می‌دارد (برای ساختن regex) و حالتِ معمول
     * نام‌ها را یکنواخت می‌کند (برای مقایسهٔ ساده).
     */
    private function normalise(string $uri, bool $keepBrace = false): string
    {
        $uri = '/'.trim($uri, '/');

        return $keepBrace
            ? (string) preg_replace('#/\{[^/{}]+\}#', '/{any}', $uri)
            : (string) preg_replace('#/\{[^/{}]+\}#', '/{}', $uri);
    }

    /**
     * فهرستِ `CORE_OWNED` نباید بی‌استفاده بماند.
     *
     * اگر روزی `CORE_OWNED` خالی شد ولی تست‌های بالا هنوز بودند، آن‌ها فقط
     * دربارهٔ دو مسیرِ مشخص حرف می‌زنند و وانمود می‌کنند عمومی‌اند.
     */
    public function test_the_core_owned_fixture_is_not_abandoned(): void
    {
        foreach (array_keys(self::CORE_OWNED) as $uri) {
            $this->assertNotContains(
                $uri,
                $this->specPaths(),
                "«{$uri}» در فهرستِ مسیرهایِ باید-مالک-هسته-باشند است ولی سندش نمی‌کند."
            );
        }
    }
}
