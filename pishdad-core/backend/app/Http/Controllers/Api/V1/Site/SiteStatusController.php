<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Settings\CachedSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * وضعیت عمومی سایت (عمومی، سبک، بدون احراز هویت).
 *
 * این نصب هیچ لایهٔ صورت‌حسابی ندارد، پس سایت همیشه فعال است. نقطهٔ پایانی و
 * شکلِ پاسخ نگه داشته شدند چون لایهٔ کش و CDN روی همان‌ها حساب شده‌اند.
 */
class SiteStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id');
        $cacheKey = 'site-'.($siteId ?: 'default');

        $data = CachedSettings::remember('site_status', $cacheKey, 60, fn (): array => [
            'status' => 'active',
            'message' => self::siteBrand().' فعال است.',
        ]);

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * نامِ سایت از تنظیمات، با کشِ کوتاه.
     *
     * ۳۰۰ ثانیه چون این متن در هر بازدیدِ عمومی ساخته می‌شود و نامِ سایت تقریباً
     * هرگز عوض نمی‌شود. نبودِ عنوان یعنی نامِ عمومی — بدون این، متن می‌شد
     * « فعال است.» که جملهٔ ناتمامی است.
     */
    private static function siteBrand(): string
    {
        $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));

        return is_array($site) && ! empty($site['title']) ? (string) $site['title'] : 'وب‌سایت ما';
    }
}
