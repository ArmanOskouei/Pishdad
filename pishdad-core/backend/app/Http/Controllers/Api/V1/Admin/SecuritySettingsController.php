<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\SecuritySettings;
use App\Support\IpAllowlist;
use App\Validation\IpOrCidr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-M7 — تبِ امنیتِ تنظیمات: IPهای مجاز پنل + سقف تلاش ورود.
 *
 * ⭐ جلوگیری از قفل‌شدن مدیر: اگر فهرستِ مجاز پُر باشد و نشانیِ همین درخواست
 * در آن نباشد، همان IP خودکار به فهرست اضافه می‌شود (و پاسخ با
 * `current_ip_added=true` توضیح می‌دهد). بدون این کار، مدیر بلافاصله پس از
 * ذخیره از پنل بیرون می‌افتاد و راهی برای برگشت نداشت.
 */
class SecuritySettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'allowed_admin_ips' => 'present|array|max:'.SecuritySettings::MAX_ALLOWED_IPS,
            'allowed_admin_ips.*' => ['string', 'max:64', new IpOrCidr],
            'login_attempt_cap' => 'required|integer|min:'.SecuritySettings::MIN_LOGIN_ATTEMPT_CAP.'|max:'.SecuritySettings::MAX_LOGIN_ATTEMPT_CAP,
        ], [
            'allowed_admin_ips.present' => 'فهرست IPهای مجاز الزامی است.',
            'allowed_admin_ips.array' => 'قالب فهرست IPهای مجاز نامعتبر است.',
            'allowed_admin_ips.max' => 'تعداد IPهای مجاز بیش از حد است (حداکثر '.SecuritySettings::MAX_ALLOWED_IPS.' مورد).',
            'allowed_admin_ips.*.max' => 'نشانی IP بیش از حد طولانی است.',
            'login_attempt_cap.required' => 'سقف تلاش ورود الزامی است.',
            'login_attempt_cap.integer' => 'سقف تلاش ورود باید عدد باشد.',
            'login_attempt_cap.min' => 'سقف تلاش ورود باید حداقل '.SecuritySettings::MIN_LOGIN_ATTEMPT_CAP.' باشد.',
            'login_attempt_cap.max' => 'سقف تلاش ورود باید حداکثر '.SecuritySettings::MAX_LOGIN_ATTEMPT_CAP.' باشد.',
        ]);

        $ips = IpAllowlist::normalize($validated['allowed_admin_ips']);

        $currentIp = (string) $request->ip();
        $addedCurrent = false;

        if ($ips !== [] && ! IpAllowlist::matches($currentIp, $ips)) {
            $ips[] = $currentIp;
            $addedCurrent = true;
        }

        SecuritySettings::save([
            'allowed_admin_ips' => $ips,
            'login_attempt_cap' => (int) $validated['login_attempt_cap'],
        ]);

        return response()->json([
            'message' => $addedCurrent
                ? 'تنظیمات امنیتی ذخیره شد. نشانی فعلی شما برای جلوگیری از قفل‌شدن به فهرست اضافه شد.'
                : 'تنظیمات امنیتی ذخیره شد.',
            'data' => $this->payload($request) + ['current_ip_added' => $addedCurrent],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        return SecuritySettings::get() + ['current_ip' => (string) $request->ip()];
    }
}
