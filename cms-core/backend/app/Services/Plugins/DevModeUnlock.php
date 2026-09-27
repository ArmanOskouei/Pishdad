<?php

namespace App\Services\Plugins;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * باز کردن حالت توسعه‌دهنده — دروازهٔ سمت سرور (K4.3 و K4.5).
 *
 * ## چرا رمز عبور و نه checkbox
 *
 * ژستور پنج کلیک یک gesture است: هر چیزی می‌تواند آن را تولید کند. اگر باز کردن
 * حالت توسعه‌دهنده فقط یک POST با CSRF توکن بود، یک صفحهٔ آلوده کافی بود تا
 * بستهٔ بدون امضا نصب شود. پس باز کردن، علاوه بر ژستور، **رمز عبور خودِ مدیر**
 * را می‌خواهد و در ازایش یک `unlock_token` یک‌بارمصرف می‌دهد.
 *
 * ## چرا توکن یک‌بارمصرف
 *
 * `unlock_token` هنگام آپلود ارائه می‌شود و در `DevMode::allows()` با
 * `hash_equals` سنجیده می‌شود. اگر همان رمز عبور بود، هر بار که می‌شد افزونهٔ
 * بدون امضا آپلود کرد، چون رمز در مرورگر ذخیره می‌شد. توکن یک‌بارمصرف یعنی
 * سرقتش از حافظهٔ مرورگر برای همیشه بی‌ارزش است.
 *
 * ## چرا هر تلاش ناموفق ثبت می‌شود
 *
 * این نکتهٔ اصلی K4.5 است. یک تلاش ناموفقِ باز کردن، نه یک خطای بی‌معنی است و نه
 * شایعه: یعنی کسی می‌خواسته دروازهٔ بدون امضا را باز کند. اگر این‌ها ثبت
 * نشوند، بعد از یک حملهٔ ناموفق هیچ راهی برای فهمیدنش نیست. و ثبت در
 * `operator_activities` **شکست را هم ثبت می‌کند**، نه فقط موفقیت را.
 */
class DevModeUnlock
{
    public function __construct(
        private readonly DevMode $devMode,
        private readonly DevModeTaps $taps,
        private readonly DevModeAudit $audit,
    ) {}

    /**
     * وضعیت ژستور برای مودال.
     *
     * @return array{count: int, required: int, ready: bool, reason: ?string, expires_at: ?string}
     */
    public function state(User $user): array
    {
        return $this->taps->state($user) + [
            'expires_at' => $this->devMode->state()['expires_at'],
        ];
    }

    /**
     * یک کلیک ژستور را ثبت می‌کند.
     *
     * @return array{count: int, required: int, ready: bool, reason: ?string, expires_at: ?string}
     *
     * @throws ValidationException اگر قاعده‌های K4.2 نقض شود
     */
    public function tap(User $user, string $ip, string $method): array
    {
        try {
            $state = $this->taps->tap($user, $ip, $method);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['taps' => [$e->getMessage()]]);
        }

        return $state + ['expires_at' => $this->devMode->state()['expires_at']];
    }

    /**
     * باز کردن حالت توسعه‌دهنده — فقط با رمز عبور صحیح **و** ژستور کامل.
     *
     * @return array{expires_at: string, unlock_token: string}
     *
     * @throws ValidationException
     */
    public function unlock(User $user, string $ip, string $password, string $method): array
    {
        $state = $this->taps->state($user);

        if (! $state['ready']) {
            $this->audit($user, $ip, 'devmode.unlock_denied', [
                'taps' => $state['count'],
                'required' => $state['required'],
                'reason' => 'gesture_incomplete',
            ]);

            throw ValidationException::withMessages([
                'taps' => ['هنوز به اندازهٔ کافی کلیک نشده است.'],
            ]);
        }

        if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
            // ثبت تلاش ناموفق **قبل** از پرتاب استثنا — وگرنه کنترل‌فلو قبلی ردیف را
            // نمی‌نویسد و حمله بی‌ردپا می‌ماند.
            $this->audit($user, $ip, 'devmode.unlock_denied', [
                'taps' => $state['count'],
                'reason' => 'bad_password',
            ]);

            throw ValidationException::withMessages([
                'password' => ['رمز عبور صحیح نیست.'],
            ]);
        }

        $token = $this->issueToken();

        $expires = $this->devMode->enable($token);

        // ژستور مصرف شد. نگه‌داشتنش یعنی یک بار پنج کلیک، رمز را یک‌بار و بعد
        // هر بار رمز را از دوباره باز کند.
        $this->taps->reset($user);

        $this->audit($user, $ip, 'devmode.unlocked', [
            'expires_at' => $expires->toIso8601String(),
        ]);

        return [
            'expires_at' => $expires->toIso8601String(),
            'unlock_token' => $token,
        ];
    }

    /**
     * بستن حالت توسعه‌دهنده. ثبت می‌شود چون «چه کسی در را بست» هم بخشی از ردپاست.
     */
    public function lock(User $user, string $ip): void
    {
        $this->devMode->disable();
        $this->taps->reset($user);
        $this->audit($user, $ip, 'devmode.locked', []);
    }

    /**
     * توکن یک‌بارمصرف.
     *
     * ۳۲ بایت تصادفی، نه بیشتر: این توکن فقط هشت ساعت و فقط برای همان کاربر زنده
     * است، پس طولانی‌تر کردنش سود امنیتی ندارد و فقط توکن را در لاگ‌ها و
     * پاسخ‌ها بزرگ‌تر می‌کند.
     */
    private function issueToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * ثبت ردپا.
     *
     * خطای ثبت **نباید** کار اصلی را متوقف کند: ردپا ابزار عیب‌یابی است، نه
     * دروازه. اگر جدول مرکزی در دسترس نباشد، باز کردن حالت توسعه‌دهنده نباید
     * شکست بخورد — وگرنه یک مشکل ذخیره‌سازی کل دروازهٔ امنیتی را از کار می‌اندازد
     * و مدیر راهی برای نصب بستهٔ خودش ندارد. `DevModeAudit` همین را انجام می‌دهد
     * و دست‌کم لاگ می‌کند.
     */
    private function audit(User $user, string $ip, string $action, array $meta): void
    {
        $this->audit->record($user, $ip, $action, $meta);
    }
}
