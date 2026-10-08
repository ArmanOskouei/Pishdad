<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\PageShareLink;
use App\Models\Setting;
use App\Services\Pages\PageShareSigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * رندر عمومی برای ISR (تسک ۱.۱): فقط صفحات published.
 * هدر کش عمومی تا لبه/CDN بتواند کش کند؛ draft هرگز از این مسیر درز نمی‌کند.
 * ستون‌های کناری با ارجاع زنده resolve می‌شوند: بلوک‌ها همیشه از روی
 * پریست خوانده می‌شوند (نه کپی) + toggle مستقل هر ستون.
 */
class PageController extends Controller
{
    /** F4.5 — زبان‌های سایت. بیرون از این فهرست ⇒ fa (fail-safe). */
    private const LOCALES = ['fa', 'en'];

    /**
     * WF-M19 — سقفِ آیتمِ فیدِ RSS. کوچک عمداً: فیدِ خواننده باید سبک بماند و
     * این endpoint عمومی و بدون احراز هویت است.
     */
    private const FEED_PAGE_LIMIT = 50;

    /** F4.5 — زبانِ درخواست از `?locale=` (پیش‌فرض زبانِ اصلی). */
    private function locale(Request $request): string
    {
        $locale = (string) $request->query('locale', Page::LOCALE_DEFAULT);

        return in_array($locale, self::LOCALES, true) ? $locale : Page::LOCALE_DEFAULT;
    }

