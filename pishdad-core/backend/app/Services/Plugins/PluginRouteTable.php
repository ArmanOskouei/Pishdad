<?php

namespace App\Services\Plugins;

use App\Http\Middleware\RequireUserRole;
use App\Models\Plugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * K5.4 — جدول routeهای فعال پلاگین‌ها، پشتِ یک catch-all مالک هسته.
 *
 * چرا این کلاس وجود دارد و چرا اصلاً جدول؟ چون هسته **یک** route واقعی
 * (`GET|POST|… /api/v1/p/{slug}/{any?}`) مالک است و بقیه داخلش dispatch می‌شوند
 * (بخش ۴ معماری). سه مشکلی که آن بخش برای گزینهٔ «route واقعی با prefix قراردادی»
 * می‌نویسد — `route:cache`، hijack مسیر هسته، و برخورد نام route — همگی ریشه در
 * این دارند که **افزونه حق ثبت route ندارد**. جدول زیر دقیقاً همان چیزی است که
 * آن حق را از افزونه می‌گیرد: افزونه یک رشته می‌نویسد، هسته تفسیر و اعتبارسنجی
 * می‌کند.
 *
 * **کش، نه کوئری در هر درخواست.** جدول از کوئری به `plugins` ساخته و در کش
 * نگه‌داری می‌شود (`CACHE_KEY`) — دقیقاً همان الگوی `ManifestRegistry::activeManifests()`.
 * یعنی هر درخواستِ `/p/…` یک کوئری به جدول پلاگین‌ها **نمی‌زند**.
 *
 * صادقانه دربارهٔ عبارت «فایل cache» معماری: اینجا از **همان** `Cache` فاسادِ
 * هسته استفاده شده و روی `CACHE_STORE` جاری می‌نشیند. در این نصب
 * (`CACHE_STORE=database`) بازخوانیِ کش یعنی یک کوئری به جدول `cache` — که
 * باز هم **کوئریِ جدول route نیست**، و اگر روزی روی `file` بنشیند حتی همان هم
 * حذف می‌شود. یک سازوکار کش تازه ساخته نشده چون `PluginTrustStore` دقیقاً همین
 * را برای کلید مهر رد کرده بود (B19: دو منبع حقیقت یعنی بعداً معلوم نیست کدام
 * را باید دور انداخت).
 *
 * **TTL عمداً کوتاه (۳۰ ثانیه) و نه `forever`.** دلیلش دوگانه است:
 *
 *  ۱. غیرفعال‌سازی باید «فوری» باشد (بخش ۴: «یک فلگ در فایل cache و فوری»).
 *     باطل‌کردن صریح (`flush()`) تنها راه رسیدن به فوریت کامل است و **هنوز هیچ
 *     caller ای ندارد**: `Plugin::booted()` فقط `ManifestRegistry::flushCache()`
 *     را صدا می‌زند و آن فایل در مالکیت این تسک نبود. TTL کوتاه پنجرهٔ
 *     باقی‌ماندهٔ یک flush فراموش‌شده را **کران‌دار** می‌کند به‌جای اینکه باز
 *     بگذارد. وقتی چرخهٔ حیات افزونه وصل شد، `flush()` باید کنار همان
 *     `ManifestRegistry::flushCache()` بنشیند و TTL نقش فقط شبکهٔ ایمنی را دارد.
 *  ۲. `Cache::forever` برای داده‌ای که یک `cache:clear` بی‌صدا نابودش می‌کند خطای
 *     ساختاری است (همان استدلال B19 در `PluginTrustStore`).
 *
 * **این کلاس هیچ کدی از افزونه را اجرا نمی‌کند.** خروجی‌اش یک *تصمیم dispatch*
 * است: کلاس/متدی که افزونه اعلام کرده، به‌علاوهٔ میدلورها و پارامترهای مسیر.
 * اجرای آن کد کارِ چرخهٔ حیات (K5.3 + K5.5) است و عمداً اینجا انجام نمی‌شود:
 * `PluginAutoloader` هنوز به هیچ‌جا وصل نیست و `class_exists()` صدا زدن اینجا
 * یعنی اجرای داوطلبانهٔ کدی که فقط امضایش بررسی شده.
 *
 * **deny-by-default، مثل بقیهٔ فاز ۱.** هر چیزی که قاعده نداشته باشد رد می‌شود و
 * لاگ می‌گردد: مسیر بدشکل، هندلر خارج از ریشهٔ اجباری namespace، میدلوری که
 * مال هسته نیست، `api.prefix` نابرابر با slug. خاموشی بی‌صدا همان باگی است که
 * `ManifestRegistry::recordCollision()` برای رفعش ساخته شد، پس هر رد یک
 * `Log::warning` هم می‌گیرد.
 *
 * رد شدن در **زمان ساخت جدول** است، نه زمان درخواست: یک اعلان خراب یعنی یک
 * رکورد route مرده در جدول که هر درخواست بررسی‌اش می‌کند. ضمناً لاگ هم یک بار در
 * هر پنجرهٔ TTL زده می‌شود نه در هر درخواست، پس ضدّسیل لازم نیست.
 *
 * شکل بلوک `api` که این کلاس می‌خواند — هنوز به‌عنوان نقطهٔ اتصال در
 * `PluginPackageContract` ثبت نشده (K5.4 مالکیتش نبود)، پس اینجا می‌آید تا یک
 * منبع حقیقت داشته باشد:
 *
 *     "api": {
 *       "prefix": "zarinpal",
 *       "routes": [
 *         {
 *           "method": "POST",
 *           "path": "callback/{id}",
 *           "handler": "Pishdad\\Plugins\\Zarinpal\\Http\\CallbackController@store",
 *           "middleware": ["auth:sanctum", "perm:plugin:zarinpal:callback.view", "throttle:60,1"]
 *         }
 *       ]
 *     }
 *
 * چرا این شکل: `handler` تنها رشتهٔ اجرایی است و باید به یک کلاس واقعی اشاره
 * کند، و `middleware` رشته‌های هسته است نه کلاس — دقیقاً همان نسبتی که معماری
 * برای میدلور تعیین می‌کند. نبودن `api` یا `middleware` خطا نیست؛ بیشتر
 * افزونه‌ها اصلاً route ندارند و یک callback عمومی نباید `auth:sanctum` تحمیل
 * شود.
 */
