<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * F4.1.F — توکن/چیدمانِ یک قالبِ مشخص برای **پیش‌نمایش** سایت عمومی.
 *
 * چرا لازم است: `/v1/site/chrome` فقط توکن‌های قالبِ **فعال** را می‌دهد. برای
 * دیدنِ قالبِ غیرفعال (تصمیمِ انتخاب) باید بتوانیم توکن‌های همان قالب را جدا
 * خواند. منبع، خودِ جدولِ `themes`/`site_themes` است تا پیش‌نمایش با چیزی که
 * پس از فعال‌سازی می‌آید یکی باشد.
 *
 * خروجی علاوه بر توکن‌های پیش‌فرض، **رنگ‌بندی‌های پیشنهادی** (هرکدام دو نیمهٔ
 * روشن/تیره) و توکن‌های چیدمان را هم می‌دهد تا صفحهٔ پیش‌نمایش بتواند بدون
 * هیچ درخواستِ دیگری، رنگ‌بندی و حالت را زنده عوض کند.
 */
class SiteThemeController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $theme = Theme::query()->where('slug', $slug)->first();

        if (! $theme) {
            return response()->json(['message' => 'قالب یافت نشد.'], 404);
        }

        $manifest = is_array($theme->manifest) ? $theme->manifest : [];

        // توکن‌های چیدمانِ قالب (شعاع/فونت/تراکم) — از جدولِ پوسته.
        $skin = DB::table('site_themes')->where('slug', $slug)->first();
        $layoutTokens = $skin ? (json_decode((string) $skin->layout_tokens, true) ?: []) : [];

        // رنگ‌بندی‌های پیشنهادی (سراسریِ نصب): هرکدام با نیمهٔ روشن و تیره.
        $colorways = DB::table('site_theme_presets')
            ->orderBy('sort_order')
            ->get(['key', 'name', 'tokens'])
            ->map(function ($p) {
                $tokens = json_decode((string) $p->tokens, true) ?: [];

                return [
                    'key' => $p->key,
                    'name' => $p->name,
                    'light' => $tokens['light'] ?? [],
                    'dark' => $tokens['dark'] ?? [],
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'name' => $theme->name,
                'slug' => $theme->slug,
                'version' => $theme->version,
                'layout' => $manifest['layout'] ?? null,
                'globals' => $manifest['globals'] ?? [],
                'builtin' => (bool) ($manifest['builtin'] ?? false),
                'layout_tokens' => $layoutTokens,
                'colorways' => $colorways,
                'default_colorway' => $colorways[0]['key'] ?? null,
            ],
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }
}
