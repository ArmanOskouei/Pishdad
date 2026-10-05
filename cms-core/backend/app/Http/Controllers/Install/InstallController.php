<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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

    public function start(): RedirectResponse|Response
    {
        if (InstallJournal::isInstalled()) {
            abort(404);
        }
        $step = InstallJournal::firstIncompleteStep() ?? 'preflight';

        return redirect()->route(self::STEP_ROUTES[$step]);
    }

    // ─── گام ۱: preflight ───────────────────────────────────────────

    public function preflight(Request $request): View|JsonResponse
    {
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
        $this->ensureNotInstalled();
        $this->checkToken($request);
        $checks = Preflight::run();

        if (Preflight::blockingFailures($checks)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'پیش‌نیازهای حیاتی برقرار نیست.', 'data' => ['checks' => $checks]], 422);
            }

            // بدون session رندر مستقیم با ۴۲۲ (نه ریدایرکت با withErrors).
            return response()->view('install.preflight', [
                'checks' => $checks,
                'blocking' => true,
                'token' => InstallJournal::token(),
                'step' => 'preflight',
                'formError' => 'پیش‌نیازهای حیاتی برقرار نیست؛ ستون «راه‌حل» را ببینید.',
                'old' => [],
            ], 422);
        }

        InstallJournal::markStepDone('preflight');

        if ($request->wantsJson()) {
            return response()->json(['message' => 'پیش‌نیازها برقرار است.', 'data' => ['next' => route('install.database')]]);
        }

        return redirect()->route('install.database');
    }

    // ─── گام ۲: database ────────────────────────────────────────────

    public function database(): View
    {
        $this->ensureStepReachable('database');

        return view('install.database', [
            'config' => Preflight::dbConfig(),
            'token' => InstallJournal::token(),
            'step' => 'database',
            'formError' => null,
            'old' => [],
        ]);
    }

    public function databaseSave(Request $request): RedirectResponse|JsonResponse|Response
    {
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
            'database.regex' => 'نام دیتابیس فقط حروف/عدد/آندرلاین باشد.',
        ]);
        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            return $this->formFail('install.database', [
                'config' => Preflight::dbConfig(),
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
            $message = 'اتصال یا مجوز دیتابیس کافی نیست: '.($conn['detail'] ?? '').' / '.($collation['detail'] ?? '');
            if ($request->wantsJson()) {
                return response()->json(['message' => $message, 'data' => ['checks' => $probe]], 422);
            }

            return $this->formFail('install.database', [
                'config' => Preflight::dbConfig(),
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
            return response()->json(['message' => 'اتصال ذخیره شد.', 'data' => ['next' => route('install.app-key')]]);
        }

        return redirect()->route('install.app-key');
    }

    // ─── گام ۳: app_key ─────────────────────────────────────────────

    public function appKey(): View
    {
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
        $this->ensureStepReachable('app_key');
        $this->checkToken($request);

        $key = 'base64:'.base64_encode(random_bytes(32));
        self::writeEnv(['APP_KEY' => $key]);
        // همین ریکوئست هم از کلید تازه استفاده کند (ری‌استارت لازم نباشد).
        config(['app.key' => $key]);
        InstallJournal::markStepDone('app_key');

        if ($request->wantsJson()) {
            return response()->json(['message' => 'کلید برنامه ساخته شد.', 'data' => ['next' => route('install.migrate')]]);
        }

        return redirect()->route('install.migrate');
    }

    // ─── گام ۴: migrate ─────────────────────────────────────────────

    public function migrate(): View
    {
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
        $this->ensureStepReachable('migrate');
        $this->checkToken($request);

        try {
            $exit = Artisan::call('migrate', ['--force' => true]);
            $output = (string) Artisan::output();
            if ($exit !== 0) {
                throw new \RuntimeException('migrate exit='.$exit.' '.$output);
            }
        } catch (Throwable $e) {
            $message = 'اجرای مهاجرت‌ها ناموفق بود. خروجی در لاگ ثبت شد.';
            report($e);
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return $this->formFail('install.migrate', [
                'token' => InstallJournal::token(),
                'step' => 'migrate',
                'ran' => InstallJournal::isStepDone('migrate'),
            ], $message);
        }

        InstallJournal::markStepDone('migrate');

        if ($request->wantsJson()) {
            return response()->json(['message' => 'مهاجرت‌ها اجرا شدند.', 'data' => ['next' => route('install.superadmin')]]);
        }

        return redirect()->route('install.superadmin');
    }

    // ─── گام ۵: superadmin ──────────────────────────────────────────

    public function superadmin(): View
    {
        $this->ensureStepReachable('superadmin');

        return view('install.superadmin', [
            'token' => InstallJournal::token(),
            'step' => 'superadmin',
            'formError' => null,
            'old' => [],
        ]);
    }

    public function superadminSave(Request $request): RedirectResponse|JsonResponse|Response
    {
        $this->ensureStepReachable('superadmin');
        $this->checkToken($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'password.min' => 'رمز عبور باید حداقل ۸ نویسه باشد.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
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

        $user = self::createSuperAdmin($validated['name'], $validated['email'], $validated['password']);
        InstallJournal::put('superadmin', ['email' => $user->email, 'id' => $user->id]);
        InstallJournal::markStepDone('superadmin');

        if ($request->wantsJson()) {
            return response()->json(['message' => 'سوپرادمین ساخته شد.', 'data' => ['next' => route('install.finalize'), 'email' => $user->email]]);
        }

        return redirect()->route('install.finalize');
    }

    // ─── گام ۶: finalize + done ─────────────────────────────────────

    public function finalizeShow(): View
    {
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
        $this->ensureStepReachable('superadmin');
        $this->checkToken($request);

        if (! InstallJournal::isStepDone('superadmin')) {
            abort(404);
        }

        InstallJournal::markStepDone('finalize');
        InstallJournal::writeLock(['email' => InstallJournal::get('superadmin')['email'] ?? null]);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'نصب کامل شد.', 'data' => ['next' => route('install.done')]]);
        }

        return redirect()->route('install.done');
    }

    public function done(): View
    {
        // صفحه پایانی فقط بعد از قفل معنا دارد؛ قبلش resume.
        if (! InstallJournal::isInstalled()) {
            abort(404);
        }
        $email = InstallJournal::get('superadmin')['email'] ?? '';

        return view('install.done', ['email' => $email, 'step' => 'finalize']);
    }

    // ─── ساخت سوپرادمین (مشترک وب + CLI) ────────────────────────────
    //
    // عمداً SuperAdminSeeder صدا زده نمی‌شود: آن سیدر سکرت TOTP را به stdout
    // چاپ می‌کند و google2fa را از ابتدا فعال می‌کند. اینجا کاربر بدون 2FA
    // ساخته می‌شود و اولین ورود، راه‌اندازی 2FA را اجبار می‌کند
    // (EnsureTwoFactorSetup + پرچم two_factor_setup_required در لاگین).

    public static function createSuperAdmin(string $name, string $email, string $password): User
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'super-admin',
                'is_super_admin' => true,
                'is_hidden' => true,
                'google2fa_secret' => null,
                'google2fa_enabled' => false,
                'recovery_codes' => [],
            ]
        );
        $user->assignRole('super-admin');

        return $user;
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
            abort(419, 'توکن نصب نامعتبر است؛ صفحه را رفرش کنید.');
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
