<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Models\Central\HardwareKey;
use App\Models\Central\KillSwitch;
use App\Models\Central\OperatorActivity;
use App\Models\Central\SetupToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * تسک ۵.۷ — پروفایل اپراتور: کلید سخت‌افزاری + توکن نصب + kill-switch + لاگ فعالیت.
 *
 * - setup-tokens و kill-switch نیازمند step-up auth هستند: 2FA تازه (<۵ دقیقه).
 * - kill-switch دومرحله‌ای است: confirm:true + دلیل اجباری + لاگ غیرقابل حذف.
 * - ثبت کلید سخت‌افزاری فعلاً TOTP سخت‌افزاری است (WebAuthn واقعی TODO).
 */
class OperatorController extends Controller
{
    /**
     * دسته UIUX (افزودنی): صدور secret موقت + otpauth_url برای ثبت کلید
     * با QR (اسکن در اپ Authenticator، بعد ثبت با کد ۶ رقمی).
     */
    public function hardwareSetup(Request $request): JsonResponse
    {
        $secret = (new Google2FA)->generateSecretKey();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_url' => (new Google2FA)->getQRCodeUrl('CMS-Operator', $request->user()->email, $secret),
            ],
        ]);
    }

    public function registerHardwareKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'secret' => 'required|string|max:100',
            'code' => 'required|string|max:20',
        ], [
            'name.required' => 'نام کلید الزامی است.',
            'code.required' => 'کد تأیید کلید الزامی است.',
        ]);

        $code = str_replace(' ', '', $validated['code']);
        if (! (new Google2FA)->verifyKey($validated['secret'], $code, 1)) {
            return response()->json(['message' => 'کد کلید سخت‌افزاری صحیح نیست.'], 422);
        }

        // TODO: WebAuthn واقعی (challenge/response با کلید فیزیکی)؛ فعلاً TOTP سخت‌افزاری.
        $key = HardwareKey::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'credential' => Crypt::encryptString($validated['secret']),
        ]);

        OperatorActivity::record($request->user(), 'operator.hardware_key', ['key_id' => $key->id]);

        return response()->json([
            'message' => 'کلید سخت‌افزاری ثبت شد.',
            'data' => $key->only(['id', 'name', 'created_at']),
        ], 201);
    }

    public function createSetupToken(Request $request): JsonResponse
    {
        $stepUp = $this->requireFreshTwoFactor($request);
        if ($stepUp) {
            return $stepUp;
        }

        $validated = $request->validate([
            'purpose' => 'sometimes|nullable|string|max:150',
            'ttl_minutes' => 'sometimes|integer|min:1|max:60',
        ]);

        $plain = Str::random(48);
        $token = SetupToken::query()->create([
            'created_by' => $request->user()->id,
            'token_hash' => hash('sha256', $plain),
            'purpose' => $validated['purpose'] ?? null,
            'expires_at' => now()->addMinutes((int) ($validated['ttl_minutes'] ?? 15)),
        ]);

        OperatorActivity::record($request->user(), 'operator.setup_token', [
            'token_id' => $token->id, 'purpose' => $token->purpose,
        ]);

        // متن توکن فقط یک‌بار نمایش داده می‌شود (فقط هش ذخیره شده است).
        return response()->json([
            'message' => 'توکن نصب ساخته شد. فقط یک‌بار نمایش داده می‌شود.',
            'data' => [
                'token' => $plain,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ], 201);
    }

    public function killSwitch(Request $request): JsonResponse
    {
        $stepUp = $this->requireFreshTwoFactor($request);
        if ($stepUp) {
            return $stepUp;
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'confirm' => 'sometimes|boolean',
        ], [
            'reason.required' => 'دلیل توقف اضطراری الزامی است.',
            'reason.min' => 'دلیل باید حداقل ۱۰ نویسه باشد.',
        ]);

        if (($validated['confirm'] ?? false) !== true) {
            return response()->json(['message' => 'برای اجرای توقف اضطراری، تأیید صریح (confirm:true) لازم است.'], 422);
        }

        $record = KillSwitch::query()->create([
            'executed_by' => $request->user()->id,
            'reason' => $validated['reason'],
            'confirmed' => true,
            'scope' => 'all',
        ]);

        OperatorActivity::record($request->user(), 'operator.kill_switch', [
            'kill_switch_id' => $record->id, 'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'توقف اضطراری ثبت و در لاگ دائمی درج شد.',
            'data' => $record,
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $query = OperatorActivity::query()->with('user:id,name,email')->latest();

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        return response()->json(['data' => $query->paginate(
            (int) $request->query('per_page', 20)
        )]);
    }

    private function requireFreshTwoFactor(Request $request): ?JsonResponse
    {
        if (! AuthController::hasFreshTwoFactor($request->user())) {
            return response()->json(
                ['message' => 'این عمل نیازمند تأیید دومرحله‌ای تازه (کمتر از ۵ دقیقه) است.'],
                403
            );
        }

        return null;
    }
}
