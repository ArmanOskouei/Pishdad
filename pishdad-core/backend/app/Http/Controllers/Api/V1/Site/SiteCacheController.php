<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * وضعیتِ کلیدِ سراسریِ «کش سایت» برای فرانت.
 *
 * عمداً بدون کش خوانده می‌شود (`no-store`) چون خودش تعیین می‌کند فرانت دادهٔ
 * سایت را کش کند یا نه.
 */
class SiteCacheController extends Controller
{
    public function show(): JsonResponse
    {
        $site = Setting::get('site', 'global', []);
        $enabled = ! is_array($site) || (($site['cache_enabled'] ?? true) !== false);

        return response()->json(['data' => ['enabled' => $enabled]])
            ->header('Cache-Control', 'no-store');
    }
}
