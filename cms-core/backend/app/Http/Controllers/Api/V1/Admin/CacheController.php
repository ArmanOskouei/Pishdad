<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Pages\PagePublisher;
use App\Services\RevalidateDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H13 — پاک‌سازی دستی کش سایت.
 *
 * مکملِ کلید سراسری «کش آن/آف»: اگر تغییر روی سایت دیده نشد، مدیر می‌تواند
 * بی‌درنگ تگ‌های ISR فرانت را باطل کند — سراسری یا فقط یک صفحه.
 */
class CacheController extends Controller
{
    /** @return list<string> */
    private function allTags(): array
    {
        $tags = ['pages', 'site-chrome', 'site-homepage'];

        Page::query()
            ->select('id', 'slug')
            ->orderBy('id')
            ->chunk(500, function ($pages) use (&$tags): void {
                foreach ($pages as $page) {
                    $tags[] = 'page:'.$page->slug;
                }
            });

        return array_values(array_unique($tags));
    }

    public function purge(RevalidateDispatcher $dispatcher): JsonResponse
    {
        $tags = $this->allTags();
        $dispatched = $dispatcher->dispatch($tags);

        return response()->json([
            'message' => $dispatched
                ? 'کش سایت پاک شد.'
                : 'درخواست پاک‌سازی کش ثبت شد، اما فرانت‌اند در دسترس نبود.',
            'data' => [
                'tags' => $tags,
                'dispatched' => $dispatched,
                'pages' => count($tags) - 3,
            ],
        ]);
    }

    public function purgePage(Request $request, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:200',
        ], [
            'slug.required' => 'اسلاگ صفحه الزامی است.',
            'slug.string' => 'اسلاگ صفحه نامعتبر است.',
            'slug.max' => 'اسلاگ صفحه بیش از حد بلند است.',
        ]);

        $slug = (string) $validated['slug'];
        $page = Page::query()->where('slug', $slug)->first();

        $tags = $page
            ? $publisher->tagsFor($page)
            : array_values(array_unique(['pages', "page:{$slug}"]));

        $dispatched = $dispatcher->dispatch($tags);

        return response()->json([
            'message' => $dispatched
                ? 'کش این صفحه پاک شد.'
                : 'درخواست پاک‌سازی کش ثبت شد، اما فرانت‌اند در دسترس نبود.',
            'data' => [
                'slug' => $slug,
                'tags' => $tags,
                'dispatched' => $dispatched,
            ],
        ]);
    }
}