final class PluginRouteTable
{
    /** کلید کش جدول. `v1` در کلید است تا تغییر شکل بدون باطل‌کردن کش ممکن باشد. */
    public const CACHE_KEY = 'plugin:routes:table:v1';

    /** @see self::flush() چرا این عدد کوچک است. */
    public const CACHE_TTL = 30;

    /**
     * متدهای HTTP قابل اعلام.
     *
     * `HEAD` جدا آمده چون Symfony آن را از `GET` نمی‌سازد؛ بدون آن یک افزونه
     * نمی‌تواند صریح بگوید HEAD را می‌خواهد. `OPTIONS` هم برای پاسخ‌های
     * preflight که یک افزونهٔ عمومی لازم دارد.
     *
     * فهرست بسته است: متدی خارج از این یعنی متدی که هسته قراردادی برایش
     * ندارد، نه متدی که «فعلاً» نیست.
     *
     * @var list<string>
     */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** سقف عمق مسیر. کارِ کران‌دار کردن کارِ regex است. */
    public const MAX_SEGMENTS = 8;

    /**
     * الفبای segment و جایی که واقعاً اعمال می‌شود.
     *
     * **`.` عمداً نیست.** نبودش یعنی `.` و `..` از همین یک قاعده می‌افتند و
     * traversal در فضای مسیر **ساختاری ناممکن** است — به‌جای فهرست سیاهی از
     * `..`، `%2e%2e`، `\` و NUL که هر کدام باید جداگانه به‌خاطر سپرده شوند.
     * بهایش: افزونه نمی‌تواند پسوند فایل در مسیر بگذارد (`report.csv`)، که با
     * query string قابل جبران است. این همان معاوضه‌ای است که
     * `PluginAutoloader::isSafeRelativePath()` هم می‌کند.
     *
     * `PATH_PARAM_CAPTURE` **امروز غیرقابل دسترس است**: هر segment پیش از
     * رسیدن به الگو از همین الفبا رد شده، پس گروه هرگز چیزی جز آن نمی‌بیند.
     * عمداً تنگ نگه داشته شده چون regex کامپایل‌شده یک قراردادِ ماندگار است —
     * روزی که `normalizePath()` باز شد، این گروه تنها چیزی است که جلوی یک
     * تطبیقِ بی‌قاعده را می‌گیرد.
     */
    private const PATH_CLASS = 'A-Za-z0-9_-';

    public const PATH_SEGMENT_PATTERN = '/^['.self::PATH_CLASS.']{1,64}$/';

