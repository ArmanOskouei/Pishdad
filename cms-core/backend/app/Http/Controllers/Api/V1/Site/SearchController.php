<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Search\SearchManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * جستجوی عمومی سایت: GET /api/v1/site/search?q=&per_page=
 * عمومی، بدون احراز هویت، throttle سفت. فقط محتوای منتشرشده (draft درز
 * نمی‌کند). تطابق فارسی‌دوست (ي/ك و نیم‌فاصله) در providerها.
 */
class SearchController extends Controller
{
    public function index(Request $request, SearchManager $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:200',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ], [
            'q.required' => 'عبارت جستجو الزامی است.',
            'q.min' => 'عبارت جستجو باید حداقل ۲ نویسه باشد.',
            'q.max' => 'عبارت جستجو حداکثر ۲۰۰ نویسه باشد.',
            'per_page.integer' => 'تعداد در صفحه نامعتبر است.',
            'per_page.min' => 'تعداد در صفحه نامعتبر است.',
            'per_page.max' => 'حداکثر ۵۰ نتیجه در هر صفحه مجاز است.',
        ]);

        $q = trim($validated['q']);
        if (mb_strlen($q) < 2) {
            return response()->json([
                'message' => 'عبارت جستجو باید حداقل ۲ نویسه باشد.',
                'errors' => ['q' => ['عبارت جستجو باید حداقل ۲ نویسه باشد.']],
            ], 422);
        }
        $perPage = (int) ($validated['per_page'] ?? 10);

        $results = $search->search($q, $perPage);

        return response()->json([
            'data' => $results,
            'meta' => ['q' => $q, 'total' => count($results), 'per_page' => $perPage],
        ])->header('Cache-Control', 'public, max-age=30');
    }
}
