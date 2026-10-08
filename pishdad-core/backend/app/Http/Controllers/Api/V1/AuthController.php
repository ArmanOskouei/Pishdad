<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Morilog\Jalali\Jalalian;
use PragmaRX\Google2FA\Google2FA;

/**
 * DEV-TASKS 2.1 — login (step 1) + TOTP verify (step 2) + session + recovery.
 *
 * Security rules:
 * - Identical 401 message for unknown email / wrong password (no enumeration).
 * - forgot-password always returns the same message (no enumeration).
 * - Brute-force lock via the `auth-login` rate limiter (see AppServiceProvider).
 * - All messages in Persian.
 */
class AuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'اعتبار ورود نامعتبر است.';

    public function login(Request $request): JsonResponse
    {
        $request->validate(
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ],
            [
                'email.required' => 'ایمیل الزامی است.',
                'email.email' => 'قالب ایمیل معتبر نیست.',
                'password.required' => 'رمز عبور الزامی است.',
            ]
        );

        $email = (string) $request->string('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->recordLoginEvent($request, $user, false, $email);

            return response()->json(['message' => self::INVALID_CREDENTIALS], 401);
        }

        // Step 2 required: hand out a short-lived challenge, NOT a session token.
        if ($user->google2fa_enabled) {
            // مرحلهٔ اولِ ۲FA ورودِ کامل نیست؛ نتیجهٔ نهایی در `verifyTwoFactor`
            // ثبت می‌شود تا «موفق» یعنی «نشست صادر شد».
            $challenge = (string) Str::uuid();
            Cache::put("2fa:{$challenge}", $user->id, now()->addMinutes(10));

            return response()->json([
                'two_factor_required' => true,
                'challenge' => $challenge,
                'message' => 'کد تأیید دومرحله‌ای را وارد کنید.',
            ]);
        }

        $this->recordLoginEvent($request, $user, true);

        // WF-M8 — نقش‌های «۲FA اجباری»: کاربرِ بدونِ ۲FA مسدود نمی‌شود؛ توکن
        // می‌گیرد و فرانت او را به صفحهٔ راه‌اندازی پروفایل هدایت می‌کند.
        return $this->tokenResponse($request, $user, $user->rolesRequireTwoFactor());
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate(
            [
                'challenge' => ['required', 'uuid'],
                'code' => ['required', 'string'],
            ],
            [
                'challenge.required' => 'شناسه مرحله دوم الزامی است.',
                'challenge.uuid' => 'شناسه مرحله دوم معتبر نیست.',
                'code.required' => 'کد تأیید الزامی است.',
            ]
        );

        $userId = Cache::get("2fa:{$request->string('challenge')}");
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user) {
            $this->recordLoginEvent($request, null, false);

            return response()->json(['message' => 'شناسه مرحله دوم منقضی یا نامعتبر است.'], 401);
        }

        $code = str_replace(' ', '', $request->string('code')->toString());

        // Recovery code path (each code is single-use, stored hashed).
        $recoveryCodes = $user->recovery_codes ?? [];
        foreach ($recoveryCodes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($recoveryCodes[$index]);
                $user->forceFill(['recovery_codes' => array_values($recoveryCodes)])->save();
                Cache::forget("2fa:{$request->string('challenge')}");
                $this->markFreshTwoFactor($user);
                $this->recordLoginEvent($request, $user, true);

                return $this->tokenResponse($request, $user);
            }
        }

        // TOTP path (±1 step window for clock skew).
        $google2fa = new Google2FA;
        $valid = $user->google2fa_secret
            && $google2fa->verifyKey($user->google2fa_secret, $code, 1);

        if (! $valid) {
            $this->recordLoginEvent($request, $user, false);

            return response()->json(['message' => self::INVALID_CREDENTIALS], 401);
        }

        Cache::forget("2fa:{$request->string('challenge')}");
        $this->markFreshTwoFactor($user);
        $this->recordLoginEvent($request, $user, true);

        return $this->tokenResponse($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->clearTokenCookie(
            response()->json(['message' => 'با موفقیت خارج شدید.'])
        );
    }

    /** Token rotation: revoke the current token, issue a fresh one. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();

        return $this->tokenResponse($request, $user);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            [
                'email.required' => 'ایمیل الزامی است.',
                'email.email' => 'قالب ایمیل معتبر نیست.',
            ]
        );

        Password::sendResetLink($request->only('email'));

        // Same message whether or not the account exists (anti-enumeration).
        return response()->json(['message' => 'اگر حسابی با این ایمیل وجود داشته باشد، لینک بازیابی ارسال شد.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate(
            [
                'token' => ['required', 'string'],
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
            [
                'token.required' => 'توکن بازیابی الزامی است.',
                'email.required' => 'ایمیل الزامی است.',
                'email.email' => 'قالب ایمیل معتبر نیست.',
                'password.required' => 'رمز عبور الزامی است.',
                'password.min' => 'رمز عبور باید حداقل ۸ نویسه باشد.',
                'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
            ]
        );

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete(); // revoke all sessions after reset
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'توکن بازیابی نامعتبر یا منقضی است.'], 422);
        }

        return response()->json(['message' => 'رمز عبور با موفقیت تغییر کرد.']);
    }

    /**
     * مهر «2FA تازه» برای step-up auth اپراتور (تسک ۵.۷): ۵ دقیقه اعتبار.
     * کلید در کش array/file (تست) و redis (پروداکشن) یکسان کار می‌کند.
     */
    public static function freshTwoFactorKey(User $user): string
    {
        return "2fa_fresh:{$user->id}";
    }

    public static function hasFreshTwoFactor(User $user): bool
    {
        return (bool) Cache::get(self::freshTwoFactorKey($user));
    }

    private function markFreshTwoFactor(User $user): void
    {
        Cache::put(self::freshTwoFactorKey($user), true, now()->addMinutes(5));
    }

    /**
     * WF-H8 — ثبت یک تلاشِ ورود برای کارت امنیتِ پروفایل.
     *
     * هرگز نباید خودِ ورود را بشکند: اگر ثبت شکست بخورد (مثلاً جدول نباشد)
     * جریانِ احراز هویت ادامه می‌یابد. UA خام ذخیره و در زمان خواندن با
     * `SessionDeviceParser` parse می‌شود تا تغییرِ پارسر به گذشته هم اعمال شود.
     */
    private function recordLoginEvent(Request $request, ?User $user, bool $successful, ?string $email = null): void
    {
        try {
            LoginEvent::query()->create([
                'user_id' => $user?->id,
                'email' => $email ?? $user?->email,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000) ?: null,
                'successful' => $successful,
            ]);
        } catch (\Throwable) {
            // تاریخچه نباید مانع ورود شود.
        }
    }

    /**
     * صدور توکن + ثبت فراداده نشست (مسئله ۲): IP و user-agent زمان لاگین
     * روی خود توکن ذخیره می‌شود تا GET profile/sessions نمایش دهد.
     */
    private function tokenResponse(Request $request, User $user, bool $setupRequired = false): JsonResponse
    {
        $issued = $user->createToken('نشست وب', ['auth']);
        $issued->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000) ?: null,
        ])->save();
        $token = $issued->plainTextToken;

        $response = response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'two_factor_required' => false,
            'two_factor_setup_required' => $setupRequired,
            'requires_2fa_setup' => $setupRequired,
            'user' => $this->userPayload($user),
        ]);

        // httpOnly-ready: Next.js can rely on the cookie instead of localStorage.
        return $response->cookie(
            'auth_token', $token,
            60 * 24 * 7, '/', null,
            config('session.secure', false), true, false, 'Lax'
        );
    }

    private function clearTokenCookie(JsonResponse $response): JsonResponse
    {
        return $response->withoutCookie('auth_token', '/');
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'google2fa_enabled' => (bool) $user->google2fa_enabled,
            'created_at' => $user->created_at?->toIso8601String(),
            // Jalali date in responses (PLAN.md: UTC storage, Jalali display).
            'created_at_jalali' => $user->created_at
                ? Jalalian::fromCarbon($user->created_at)->format('Y/m/d H:i')
                : null,
        ];
    }
}