    /** گروه ضبط در regex کامپایل‌شده — عمداً همان الفبا، نه `[^/]+`. */
    private const PATH_PARAM_CAPTURE = '(['.self::PATH_CLASS.']{1,64})';

    /**
     * نام اسلاگ. با `PluginTrustStore::normalizeSlug()` و ستون `slug` هم‌خوان است.
     *
     * `[a-z0-9]` اول یعنی slug نمی‌تواند با `-`/`_` شروع شود؛ هم یکپارچگی با
     * پیشوند جدول `[{slug}_]` (K1.5.3) و هم اینکه یک شناسهٔ خوانا می‌ماند.
     */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** `{name}` در مسیر اعلامی. کوچک و snake_case تا با نام‌گذاری هسته بخواند. */
    private const PARAM_NAME_PATTERN = '/^\{([a-z_][a-z0-9_]{0,31})\}$/';

    private const METHOD_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private const CLASS_SEGMENT_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * میدلورهایی که هسته مالکشان است و افزونه فقط آرگومانشان را می‌نویسد.
     *
     * **چرا فهرست بسته:** اصل «میدلور» در معماری — افزونه هرگز کلاس میدلور را
     * مستقیم ثبت نمی‌کند، فقط رشته اعلام می‌کند و هسته resolve می‌کند. اگر
     * پذیرشِ هر رشته‌ای بود، یک افزونه می‌توانست `App\Http\Middleware\…` یا
     * حتی کلاس خودش را به pipeline هسته تزریق کند و مرز امنیتی اصلاً شکل
     * نمی‌گرفت.
     *
     * `throttle` فقط شکل عددی را می‌پذیرد. معماری `throttle:plugin` را نام می‌برد
     * ولی چنین limiterای در `AppServiceProvider` ثبت نشده (`contact`،
     * `site-search`، `auth-login` تنها سه‌اند) و پذیرفتن نامی که وجود ندارد یعنی
     * انتقال یک خطای runtime به زمان اجرا.
     */
    private const MIDDLEWARE_RULES = [
        'auth' => '/^sanctum$/',
        'throttle' => '/^\d{1,5}(?:,\d{1,4})?$/',
        // L-B12 — نگهبانِ نقش. نقش از مانیفست می‌آید ولی این قاعده ثابت است تا
        // یک بستهٔ مخرب نتواند الگوی دلخواه تزریق کند. اعتبارسنجیِ fail-closed
        // نهایی در `RequireUserRole::isInjectableRole` است.
        'role' => '/^[a-z0-9][a-z0-9_-]{1,39}$/i',
    ];

    /**
     * نام‌های کاملِ میان‌افزارهایی که **بدون آرگومان** مجازند.
     *
     * ⚠️ کلید کامل است، نه alias. چون `explode(':', 'shop.operator')` می‌دهد
     * `['shop', 'operator']` — یعنی alias برابر `shop` و آرگومان برابر
     * `operator`.
     *
     * تلاش اول `['shop']` را در فهرست گذاشت و فکر می‌کرد درست است؛ نتیجه
     * `middleware_not_core_owned` روی هر ۶۳ مسیر و جدول route خالی. درس: این
     * فهرست را باید **از روی همان رشته‌ای** نوشت که افزونه‌نویس در مانیفست
     * می‌نویسد، نه از روی تصورِ alias بودنش.
     *
     * ## چرا این امن است
     *
     * فهرست **بسته** است، نه الگو، و آرگومانی هم نمی‌پذیرد. یعنی یک افزونهٔ
     * بازاری می‌تواند بگوید «shop.operator» و از درِ هسته رد شود — ولی این
     * **قبلاً هم** می‌توانست: هر نگهبانِ نقش خودش فقط نقشِ خودش را می‌پذیرد و
     * هیچ ورودیِ افزونه‌ای را نمی‌خواند. این alias دروازهٔ تازه‌ای باز نمی‌کند؛
     * فقط اجازه می‌دهد افزونه‌ای که *قبلاً* می‌توانست، این را اعلام کند.
     */
    /**
     * aliasِ نگهبانِ نقش. عمداً یک رشتهٔ **عمومی** است: رشتهٔ alias در مانیفست
     * افزونه می‌آید و در build عمومی دیده می‌شد.
     */
    private const ROLE_MIDDLEWARE_ALIAS = 'role';

    /** میان‌افزارهای هسته‌ایِ بی‌آرگومان (بدون نقش). */
    private const MIDDLEWARE_ALIASES = [
        'auth',
        'throttle:api',
    ];

