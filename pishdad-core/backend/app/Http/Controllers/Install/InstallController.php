<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * F1.1.F — کنترلر نصب وب (Blade + progressive enhancement، بدون React).
 *
 * ترتیب گام‌ها (InstallJournal::STEPS) و resume از ژورنال: هر GET/POST اول
 * وضعیت نصب/ژورنال را می‌سنجد. هیچ دکمه «رد کردن»‌ای وجود ندارد.
 *
 * امنیت بدون web middleware: هر POST به `install_token` سمت‌سرور (ژورنال)
 * نیاز دارد؛ مقایسه با hash_equals.
 */
class InstallController extends Controller
{
    public const STEP_ROUTES = [
        'preflight' => 'install.preflight',
        'database' => 'install.database',
        'app_key' => 'install.app-key',
        'migrate' => 'install.migrate',
        'superadmin' => 'install.superadmin',
        'finalize' => 'install.finalize',
    ];

    // ─── شروع / resume ──────────────────────────────────────────────

    public function start(Request $request): RedirectResponse|Response
    {
        $this->resolveLocale($request);
        if (InstallJournal::isInstalled()) {
            abort(404);
        }
        $step = InstallJournal::firstIncompleteStep() ?? 'preflight';
        // E18: گذر وب گام‌های ۳ (کلید) و ۴ (مهاجرت) را در صفحهٔ prepare
        // ادغام می‌کند؛ resume هم باید به همان‌جا برگردد نه به صفحه‌های جدا.
        if (in_array($step, ['app_key', 'migrate'], true)) {
            return redirect()->route('install.prepare');
        }

        return redirect()->route(self::STEP_ROUTES[$step]);
    }

    // ─── تغییر زبان نصب (E20) ──────────────────────────────────────
    //
    // نصب‌کننده نه session دارد نه web middleware؛ پس زبان در کوکیِ ساده
    // (`install_lang`) + ژورنال می‌ماند و با `?lang=` عوض می‌شود. `back`
    // عمداً فقط مسیرِ نسبیِ داخل `/install` است (نه URL کامل) تا ریدایرکتِ
    // باز پیش نیاید.

    public function switchLang(string $lang, Request $request): RedirectResponse
    {
        if (! in_array($lang, ['fa', 'en'], true)) {
            abort(404);
        }
        InstallJournal::put('lang', ['code' => $lang]);
        App::setLocale($lang);
        $back = '/'.ltrim((string) $request->query('back', ''), '/');
        if (! str_starts_with($back, '/install')) {
            $back = route('install.start');
        }

        return redirect($back)->cookie(cookie()->forever('install_lang', $lang));
    }

    /**
     * زبانِ این ریکوئست را مشخص و ست می‌کند: `?lang=` ← کوکی ← ژورنال ←
     * پیش‌فرض فارسی. عمداً از هدر Accept-Language استفاده نمی‌شود تا زبانِ
     * پیش‌فرضِ محصول (فارسی) برای همه پایدار بماند؛ انگلیسی فقط با انتخابِ
     * صریح کاربر (?lang= یا سوییچر) فعال می‌شود و همان در ژورنال/کوکی می‌ماند.
     * هر اکشنِ نصب اول همین را صدا می‌زند.
     */
    private function resolveLocale(Request $request): string
    {
        $lang = (string) $request->query('lang', '');
        if (! in_array($lang, ['fa', 'en'], true)) {
            $lang = (string) $request->cookie('install_lang', '');
        }
        if (! in_array($lang, ['fa', 'en'], true)) {
            $stored = InstallJournal::get('lang');
            $lang = isset($stored['code']) && in_array($stored['code'], ['fa', 'en'], true)
                ? (string) $stored['code']
                : '';
        }
        if ($lang === '') {
            $lang = 'fa';
        }
        if ((string) $request->query('lang', '') !== '') {
            InstallJournal::put('lang', ['code' => $lang]);
        }
        App::setLocale($lang);

        return $lang;
    }

    // ─── گام ۱: preflight ───────────────────────────────────────────

    public function preflight(Request $request): View|JsonResponse
    {
        $this->resolveLocale($request);
        $this->ensureNotInstalled();
        $checks = Preflight::run();
        $blocking = Preflight::blockingFailures($checks);

        if ($request->wantsJson()) {
            return response()->json(['data' => ['checks' => $checks, 'blocking_failures' => $blocking]]);
        }

        return view('install.preflight', [
            'checks' => $checks,
            'blocking' => $blocking,
            'token' => InstallJournal::token(),
            'step' => 'preflight',
            'formError' => null,
            'old' => [],
        ]);
    }