    /**
     * فهرست سبک صفحات منتشرشده (برای sitemap.xml و llms.txt فرانت).
     * فقط slug/title/تاریخ‌ها — بدون بلوک‌ها.
     */
    public function index(Request $request): JsonResponse
    {
        $pages = Page::query()
            ->where('locale', $this->locale($request))
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get(['title', 'slug', 'is_single', 'published_at', 'updated_at']);

        return response()->json([
            'data' => $pages->map(fn (Page $p) => [
                'title' => $p->title,
                'slug' => $p->slug,
                'is_single' => (bool) $p->is_single,
                'published_at' => $p->published_at?->toIso8601String(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->all(),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /**
     * صفحه خانه عمومی (برای روت `/` فرانت): ترتیب حل —
     * homepage_page_id تنظیمات مشترک → اسلاگ `home` → جدیدترین published → 404 خنثی.
     * فقط published؛ draft هرگز درز نمی‌کند.
     */
    public function homepage(Request $request): JsonResponse
    {
        // صفحهٔ خانه قابلیتِ خودِ core است، نه بخشی از اشتراک. قبلاً پشت شرطِ
        // «اشتراکی وجود دارد؟» پنهان بود و در نبودِ افزونهٔ مرکزی همه‌چیز ۴۰۴ می‌داد.
        $site = array_merge(
            SiteSettingsController::SITE_DEFAULTS,
            Setting::get('site', 'global', []) ?? []
        );

        $homepageId = $site['homepage_page_id'] ?? null;
        $page = null;
        $locale = $this->locale($request);

        if (is_numeric($homepageId) && (int) $homepageId > 0) {
            $page = Page::query()
                ->where('id', (int) $homepageId)
                ->where('locale', $locale)
                ->where('status', Page::STATUS_PUBLISHED)
                ->whereNotNull('published_revision_id')
                ->with(['publishedRevision', 'leftPreset', 'rightPreset'])
                ->first();
        }

        $page ??= Page::query()
            ->where('slug', 'home')
            ->where('locale', $locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->with(['publishedRevision', 'leftPreset', 'rightPreset'])
            ->first();

        $page ??= Page::query()
            ->where('locale', $locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->orderByDesc('published_at')
            ->with(['publishedRevision', 'leftPreset', 'rightPreset'])
            ->first();

        if (! $page) {
            return response()->json(['message' => 'صفحه خانه‌ای تنظیم نشده است.'], 404);
        }

        return response()->json(['data' => $this->payload($page)])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    public function show(Request $request, string $path): JsonResponse
    {
        $page = Page::query()
            ->where('slug', $path)
            ->where('locale', $this->locale($request))
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->with(['publishedRevision', 'leftPreset', 'rightPreset'])
            ->first();

        if (! $page) {
            return response()->json(['message' => 'صفحه یافت نشد.'], 404);
        }

        return response()->json(['data' => $this->payload($page)])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /**
     * WF-H2 — پیش‌نمایشِ عمومیِ پیش‌نویس با توکنِ اشتراک (بدون ورود).
     *
     * فقط با توکنِ معتبرِ امضاشده و منقضی‌نشده و ردیفِ غیرباطل‌شده سرو می‌شود؛
     * draft هرگز بدون این شرایط درز نمی‌کند. پاسخ `noindex` است (هم هدر هم متا)
     * و هیچ‌وقت کش نمی‌شود.
     */
    public function showShare(string $token, PageShareSigner $signer): JsonResponse
    {
        $claims = $signer->verify($token);
        if ($claims === null) {
            return response()->json(['message' => 'لینک اشتراک نامعتبر است.'], 403);
        }

        if ($claims['expires_at'] < time()) {
            return response()->json(['message' => 'لینک اشتراک منقضی شده است.'], 410);
        }

        $link = PageShareLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if ($link === null
            || $link->page_id !== $claims['page_id']
            || $link->revision_id !== $claims['revision_id']) {
            return response()->json(['message' => 'لینک اشتراک نامعتبر یا لغو شده است.'], 403);
        }

        $page = Page::query()->with(['leftPreset', 'rightPreset'])->find($claims['page_id']);
        if ($page === null) {
            return response()->json(['message' => 'صفحه یافت نشد.'], 404);
        }

        $revision = $page->revisions()->whereKey($claims['revision_id'])->first();
        if ($revision === null) {
            return response()->json(['message' => 'نسخه یافت نشد.'], 404);
        }

        $payload = $this->payloadForRevision($page, $revision);
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['noindex'] = true;
        $meta['robots'] = 'noindex, nofollow';
        $payload['meta'] = $meta;

        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * WF-C7 — بایگانی بلاگ (فهرست صفحهبندیشدهٔ نوشتهها).
     *
     * قراردادِ نوشته: یک صفحهٔ `published` با `meta.page_type = 'blog'`
     * (کانالِ `page_types`ِ مانیفست، مثل افزونهٔ بلاگ). دسته/برچسب هم در
     * `meta` زندگی میکنند و اختیاریاند:
     *
     *   category : رشتهٔ ساده `meta.category` یا آرایهٔ `meta.categories`
     *   tag      : رشتهٔ ساده `meta.tag` یا آرایهٔ `meta.tags`
     *
     * خروجی سبک است (بدون بلوک) تا ISR سبک بماند؛ بلوکها فقط در show.
     */
    public function blog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'category' => 'sometimes|string|max:120',
            'tag' => 'sometimes|string|max:120',
        ], [
            'per_page.max' => 'حداکثر ۵۰ نوشته در هر صفحه مجاز است.',
            'per_page.min' => 'تعداد در صفحه نامعتبر است.',
            'page.min' => 'شمارهٔ صفحه نامعتبر است.',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $pageNo = (int) ($validated['page'] ?? 1);
        $category = isset($validated['category']) ? trim((string) $validated['category']) : null;
        $tag = isset($validated['tag']) ? trim((string) $validated['tag']) : null;

        $paginator = $this->blogQuery($request, $category, $tag)
            ->with('publishedRevision')
            ->paginate($perPage, ['*'], 'page', $pageNo);

        return response()->json([
            'data' => [
                'data' => collect($paginator->items())->map(fn (Page $p) => $this->blogListItem($p))->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /**
     * WF-C7 — فید RSS 2.0 نوشتههای بلاگ (فقط published).
     *
     * همان دادهٔ `blog` بِدو فید؛ نشانیها با در نظر گرفتنِ مدلِ زبانِ سایت
     * ساخته میشوند (زبانِ پایه بدون پیشوند، زبانِ دوم با `/{locale}`).
     * پاسخ با هدر کش عمومی میآید تا لبه/CDN بتواند کش کند.
     */
    public function blogFeed(Request $request): Response
    {
        $locale = $this->locale($request);
        $site = array_merge(SiteSettingsController::SITE_DEFAULTS, Setting::get('site', 'global', []) ?? []);

        $pages = $this->blogQuery($request)
            ->with('publishedRevision')
            ->limit(50)
            ->get();

        $base = rtrim((string) ($site['site_url'] ?? ''), '/');
        if ($base === '') {
            $base = $request->getSchemeAndHttpHost();
        }

        $primary = (string) ($site['locale'] ?? Page::LOCALE_DEFAULT);
        $prefix = $locale === $primary ? '' : '/'.$locale;
        $archiveUrl = $base.$prefix.'/blog';
        $feedUrl = $archiveUrl.'/feed.xml';

        $items = '';
        foreach ($pages as $page) {
            $item = $this->blogListItem($page);
            $link = $base.$prefix.'/blog/'.$item['slug'];
            $pub = $page->published_at?->toRfc2822String();

            $items .= '<item>';
            $items .= '<title>'.$this->xmlEscape($page->title).'</title>';
            $items .= '<link>'.$this->xmlEscape($link).'</link>';
            $items .= '<guid isPermaLink="true">'.$this->xmlEscape($link).'</guid>';
            if ($pub !== null) {
                $items .= '<pubDate>'.$this->xmlEscape($pub).'</pubDate>';
            }
            if (is_string($item['category']) && $item['category'] !== '') {
                $items .= '<category>'.$this->xmlEscape($item['category']).'</category>';
            }
            if (is_string($item['excerpt']) && $item['excerpt'] !== '') {
                $items .= '<description>'.$this->xmlEscape($item['excerpt']).'</description>';
            }
            $items .= '</item>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n"
            .'<channel>'."\n"
            .'<title>'.$this->xmlEscape((string) ($site['title'] ?? '')).'</title>'."\n"
            .'<link>'.$this->xmlEscape($archiveUrl).'</link>'."\n"
            .'<description>'.$this->xmlEscape((string) ($site['description'] ?? '')).'</description>'."\n"
            .'<language>'.($locale === 'en' ? 'en' : 'fa').'</language>'."\n"
            .'<atom:link href="'.$this->xmlEscape($feedUrl).'" rel="self" type="application/rss+xml" />'."\n"
            .$items
            .'</channel>'."\n".'</rss>';

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=60, s-maxage=300',
        ]);
    }

    /**
     * WF-M19 — دادهٔ خامِ فید RSS از **همهٔ** محتوای منتشرشده (نه فقط بلاگ).
     *
     * `blogFeed` بالا خودش XML می‌سازد و فقط `page_type=blog` را می‌گیرد؛ اینجا
     * فهرستِ JSON می‌دهد تا فرانت یک فیدِ واحد از همهٔ صفحات بسازد — پس عمداً
     * XML نیست (یک منبعِ حقیقت: شکلِ سند در فرانسازندهٔ فید می‌ماند).
     *
     * قفل‌های عدمِ درز، از محکم‌ترین به نرم‌ترین:
     *   ۱. کوئری: زبانِ درخواست + `published` + `published_revision_id` غیر null
     *      (بدون نسخهٔ منتشرشده صفحه‌ای روی سایت اصلاً وجود ندارد).
     *   ۲. SoftDeletes مدل ⇒ صفحهٔ داخلِ زباله‌دان هم با `deleted_at` فیلتر می‌شود.
     *   ۳. فیلترِ PHP روی متای **نسخهٔ منتشرشده**: `noindex`/`robots`.
     *
     * متا از روی revision خوانده می‌شود نه از `pages.meta` (پیش‌نویس) — وگرنه
     * یک `noindex` که هنوز منتشر نشده بود فیدِ زنده را مسموم می‌کرد.
     *
     * سقف: `FEED_PAGE_LIMIT * 2` ردیف برای فیلترِ PHP، خروجی ≤ `FEED_PAGE_LIMIT`.
     */
    public function feed(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $site = array_merge(SiteSettingsController::SITE_DEFAULTS, Setting::get('site', 'global', []) ?? []);

        $pages = Page::query()
            ->where('locale', $locale)
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->whereNotNull('published_at')
            ->where('slug', '!=', '')
            ->with('publishedRevision')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::FEED_PAGE_LIMIT * 2)
            ->get();

        $base = $this->siteBase($site, $request);
        $prefix = $locale === $this->sitePrimaryLocale($site) ? '' : '/'.$locale;

        $items = [];
        foreach ($pages as $page) {
            if (count($items) >= self::FEED_PAGE_LIMIT) {
                break;
            }

            $revision = $page->publishedRevision;
            $meta = $revision?->meta ?? $page->meta;
            $meta = is_array($meta) ? $this->withMetaMediaUrl($meta) : [];

            if (! $this->feedVisible($meta)) {
                continue;
            }

            $path = $prefix.'/'.$this->feedPath($page, $meta);
            $type = $meta['page_type'] ?? $meta['_page_type'] ?? null;

            $items[] = [
                'title' => $page->title,
                'slug' => $page->slug,
                // `path` نسبی و آگاه به زبان است (فقط یک منبعِ حقیقت برای مسیر)؛
                // `url` نسخهٔ مطلقِ همان مسیر روی دامنهٔ تنظیم‌شدهٔ سایت.
                'path' => $path,
                'url' => $base.$path,
                'summary' => $this->excerpt($meta, $revision?->blocks ?? []),
                'page_type' => is_string($type) ? $type : null,
                'published_at' => $page->published_at?->toIso8601String(),
                'updated_at' => $page->updated_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $items])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    /** نشانیِ پایهٔ سایت: تنظیمات (`site_url`) اول، بعد میزبانِ همین درخواست. */
    private function siteBase(array $site, Request $request): string
    {
        $base = rtrim((string) ($site['site_url'] ?? ''), '/');

        return $base !== '' ? $base : $request->getSchemeAndHttpHost();
    }

    /** WF-M19 — زبانِ اصلیِ سایت (پیشوندِ مسیر فقط برای زبانِ غیرِ اصلی لازم است). */
    private function sitePrimaryLocale(array $site): string
    {
        $primary = (string) ($site['locale'] ?? Page::LOCALE_DEFAULT);

        return in_array($primary, self::LOCALES, true) ? $primary : Page::LOCALE_DEFAULT;
    }

    /**
     * مسیرِ عمومیِ صفحه بدون دامنه: نوشتهٔ بلاگ زیر `/blog/{slug}` (با حذفِ
     * پیشوندِ `blog/` در اسلاگ) و بقیه همان اسلاگِ خودشان — دقیقاً همان قراردادی
     * که `blogFeed` برای لینکِ نوشته‌ها می‌سازد.
     */
    private function feedPath(Page $page, array $meta): string
    {
        $type = $meta['page_type'] ?? $meta['_page_type'] ?? null;
        $slug = ltrim($page->slug, '/');

        if (is_string($type) && $type === 'blog') {
            return 'blog/'.$this->normalizeBlogSlug($page->slug);
        }

        return $slug;
    }

    /**
     * آیا این صفحه اجازه دارد در فیدِ عمومی باشد؟
     *
     * `published` را کوئری تضمین می‌کند؛ اینجا فقط noindex فیلتر می‌شود — چنین
     * صفحه‌ای عمداً از دیدِ موتورِ جست‌وجو پنهان است، پس دادنش به خوانندگانِ
     * فید هم درزِ همان قصد است. هر دو شکلِ رایجِ سیگنال (`meta.noindex` بولی و
     * `meta.robots` متنی) پذیرفته می‌شوند.
     */
    private function feedVisible(array $meta): bool
    {
        if (array_key_exists('noindex', $meta) && filter_var($meta['noindex'], FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $robots = $meta['robots'] ?? null;

        return ! (is_string($robots) && stripos($robots, 'noindex') !== false);
    }

    /**
     * WF-C7 — پرسِ پایهٔ بایگانی: فقط صفحههای منتشرشدهٔ نوعِ `blog` در زبانِ
     * درخواست. فیلتر دسته/برچسب فقط اگر مقدار آمده باشد اعمال میشود.
     */
    private function blogQuery(Request $request, ?string $category = null, ?string $tag = null): Builder
    {
        $query = Page::query()
            ->where('locale', $this->locale($request))
            ->where('status', Page::STATUS_PUBLISHED)
            ->whereNotNull('published_revision_id')
            ->where(function (Builder $q) {
                $q->where('meta->page_type', 'blog')
                    ->orWhere('meta->_page_type', 'blog');
            });

        if ($category !== null && $category !== '') {
            $query->where(function (Builder $q) use ($category) {
                $q->where('meta->category', $category)
                    ->orWhereJsonContains('meta->categories', [$category]);
            });
        }

        if ($tag !== null && $tag !== '') {
            $query->where(function (Builder $q) use ($tag) {
                $q->where('meta->tag', $tag)
                    ->orWhereJsonContains('meta->tags', [$tag]);
            });
        }

        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /** WF-C7 — شکل سبکِ یک نوشته برای فهرست/فید (بدون بلوک). */
    private function blogListItem(Page $page): array
    {
        $revision = $page->publishedRevision;
        $meta = $revision?->meta ?? $page->meta;
        $meta = is_array($meta) ? $this->withMetaMediaUrl($meta) : [];

        return [
            'title' => $page->title,
            'slug' => $this->normalizeBlogSlug($page->slug),
            'excerpt' => $this->excerpt($meta, $revision?->blocks ?? []),
            'image_url' => isset($meta['og_image_url']) && is_string($meta['og_image_url']) ? $meta['og_image_url'] : null,
            'author' => $this->metaAuthor($meta),
            'category' => isset($meta['category']) && is_string($meta['category']) ? $meta['category'] : null,
            'tags' => $this->tagList($meta),
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /** خلاصه: اول متای صریح، وگرنه متنِ بلوکهای متنی. */
    private function excerpt(array $meta, array $blocks): ?string
    {
        foreach (['excerpt', 'description', 'summary'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                return $this->plainText($meta[$key], 220);
            }
        }

        $out = [];
        foreach ($blocks as $block) {
            $data = is_array($block) ? ($block['data'] ?? null) : null;
            if (! is_array($data)) {
                continue;
            }
            foreach (['title', 'subtitle', 'excerpt', 'body', 'text', 'quote'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $text = $this->plainText($data[$key]);
                    if ($text !== '') {
                        $out[] = $text;
                    }
                }
            }
        }

        $joined = trim(implode(' ', $out));

        return $joined === '' ? null : mb_substr($joined, 0, 220);
    }

    private function plainText(string $value, ?int $limit = null): string
    {
        $text = strip_tags($value);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $limit !== null ? mb_substr($text, 0, $limit) : $text;
    }

    private function metaAuthor(array $meta): ?string
    {
        foreach (['author_name', 'author'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                return trim($meta[$key]);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tagList(array $meta): array
    {
        $raw = $meta['tags'] ?? null;
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (! is_array($raw)) {
            return [];
        }

        $seen = [];
        foreach ($raw as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $seen[trim($tag)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * WF-C7 — اسلاگِ نوشته برای مسیرِ `/blog/{slug}`.
     *
     * بعضی نصبها اسلاگ را با پیشوند `blog/` ذخیره کردهاند؛ هم آن شکل و هم
     * شکل خام یک URL میگیرند تا بایگانی هر دو را درست لینک کند.
     */
    private function normalizeBlogSlug(string $slug): string
    {
        $slug = ltrim($slug, '/');

        return stripos($slug, 'blog/') === 0 ? substr($slug, 5) : $slug;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** شکل یکسان خروجی صفحه برای show و homepage (تغییرشکل نده — قرارداد ISR). */
    private function payload(Page $page): array
    {
        return $this->payloadForRevision($page, $page->publishedRevision);
    }

    /**
     * WF-H2 — همان قراردادِ payload ولی از یک revision مشخص (پیش‌نمایشِ
     * پیش‌نویس با توکن). شکلِ خروجی را تغییر نده.
     */
    private function payloadForRevision(Page $page, ?PageRevision $revision): array
    {
        $meta = $this->withMetaMediaUrl($revision?->meta ?? $page->meta);

        // WF-C7 — نویسندهٔ تکنوشته: اگر ویرایشگر تعیین نکرده، مالکِ صفحه.
        if (! isset($meta['author_name']) || ! is_string($meta['author_name']) || trim($meta['author_name']) === '') {
            $ownerName = $page->owner?->name;
            if (is_string($ownerName) && $ownerName !== '') {
                $meta['author_name'] = $ownerName;
            }
        }

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'is_single' => (bool) $page->is_single,
            'blocks' => $this->withMediaUrls($revision?->blocks ?? []),
            'meta' => $meta,
            'published_at' => $page->published_at,
            'updated_at' => $page->updated_at,
            'version' => $revision?->version,
            'sidebars' => [
                'left' => [
                    'enabled' => (bool) $page->left_enabled,
                    'preset_id' => $page->left_preset_id,
                    'blocks' => $this->withMediaUrls($page->leftPreset?->blocks ?? []),
                ],
                'right' => [
                    'enabled' => (bool) $page->right_enabled,
                    'preset_id' => $page->right_preset_id,
                    'blocks' => $this->withMediaUrls($page->rightPreset?->blocks ?? []),
                ],
            ],
        ];
    }

    /**
     * WF-C1 — حل تصویر OG صفحه در خروجی عمومی.
     *
     * ویرایشگر `meta.og_image_media_id` را ذخیره می‌کند ولی رندر سئو به
     * نشانی کامل نیاز دارد. این متد شناسه را می‌خواند (و شکل قدیمیِ عددیِ
     * `og_image` را هم می‌پذیرد) و `meta.og_image_url` را تزریق می‌کند.
     * اگر رسانه پیدا نشود هیچ کلیدی اضافه نمی‌شود (fail-soft).
     *
     * @return array<string, mixed>
     */
    private function withMetaMediaUrl(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        $id = null;
        if (isset($meta['og_image_media_id']) && is_numeric($meta['og_image_media_id'])) {
            $id = (int) $meta['og_image_media_id'];
        } elseif (isset($meta['og_image']) && is_numeric($meta['og_image'])) {
            $id = (int) $meta['og_image'];
        }

        if ($id !== null && $id > 0) {
            $row = Media::query()->find($id, ['id', 'disk', 'path']);
            if ($row !== null && is_string($row->path) && $row->path !== '') {
                $url = $this->publicUrlFor((string) ($row->disk ?: config('filesystems.default')), $row->path);
                if ($url !== null) {
                    $meta['og_image_url'] = $url;
                }
            }
        }

        return $meta;
    }

    /**
     * نشانی عمومیِ یک فایل روی یک دیسک مشخص.
     *
     * ## چرا بر اساس دیسکِ ردیف
     *
     * نصب می‌تواند دیسک پیش‌فرضش `local`، `public` یا `s3` باشد. اگر URL
     * همیشه از یک دیسکِ ثابت خوانده شود، رسانه‌ای که روی دیسکِ دیگری
     * ذخیره شده بی‌صدا بی‌URL می‌ماند و فرانت به‌جای تصویر، قالبِ «تصویر
     * در دسترس نیست» را نشان می‌دهد — یعنی یک نصبِ پیش‌فرضِ بدون S3
     * هیچ‌وقت تصویر نشان نمی‌داد، و هیچ تستی هم نبود که بگیرد.
     *
     * ## چرا `s3` متفاوت است
     *
     * S3/MinIO فایل را از مسیر عمومی سرو نمی‌کند؛ باید به `endpoint` اشاره
     * کرد. دیسک‌های محلی با `Storage::url()` مسیرِ `/storage/…` می‌دهند که
     * `public/disks.php` آن را سرو می‌کند.
     *
     * @return string|null null یعنی «نشانی عمومی نداریم» — در آن حالت
     *                     فقط کلیدِ URL تزریق نمی‌شود و فرانت قالبِ خالی
     *                     نشان می‌دهد (بی‌صدا نیست).
     */
    private function publicUrlFor(string $disk, string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if ($disk === 's3') {
            $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

            return $base === '' ? null : $base.'/'.$path;
        }

        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }

        return $url === '' ? null : $url;
    }

    /**
     * غنی‌سازی بلوک‌ها با نشانی عمومی فایل‌ها — در یک کوئری (بدون N+1).
     * قرارداد فرانت (BlockRenderer/mediaSrc): image/hero ← کلید `url`،
     * گالری ← `url_0..N`، پوستر ویدیو ← `poster_url`. فقط افزودنی است؛
     * اگر مدیایی نباشد هیچ کلیدی اضافه نمی‌شود.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function withMediaUrls(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            $d = is_array($b) ? ($b['data'] ?? null) : null;
            if (! is_array($d)) {
                continue;
            }
            foreach (['media_id', 'image_id', 'poster_media_id'] as $k) {
                if (isset($d[$k]) && is_numeric($d[$k])) {
                    $ids[] = (int) $d[$k];
                }
            }
            if (isset($d['media_ids']) && is_array($d['media_ids'])) {
                foreach ($d['media_ids'] as $mid) {
                    if (is_numeric($mid)) {
                        $ids[] = (int) $mid;
                    }
                }
            }
        }

        $map = [];
        $variantMap = [];
        // WF-L1 — نقطهٔ کانونی هر مدیا برای `<img style="object-position">`.
        $focalMap = [];
        $ids = array_values(array_unique($ids));

        if ($ids !== []) {
            /**
             * 🔴 نباید فقط `s3` را نگاه کنیم.
             *
             * نسخهٔ اول فقط `config('filesystems.disks.s3.url')` را می‌خواند،
             * پس هر رسانه‌ای روی `public` یا `local` **بی‌صدا** بی‌URL می‌ماند
             * و فرانت به‌جای تصویر، قالب «تصویر در دسترس نیست» را نشان می‌دهد.
             * یعنی یک نصبِ پیش‌فرضِ بدون S3 هیچ‌وقت تصویر نشان نمی‌داد —
             * و هیچ تستی هم نبود که بگیرد.
             *
             * پس دیسکِ خودِ ردیف را می‌خوانیم و URL همان دیسک را می‌سازیم.
             *
             * @var array<int, array{disk: string|null, path: string|null}> $rows
             */
            $rows = Media::query()
                ->whereIn('id', $ids)
                ->get(['id', 'disk', 'path', 'mime', 'variants', 'focal_x', 'focal_y'])
                ->keyBy('id');

            foreach ($ids as $id) {
                $row = $rows->get($id);

                if ($row === null || ! is_string($row->path) || $row->path === '') {
                    continue;
                }

                $disk = (string) ($row->disk ?: config('filesystems.default'));
                $url = $this->publicUrlFor($disk, $row->path);

                if ($url !== null) {
                    $map[$id] = $url;
                }

                // WF-L1 — فقط وقتی هر دو مختصات ست شده باشند معنا دارد؛
                // یکی‌بودن یعنی دادهٔ ناقص ⇒ مثل «ست‌نشده» رفتار می‌شود.
                if ($row->focal_x !== null && $row->focal_y !== null) {
                    $focalMap[$id] = ['x' => (float) $row->focal_x, 'y' => (float) $row->focal_y];
                }

                // WF-C4 — فرادادهٔ نسخه‌های واکنش‌گرا (اگر آپلود/command ساخته
                // باشد). نبودش یعنی `variantMap[$id]` خالی می‌ماند و رفتار
                // دقیقاً مثل قبل است.
                $variants = $this->variantInfoFor($disk, $row->mime, $row->variants);

                if ($variants !== []) {
                    $variantMap[$id] = $variants;
                }
            }
        }

        foreach ($blocks as &$b) {
            if (! is_array($b) || ! isset($b['data']) || ! is_array($b['data'])) {
                continue;
            }
            $d = &$b['data'];
            if (isset($d['media_id'], $map[(int) $d['media_id']])) {
                $d['url'] = $map[(int) $d['media_id']];
                $this->injectVariants($d, (int) $d['media_id'], $variantMap, 'srcset', 'sources');
                $this->injectFocal($d, (int) $d['media_id'], $focalMap, 'focal_x', 'focal_y');
            } elseif (isset($d['image_id'], $map[(int) $d['image_id']])) {
                $d['url'] = $map[(int) $d['image_id']];
                $this->injectVariants($d, (int) $d['image_id'], $variantMap, 'srcset', 'sources');
                $this->injectFocal($d, (int) $d['image_id'], $focalMap, 'focal_x', 'focal_y');
            }
            if (isset($d['poster_media_id'], $map[(int) $d['poster_media_id']])) {
                $d['poster_url'] = $map[(int) $d['poster_media_id']];
                $this->injectVariants($d, (int) $d['poster_media_id'], $variantMap, 'poster_srcset', 'poster_sources');
                $this->injectFocal($d, (int) $d['poster_media_id'], $focalMap, 'poster_focal_x', 'poster_focal_y');
            }
            if (isset($d['media_ids']) && is_array($d['media_ids'])) {
                foreach (array_values($d['media_ids']) as $idx => $mid) {
                    if (isset($map[(int) $mid])) {
                        $d["url_{$idx}"] = $map[(int) $mid];
                        $this->injectVariants($d, (int) $mid, $variantMap, "srcset_{$idx}", "sources_{$idx}");
                        $this->injectFocal($d, (int) $mid, $focalMap, "focal_x_{$idx}", "focal_y_{$idx}");
                    }
                }
            }
        }
        unset($d, $b);

        return $blocks;
    }

    /**
     * WF-C4 — از فرادادهٔ `media.variants`، `srcset` قالبِ اصلی و فهرستِ
     * `sources` (AVIF/WebP) می‌سازد. خالی یعنی «نسخه‌ای نیست» ⇒ فرانت به
     * `url` اصلی fallback می‌کند.
     *
     * @return array{srcset?: string, sources?: list<array{type: string, srcset: string}>}
     */
    private function variantInfoFor(string $disk, mixed $mime, mixed $variants): array
    {
        if (! is_array($variants) || ! is_array($variants['items'] ?? null)) {
            return [];
        }

        /** @var array<string, array<int, string>> $byMime */
        $byMime = [];

        foreach ($variants['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $width = (int) ($item['width'] ?? 0);
            $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];

            foreach ($sources as $variantMime => $variantPath) {
                if ($width <= 0 || ! is_string($variantMime) || ! is_string($variantPath) || $variantPath === '') {
                    continue;
                }

                $url = $this->publicUrlFor($disk, $variantPath);

                if ($url !== null) {
                    $byMime[$variantMime][$width] = $url;
                }
            }
        }

        if ($byMime === []) {
            return [];
        }

        $preferred = is_string($mime) && isset($byMime[$mime])
            ? $mime
            : (string) array_key_first($byMime);

        $out = [];

        $srcset = $this->srcsetString($byMime[$preferred] ?? []);
        if ($srcset !== '') {
            $out['srcset'] = $srcset;
        }

        $sources = [];
        foreach (['image/avif', 'image/webp'] as $modern) {
            if (! isset($byMime[$modern])) {
                continue;
            }

            $modernSet = $this->srcsetString($byMime[$modern]);
            if ($modernSet !== '') {
                $sources[] = ['type' => $modern, 'srcset' => $modernSet];
            }
        }

        if ($sources !== []) {
            $out['sources'] = $sources;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $variantMap
     * @param  array<string, mixed>  $data
     */
    private function injectVariants(array &$data, int $id, array $variantMap, string $srcsetKey, string $sourcesKey): void
    {
        if (! isset($variantMap[$id])) {
            return;
        }

        $info = $variantMap[$id];

        if (isset($info['srcset'])) {
            $data[$srcsetKey] = $info['srcset'];
        }
        if (isset($info['sources'])) {
            $data[$sourcesKey] = $info['sources'];
        }
    }

    /**
     * WF-L1 — مختصات نرمال‌شدهٔ 0..1 نقطهٔ کانونی را روی `data` می‌گذارد
     * (`object-position` فرانت). نبود رکورد یعنی هیچ کلیدی اضافه نمی‌شود، پس
     * رندرِ بدون نقطهٔ کانونی دقیقاً «وسط» قبلی می‌ماند.
     *
     * @param  array<string, array{x: float, y: float}>  $focalMap
     * @param  array<string, mixed>  $data
     */
    private function injectFocal(array &$data, int $id, array $focalMap, string $xKey, string $yKey): void
    {
        if (! isset($focalMap[$id])) {
            return;
        }

        $data[$xKey] = $focalMap[$id]['x'];
        $data[$yKey] = $focalMap[$id]['y'];
    }

    /** @param array<int, string> $widthToUrl */
    private function srcsetString(array $widthToUrl): string
    {
        if ($widthToUrl === []) {
            return '';
        }

        ksort($widthToUrl);

        $parts = [];
        foreach ($widthToUrl as $width => $url) {
            $parts[] = $url.' '.$width.'w';
        }

        return implode(', ', $parts);
    }
}
