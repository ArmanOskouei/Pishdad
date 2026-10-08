<?php

namespace App\Services\Analytics;

use App\Models\Page;
use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * WF-H12/WF-M12 — تنها صاحبِ «تجمعِ بازدید».
 *
 * گزارشِ `Admin\AnalyticsController` (بازهٔ دلخواه) و کارتِ «صفحات پربازدید ۷
 * روز» در `Admin\DashboardController` هر دو از همین کلاس می‌خوانند. پیش از این
 * سطلِ top-N یک متدِ `private` داخلِ `AnalyticsController` بود، پس مصرف‌کنندهٔ
 * دوم یا همان کوئری را کپی می‌کرد (دو تعریفِ مستقل و به‌تدریج ناسازگار از
 * «پربازدیدترین») یا کلاً تحلیل را دور می‌زد.
 *
 * کنترلرها فقط **بازه** و **سقف** را تعیین می‌کنند؛ شکلِ سطل و معنای شمارش اینجاست.
 */
class SiteAnalytics
{
    /** سقف ردیف‌های هر جدولِ تجمعی در گزارشِ تحلیل. */
    public const TOP_LIMIT = 10;

    /** زبان‌های عمومی — هم‌قرارداد با `Site\AnalyticsController` و `lib/i18n/public`. */
    private const PUBLIC_LOCALES = ['fa', 'en'];

    /**
     * سقفِ مسیرهایی که برای یافتن صفحه خوانده می‌شوند.
     *
     * فیلترِ «منتشرشده» **بعد** از این سقف اعمال می‌شود؛ اگر قبل از آن اعمال
     * می‌شد، کوئری باید join می‌زد و اسلاگ/زبانِ مسیر را در SQL حدس می‌زد — و
     * حدسِ اسلاگ همان چیزی است که WF-H12 آگاهانه از آن پرهیز کرد. برای پنجرهٔ
     * ۷ روزه، ۵۰ مسیرِ پربازدید بیش از آن است که یک سایتِ خودمیزبانِ واقعی
     * داشته باشد.
     */
    private const RESOLVE_SCAN_LIMIT = 50;