    public function preflightConfirm(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->resolveLocale($request);
        $this->ensureNotInstalled();
        $this->checkToken($request);
        $checks = Preflight::run();

        if (Preflight::blockingFailures($checks)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('install.msgs.preflight_blocked'), 'data' => ['checks' => $checks]], 422);
            }

            // بدون session رندر مستقیم با ۴۲۲ (نه ریدایرکت با withErrors).
            return response()->view('install.preflight', [
                'checks' => $checks,
                'blocking' => true,
                'token' => InstallJournal::token(),
                'step' => 'preflight',
                'formError' => __('install.msgs.preflight_blocked_hint'),
                'old' => [],
            ], 422);
        }

        InstallJournal::markStepDone('preflight');

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.preflight_ok'), 'data' => ['next' => route('install.database')]]);
        }

        return redirect()->route('install.database');
    }

    // ─── گام ۲: database ────────────────────────────────────────────

    public function database(): View
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('database');

        return view('install.database', [
            'config' => $this->databasePrefill(),
            'token' => InstallJournal::token(),
            'step' => 'database',
            'formError' => null,
            'old' => [],
        ]);
    }

    /**
     * مقادیرِ اولیهٔ **فرمِ** گام ۲.
     *
     * ## چرا این از `.env` نمی‌خواند
     *
     * پیش از این، ورودیِ این متد `Preflight::dbConfig()` بود و آن `.env` را هم
     * می‌خواند. نتیجه: روی هر محیطی که `.env` مقدارِ توسعه داشت (مثل `cms`)،
     * فرم هم همان را نشان می‌داد و پیش‌فرضِ `Pishdad` هرگز دیده نمی‌شد.
     *
     * ولی `.env` منبعِ درستی برای پیش‌پرکردنِ فرم نیست: این نصب‌کننده **خودش**
     * `.env` را می‌نویسد، پس مقداری که آنجاست یا از نصبِ قبلی مانده یا از
     * فایلِ نمونه. منبعِ درستِ «ادامه دادنِ نصبِ نیمه‌کاره» ژورنال است.
     *
     * پس ترتیب این است: ژورنال (که کاربر خودش در همین نصب ذخیره کرده) ← وگرنه
     * پیش‌فرضِ `Pishdad`. بررسی‌های گام ۱ همچنان `Preflight::dbConfig()` را
     * می‌خوانند، چون آن‌ها **باید** `.env` را ببینند تا بگویند اتصالِ موجود
     * سالم است یا نه.
     *
     * @param  array<string, string>  $submitted  مقادیرِ همین ارسال، وقتی اعتبارسنجی رد شده
     */
    private function databasePrefill(array $submitted = []): array
    {
        $journal = InstallJournal::get('db');

        return $this->withDatabaseDefaults([
            'host' => $submitted['host'] ?? $journal['host'] ?? '',
            'port' => $submitted['port'] ?? $journal['port'] ?? '',
            'database' => $submitted['database'] ?? $journal['database'] ?? '',
            'username' => $submitted['username'] ?? $journal['username'] ?? '',
            'password' => $submitted['password'] ?? $journal['password'] ?? '',
        ]);
    }

    /**
     * پیش‌فرض‌های گام ۲ + رمزِ پیشنهادیِ همان لحظه.
     *
     * نام دیتابیس و کاربر هر دو `Pishdad` هستند تا کاربر لازم نباشد چیزی حدس
     * بزند؛ اگر ژورنال مقدارِ واقعی داشته باشد، همان برنده است.
     *
     * E51 — ولی پیش‌فرضِ هاستِ `127.0.0.1` داخلِ کانتینر یعنی «خودِ کانتینر» و
     * اتصال همیشه refused می‌شد (دومین برخوردِ کاربر: فرم با `127.0.0.1` و
     * `Pishdad` پر می‌آمد در حالی که دیتابیسِ داکر `db`/`cms` بود). `/.dockerenv`
     * فقط داخلِ کانتینرِ داکر هست، پس این تشخیص امن است و روی نصبِ ساده هیچ
     * اثری ندارد. مقادیرِ داکری همان پیش‌فرض‌های `docker-compose.yml` هستند
     * (`db`/`cms`/`cms`/`cmssecret`)؛ اگر کاربر `POSTGRES_*` را عوض کرده باشد،
     * پروبِ واقعی خطا می‌دهد و خودش تایپ می‌کند.
     *
     * عمداً `clear_env = no` نمی‌گذاریم: نصب‌کننده هر پنج کلیدِ `DB_*` را در
     * `.env` می‌نویسد، در حالی که کامپوز چهارتایشان را در محیطِ پروسه هم دارد —
     * پس محیطِ پروسه انتخابِ کاربر در فرم را خنثی می‌کرد (همان درسِ E63، بدتر).
     *
     * رمز **در هر بارگذاریِ صفحه** تازه ساخته می‌شود و روی خودِ صفحه هم نمایش
     * داده می‌شود، پس «راز» نیست — ولی همین باعث می‌شود کاربر مجبور نباشد یک
     * رمزِ خوب از خودش دربیاورد و همان را در فرمان‌های SQL هم کپی کند.
     * الفبای رمز فقط `A-Za-z0-9` است تا داخل `CREATE ROLE ... PASSWORD '…'`
     * و `docker run -e POSTGRES_PASSWORD=…` بدون escape بنشیند.
     */
    private function withDatabaseDefaults(array $config): array
    {
        $inContainer = file_exists('/.dockerenv');

        return [
            'host' => ($config['host'] ?? '') !== '' ? (string) $config['host'] : ($inContainer ? 'db' : '127.0.0.1'),
            'port' => ($config['port'] ?? '') !== '' ? (string) $config['port'] : '5432',
            'database' => ($config['database'] ?? '') !== '' ? (string) $config['database'] : ($inContainer ? 'cms' : 'Pishdad'),
            'username' => ($config['username'] ?? '') !== '' ? (string) $config['username'] : ($inContainer ? 'cms' : 'Pishdad'),
            'password' => ($config['password'] ?? '') !== '' ? (string) $config['password'] : ($inContainer ? 'cmssecret' : self::suggestedPassword()),
        ];
    }

    /** رمزِ تصادفیِ امن، فقط حروف و عدد (امن برای SQL و shell). */
    private static function suggestedPassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public function databaseSave(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('database');
        $this->checkToken($request);

        // اعتبارسنجی دستی + رندر مستقیم (بدون session، پس withErrors/old() نداریم).
        $validator = Validator::make($request->all(), [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:63'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'database.regex' => __('install.validation.db_name_rule'),
        ]);
        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            return $this->formFail('install.database', [
                'config' => $this->databasePrefill($request->only(['host', 'port', 'database', 'username', 'password'])),
                'token' => InstallJournal::token(),
                'step' => 'database',
            ], (string) $validator->errors()->first(), $this->safeOld($request));
        }
        $validated = $validator->validated();
        $validated['password'] = (string) ($validated['password'] ?? '');

        // اتصال واقعی + مجوز واقعی، قبل از ذخیره.
        $probe = Preflight::run($validated);
        $conn = $this->findCheck($probe, 'db_connection');
        $collation = $this->findCheck($probe, 'db_collation_privilege');
        if (($conn['status'] ?? '') !== 'pass' || ($collation['status'] ?? '') !== 'pass') {
            $message = __('install.msgs.db_probe_fail', ['conn' => $conn['detail'] ?? '', 'collation' => $collation['detail'] ?? '']);
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'data' => ['checks' => $probe]], 422);
            }

            return $this->formFail('install.database', [
                'config' => $this->databasePrefill($validated),
                'token' => InstallJournal::token(),
                'step' => 'database',
            ], $message, $this->safeOld($request));
        }

        InstallJournal::put('db', $validated);
        self::writeEnv([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $validated['host'],
            'DB_PORT' => (string) $validated['port'],
            'DB_DATABASE' => (string) $validated['database'],
            'DB_USERNAME' => (string) $validated['username'],
            'DB_PASSWORD' => (string) $validated['password'],
        ]);
        InstallJournal::markStepDone('database');

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.db_saved'), 'data' => ['next' => route('install.prepare')]]);
        }

        return redirect()->route('install.prepare');
    }

    // ─── گام ۲ب: prepare — پیام موفقیت اتصال + اجرای خودکار ۳ و ۴ ──────
    //
    // E18: کاربر نمی‌خواهد دو کلیکِ اضافه (ساخت کلید، اجرای مهاجرت‌ها) بزند.
    // بعد از اتصال موفق، این صفحه «اتصال برقرار شد» را نشان می‌دهد و یک دکمهٔ
    // واحد («شروع آماده‌سازی و ساخت دیتابیس») گام ۳ و ۴ را خودکار اجرا می‌کند.
    // صفحه‌های جدا `/install/app-key` و `/install/migrate` سر جایشان‌اند
    // (سازگاری API/JSON + فراخوانی مستقیم)، ولی گذرِ وب از اینجا می‌گذرد.

    public function prepare(): View|RedirectResponse
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('database');
        // resume: اگر قبلاً آماده‌سازی تمام شده، مستقیم گام بعد.
        if (InstallJournal::isStepDone('migrate')) {
            return redirect()->route('install.superadmin');
        }

        return view('install.prepare', [
            'db' => InstallJournal::get('db'),
            'token' => InstallJournal::token(),
            'step' => 'database',
            'formError' => null,
        ]);
    }

    public function prepareRun(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('database');
        $this->checkToken($request);

        // ۳) کلید برنامه (فقط اگر معتبر نیست — اجرای مجدد بی‌اثر است).
        $this->ensureAppKey();

        // ۴) مهاجرت‌ها.
        $migrated = $this->runMigrations();
        if (! $migrated['ok']) {
            // جزئیات همین حالا داخل runMigrations در لاگ ثبت شده.
            $message = __('install.msgs.prepare_fail');
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return $this->formFail('install.prepare', [
                'db' => InstallJournal::get('db'),
                'token' => InstallJournal::token(),
                'step' => 'database',
            ], $message);
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.prepare_done'), 'data' => ['next' => route('install.superadmin')]]);
        }

        return redirect()->route('install.superadmin');
    }

    // ─── E19: prepare مرحله‌ای (پیشرفت + خطای درون‌صفحه‌ای) ──────────
    //
    // صفحهٔ prepare با JS دو فراخوانیِ پشت‌سرهم می‌زند تا نوار پیشرفت فازبه‌فاز
    // جلو برود: ۱) ساخت کلید (سریع) ۲) مهاجرت‌ها (طولانی). هنگام خطا، به‌جای
    // «برو لاگ را بخوان»، همان‌جا خلاصهٔ قابل‌فهم + بریدهٔ لاگ برمی‌گردد.
    // بدون JS همان فرمِ تکیِ prepare.run کار می‌کند (fallback).

    public function prepareKey(Request $request): RedirectResponse|JsonResponse
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('database');
        $this->checkToken($request);

        $this->ensureAppKey();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.key_made'), 'data' => ['done' => true]]);
        }

        return redirect()->route('install.prepare');
    }

    public function prepareMigrate(Request $request): RedirectResponse|JsonResponse
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('database');
        $this->checkToken($request);

        $migrated = $this->runMigrations();
        if (! $migrated['ok']) {
            $payload = [
                'message' => __('install.msgs.prepare_fail'),
                'diagnosis' => $this->diagnoseMigrateFailure($migrated['output']),
                'log' => $this->recentLogExcerpt(),
            ];
            if ($request->wantsJson()) {
                return response()->json($payload, 500);
            }

            return $this->formFail('install.prepare', [
                'db' => InstallJournal::get('db'),
                'token' => InstallJournal::token(),
                'step' => 'database',
            ], (string) $payload['message']);
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.prepare_done'), 'data' => ['next' => route('install.superadmin')]]);
        }

        return redirect()->route('install.superadmin');
    }

    /**
     * نگاشت امضای خطای مهاجرت به خلاصهٔ قابل‌فهم کاربر (E19).
     * ترتیب مهم است: امضای خاص‌تر اول.
     */
    private function diagnoseMigrateFailure(string $output): string
    {
        $hay = strtolower($output);
        $has = function (string ...$needles) use ($hay): bool {
            foreach ($needles as $n) {
                if (str_contains($hay, $n)) {
                    return true;
                }
            }

            return false;
        };

        if ($has('event trigger')) {
            return __('install.diagnosis.event_trigger');
        }
        if ($has('could not connect', 'connection refused', '08006', '08001', 'timeout expired', 'no such host', 'name resolution')) {
            return __('install.diagnosis.connection');
        }
        if ($has('password authentication failed', '28p01', 'authentication failed')) {
            return __('install.diagnosis.auth');
        }
        if ($has('3d000', 'invalid catalog', 'database does not exist')) {
            return __('install.diagnosis.catalog');
        }
        if ($has('permission denied', '42501', '42502', 'must be owner', 'must be superuser')) {
            return __('install.diagnosis.privilege');
        }
        if ($has('already exists', 'duplicate', '42p07', '23505')) {
            return __('install.diagnosis.duplicate');
        }

        return __('install.diagnosis.unknown');
    }

    /**
     * ~۴۰ خط آخر لاگ لاراول برای نمایش درون‌صفحه‌ای خطا (E19).
     * سقف حجم دارد و هرگز استثنا بیرون نمی‌دهد (خودِ گزارشِ خطا نباید خطا بدهد).
     */
    private function recentLogExcerpt(): string
    {
        try {
            $path = storage_path('logs/laravel.log');
            if (! is_file($path)) {
                return '';
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $tail = array_slice($lines, -40);
            $text = implode("\n", $tail);
            if (strlen($text) > 4000) {
                $text = '…'.substr($text, -4000);
            }

            return $text;
        } catch (Throwable) {
            return '';
        }
    }

    // ─── گام ۳: app_key ─────────────────────────────────────────────

    public function appKey(): View
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('app_key');
        $key = (string) (config('app.key') ?? env('APP_KEY', ''));
        $valid = str_starts_with($key, 'base64:');

        return view('install.appkey', [
            'valid' => $valid,
            'token' => InstallJournal::token(),
            'step' => 'app_key',
        ]);
    }

    public function appKeyGenerate(Request $request): RedirectResponse|JsonResponse
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('app_key');
        $this->checkToken($request);

        $this->ensureAppKey();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.key_made'), 'data' => ['next' => route('install.migrate')]]);
        }

        return redirect()->route('install.migrate');
    }

    /**
     * کلید برنامه را می‌سازد و در .env می‌نویسد — فقط اگر کلیدِ معتبری نیست
     * (E18: اجرای مجددِ prepare نباید کلیدِ موجود را عوض کند).
     *
     * عمداً فقط `config('app.key')` خوانده می‌شود نه `env()` — در بوت واقعی
     * هر دو یکی‌اند، ولی در تست `env()` همیشه مقدار phpunit را دارد و مسیرِ
     * «کلید نیست، بساز» قابل تست نمی‌شد.
     */
    private function ensureAppKey(): void
    {
        $key = (string) (config('app.key') ?? '');
        if (str_starts_with($key, 'base64:')) {
            if (! InstallJournal::isStepDone('app_key')) {
                InstallJournal::markStepDone('app_key');
            }

            return;
        }
        $key = 'base64:'.base64_encode(random_bytes(32));
        self::writeEnv(['APP_KEY' => $key]);
        // همین ریکوئست هم از کلید تازه استفاده کند (ری‌استارت لازم نباشد).
        config(['app.key' => $key]);
        InstallJournal::markStepDone('app_key');
    }

    // ─── گام ۴: migrate ─────────────────────────────────────────────

    public function migrate(): View
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('migrate');

        return view('install.migrate', [
            'token' => InstallJournal::token(),
            'step' => 'migrate',
            'ran' => InstallJournal::isStepDone('migrate'),
            'formError' => null,
        ]);
    }

    public function migrateRun(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('migrate');
        $this->checkToken($request);

        $migrated = $this->runMigrations();
        if (! $migrated['ok']) {
            // جزئیات همین حالا داخل runMigrations در لاگ ثبت شده.
            $message = __('install.msgs.migrate_fail');
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return $this->formFail('install.migrate', [
                'token' => InstallJournal::token(),
                'step' => 'migrate',
                'ran' => InstallJournal::isStepDone('migrate'),
            ], $message);
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.migrated'), 'data' => ['next' => route('install.superadmin')]]);
        }

        return redirect()->route('install.superadmin');
    }

    /**
     * اجرای `migrate --force` + ثبت گام. خروجی همیشه برمی‌گردد تا فراخواننده
     * (prepare یا migrateRun) خودش تصمیم بگیرد.
     *
     * @return array{ok: bool, output: string}
     */
    private function runMigrations(): array
    {
        try {
            $exit = Artisan::call('migrate', ['--force' => true]);
            $output = (string) Artisan::output();
            if ($exit !== 0) {
                return ['ok' => false, 'output' => 'exit='.$exit.' '.$output];
            }
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'output' => $e->getMessage()];
        }

        InstallJournal::markStepDone('migrate');

        return ['ok' => true, 'output' => ''];
    }

    // ─── گام ۵: superadmin ──────────────────────────────────────────

    public function superadmin(): View
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('superadmin');

        return view('install.superadmin', [
            'token' => InstallJournal::token(),
            // E57 — پیشفرضِ نشانیِ سایت: همان مقداری که `siteUrl()` میدهد
            // (فرم → محیط → پیشفرض)، تا کاربر فقط تأییدش کند.
            'frontendUrl' => self::siteUrl(),
            'step' => 'superadmin',
            'formError' => null,
            'old' => [],
        ]);
    }

    public function superadminSave(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('superadmin');
        $this->checkToken($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // E57 — سروری که اینجا اجرا می‌شود فقط بک‌اند است، پس نشانیِ سایتِ
            // فرانت را باید کاربر بدهد. اختیاری است تا نصبِ فقط-بک‌اند نشکند؛
            // اگر خالی بماند `siteUrl()` به `FRONTEND_URL` و بعد پیش‌فرض می‌افتد.
            'frontend_url' => ['nullable', 'url', 'max:255'],
        ], [
            'email.unique' => __('install.validation.email_unique'),
            'password.min' => __('install.validation.password_min'),
            'password.confirmed' => __('install.validation.password_confirmed'),
        ]);
        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            return $this->formFail('install.superadmin', [
                'token' => InstallJournal::token(),
                'step' => 'superadmin',
            ], (string) $validator->errors()->first(), $this->safeOld($request));
        }
        $validated = $validator->validated();

        $user = self::createFirstAdmin($validated['name'], $validated['email'], $validated['password']);

        InstallJournal::put('superadmin', [
            'email' => $user->email,
            'id' => $user->id,
            // E57 — نشانیِ سایتِ فرانت، تا صفحهٔ پایانی و صفحهٔ ریشه لینک‌های
            // درست بسازند.
            'frontend_url' => rtrim((string) ($validated['frontend_url'] ?? ''), '/'),
        ]);

        // E56 — رمزِ خام فقط تا لحظهٔ نمایش در صفحهٔ پایانی نگه داشته می‌شود و
        // همان‌جا پاک می‌شود (نگاه کنید به `done()`). پیش از این هرگز ذخیره
        // نمی‌شد و کاربر باید خودش یادش می‌ماند.
        InstallJournal::put('superadmin_password', ['value' => $validated['password']]);

        InstallJournal::markStepDone('superadmin');

        // E30 — محتوای ازپیش‌ساختهٔ سایت (صفحه‌ها + تصویرها) همین حالا ساخته
        // می‌شود، وگرنه نصبِ تازه با سایتِ خالی بالا می‌آمد.
        self::seedSiteContent();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.admin_made'), 'data' => ['next' => route('install.finalize'), 'email' => $user->email]]);
        }

        return redirect()->route('install.finalize');
    }

    // ─── گام ۶: finalize + done ─────────────────────────────────────

    public function finalizeShow(): View
    {
        $this->resolveLocale(request());
        $this->ensureStepReachable('superadmin');
        if (! InstallJournal::isStepDone('superadmin')) {
            abort(404);
        }

        return view('install.finalize', [
            'token' => InstallJournal::token(),
            'step' => 'finalize',
            'email' => InstallJournal::get('superadmin')['email'] ?? '',
        ]);
    }

    public function finalize(Request $request): RedirectResponse|JsonResponse
    {
        $this->resolveLocale($request);
        $this->ensureStepReachable('superadmin');
        $this->checkToken($request);

        if (! InstallJournal::isStepDone('superadmin')) {
            abort(404);
        }

        InstallJournal::markStepDone('finalize');
        InstallJournal::writeLock(['email' => InstallJournal::get('superadmin')['email'] ?? null]);

        // E59 — زمانِ اتمامِ نصب، برای انقضای اطلاعاتِ صفحهٔ پایانی.
        // صفحهٔ پایانی رمزِ مدیر و مشخصاتِ دیتابیس را نشان میدهد؛ این‌ها نباید
        // برای همیشه در ژورنال بمانند. `done()` بعد از دو ساعت پاکشان می‌کند.
        InstallJournal::put('install_done_at', ['at' => now()->toIso8601String()]);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('install.msgs.install_done'), 'data' => ['next' => route('install.done')]]);
        }

        return redirect()->route('install.done');
    }

    /** چند ساعت اطلاعاتِ صفحهٔ پایانی زنده می‌ماند، بعد پاک می‌شود (E59). */
    public const INFO_TTL_HOURS = 2;

    /**
     * نشانیِ عمومیِ سایتِ فرانت — **یک** منبعِ حقیقت برای صفحهٔ پایانی و صفحهٔ ریشه.
     *
     * ترتیب: مقداری که کاربر در گامِ اطلاعاتِ مدیریت وارد کرده → `FRONTEND_URL`
     * از محیط → پیش‌فرض.
     *
     * ⚠️ چرا فرم بر محیط مقدم است: `env()` در مسیرِ php-fpm قابلِ اتکا نیست.
     * فایلِ pool هیچ `clear_env` ندارد، پس پیش‌فرضِ بستهٔ php-fpm محیطِ ورکرها را
     * پاک می‌کند (E51) و `FRONTEND_URL` هرگز دیده نمی‌شود. نتیجه‌اش این بود که
     * روی یک سرورِ واقعی هم همیشه `http://localhost:3000` می‌ماند و لینک‌های
     * صفحهٔ پایانی غلط بودند. حالا مقداری که کاربر **در فرم** داده برنده است و
     * `getenv` هم به‌عنوان راهِ دوم پرسیده می‌شود.
     */
    public static function siteUrl(): string
    {
        $fromForm = trim((string) (InstallJournal::get('superadmin')['frontend_url'] ?? ''));
        if ($fromForm !== '') {
            return rtrim($fromForm, '/');
        }

        $fromEnv = trim((string) (getenv('FRONTEND_URL') ?: env('FRONTEND_URL', '')));

        return rtrim($fromEnv !== '' ? $fromEnv : 'http://localhost:3000', '/');
    }

    public function done(): View
    {
        $this->resolveLocale(request());
        // صفحه پایانی فقط بعد از قفل معنا دارد؛ قبلش resume.
        if (! InstallJournal::isInstalled()) {
            abort(404);
        }

        /*
         * E59 — انقضای اطلاعاتِ صفحهٔ پایانی پس از دو ساعت.
         *
         * این صفحه رمزِ مدیر و مشخصاتِ دیتابیس را نشان می‌دهد. ماندنِ همیشگیِ
         * این‌ها در ژورنال یعنی هر کسی که بعداً به آن فایل دسترسی پیدا کند همه را
         * می‌بیند، در حالی که کاربر پس از نصب دیگر نیازی به دیدنشان ندارد.
         *
         * ⚠️ پاک‌سازی **تنبل** (lazy) است: نه cron لازم دارد و نه زمان‌بند، چون
         * همین صفحه تنها مصرف‌کنندهٔ این داده است. به‌محضِ بازدیدِ پس از موعد،
         * پاک می‌شود.
         */
        $doneAtRaw = (string) (InstallJournal::get('install_done_at')['at'] ?? '');
        $expiresAt = $doneAtRaw !== ''
            ? \Illuminate\Support\Carbon::parse($doneAtRaw)->addHours(self::INFO_TTL_HOURS)
            : null;
        $infoExpired = $expiresAt !== null && \Illuminate\Support\Carbon::now()->greaterThanOrEqualTo($expiresAt);

        if ($infoExpired) {
            InstallJournal::put('db', []);
            InstallJournal::put('superadmin_password', []);
        }

        $frontend = self::siteUrl();

        /*
         * ⚠️ رمزِ مدیر **یک بار** اینجا نشان داده می‌شود و همان لحظه پاک می‌شود.
         *
         * نگه‌داشتنش یعنی یک رمزِ خام روی دیسک می‌ماند؛ پاک‌کردنش یعنی اگر کاربر
         * صفحه را رفرش کند دیگر نمی‌بیندش. آن معاوضه عمدی است و به نفعِ امنیت
         * تمام می‌شود: رمز روی کاغذِ کاربر است، نه در فایلِ سرور.
         */
        $adminPassword = (string) (InstallJournal::get('superadmin_password')['value'] ?? '');
        if ($adminPassword !== '') {
            InstallJournal::put('superadmin_password', []);
        }

        return view('install.done', [
            'email' => InstallJournal::get('superadmin')['email'] ?? '',
            'db' => InstallJournal::get('db'),
            'frontend' => $frontend,
            'panel' => $frontend.'/admin/login',
            'adminPassword' => $adminPassword,
            /*
             * ⚠️ نشانیِ عمومی از **خودِ درخواست** ساخته میشود، نه از `APP_URL`.
             *
             * `APP_URL` در `.env.example` مقدارِ `http://localhost` است (بدونِ
             * پورت) و در php-fpm هم دیده نمیشود (E51). نتیجه‌اش این بود که
             * صفحهٔ پایانی `http://localhost/api` را نشان میداد در حالی که
             * بک‌اند روی `:8080` است — یعنی همان چیزی که کاربر دید.
             *
             * کسی که همین حالا این صفحه را می‌بیند، بک‌اند را روی همین نشانی در
             * مرورگر باز کرده؛ پس معتبرترین منبعِ «بک‌اند کجا دیده می‌شود» خودِ
             * درخواست است. `APP_URL` فقط راهِ دوم می‌ماند.
             */
            'apiUrl' => rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url'), '/').'/api',
            'revalidateSecret' => (string) config('revalidate.secret'),
            // E59 — انقضای اطلاعاتِ این صفحه.
            'infoTtlHours' => self::INFO_TTL_HOURS,
            'infoExpiresAt' => $expiresAt?->toIso8601String(),
            'infoExpired' => $infoExpired,
            'step' => 'finalize',
        ]);
    }

    // ─── ساختِ نخستین مدیر (مشترک وب + CLI) ─────────────────────────
    //
    // نقش `owner` (که همهٔ پرمیشن‌ها را دارد) به او داده می‌شود. هیچ نقشِ
    // مخفی/مستثنا و هیچ 2FA اجباری‌ای وجود ندارد؛ 2FA را خودش از پروفایل فعال
    // می‌کند. (پیش‌تر یک «سوپرادمین مخفی» با پرچم‌های bypass ساخته می‌شد.)

    public static function createFirstAdmin(string $name, string $email, string $password): User
    {
        // نقش‌ها/پرمیشن‌ها باید موجود باشند تا `owner` (که همهٔ پرمیشن‌ها را دارد)
        // به نخستین مدیر داده شود؛ روی نصبِ تازه که هنوز seed نشده، همین‌جا
        // ساخته می‌شوند.
        if (! Role::query()->where('name', 'owner')->exists()) {
            app(\Database\Seeders\RolesPermissionsSeeder::class)->run();
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'admin',
                'google2fa_secret' => null,
                'google2fa_enabled' => false,
                'recovery_codes' => [],
            ]
        );
        $user->assignRole('owner');

        return $user;
    }

    /**
     * E30 — محتوای ازپیش‌ساختهٔ سایت: صفحه‌ها، تصویرها، هویت و پوسته.
     *
     * ## چرا این متد لازم شد
     *
     * `PishdadSiteSeeder` تا امروز **هیچ‌جا در کدِ تولیدی** صدا زده نمی‌شد؛
     * فقط تست و `phpunit.xml` به آن اشاره داشتند. یعنی هر نصبِ واقعی با سایتِ
     * خالی بالا می‌آمد، در حالی که صفحه‌ها و تصویرهای آماده در مخزن بودند.
     *
     * ## چرا اینجا و نه در گام مهاجرت
     *
     * این seeder نخستین کاربر را مالکِ صفحه‌ها می‌کند، و نخستین کاربر همان
     * مدیرِ گام ۵ است. پس نقطهٔ درست، بلافاصله بعد از ساختِ اوست.
     *
     * idempotent است (بر پایهٔ slug و هشِ محتوا)، پس نصبِ دوباره رکورد تکراری
     * نمی‌سازد.
     *
     * ## لینکِ `public/storage`
     *
     * تصویرها روی دیسکِ `public` نوشته می‌شوند و از مسیر `/storage/...` سرو
     * می‌شوند. بدونِ این symlink، فایل روی دیسک هست ولی مرورگر ۴۰۴ می‌گیرد و
     * صفحه‌ها با تصویرِ شکسته بالا می‌آیند.
     */
    private static function seedSiteContent(): void
    {
        try {
            $link = public_path('storage');

            if (! is_link($link) && ! is_dir($link)) {
                Artisan::call('storage:link');
            }

            /*
             * ⚠️ قالب‌های سایت باید **صریح** اینجا صدا زده شوند.
             *
             * `SiteThemeSeeder` تنها از `DatabaseSeeder` صدا زده می‌شود و جریانِ
             * نصب عمداً `DatabaseSeeder` را اجرا نمی‌کند — چون `DemoAdminSeeder`
             * محتوای نمایشی می‌سازد و کاربرِ مدیر را خودِ نصب‌کننده می‌سازد.
             *
             * نتیجه‌اش این بود که نصبِ تازه **بدونِ هیچ قالبی** بالا می‌آمد و
             * صفحهٔ «قالب‌های سایت» در پنل خالی بود، با وجودی که قالب‌ها بخشی از
             * خودِ محصول‌اند نه محتوای نمایشی. با یک نصبِ واقعی گزارش شد.
             *
             * ترتیب مهم است: `SiteThemeSeeder` به مجوزِ `themes.review_status`
             * تکیه دارد که `RolesPermissionsSeeder` می‌سازد و در گامِ مجوزها
             * اجرا می‌شود؛ پس باید بعد از آن و **پیش از** `PishdadSiteSeeder`
             * برود (صفحه‌های نمونه به قالبِ فعال ارجاع می‌دهند).
             */
            app(\Database\Seeders\SiteThemeSeeder::class)->run();

            app(\Database\Seeders\PishdadSiteSeeder::class)->run();
        } catch (Throwable $e) {
            // محتوای نمونه نباید نصب را بشکند: نصبِ سالمِ بدونِ محتوای نمونه
            // بهتر از نصبِ نیمه‌کاره است. ولی سکوت هم نمی‌کنیم — دلیلش در لاگ
            // می‌ماند تا کسی که سایتش خالی بالا آمده ردش را ببیند.
            report($e);
            Log::warning('install.site_content_seed_failed', ['reason' => $e->getMessage()]);
        }
    }

    // ─── .env نویسی ─────────────────────────────────────────────────

    public static function envPath(): string
    {
        return (string) (config('installer.env_path') ?? base_path('.env'));
    }

    /** @param array<string, string> $pairs */
    public static function writeEnv(array $pairs): void
    {
        $path = self::envPath();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        if ($content !== '' && ! str_ends_with($content, "\n")) {
            $content .= "\n";
        }
        foreach ($pairs as $key => $value) {
            $line = $key.'='.self::envEscape($value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $content)) {
                $content = (string) preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $content);
            } else {
                $content .= $line."\n";
            }
        }
        if (is_file($path) && ! is_file($path.'.install-bak')) {
            @copy($path, $path.'.install-bak');
        }
        file_put_contents($path, $content);
    }

    private static function envEscape(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    // ─── نگهبان‌های داخلی ───────────────────────────────────────────

    private function ensureNotInstalled(): void
    {
        if (InstallJournal::isInstalled()) {
            abort(404);
        }
    }

    /**
     * گام N فقط وقتی قابل دیدن است که همه گام‌های قبلش در ژورنال done باشند
     * (resume واقعی؛ دور زدن ترتیب ممکن نیست).
     */
    private function ensureStepReachable(string $step): void
    {
        $this->ensureNotInstalled();
        foreach (InstallJournal::STEPS as $candidate) {
            if ($candidate === $step) {
                return;
            }
            if (! InstallJournal::isStepDone($candidate)) {
                abort(404);
            }
        }
        abort(404);
    }

    private function checkToken(Request $request): void
    {
        $given = (string) $request->input('install_token', '');
        if ($given === '' || ! hash_equals(InstallJournal::token(), $given)) {
            abort(419, __('install.msgs.token_invalid'));
        }
    }

    /**
     * رندر مستقیم خطای فرم با ۴۲۲ — چون session نداریم، withErrors/old()
     * کار نمی‌کنند؛ خطا و مقادیر امن صریح پاس داده می‌شوند.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $old
     */
    private function formFail(string $view, array $data, string $message, array $old = []): Response
    {
        return response()->view($view, array_merge($data, [
            'formError' => $message,
            'old' => $old,
        ]), 422);
    }

    /** مقادیر برگشتی فرم، همیشه بدون رمزها. @return array<string, mixed> */
    private function safeOld(Request $request): array
    {
        return collect($request->except(['password', 'password_confirmation', 'install_token']))
            ->map(fn ($v) => is_string($v) ? $v : '')
            ->all();
    }

    /** @param list<array{key:string,label:string,status:string,detail:string,remedy:string}> $checks */
    private function findCheck(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? '') === $key) {
                return $check;
            }
        }

        return ['status' => 'fail', 'detail' => 'چک یافت نشد.'];
    }
}
