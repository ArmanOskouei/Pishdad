<?php

namespace App\Providers;

use App\Services\Audit\AuditSink;
use App\Services\Audit\AuditTrail;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\ZarinpalGatewayDriver;
use App\Services\Billing\ManualBillingDriver;
use App\Services\Chabokan\ChabokanClientInterface;
use App\Services\Chabokan\ChabokanDriverFactory;
// بدون این `use`، PHP این نام را داخل `App\Providers` حل می‌کرد و کل اپ
// با BindingResolutionException بالا نمی‌آمد (کلاس در `App\Services\Plugins` است).
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\ServiceProviderRegistry;
use App\Services\RevalidateSigner;
use App\Services\Sms\SmsDriverFactory;
use App\Services\Sms\SmsSenderInterface;
use App\Services\Subscription\NullSubscriptionResolver;
use App\Services\Subscription\NullSubscriptionSource;
use App\Services\Subscription\SubscriptionResolverInterface;
use App\Services\Subscription\SubscriptionSource;
use App\Validation\NoMarkup;
use App\Validation\SafeUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            RevalidateSigner::class,
            fn () => new RevalidateSigner(
                (string) config('revalidate.secret'),
                (int) config('revalidate.leeway', 300),
            )
        );

        // K5.0-W — بارگذارِ کلاس باید **یک نمونه** باشد.
        //
        // `register()`/`unregister()` روی وضعیتِ درونیِ همین شیء کار می‌کنند،
        // پس دو نمونه یعنی دو جدولِ جدا: `boot()` روی یکی ثبت می‌کرد و
        // `deactivate()` روی دیگری برمی‌داشت — و افزونهٔ غیرفعال‌شده همچنان
        // کلاسش را resolve می‌کرد. بدون این singleton، چرخهٔ عمر بی‌اثر است.
        $this->app->singleton(PluginAutoloader::class);

        // K5.0 — این سه binding دیگر مستقیم به درایور وصل نمی‌شوند. رجیستری
        // نقطهٔ `core.service_provider` است: اگر افزونهٔ فعال و تأییدشده‌ای
        // پیاده‌سازی معتبری اعلام کرده باشد، همان بارگذاری می‌شود؛ وگرنه
        // درایور پیش‌فرض هسته. یعنی هسته دیگر نام درایور را انتخاب نمی‌کند و
        // افزونه می‌تواند بدون دست‌زدن به هسته تعویضش کند.
        $registry = $this->app->make(ServiceProviderRegistry::class);

        // درگاه پرداخت مرکزی: در MVP درایور Stub زرین‌پال (بدون شارژ واقعی).
        // K8.6 — درگاه پرداخت: پیش‌فرض «دستی» (هیچ شارژی). اگر اپراتور درایورِ
        // zarinpal را با merchant_id واقعی تنظیم کند، همان درایورِ واقعی bind
        // می‌شود؛ در غیر این صورت ManualBillingDriver می‌ماند تا هیچ‌وقت جریانِ
        // پرداختِ ساختگی ادعا نشود.
        $registry->bind(
            PaymentGatewayInterface::class,
            function () {
                $cfg = (array) config('billing.gateway', []);
                $driver = (string) ($cfg['driver'] ?? 'manual');
                $merchantId = trim((string) ($cfg['merchant_id'] ?? ''));

                if ($driver === 'zarinpal' && $merchantId !== '') {
                    return new ZarinpalGatewayDriver($merchantId, (bool) ($cfg['sandbox'] ?? true));
                }

                return new ManualBillingDriver;
            }
        );

        // چابکان مرکزی (تسک ۳.۳ / E4): انتخابِ درایور از روی `CHABOKAN_DRIVER`.
        //
        // پیش از این اینجا `new StubChabokanDriver` هاردکد بود، یعنی حتی با
        // `CHABOKAN_DRIVER=api` هم stub برمی‌گشت و تنظیمِ اپراتور بی‌اثر بود.
        // حالا `ChabokanDriverFactory` نام را از کانفیگ می‌خواند و — اگر کسی
        // `api` را انتخاب کرده باشد ولی `CHABOKAN_API_TOKEN` نداده باشد —
        // exception می‌دهد با پیامِ فارسی که **نامِ دقیقِ کلید** را می‌گوید.
        // یعنی حالتِ «فکر می‌کنم وصل است ولی نیست» دیگر ممکن نیست.
        $registry->bind(
            ChabokanClientInterface::class,
            fn () => ChabokanDriverFactory::make()
        );

        // کانال پیامک (E3). هم از رجیستری، پس یک افزونه می‌تواند درگاهِ خودش را
        // اعلام کند و بدونِ دست‌زدن به هسته عوضش کند. پیش‌فرض همیشه
        // `SmsDriverFactory` است که خودش fail-closed است.
        $registry->bind(
            SmsSenderInterface::class,
            fn () => SmsDriverFactory::make()
        );

        // K7.3 — منبعِ وضعیتِ اشتراک هم از همین رجیستری می‌آید.
        //
        // پیش از این، `ClientSubscriptionResolver` افزونهٔ مرکزی را با یک
        // `class_exists` هاردکد صدا می‌زد. نتیجه: افزونهٔ سومی **هیچ راهی**
        // برای اعلامِ وضعیتِ اشتراک نداشت، حتی اگر کاملاً سالم بود. حالا همان
        // نقطهٔ اتصالِ K5.0 اینجا هم فعال است و پیش‌فرض همیشه
        // `NullSubscriptionSource` است — یعنی «سایت فعالِ بی‌اشتراک».
        $registry->bind(
            SubscriptionSource::class,
            fn () => new NullSubscriptionSource
        );

        // J12 — resolverِ دادهٔ اشتراک.
        //
        // پیش‌فرض عمداً `NullSubscriptionResolver` است: نسخهٔ رایگان هیچ مسیرِ
        // کدِ اشتراکِ دور ندارد و نصبِ تازه نباید هیچ چیزی برای نمایش داشته باشد.
        // انتخابِ پیاده‌سازی به **binding** منتقل شد (نه به شرطِ `remote_url` در
        // هسته)، پس فردا با `SUBSCRIPTION_RESOLVER` یا rebind می‌شود روشنش کرد —
        // بدون اینکه هسته نامِ هیچ کلاسِ قرنطینه‌شده‌ای را بداند.
        $this->app->bind(SubscriptionResolverInterface::class, function () {
            $class = config('subscription.resolver');

            if (is_string($class) && $class !== '' && class_exists($class)
                && is_a($class, SubscriptionResolverInterface::class, true)) {
                try {
                    return new $class;
                } catch (\Throwable $e) {
                    Log::warning('subscription.resolver_unresolvable', [
                        'class' => $class,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }

            return new NullSubscriptionResolver;
        });
    }

    public function boot(): void
    {
        $this->bootBundledPlugins();

        // F0.1 / F0.2 — قاعده‌های اعتبارسنجی سراسری.
        //
        // اینها «پیش‌فرض امن» را در لحظهٔ نوشتن می‌سازند، نه در لحظهٔ رندر. دلیل:
        // هر رندرر تازه‌ای که اضافه شود (بلوک پلاگینی، ویجت، ایمیل) به همان
        // فیلد می‌رسد؛ اگر فقط فرانت پاک‌سازی کند، آن رندرر یا باید sanitize
        // را به یاد بیاورد یا سوراخ است — و این در بازبینی قابل تشخیص نیست.
        //
        // ⚠️ `extend` یک `bool` می‌خواهد. برگرداندن یک شیء `ValidationRule`
        // یعنی truthy ⇒ **همه‌چیز رد می‌شود** و قاعده بی‌اثر ولی ظاهراً فعال
        // می‌ماند. برای همین منطق در متدهای استاتیک است و closure فقط صدایش
        // می‌زند.
        Validator::extend(
            'no_markup',
            fn (string $attribute, mixed $value): bool => NoMarkup::passes($value)
        );
        Validator::extend(
            'safe_href',
            fn (string $attribute, mixed $value): bool => SafeUrl::passes($value, 'href')
        );
        Validator::extend(
            'safe_src',
            fn (string $attribute, mixed $value): bool => SafeUrl::passes($value, 'src')
        );
        Validator::replacer('no_markup', fn (string $message, string $attribute) => 'این مقدار نباید شامل علامت «<» باشد.');
        Validator::replacer('safe_href', fn (string $message, string $attribute) => SafeUrl::message('href'));
        Validator::replacer('safe_src', fn (string $message, string $attribute) => SafeUrl::message('src'));

        // API-only: password-reset links go to the Next.js page, not a Blade route.
        // (Default ResetPassword notification needs a `password.reset` named route.)
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $base = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

            return "{$base}/reset-password?token={$token}&email=".urlencode($user->getEmailForPasswordReset());
        });

        // فرم تماس عمومی (تسک ۵.۳): سفت — ۵ تلاش در دقیقه برای هر IP.
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'درخواست‌های شما بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.'],
                        429
                    );
                });
        });
        // جستجوی عمومی سایت: سفت — ۳۰ درخواست در دقیقه برای هر IP.
        RateLimiter::for('site-search', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(
                        ['message' => 'درخواست‌های شما بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.'],
                        429
                    );
                });
        });
        // Brute-force lock for auth: 10 attempts / 15 min per (email + ip),
        // identical Persian 429 message (no information leak).
        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinutes(15, 10)
                ->by($request->input('email', '').'|'.$request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'تلاش‌های ورود بیش از حد مجاز است. لطفاً دقایقی دیگر تلاش کنید.',
                    ], 429);
                });
        });
    }

    /**
     * K7.8 — ثبت PSR-4 افزونه‌های داخلی، در هر درخواست.
     *
     * ## چرا اینجا و نه فقط کنار dispatcher
     *
     * `PluginDispatcher` بارگذار را **تنبل** صدا می‌زند: فقط وقتی خواسته‌ای به
     * مسیر افزونه می‌رسد. ولی پس از انتقال مرکز، کدِ هسته هم مدل‌های مرکزی را
     * صدا می‌زند — `PaymentWebhookController` روی جدول فاکتور می‌نویسد،
     * `HeartbeatController` به جدول مشتریان می‌خواند، و
     * `SubscriptionStatusController` به جدول اشتراک‌ها.
     *
     * آن مسیرها هرگز از `PluginDispatcher` رد نمی‌شوند، پس namespace افزونه
     * هنوز ثبت نشده بود و کلاس پیدا نمی‌شد. نتیجه یک `Error: Class not found`
     * در **heartbeat نصب‌ها** و **callback درگاه** بود — یعنی دقیقاً دو جایی که
     * نباید بشکنند.
     *
     * ثبت زودهنگام این مشکل را حل می‌کند و برای افزونهٔ داخلی **درست** است: این
     * بسته با `git pull` می‌آید، قابل غیرفعال‌سازی نیست، و هرگز در جدول
     * `system_plugins` هم نمی‌نشیند چون اصلاً نصب نمی‌شود.
     *
     * ## چرا فقط `source` و نه بسته‌های بازاری
     *
     * بستهٔ بازاری همچنان تنبل بارگذاری می‌شود. زودهنگام‌کردنش هم هزینهٔ راه‌اندازیِ
     * هر request را برای افزونه‌هایی می‌پردازد که شاید اصلاً در آن درخواست
     * استفاده نشوند، و هم بسته‌ای را که کاربر می‌تواند غیرفعال کند عملاً بی‌اختیار
     * بارگذاری می‌کند — که خلاف قرارداد «افزونهٔ غیرفعال هیچ مسیری ندارد» است.
     *
     * @see PluginReleaseManager::sourceAutoloadRoot()
     */
    private function bootBundledPlugins(): void
    {
        $releases = $this->app->make(PluginReleaseManager::class);
        $autoloader = $this->app->make(PluginAutoloader::class);

        foreach ($this->bundledPluginSources($releases) as $slug => $source) {
            $root = $source['root'];
            $dir = $source['dir'];

            try {
                $autoloader->register(
                    $slug,
                    $root,
                    PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\',
                );
            } catch (InvalidArgumentException $e) {
                // ثبتِ یک بستهٔ داخلی نباید کل سایت را بالا نیاورد. بستهٔ
                // ناسالم یک هشدار می‌ماند نه یک صفحهٔ ۵۰۰ برای همه.
                Log::warning('bundled_plugin_register_failed', [
                    'slug' => $slug,
                    'reason' => $e->getMessage(),
                ]);
            }

            $this->loadBundledPluginConsoleRoutes($slug, $dir);
            $this->registerBundledPluginCommands($slug, $root);
            $this->registerBundledAuditSink($slug);
        }
    }

    /**
     * J11 — فهرستِ بسته‌های داخلیِ قابل بارگذاری.
     *
     * منبعِ اصلی `plugins/{slug}` است (رفتارِ تولید). ولی در **محیط تست**
     * بسته‌های قرنطینه‌شدهٔ `plugins/_quarantine/{slug}` هم مثل بستهٔ داخلی
     * بارگذاری می‌شوند تا مجموعهٔ تستِ خصوصیِ همین مخزن رفتارِ قبلی‌اش را حفظ
     * کند. این شاخه هرگز در تولید اجرا نمی‌شود (`runningUnitTests()`).
     *
     * @return array<string, array{root: string, dir: string}>
     */
    private function bundledPluginSources(PluginReleaseManager $releases): array
    {
        $out = [];

        foreach ($releases->sourceSlugs() as $slug) {
            $root = $releases->sourceAutoloadRoot($slug);
            $dir = $releases->sourceDir($slug);

            if ($root !== null && $dir !== null) {
                $out[$slug] = ['root' => $root, 'dir' => $dir];
            }
        }

        if (! $this->app->runningUnitTests()) {
            return $out;
        }

        foreach (glob(base_path('plugins/_quarantine/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);

            if (isset($out[$slug]) || preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $slug) !== 1) {
                continue;
            }

            $root = $dir.'/'.PluginPackageContract::BACKEND_ROOT.'/src';

            if (is_dir($root)) {
                $out[$slug] = ['root' => $root, 'dir' => $dir];
            }
        }

        return $out;
    }

    /**
     * J5 — افزونه‌ای که جدولِ لاگِ ممیزیِ خودش را دارد، می‌تواند آن را وصل کند.
     *
     * ## چرا این هم عمومی است
     *
     * پیش‌تر `DevModeAudit` مستقیم `Central\Models\OperatorActivity` را صدا
     * می‌زد و در `KNOWN_VIOLATIONS` استثنا شده بود. آن استثنا یعنی «هسته می‌داند
     * افزونهٔ مرکزی کلاسِ ممیزی دارد» — دقیقاً همان وابستگی‌ای که این فاز
     * حذفش می‌کند.
     *
     * حالا هر افزونه می‌تواند یک `AuditSink` اعلام کند و هسته فقط می‌نویسد.
     * قرارداد در `manifest.json` است، نه در کدِ هسته.
     *
     * نکته: کلاس اگر وجود نداشته باشد `instanceof` شکست می‌خورد و بی‌صدا رد
     * می‌شود. یک افزونهٔ ناقص نباید سایت را بالا نیاورد، ولی لاگِ ممیزیِ
     * هسته هم از کار نیفتد.
     */
    private function registerBundledAuditSink(string $slug): void
    {
        $class = 'Pishdad\\Plugins\\'.Str::studly($slug).'\\Audit\\OperatorActivitySink';

        try {
            if (! class_exists($class)) {
                return;
            }

            $sink = new $class;

            if ($sink instanceof AuditSink) {
                AuditTrail::pushSink($sink);
            }
        } catch (Throwable $e) {
            Log::warning('bundled_plugin_audit_sink_failed', [
                'slug' => $slug,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * J5 — فرمان‌های Artisanِ افزونه باید از سمت خودِ افزونه ثبت شوند.
     *
     * ## چرا لازم شد
     *
     * سه فرمان `billing:*` قبلاً در `app/Console/Commands/` هسته بودند و روی
     * جدول‌های افزونه کار می‌کردند — یعنی هسته برای کاری که مالِ افزونه بود،
     * به مدل‌های افزونه وابسته بود. حالا کلاس‌ها داخل بسته‌اند.
     *
     * ولی **جابه‌جایی فایل کافی نبود**: PSR-4 فقط کلاس را قابل بارگذاری می‌کند،
     * نه ثبت‌شده. تا وقتی فرمانی با `$this->commands([...])` ثبت نشود، در
     * `Artisan::all()` غایب است و `schedule:run` فرمان را «ناموجود» رد می‌کند.
     *
     * و آن بدترین حالت است: **بی‌صدا**. زمان‌بندیِ افزونه هر روز سه فرمانِ
     * ناشناخته صدا می‌زند، هیچ خطایی نمی‌دهد، و کسی نمی‌فهمد تعلیقِ اشتراک
     * ماه‌ها اجرا نشده. دقیقاً همان چیزی که تست مرز برایش نوشته شده.
     *
     * ## چرا فهرست در هسته نیست
     *
     * این متد **نامِ فرمانی را نمی‌داند** — فقط هر کلاسِ Command را که در
     * `Laravel/src/Console/Commands/` پیدا می‌کند ثبت می‌کند. پس افزونهٔ تازه
     * بدون دست‌زدن به هسته فرمان‌هایش را می‌گیرد.
     *
     * ⚠️ ثبت با `Application::starting()` انجام می‌شود، نه مستقیم. دلیلش دو تاست:
     *
     *  ۱. در لحظهٔ `boot()` هنوز اپلیکیشن کنسول ساخته نشده، پس فرمانی که
     *     همان‌جا `add()` شود در `Artisan::all()` ظاهر نمی‌شود.
     *  ۲. `Illuminate\Contracts\Console\Kernel` **متد `all()` ندارد** — متد
     *     روی کلاسِ concrete است و در boot قابل اتکا نیست. تلاش برای
     *     dedupe با آن، `Cannot call abstract method` می‌داد و **کل بوت
     *     افزونه‌ها را می‌شکست**، نه فقط ثبت فرمان‌ها را.
     *
     * پس همان callback‌ای استفاده می‌شود که خود `ServiceProvider::commands()`
     * در Laravel دارد، و dedupe داخلش انجام می‌شود جایی که `$artisan` واقعی
     * در دست است.
     */
    private function registerBundledPluginCommands(string $slug, string $root): void
    {
        $classes = [];

        foreach (glob($root.'/Console/Commands/*.php') ?: [] as $file) {
            $class = PluginAutoloader::NAMESPACE_ROOT
                .Str::studly($slug).'\\Console\\Commands\\'
                .basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $classes[] = $class;
            }
        }

        if ($classes === []) {
            return;
        }

        Application::starting(function ($artisan) use ($classes): void {
            foreach ($classes as $class) {
                $instance = new $class;

                // دو افزونه می‌توانند نامِ فرمانِ یکسان اعلام کنند. بدون این
                // گارد، دومی ساکتانه جای اولی را می‌گیرد و هیچ‌کس نمی‌فهمد.
                if ($artisan->has($instance->getName())) {
                    Log::warning('bundled_plugin_command_duplicate', [
                        'command' => $instance->getName(),
                        'class' => $class,
                    ]);

                    continue;
                }

                $artisan->add($instance);
            }
        });
    }

    /**
     * J5 — میزبانِ فرمان‌های کنسولی برای افزونه‌های داخلی.
     *
     * ## چرا فایل‌محور و نه از مانیفست
     *
     * `api.routes` در مانیفست **HTTP** است و با `PluginRouteTable` کامپایل می‌شود.
     * زمان‌بندیِ `Schedule` اصلاً مسیر نیست، پس در آن ساختار جایی ندارد؛ و
     * افزودنش به مانیفست یعنی یک کلید تازه در قراردادِی که ده‌ها تست رویش
     * قفل گذاشته‌اند. یک قراردادِ قراردادی (`Laravel/routes/console.php`) هم
     * ارزان‌تر است و هم با بقیهٔ لاراول هم‌خوان می‌ماند.
     *
     * ## چرا `boot` و نه `register`
     *
     * `Schedule::command()` روی facade است و باید بعد از بالا آمدنِ
     * `Schedule` صدا زده شود — دقیقاً مثل `routes/console.php` خودِ هسته که
     * لاراول در فازِ `commands` بار می‌کند.
     *
     * ## چرا خطای `require` نباید سایت را بیندازد
     *
     * دقیقاً مثل ثبتِ autoload بالا: بستهٔ داخلیِ ناسالم یک هشدار می‌ماند، نه
     * صفحهٔ ۵۰۰ برای همه. نبودِ فایل هم **خطا نیست** — بیشتر افزونه‌ها فرمانِ
     * کنسولی ندارند.
     */
    private function loadBundledPluginConsoleRoutes(string $slug, string $dir): void
    {
        $file = $dir.'/'.PluginPackageContract::BACKEND_ROOT.'/routes/console.php';

        if (! is_file($file)) {
            return;
        }

        try {
            require $file;
        } catch (\Throwable $e) {
            Log::warning('bundled_plugin_console_routes_failed', [
                'slug' => $slug,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
