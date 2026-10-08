<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-C2 — بازخوانی عمومی ریدایرکت‌ها برای میدل‌ور فرانت.
 *
 * میدل‌ور Next (proxy.ts) این فهرست را با TTL کوتاه کش می‌کند و پیش از
 * روتینگ مسیر را تطبیق می‌دهد. افزایش `hits` از راه همان میدل‌ور و از
 * endpoint سبکِ `hit` انجام می‌شود (بدون بلاک‌کردن پاسخ ریدایرکت).
 */
class RedirectController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Redirect::query()
            ->where('active', true)
            ->orderBy('id')
            ->get(['from_path', 'to_path', 'status_code'])
            ->map(fn (Redirect $r) => [
                'from_path' => Redirect::normalizePath($r->from_path),
                'to_path' => $r->to_path,
                'status_code' => (int) $r->status_code,
            ])
            ->all();

        return response()
            ->json(['data' => $rows])
            ->header('Cache-Control', 'public, max-age=30, s-maxage=60');
    }

    public function hit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500',
        ]);

        Redirect::query()
            ->where('active', true)
            ->where('from_path', Redirect::normalizePath($validated['path']))
            ->increment('hits');

        return response()->json(['ok' => true]);
    }
}