    /**
     * تجمعِ یک ستون، نزولی، با سقف. ردیف‌های `null` حذف می‌شوند تا «نامعلوم»
     * به‌جای یک مقدار واقعی جا نزند.
     *
     * @param  Builder  $query  محدود به بازه (کارِ فراخوان).
     * @return array<int, array{key: string, views: int}>
     */
    public function top(Builder $query, string $column, int $limit = self::TOP_LIMIT): array
    {
        return $query
            ->whereNotNull($column)
            ->select($column)
            ->selectRaw('COUNT(*) as views')
            ->groupBy($column)
            ->orderByDesc('views')
            ->orderBy($column)
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => [
                'key' => (string) $row->{$column},
                'views' => (int) $row->views,
            ])
            ->all();
    }

    /**
     * WF-M12 — پربازدیدترین صفحه‌های *عمومی* یک بازه، همراه عنوانشان.
     *
     * ## چرا به `path` گروه می‌کنیم و نه به `page_id`
     *
     * ثبت‌کنندهٔ عمومی برای مسیرِ ریشه (`/`) هیچ‌وقت `page_id` پیدا نمی‌کند
     * (اسلاگِ خالی است و اسلاگِ صفحهٔ خانه `home`). گروه‌بندی روی `page_id`
     * یعنی **پربازدیدترین صفحهٔ هر سایت همیشه حذف می‌شد**. اینجا همان سطلِ
     * مسیرِ گزارشِ تحلیل را می‌گیریم و بعد هویتِ صفحه را حل می‌کنیم.
     *
     * ## «منتشرشده» یعنی چه
     *
     * همان شرطی که مسیرِ عمومی و سازندهٔ منو دارند: `status = published` **و**
     * `published_revision_id` نه‌تهی (`Layouts\LinkItems`). پیش‌نویس یا صفحهٔ
     * بدونِ نسخهٔ زنده در کارت نمی‌آید: بازدیدِ آن بازدیدِ چیزی نیست که مردم
     * دیده‌اند.
     *
     * @return array<int, array{path: string, page_id: int, title: string, views: int}>
     */
    public function topPages(Carbon $start, Carbon $end, int $limit = 5): array
    {
        $views = [];
        foreach ($this->top($this->range($start, $end), 'path', self::RESOLVE_SCAN_LIMIT) as $bucket) {
            $views[$bucket['key']] = $bucket['views'];
        }

        if ($views === []) {
            return [];
        }

        $pages = $this->publishedPagesByPath(array_keys($views));

        $out = [];
        foreach ($views as $path => $count) {
            $page = $pages[$path] ?? null;
            if ($page === null) {
                continue;
            }

            $out[] = [
                'path' => (string) $path,
                'page_id' => (int) $page->id,
                'title' => (string) $page->title,
                'views' => (int) $count,
            ];
        }

        return array_slice($out, 0, max(0, $limit));
    }

    private function range(Carbon $start, Carbon $end): Builder
    {
        return PageView::query()->whereBetween('created_at', [$start->copy(), $end->copy()]);
    }

    /**
     * مسیر ⇒ صفحهٔ عمومی، با همان قاعده‌ای که سایت عمومی موقع **ثبت** بازدید
     * به کار برد (`Site\AnalyticsController::resolvePageId`): پیشوندِ زبان جدا
     * و بقیهٔ مسیر اسلاگ. برای ریشه اسلاگِ `home` — هم‌قرارداد با
     * `Site\PageController`.
     *
     * یک کوئری برای همهٔ اسلاگ‌ها. دو نگاشت ساخته می‌شود: «زبانِ دقیقِ مسیر» و
     * «هر زبانی» برای مسیرِ بدونِ پیشوند.
     *
     * ⭐ نگاشتِ «هر زبانی» باید **زبانِ پیش‌فرض** را برنده کند — همان چیزی که
     * `Site\PageController::locale()` برای مسیرِ بدونِ پیشوند می‌خواند. با
     * `orderBy('locale')` می‌شد `/about` را به نسخهٔ `en` چسباند (چون `'en'`
     * الفبایی‌تر است) و عنوانِ فارسیِ همان صفحه را گم کرد.
     *
     * @param  list<string>  $paths
     * @return array<string, Page>
     */
    private function publishedPagesByPath(array $paths): array
    {
        $targets = [];
        foreach ($paths as $path) {
            [$locale, $slug] = $this->localeAndSlug($path);
            $targets[$path] = [$locale, $slug === '' ? 'home' : $slug];
        }

        $pages = Page::query()
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->whereIn('slug', array_values(array_unique(array_column($targets, 1))))
            ->orderBy('locale')
            ->get(['id', 'slug', 'locale', 'title']);

        $exact = [];
        $anyLocale = [];
        foreach ($pages as $page) {
            $exact[$page->locale.'|'.$page->slug] ??= $page;
        }
        foreach ($pages as $page) {
            if ($page->locale === Page::LOCALE_DEFAULT) {
                $anyLocale[$page->slug] = $page;
            } else {
                $anyLocale[$page->slug] ??= $page;
            }
        }

        $out = [];
        foreach ($targets as $path => [$locale, $slug]) {
            $page = $locale === null
                ? ($anyLocale[$slug] ?? null)
                : ($exact[$locale.'|'.$slug] ?? null);
            if ($page !== null) {
                $out[$path] = $page;
            }
        }

        return $out;
    }

    /**
     * `/en/about` ⇒ (en, about) · `/about` ⇒ (null, about) · `/` ⇒ (null, «»).
     *
     * @return array{0: string|null, 1: string}
     */
    private function localeAndSlug(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return [null, ''];
        }

        $locale = in_array($segments[0], self::PUBLIC_LOCALES, true) ? array_shift($segments) : null;

        return [$locale, implode('/', $segments)];
    }
}
