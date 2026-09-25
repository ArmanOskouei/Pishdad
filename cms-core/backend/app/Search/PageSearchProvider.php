<?php

namespace App\Search;

use App\Models\Page;

/**
 * provider پیش‌فرض جستجو: صفحات منتشرشده (الگوی همه providerهای آینده).
 * دامنه: عنوان + متن بلوک‌ها + meta. draft/منتشرنشده هرگز برنمی‌گردد.
 */
final class PageSearchProvider implements SearchableProvider
{
    public function key(): string
    {
        return 'page';
    }

    public function search(string $query, string $normalizedQuery, int $limit): array
    {
        $pages = Page::query()
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get(['id', 'title', 'slug', 'blocks', 'meta', 'updated_at']);

        $out = [];
        foreach ($pages as $page) {
            $blocksText = PersianText::flatten($page->blocks ?? []);
            $metaText = PersianText::flatten($page->meta ?? []);
            $combined = trim($page->title.' '.$blocksText.' '.$metaText);
            if ($combined === '') {
                continue;
            }
            if (mb_strpos(PersianText::normalize($combined), $normalizedQuery) === false) {
                continue;
            }

            // snippet: اول متن بلوک‌ها (نزدیک‌ترین به محتوا)، وگرنه عنوان+meta.
            $snippetSource = $blocksText !== '' ? $blocksText : $combined;
            $out[] = [
                'title' => (string) $page->title,
                'slug' => (string) $page->slug,
                'snippet' => PersianText::snippet($snippetSource, $query),
                'type' => 'page',
                'updated_at' => $page->updated_at?->toIso8601String(),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
