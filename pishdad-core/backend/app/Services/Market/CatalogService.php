<?php

namespace App\Services\Market;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WF-H16 — خواندنِ فروشگاه: جستجو/دسته/فیلتر + پروجکشنِ جزئیات.
 *
 * هیچ فیلدی ساخته نمی‌شود. متادیتای غنی (`category`، `screenshots`،
 * `long_description`، `changelog`) فقط اگر در مانیفستِ موجودِ افزونه باشد خوانده
 * می‌شود؛ در نبودش `null`/آرایهٔ خالی برمی‌گردد تا UI «نداریم» را نشان دهد،
 * نه یک مقدارِ جعلی. `price`/`currency` همچنان از ReviewService می‌آید تا معنای
 * «رایگان vs پولی» یک منبع داشته باشد.
 */
class CatalogService
{
    public function __construct(
        private DeliveryService $delivery,
        private ReviewService $review,
        private MarketReviewService $marketReviews,
    ) {}

    /** @param array{q?: mixed, category?: mixed, price?: mixed} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Plugin::query()
            ->where('source', Plugin::SOURCE_MARKET)
            ->where('review_status', Plugin::REVIEW_APPROVED)
            ->where('yanked', false);

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // E85 — فارسی‌دوست (نام/توضیح فارسی افزونه‌ها: چندفرمی + غلط نزدیک).
            \App\Search\PersianText::whereFa($query, $q, ['name', 'slug', 'description', "(manifest->>'description')"]);
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->where(function ($w) use ($category): void {
                $w->where('manifest->category', $category)
                    ->orWhereJsonContains('manifest->categories', $category)
                    ->orWhereJsonContains('manifest->tags', $category);
            });
        }

        $price = (string) ($filters['price'] ?? '');
        if ($price === 'free' || $price === 'paid') {
            $expr = "CASE WHEN jsonb_typeof(manifest->'price') = 'number' THEN (manifest->>'price')::numeric ELSE 0 END";
            $query->whereRaw($expr.($price === 'paid' ? ' > 0' : ' <= 0'));
        }

        return $query->latest()->paginate(20)->withQueryString();
    }

    /** شکلِ فهرست: همهٔ ستون‌های افزونه + قیمت/ارز + وضعیت تحویل + متادیتای غنی. */
    public function present(Plugin $plugin, ?User $user): array
    {
        $stats = $this->marketReviews->stats($plugin);

        return array_merge($plugin->toArray(), [
            'price' => $this->review->priceOf($plugin),
            'currency' => $this->review->currencyOf($plugin),
            'delivery' => $this->delivery->deliveryStatus($plugin, $user),
            'category' => $this->categoryOf($plugin),
            'screenshots' => $this->screenshotsOf($plugin),
            'long_description' => $this->longDescriptionOf($plugin),
            'changelog' => $this->changelogOf($plugin),
            // WF-H18 — social proof: میانگین/شمار امتیاز و نصب فعال (همه واقعی).
            'rating' => $stats['rating'],
            'rating_count' => $stats['rating_count'],
            'active_installs' => $stats['active_installs'],
        ]);
    }

    /** شکلِ صفحهٔ جزئیات: فهرست + فهرست نسخه‌ها/changelog + نظرهای تأییدشده. */
    public function detail(Plugin $plugin, ?User $user): array
    {
        $myReview = $user !== null ? $this->marketReviews->myReview($plugin, $user) : null;

        return array_merge($this->present($plugin, $user), [
            'versions' => $this->versionsOf($plugin),
            'reviews' => $this->marketReviews->approvedFor($plugin),
            'can_review' => $user !== null
                && $this->marketReviews->hasPurchased($plugin, $user)
                && $myReview === null,
            'has_reviewed' => $myReview !== null,
            'my_review_status' => $myReview?->status,
        ]);
    }

    /** دسته‌های متمایزِ افزونه‌های در دسترس — از خود مانیفست، نه هاردکد. */
    public function categories(): array
    {
        $out = [];
        Plugin::query()
            ->where('source', Plugin::SOURCE_MARKET)
            ->where('review_status', Plugin::REVIEW_APPROVED)
            ->where('yanked', false)
            ->get(['manifest'])
            ->each(function (Plugin $p) use (&$out): void {
                foreach ($this->categoriesIn(is_array($p->manifest) ? $p->manifest : []) as $c) {
                    $out[$c] = true;
                }
            });
        $keys = array_keys($out);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return string[] */
    public function categoriesIn(array $manifest): array
    {
        $out = [];
        $category = $manifest['category'] ?? null;
        if (is_string($category) && trim($category) !== '') {
            $out[] = trim($category);
        }
        foreach (['categories', 'tags'] as $key) {
            $list = $manifest[$key] ?? null;
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = trim($item);
                }
            }
        }

        return array_values(array_unique($out));
    }

    public function categoryOf(Plugin $plugin): ?string
    {
        return $this->categoriesIn(is_array($plugin->manifest) ? $plugin->manifest : [])[0] ?? null;
    }

    /** @return string[] */
    public function screenshotsOf(Plugin $plugin): array
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];
        foreach (['screenshots', 'images', 'gallery', 'screens'] as $key) {
            $list = $manifest[$key] ?? null;
            if (! is_array($list)) {
                continue;
            }
            $urls = [];
            foreach ($list as $item) {
                $url = is_string($item) ? $item : (is_array($item) ? ($item['url'] ?? null) : null);
                if (! is_string($url)) {
                    continue;
                }
                $url = trim($url);
                if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '/'))) {
                    $urls[] = $url;
                }
            }
            if ($urls !== []) {
                return array_values(array_unique($urls));
            }
        }

        return [];
    }

    public function longDescriptionOf(Plugin $plugin): ?string
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];
        foreach (['long_description', 'description'] as $key) {
            $v = $manifest[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        if (is_string($plugin->description) && trim($plugin->description) !== '') {
            return trim($plugin->description);
        }

        return null;
    }

    public function changelogOf(Plugin $plugin): ?string
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];
        foreach (['changelog', 'release_notes'] as $key) {
            $v = $manifest[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /**
     * فهرست نسخه‌ها برای same slug — از رکوردهای واقعیِ بازار.
     *
     * @return array<int, array{version: string, changelog: ?string, released_at: ?string, yanked: bool}>
     */
    public function versionsOf(Plugin $plugin): array
    {
        return Plugin::query()
            ->where('slug', $plugin->slug)
            ->where('source', Plugin::SOURCE_MARKET)
            ->where('review_status', Plugin::REVIEW_APPROVED)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Plugin $p) => [
                'version' => (string) $p->version,
                'changelog' => $this->changelogOf($p),
                'released_at' => $p->reviewed_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
                'yanked' => (bool) ($p->yanked ?? false),
            ])
            ->unique('version')
            ->values()
            ->all();
    }
}