    /** باقی‌ماندهٔ نام پرمیشن پس از `plugin:{slug}:` — دقیقاً شکلی که خود هسته می‌سازد. */
    private const PERMISSION_TAIL_PATTERN = '/^[a-z0-9_-]{2,40}\.[a-z]{1,16}$/';

    /**
     * جدول routeهای فعال: slug => {slug, routes}.
     *
     * فقط افزونه‌هایی داخل می‌شوند که دست‌کم یک route معتبر دارند — تا
     * `find()` برای یک slug ناشناخته به‌سرعت `null` بدهد و جدول کوچک بماند.
     *
     * @return array<string, array{slug: string, routes: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->build();
        });
    }

    /**
     * باطل‌کردن جدول. باید کنار `ManifestRegistry::flushCache()` صدا زده شود.
     *
     * static است چون تنها کارش `Cache::forget` روی یک ثابت کلاسی است و
     * باید از `Plugin::booted()` — جایی که خودش static context است — صدا
     * زده شود. به‌عنوان instance method از آنجا قابل فراخوانی نبود.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * resolve یک درخواست به یک route اعلام‌شده.
     *
     * اعتبارسنجی **قبل از هر lookup** انجام می‌شود — هم slug و هم مسیر — پس یک
     * ورودی بدشکل هرگز نه به کش می‌رسد و نه کلید آرایه‌ای می‌شود. این ترتیب
     * عمدی است: در غیر این صورت باید اول کلیدی بسازی که ساختنش از ورودی کاربر
     * آمده، و آنجا دقیقاً جایی است که مسیر دلخواه متولد می‌شود.
     *
     * @param  string  $slug  اسلاگ از `{slug}`
     * @param  string  $path  باقی‌ماندهٔ مسیر از `{any?}`؛ می‌تواند تهی باشد
     * @return array<string, mixed>|null اعلان route، یا null اگر هیچ routeای نخواند
     */
    public function resolve(string $slug, string $path, ?string $method = null): ?array
    {
        if (! self::isValidSlug($slug)) {
            return null;
        }

        $segments = self::normalizePath($path);

        if ($segments === null) {
            return null;
        }

        $entry = $this->all()[$slug] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $wanted = $method === null ? null : strtoupper($method);

        // ⚠️ **متد باید داخل حلقهٔ جست‌وجو باشد، نه بعد از آن.**
        //
        // نسخهٔ قبلی اولین regex هم‌خوان را برمی‌گرداند و `PluginRouter` متد را
        // **بعداً** می‌سنجید و ۴۰۴ می‌داد. برای بسته‌ای که روی هر مسیر فقط یک
        // متد داشت هیچ‌وقت دیده نمی‌شد — ولی هر افزونهٔ واقعی روی یک مسیر
        // `GET` و `POST` دارد.
        //
        // نتیجه: `POST /clients` همیشه ۴۰۴ می‌داد در حالی که `GET /clients`
        // کار می‌کرد. و بدتر، خطایی که می‌دیدیم «این مسیر وجود ندارد» بود —
        // یعنی کاربر و نویسنده هر دو فکر می‌کردند مسیر غلط است، در حالی که
        // مسیر درست بود و فقط متد اشتباه بود.
        //
        // اولویت با **ترتیبِ اعلان** است، نه الفبای متد: اگر افزونه‌نویس عمداً
        // یک مسیر را دوبار با متد متفاوت اعلام کند، همان ترتیبِ فایل مانیفست
        // برنده است — که قابل پیش‌بینی است، برخلاف مرتب‌سازی.
        foreach ($entry['routes'] as $route) {
            if ($wanted !== null && $route['method'] !== $wanted) {
                continue;
            }

            if (preg_match($route['regex'], implode('/', $segments), $params) !== 1) {
                continue;
            }

            return [
                'slug' => $entry['slug'],
                'method' => $route['method'],
                'path' => $route['path'],
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => $this->namedParams($route['params'], $params),
            ];
        }

        return null;
    }

