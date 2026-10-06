<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RevalidateDispatcher;
use App\Services\Settings\PerformanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-M13 — تبِ «کارایی» در تنظیمات: دامنهٔ دارایی/CDN + توکن پاک‌سازی + lazy/preload.
 *
 * توکن پاک‌سازی رمزنگاری ذخیره می‌شود و **هرگز** به کلاینت برنمی‌گردد؛ فقط
 * `cdn_purge_token_set` (بولین) می‌آید. ذخیرهٔ توکنِ خالی = «بدون تغییر».
 * همراستا با `docs/CDN-ASSESSMENT.md` — CDN روی HTML توصیه نمی‌شود، پس دامنهٔ
 * دارایی فقط برای فایل‌های ایستا معنا دارد.
 */
class PerformanceSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $validated = $request->validate([
            'asset_domain' => 'nullable|string|max:2048|url:http,https',
            // توکن می‌تواند نویسه‌های خاص داشته باشد ⇒ عمداً `no_markup` ندارد.
            'cdn_purge_token' => 'nullable|string|max:1024',
            'clear_cdn_purge_token' => 'sometimes|boolean',
            'lazy_load_enabled' => 'sometimes|boolean',
            'font_preload_enabled' => 'sometimes|boolean',
        ], [
            'asset_domain.url' => 'دامنهٔ دارایی معتبر نیست (مثل https://cdn.example.ir).',
            'asset_domain.max' => 'دامنهٔ دارایی بیش از حد طولانی است.',
            'cdn_purge_token.max' => 'توکن پاک‌سازی CDN بیش از حد طولانی است.',
            'lazy_load_enabled.boolean' => 'مقدار lazy-load معتبر نیست.',
            'font_preload_enabled.boolean' => 'مقدار preload فونت معتبر نیست.',
        ]);

        PerformanceSettings::save($validated);

        // کروم عمومی همین تنظیمات را افشا می‌کند ⇒ اعلان به فرانت (fire-and-forget).
        $dispatcher->dispatch(['site-chrome', 'pages']);

        return response()->json([
            'message' => 'تنظیمات کارایی ذخیره شد.',
            'data' => $this->payload(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $settings = PerformanceSettings::get();

        return [
            'asset_domain' => PerformanceSettings::assetDomain(),
            'cdn_purge_token_set' => PerformanceSettings::hasCdnPurgeToken(),
            'lazy_load_enabled' => (bool) ($settings['lazy_load_enabled'] ?? true),
            'font_preload_enabled' => (bool) ($settings['font_preload_enabled'] ?? true),
        ];
    }
}
