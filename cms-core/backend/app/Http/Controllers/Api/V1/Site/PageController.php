<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** شکل یکسان خروجی صفحه برای show و homepage (تغییرشکل نده — قرارداد ISR). */
    private function payload(Page $page): array
    {
        $revision = $page->publishedRevision;

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'is_single' => (bool) $page->is_single,
            'blocks' => $this->withMediaUrls($revision?->blocks ?? []),
            'meta' => $revision?->meta ?? $page->meta,
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
                ->get(['id', 'disk', 'path'])
                ->keyBy('id');

            foreach ($ids as $id) {
                $row = $rows->get($id);

                if ($row === null || ! is_string($row->path) || $row->path === '') {
                    continue;
                }

                $url = $this->publicUrlFor((string) ($row->disk ?: config('filesystems.default')), $row->path);

                if ($url !== null) {
                    $map[$id] = $url;
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
            } elseif (isset($d['image_id'], $map[(int) $d['image_id']])) {
                $d['url'] = $map[(int) $d['image_id']];
            }
            if (isset($d['poster_media_id'], $map[(int) $d['poster_media_id']])) {
                $d['poster_url'] = $map[(int) $d['poster_media_id']];
            }
            if (isset($d['media_ids']) && is_array($d['media_ids'])) {
                foreach (array_values($d['media_ids']) as $idx => $mid) {
                    if (isset($map[(int) $mid])) {
                        $d["url_{$idx}"] = $map[(int) $mid];
                    }
                }
            }
        }
        unset($d, $b);

        return $blocks;
    }
}