    /** مسیر واردشده امن است؟ آرایهٔ segmentها، یا null اگر نباشد. */
    public static function isValidPath(string $path): bool
    {
        return self::normalizePath($path) !== null;
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    // ── ساخت جدول (کش miss) ────────────────────────────────────────────────

    /**
     * @return array<string, array{slug: string, routes: list<array<string, mixed>>}>
     */
    private function build(): array
    {
        $table = [];

        $plugins = Plugin::query()
            ->where('active', true)
            ->get(['slug', 'review_status', 'manifest']);

        foreach ($plugins as $plugin) {
            $slug = (string) $plugin->slug;

            if (! self::isValidSlug($slug)) {
                $this->reject($slug, 'slug_wrong_shape');

                continue;
            }

            // رد شدن بازبینی `active` را عوض نمی‌کند (و نمی‌تواند: آن فایل مال
            // این تسک نیست)، پس اگر فقط `active` را ملاک بگیریم، افزونه‌ای که
            // بعد از فعال‌سازی رد شده همچنان routeهایش را سرو می‌کند. جدول
            // route دروازهٔ آخر است، پس اینجا fail-closed می‌کنیم.
            if ($plugin->review_status === Plugin::REVIEW_REJECTED) {
                $this->reject($slug, 'review_rejected');

                continue;
            }

            $routes = $this->compileRoutes($slug, $plugin->manifest);

            if ($routes !== []) {
                $table[$slug] = ['slug' => $slug, 'routes' => $routes];
            }
        }

        return $table;
    }

    /**
     * routeهای معتبر یک افزونه.
     *
     * یک route بدشکل کل بسته را نمی‌اندازد: باقی routeهای همان افزونه اعمال
     * می‌شوند. برعکسِ برخورد نام (که اولویت با نگه‌دارنده است)، اینجا اولویت با
     * «آنچه سالم است» است.
     *
     * @return list<array<string, mixed>>
     */
    private function compileRoutes(string $slug, mixed $manifest): array
    {
        $api = is_array($manifest) ? ($manifest['api'] ?? null) : null;

        if (! is_array($api)) {
            return [];
        }

        // «api.prefix باید دقیقاً برابر slug باشد — یک منبع حقیقت». دو مسیر
        // (مانیفست و URL) که بتوانند اختلاف داشته باشند یعنی یک روز افزونه‌ای
        // زیر slug دیگری سرو می‌شود. نبودن `api` خطا نیست؛ بیشتر افزونه‌ها
        // اصلاً route ندارند.
        if (($api['prefix'] ?? null) !== $slug) {
            $this->reject($slug, 'api_prefix_mismatch', ['declared' => $api['prefix'] ?? null]);

            return [];
        }

        $declared = $api['routes'] ?? null;

        if (! is_array($declared)) {
            return [];
        }

        $namespace = PluginPackageContract::PLUGIN_NAMESPACE_PREFIX.Str::studly($slug).'\\';
        $compiled = [];
        $seen = [];

        foreach ($declared as $index => $route) {
            if (! is_array($route)) {
                $this->reject($slug, 'route_not_an_object', ['index' => $index]);

                continue;
            }

            $entry = $this->compileRoute($slug, $namespace, $route);

            if ($entry === null) {
                continue;
            }

            $key = $entry['method'].' '.$entry['path'];

            // تکراری در خودِ یک بسته یعنی بسته خراب است. اولی می‌ماند و دومی
            // لاگ می‌شود، چون انتخاب بی‌صدای «کدام‌یک» یعنی رفتار به ترتیب
            // درج آرایه وابسته می‌شود.
            if (isset($seen[$key])) {
                $this->reject($slug, 'route_duplicate', ['route' => $key]);

                continue;
            }

            $seen[$key] = true;
            $compiled[] = $entry;
        }

        return $compiled;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>|null
     */
    private function compileRoute(string $slug, string $namespace, array $route): ?array
    {
        $method = is_string($route['method'] ?? null) ? strtoupper($route['method']) : '';

        if (! in_array($method, self::METHODS, true)) {
            $this->reject($slug, 'method_not_allowed', ['method' => $route['method'] ?? null]);

            return null;
        }

        $path = is_string($route['path'] ?? null) ? $route['path'] : null;
        $compiled = $path === null ? null : $this->compilePath($slug, $path);

        if ($compiled === null) {
            $this->reject($slug, 'path_malformed', ['path' => $path]);

            return null;
        }

        $handler = is_string($route['handler'] ?? null) ? $route['handler'] : null;
        $target = $handler === null ? null : $this->compileHandler($slug, $namespace, $handler);

        if ($target === null) {
            $this->reject($slug, 'handler_rejected', ['handler' => $handler]);

            return null;
        }

        $middleware = $this->compileMiddleware($slug, $route['middleware'] ?? []);

        if ($middleware === null) {
            return null;
        }

        return [
            'method' => $method,
            'path' => $compiled['path'],
            'regex' => $compiled['regex'],
            'params' => $compiled['params'],
            'handler' => $target['class'].'@'.$target['method'],
            'middleware' => $middleware,
        ];
    }

    /**
     * مسیر اعلامی → regex کامپایل‌شده.
     *
     * **چرا regex و نه تطبیق segment به segment:** نگاشت نام‌های پارامتر به
     * `preg_match` طبیعی است، ولی یک شرط کلی لازم دارد که تضمین کند ورودی
     * هرگز به الگوی regex نشت نمی‌کند. هر segment پیش از رسیدن به الگو یا یک
     * الفبای ثابت را پاس می‌کند یا دقیقاً یک `{name}`؛ پس رشتهٔ ساخته‌شده از
     * الفبایی ساخته شده که خودش regex نیست. `preg_quote` روی literalها دفاع
     * دوم است، نه دفاع اصلی — و گروه‌های ضبط بی‌نام‌اند تا هیچ نامی که از
     * مانیفست آمده وارد الگو نشود.
     *
     * `D` لازم است: بدون آن `$` قبل از `\n` انتهایی هم true است. با الفبای
     * فعلی یک مسیر درخواستی هرگز `\n` ندارد (پس شرط نامعقول نیست)، ولی روی
     * متنِ *کامپایل‌شده* نگه‌داشتنش هزینه‌ای ندارد و روزی که الفبا باز شد
     * تنها چیزی که جلوی یک بدنهٔ تزریق‌شدهٔ چندخطی را می‌گیرد همین است.
     *
     * @return array{path: string, regex: string, params: list<string>}|null
     */
    private function compilePath(string $slug, string $declared): ?array
    {
        $segments = $this->segmentize($declared);

        if ($segments === null) {
            return null;
        }

        $pattern = [];
        $params = [];
        $names = [];

        foreach ($segments as $segment) {
            if (preg_match(self::PARAM_NAME_PATTERN, $segment, $m) === 1) {
                $name = $m[1];

                // پارامتر تکراری در یک الگو یعنی نامش دو بار در `preg_match`
                // پر می‌شود و دومی اولی را می‌بندد. جایی برای حدس زدن نیست.
                if (isset($names[$name])) {
                    $this->reject($slug, 'param_duplicate', ['param' => $name]);

                    return null;
                }

                $names[$name] = true;
                $params[] = $name;
                $pattern[] = self::PATH_PARAM_CAPTURE;

                continue;
            }

            if (preg_match(self::PATH_SEGMENT_PATTERN, $segment) !== 1) {
                $this->reject($slug, 'path_malformed', ['path' => $declared]);

                return null;
            }

            $pattern[] = preg_quote($segment, '#');
        }

        return [
            'path' => implode('/', $segments),
            'regex' => '#^'.implode('/', $pattern).'$#D',
            'params' => $params,
        ];
    }

    /**
     * هندلر `Class@method`.
     *
     * قاعدهٔ namespace یکی‌درمیان با `PluginAutoloader` است: ریشهٔ اجباری
     * `Pishdad\Plugins\{StudlySlug}\`. **جداکنندهٔ تهانیه خودش «دقیقاً یک سطح
     * زیر ریشه» را enforce می‌کند** — `Pishdad\Plugins\Demo` با پیشوندی که به
     * `\` ختم می‌شود شروع نمی‌شود، پس کلاسی که مستقیم در ریشهٔ بسته بنشیند
     * (که بستهٔ افزونه است، نه پیاده‌سازیِ route) همین‌جا می‌افتد. شرط جداگانهٔ
     * «طول» اضافه کردنش یعنی ادعای دفاعی که از قبل در داده هست.
     *
     * `@` با `explode(..., 2)` شکسته می‌شود نه با `strrpos`: نام کلاس PHP نمی‌تواند
     * `@` داشته باشد، پس اولین `@` تنها جداکنندهٔ ممکن است، و سقف ۲ یعنی `@`
     * دوم نمی‌تواند به نام متد چسبیده و یک کلاس دروغین بسازد.
     *
     * نام متدِ `__`-دار رد می‌شود: `__construct` و هم‌خانواده‌هایش جادویی‌اند و
     * dispatch کردن به آن‌ها یعنی اجازهٔ کنترل چرخهٔ حیات شیء به افزونه.
     *
     * بررسی identifier بودن هر segment نام کلاس بی‌خطر است — نام کلاس مسیر نیست
     * — ولی نگه‌داشته شده چون همان بررسی تنها چیزی است که `..` و segment تهی
     * را می‌گیرد، و روزی که کسی نام کلاس را به `require` بدهد غیب می‌شود.
     *
     * @return array{class: string, method: string}|null
     */
    private function compileHandler(string $slug, string $namespace, string $raw): ?array
    {
        $parts = explode('@', $raw, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$class, $method] = $parts;

        if (! str_starts_with($class, $namespace)) {
            return null;
        }

        foreach (explode('\\', substr($class, strlen($namespace))) as $segment) {
            if (preg_match(self::CLASS_SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        if (preg_match(self::METHOD_NAME_PATTERN, $method) !== 1 || str_starts_with($method, '__')) {
            return null;
        }

        return ['class' => $class, 'method' => $method];
    }

    /**
     * فهرست میدلورها، یا null اگر حتی یکی خارج از قرارداد هسته باشد.
     *
     * **کل بستهٔ route دور انداخته می‌شود، نه فقط همان مورد.** یک رشتهٔ ناشناخته
     * یعنی نویسنده چیزی می‌خواسته که هسته مالکش نیست؛ حدس‌زدن اینکه «شاید
     * بی‌خطر بود» خلافِ deny-by-default است.
     *
     * @return list<string>|null
     */
    private function compileMiddleware(string $slug, mixed $declared): ?array
    {
        if ($declared === null) {
            return [];
        }

        if (! is_array($declared)) {
            $this->reject($slug, 'middleware_not_a_list');

            return null;
        }

        $out = [];

        foreach ($declared as $entry) {
            if (! is_string($entry)) {
                $this->reject($slug, 'middleware_not_a_string');

                return null;
            }

            [$alias, $argument] = array_pad(explode(':', $entry, 2), 2, null);

            if ($alias === 'perm') {
                // فقط پرمیشنی که خود هسته برای *همین* افزونه ساخته.
                // `ManifestRegistry::manifestPermissions()` نام را
                // `plugin:{slug}:{module}.{action}` می‌سازد، پس شکل مجاز دقیقاً
                // همان است — و نتیجهٔ امنیتی روشن: افزونه نمی‌تواند پرمیشن
                // هسته یا افزونهٔ دیگر را در route خودش اعلام کند.
                $tail = is_string($argument) && str_starts_with($argument, 'plugin:'.$slug.':')
                    ? substr($argument, strlen('plugin:'.$slug.':'))
                    : null;

                if ($tail === null || preg_match(self::PERMISSION_TAIL_PATTERN, $tail) !== 1) {
                    $this->reject($slug, 'permission_not_owned', ['middleware' => $entry]);

                    return null;
                }

                $out[] = $entry;

                continue;
            }

            $rule = self::MIDDLEWARE_RULES[$alias] ?? null;

            // میان‌افزارِ کاملِ بدون-آرگومان، مثل `shop.operator`.
            //
            // مقایسه روی **رشتهٔ کامل** است نه alias. اگر با alias می‌سنجیدیم،
            // `shop.operator` با `['shop', 'operator']` تجزیه می‌شد و alias
            // برابر `shop` می‌شد، پس `in_array` هیچ‌وقت true نمی‌شد.
            //
            // شرطِ `argument === null` عمدی است: اگر alias در فهرست باشد ولی
            // آرگومانی هم بدهد، یعنی کسی دارد چیزی را طور دیگری جا می‌زند و
            // نباید بی‌صدا به همان کلاس برسد.
            if (in_array($entry, self::MIDDLEWARE_ALIASES, true)) {
                if ($argument !== null) {
                    $this->reject($slug, 'middleware_takes_no_argument', ['middleware' => $entry]);

                    return null;
                }

                $out[] = $entry;

                continue;
            }

            // `role:<role>` — نقش از مانیفست می‌آید.
            //
            // قبلاً aliasِ بدون آرگومانِ `shop.operator` بود. این نسخه عمومی است
            // تا افزونهٔ بعدی هم بدون ویرایش هسته، routeهای خودش را محافظت کند.
            // آرگومان **اجباری** است: بدون آن، نگهبان اصلاً معنا ندارد و سکوتاً همه
            // را رد می‌کرد — بدتر از نبودنش چون دیباگ‌کردنش سخت است.
            if ($alias === self::ROLE_MIDDLEWARE_ALIAS) {
                if (! is_string($argument)
                    || ! RequireUserRole::isInjectableRole($argument)
                ) {
                    $this->reject($slug, 'middleware_bad_role_argument', [
                        'middleware' => $entry,
                        'argument' => is_string($argument) ? $argument : null,
                    ]);

                    return null;
                }

                $out[] = $entry;

                continue;
            }

            if ($rule === null || ! is_string($argument) || preg_match($rule, $argument) !== 1) {
                $this->reject($slug, 'middleware_not_core_owned', ['middleware' => $entry]);

                return null;
            }

            $out[] = $entry;
        }

        return array_values(array_unique($out));
    }

    // ── کمکی ──────────────────────────────────────────────────────────────

    /**
     * segmentهای یک مسیر **درخواستی**، یا null اگر ناامن باشد.
     *
     * تنها تفاوتش با `segmentize()` همین بررسی محتواست: مسیرِ آمده از
     * `{any?}` هیچ `{name}`ای ندارد، پس هر segment باید الفبای ثابت را پاس کند.
     * مسیرِ اعلامی در `compilePath()` جداگانه همین را می‌گیرد و آنجا `{name}`
     * هم معتبر است — هم‌کردن این دو یعنی مانیفست نمی‌تواند پارامتر اعلام کند.
     *
     * @return list<string>|null رشتهٔ تهی ⇒ مسیر ریشه، که معتبر است
     */
    private static function normalizePath(string $path): ?array
    {
        $segments = self::segmentize($path);

        if ($segments === null) {
            return null;
        }

        foreach ($segments as $segment) {
            if (preg_match(self::PATH_SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * بریدن یک مسیر به segmentها + قاعده‌های امنیتیِ مستقل از محتوا.
     *
     * **هیچ بررسی جداگانه‌ای برای `\`، NUL یا `..` اینجا نیست و لازم هم نیست.**
     * الفبای segment این‌ها را ذاتاً رد می‌کند، پس یک شرط واحد به‌جای فهرستی از
     * شکل‌های traversal — همان معاوضه‌ای که `PluginAutoloader::isSafeRelativePath`
     * هم می‌کند. شرط جدا اضافه کردنش نه اطلاعات تازه‌ای می‌دهد و نه با تغییر
     * الفبا کسی یادش می‌ماند آن را هم عوض کند. هر دو مصرف‌کننده، محتوا را بعداً
     * و به یک شکل می‌سنجند.
     *
     * سقف عمق کارِ regex را کران‌دار می‌کند و پیش از هر تفسیری می‌آید.
     *
     * دو مصرف‌کننده دارد (مسیر درخواستی و مسیر اعلامی) تا پیش از بررسی محتوا
     * دقیقاً یک رفتار داشته باشند؛ فرقشان در محتواست نه در امنیت.
     *
     * @return list<string>|null
     */
    private static function segmentize(string $path): ?array
    {
        $path = trim($path, '/');

        if ($path === '') {
            return [];
        }

        $segments = explode('/', $path);

        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }

        return $segments;
    }

    /**
     * نام‌های پارامتر → مقادیرشان.
     *
     * **مکان‌نما، نه کلید نام.** گروه‌های ضبط عمداً بی‌نام‌اند (`[…]{1,64}`) و
     * شمارهٔ گروه n+1 همان nاُمین پارامتر است — چون `compilePath()` هر دو را با
     * هم و به یک ترتیب به آرایه اضافه می‌کند. گروه بی‌نام یک سطح از نگاشت کم
     * دارد: هیچ نامی که از مانیفست آمده داخل الگوی regex نمی‌نشیند.
     *
     * @param  list<string>  $names  به همان ترتیبِ گروه‌های ضبط
     * @param  array<int|string, string>  $captures  خروجی `preg_match`
     * @return array<string, string>
     */
    private function namedParams(array $names, array $captures): array
    {
        $out = [];

        foreach (array_values($names) as $offset => $name) {
            $out[$name] = $captures[$offset + 1] ?? '';
        }

        return $out;
    }

    /**
     * رد شدن با دلیل — همیشه با لاگ.
     *
     * لاگ در زمان ساخت جدول است، نه در زمان درخواست، پس ضدّسیل لازم نیست؛ ولی
     * بی‌صدا هم نه: بی‌صدا یعنی «افزونه نصب شد و اصلاً کار نمی‌کند» و دقیقاً همان
     * چیزی است که `ManifestRegistry::recordCollision()` برای رفعش نوشته شد.
     *
     * @param  array<string, mixed>  $context
     */
    private function reject(string $slug, string $reason, array $context = []): void
    {
        Log::warning('plugin.route_rejected', array_merge(['slug' => $slug, 'reason' => $reason], $context));
    }
}
