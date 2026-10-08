<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Page;
use App\Models\PageEditLock;
use App\Models\PageRevision;
use App\Models\PageShareLink;
use App\Models\SidePreset;
use App\Services\Audit\ActivityLogger;
use App\Services\Pages\PagePublisher;
use App\Services\Pages\PageShareSigner;
use App\Services\RevalidateDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * تسک ۱.۱ — مدیریت صفحات (ویرایشگر بلوکی).
 * هر ویرایش/انتشار/بازگشت = revision append-only جدید؛ هیچ‌وقت بازنویسی تاریخچه.
 * انتشار = revision جدید + published + ردیابی revalidate امضاشده (HMAC+nonce).
 */
class PageController extends Controller
{
    /** WF-M2 — بازهٔ پیشنهادی طول عنوان سئو (هم‌راستا با `seo-preview.ts`). */
    private const SEO_TITLE_MIN = 30;

    private const SEO_TITLE_MAX = 60;

    /** WF-M2 — بازهٔ پیشنهادی طول توضیح متا. */
    private const SEO_DESC_MIN = 70;

    private const SEO_DESC_MAX = 160;

    /** سقفِ ستون `title` — برشِ عنوانِ کپی با همین عدد هم‌راستا می‌ماند. */
    private const TITLE_MAX = 200;

    /** سقفِ ستون `slug` — برشِ پایهٔ اسلاگِ کپی با همین عدد هم‌راستا می‌ماند. */
    private const SLUG_MAX = 200;

    /** WF-M4 — سقفِ تلاش برای یافتنِ اسلاگِ آزادِ `-copy`. */
    private const COPY_SLUG_ATTEMPTS = 500;

    /** WF-M5 — سقفِ شناسه در یک درخواستِ گروهی (هر شناسه یک انتشار/ابطال/حذف است). */
    private const BULK_MAX_IDS = 100;

    /**
     * WF-M5 — اکشن‌های گروهی: پرمیشنِ لازم، فعلِ گزارش و پیامِ موفقیت هر کدام.
     *
     * کلیدِ `action` در اعتبارسنجی هم از همین آرایه می‌آید تا افزودنِ اکشن تازه
     * یک جا را فراموش نکند. انتشار و لغو انتشار `pages.edit` می‌خواهند (تغییرِ
     * وضعیت/نسخه) ولی انتقال به سطل زباله `pages.delete` — وگرنه هر ویرایشگری
     * می‌توانست کل نصب را از لیست پاک کند.
     *
     * @var array<string, array{permission: string, verb: string, message: string}>
     */
    private const BULK_ACTIONS = [
        'publish' => ['permission' => 'pages.edit', 'verb' => 'منتشر', 'message' => 'صفحه منتشر شد.'],
        'unpublish' => ['permission' => 'pages.edit', 'verb' => 'از انتشار خارج', 'message' => 'انتشار صفحه لغو شد.'],
        'trash' => ['permission' => 'pages.delete', 'verb' => 'به سطل زباله منتقل', 'message' => 'صفحه به سطل زباله منتقل شد.'],
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        // پیشداد تک‌سایتی: داده مدیریتی مشترک است — همه مدیران همه صفحات را می‌بینند.
        // RBAC تعیین می‌کند چه کاری مجاز است، نه چه داده‌ای دیده می‌شود.
        $query = Page::query()->withCount('revisions');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%"));
        }
        if ($request->query('seo') === 'unhealthy') {
            $query->whereRaw($this->seoUnhealthySql());
        }

        $pages = $query->latest()->paginate((int) $request->query('per_page', 15));

        // WF-M2 — وضعیت سئو از همان `meta` و در حافظه محاسبه می‌شود (بدون کوئری اضافه).
        $pages->getCollection()->transform(function (Page $page): Page {
            $page->setAttribute('seo', $this->seoHealth($page));

            return $page;
        });

        return response()->json($pages);
    }

    /**
     * WF-M2 — سلامت سئوی یک صفحه. عنوان/توضیح از `meta` خوانده می‌شود و در
     * نبودشان به عنوان خودِ صفحه برمی‌گردد؛ همان معیاری که رندرر عمومی برای
     * `<title>` به کار می‌برد. طول‌ها فقط وقتی سنجیده می‌شوند که مقدار غیرخالی
     * باشد تا یک عنوان خالی هم‌زمان `title_empty` و `title_len` ندهد.
     *
     * @return array{healthy: bool, issues: list<string>, title_length: int, description_length: int}
     */
    private function seoHealth(Page $page): array
    {
        $meta = is_array($page->meta) ? $page->meta : [];

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) $page->title);
        }
        $description = trim((string) ($meta['description'] ?? ''));
        $og = trim((string) ($meta['og_image_media_id'] ?? ''));
        if ($og === '') {
            $og = trim((string) ($meta['og_image'] ?? ''));
        }

        $titleLength = mb_strlen($title);
        $descLength = mb_strlen($description);

        $issues = [];
        if ($title === '') {
            $issues[] = 'title_empty';
        } elseif ($titleLength < self::SEO_TITLE_MIN || $titleLength > self::SEO_TITLE_MAX) {
            $issues[] = 'title_len';
        }
        if ($description === '') {
            $issues[] = 'description_empty';
        } elseif ($descLength < self::SEO_DESC_MIN || $descLength > self::SEO_DESC_MAX) {
            $issues[] = 'description_len';
        }
        if ($og === '') {
            $issues[] = 'no_og';
        }

        return [
            'healthy' => $issues === [],
            'issues' => $issues,
            'title_length' => $titleLength,
            'description_length' => $descLength,
        ];
    }

    /**
     * WF-M2 — شرط SQL «فقط ناسالم» برای فیلتر لیست (بدون واکشی کل جدول).
     * عمداً آینهٔ قاعدهٔ `seoHealth` است؛ هر تغییر در یکی باید در دیگری هم بیاید.
     */
    private function seoUnhealthySql(): string
    {
        $title = "COALESCE(NULLIF(BTRIM(meta->>'title'), ''), NULLIF(BTRIM(title), ''))";
        $desc = "NULLIF(BTRIM(meta->>'description'), '')";
        $og = "COALESCE(NULLIF(BTRIM(meta->>'og_image_media_id'), ''), NULLIF(BTRIM(meta->>'og_image'), ''))";

        return '('
            ."{$title} IS NULL"
            ." OR ({$title} IS NOT NULL AND char_length({$title}) NOT BETWEEN ".self::SEO_TITLE_MIN.' AND '.self::SEO_TITLE_MAX.')'
            ." OR {$desc} IS NULL"
            ." OR char_length({$desc}) NOT BETWEEN ".self::SEO_DESC_MIN.' AND '.self::SEO_DESC_MAX
            ." OR {$og} IS NULL"
            .')';
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(null, $request->input('locale')), $this->messages());
        $this->assertPresets($validated);

        $page = Page::query()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?? Str::slug($validated['title']),
            'locale' => $validated['locale'] ?? Page::LOCALE_DEFAULT,
            'status' => Page::STATUS_DRAFT,
            'is_single' => $validated['is_single'] ?? false,
            'blocks' => $validated['blocks'] ?? [],
            'meta' => $validated['meta'] ?? null,
            'left_preset_id' => $validated['left_preset_id'] ?? null,
            'right_preset_id' => $validated['right_preset_id'] ?? null,
            'left_enabled' => $validated['left_enabled'] ?? true,
            'right_enabled' => $validated['right_enabled'] ?? true,
        ]);

        $revision = $page->snapshot($page->blocks ?? [], $page->meta, $request->user()->id, 'ایجاد صفحه');

        $this->activity->page(
            $request->user()->id,
            ActivityLog::ACTION_PAGE_CREATE,
            $page,
            "ساخت صفحه «{$page->title}»",
            [
                'title' => $page->title,
                'slug' => $page->slug,
                'status' => $page->status,
                'blocks_count' => count($page->blocks ?? []),
                'version' => $revision->version,
            ],
        );

        return response()->json([
            'message' => 'صفحه ساخته شد.',
            'data' => $page->fresh()->load('revisions'),
        ], 201);
    }

    public function show(Request $request, Page $page): JsonResponse
    {
        // مشترک: وجود رکورد (404 خودکار بایندینگ) + احراز هویت کافی است.

        return response()->json(['data' => $page->load('revisions', 'publishedRevision', 'leftPreset', 'rightPreset')]);
    }

    /** ویرایش = snapshot جدید (نسخه‌بندی خودکار) + اتصال ستون‌های کناری. */
    public function update(Request $request, Page $page): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        $oldSlug = (string) $page->slug;

        // WF-H3 — کنترل همروندیِ خوش‌بینانه: اگر کلاینت آخرین `updated_at` را
        // بفرستد و صفحه از آن زمان تغییر کرده باشد، با 409 رد می‌شود. اگر
        // نفرستد (کلاینت قدیمی)، رفتار مثل قبل است — سازگاری عقب‌رو.
        $clientUpdatedAt = $request->input('updated_at');
        if (is_string($clientUpdatedAt) && trim($clientUpdatedAt) !== '') {
            $expected = null;
            try {
                $expected = Carbon::parse($clientUpdatedAt);
            } catch (\Throwable) {
                $expected = null;
            }
            if ($expected === null || $page->updated_at === null
                || $expected->getTimestamp() !== $page->updated_at->getTimestamp()) {
                return response()->json([
                    'message' => 'این صفحه از زمانِ بارگذاریِ شما تغییر کرده است. برای جلوگیری از بازنویسی، ابتدا محتوای تازه را بارگذاری کنید.',
                    'conflict' => true,
                    'data' => $page->fresh()->load('revisions'),
                ], 409);
            }
        }

        $validated = $request->validate($this->rules($page->id, $request->input('locale', $page->locale)), $this->messages());
        $this->assertPresets($validated);

        $before = [
            'title' => $page->title,
            'slug' => $page->slug,
            'locale' => $page->locale,
            'is_single' => (bool) $page->is_single,
            'left_preset_id' => $page->left_preset_id,
            'right_preset_id' => $page->right_preset_id,
            'left_enabled' => (bool) $page->left_enabled,
            'right_enabled' => (bool) $page->right_enabled,
        ];
        $beforeBlocksData = $page->blocks ?? [];
        $beforeBlocks = count($beforeBlocksData);
        $beforeMeta = $page->meta;
        $previousRevision = $this->latestRevision($page);

        $page->forceFill([
            'title' => $validated['title'] ?? $page->title,
            'slug' => $validated['slug'] ?? $page->slug,
            'locale' => $validated['locale'] ?? $page->locale,
            'is_single' => array_key_exists('is_single', $validated) ? (bool) $validated['is_single'] : $page->is_single,
            'left_preset_id' => array_key_exists('left_preset_id', $validated) ? $validated['left_preset_id'] : $page->left_preset_id,
            'right_preset_id' => array_key_exists('right_preset_id', $validated) ? $validated['right_preset_id'] : $page->right_preset_id,
            'left_enabled' => array_key_exists('left_enabled', $validated) ? (bool) $validated['left_enabled'] : $page->left_enabled,
            'right_enabled' => array_key_exists('right_enabled', $validated) ? (bool) $validated['right_enabled'] : $page->right_enabled,
        ])->save();

        $revision = $page->snapshot(
            $validated['blocks'] ?? $page->blocks ?? [],
            array_key_exists('meta', $validated) ? $validated['meta'] : $page->meta,
            $request->user()->id,
            'ویرایش'
        );

        // WF-H3 — هر ذخیره‌یِ موفقِ صاحبِ قفل، heartbeat را هم تازه می‌کند.
        PageEditLock::query()
            ->where('page_id', $page->id)
            ->where('user_id', $request->user()->id)
            ->update(['heartbeat_at' => Carbon::now()]);

        $afterBlocks = count($page->blocks ?? []);
        $changed = [];
        foreach ($before as $field => $value) {
            $current = in_array($field, ['is_single', 'left_enabled', 'right_enabled'], true)
                ? (bool) $page->{$field}
                : $page->{$field};
            if ($value != $current) {
                $changed[] = $field;
            }
        }
        if ($beforeBlocksData != ($page->blocks ?? [])) {
            $changed[] = 'blocks';
        }
        if ($beforeMeta != $page->meta) {
            $changed[] = 'meta';
        }

        $this->activity->page(
            $request->user()->id,
            ActivityLog::ACTION_PAGE_UPDATE,
            $page,
            'ویرایش صفحه «'.$page->title.'»'.($changed !== [] ? ' — فیلدهای تغییرکرده: '.implode('، ', $changed) : ''),
            [
                'changed' => array_values(array_unique($changed)),
                'blocks_count' => $afterBlocks,
                'previous_blocks_count' => $beforeBlocks,
                'version' => $revision->version,
                'previous_revision_id' => $previousRevision?->id,
            ],
        );

        return response()->json([
            'message' => "ویرایش ذخیره شد (نسخه {$revision->version}).",
            'data' => $page->fresh()->load('revisions'),
            'redirect_suggestion' => $this->redirectSuggestion($oldSlug, $page),
        ]);
    }

    /**
     * WF-C2 — پیشنهاد ریدایرکت از اسلاگ قدیم به جدید (فقط پیشنهاد، بدون ثبت).
     *
     * @return array{from_path: string, to_path: string}|null
     */
    private function redirectSuggestion(string $oldSlug, Page $page): ?array
    {
        $newSlug = (string) $page->slug;
        if ($oldSlug === '' || $oldSlug === $newSlug) {
            return null;
        }
        $locale = (string) ($page->locale ?? Page::LOCALE_DEFAULT);
        $prefix = $locale === Page::LOCALE_DEFAULT ? '' : '/'.$locale;

        return ['from_path' => $prefix.'/'.$oldSlug, 'to_path' => $prefix.'/'.$newSlug];
    }

    /**
     * WF-M4 — تکثیر صفحه: کلونِ بلوک‌ها، متا/سئو و تنظیماتِ ستون‌های کناری در یک
     * پیش‌نویسِ تازه با اسلاگِ `{slug}-copy`.
     *
     * سه چیز عمداً کپی **نمی‌شود**: `published_revision_id`/`published_at` (نسخهٔ
     * منتشرشده مالِ صفحهٔ مبدأ است و کپی همیشه از `draft` شروع می‌شود)،
     * `scheduled_at` (موعدِ انتشار به صفحهٔ دیگری تعلق دارد) و قفلِ ویرایش.
     * اسلاگ در همان زبان یکتا نگه داشته می‌شود (`-copy`، `-copy-2`، …) و دامنهٔ
     * بررسی سطرهای سطل‌زباله را هم دارد چون یکتاییِ دیتابیس روی `slug+locale`
     * است و سطر حذف‌شده در جدول می‌ماند.
     */
    public function duplicate(Request $request, Page $page): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        $locale = (string) ($page->locale ?? Page::LOCALE_DEFAULT);
        [$slug, $index] = $this->uniqueCopySlug((string) $page->slug, $locale);

        $copy = Page::query()->create([
            'user_id' => $request->user()->id,
            'title' => $this->copyTitle((string) $page->title, $index),
            'slug' => $slug,
            'locale' => $locale,
            'status' => Page::STATUS_DRAFT,
            'is_single' => (bool) $page->is_single,
            'blocks' => $page->blocks ?? [],
            'meta' => $page->meta,
            'left_preset_id' => $page->left_preset_id,
            'right_preset_id' => $page->right_preset_id,
            'left_enabled' => (bool) $page->left_enabled,
            'right_enabled' => (bool) $page->right_enabled,
        ]);

        $revision = $copy->snapshot($copy->blocks ?? [], $copy->meta, $request->user()->id, 'تکثیر صفحه');

        $this->activity->page(
            $request->user()->id,
            ActivityLog::ACTION_PAGE_CREATE,
            $copy,
            "تکثیر صفحه «{$page->title}» به «{$copy->title}»",
            [
                'source_page_id' => $page->id,
                'source_slug' => $page->slug,
                'locale' => $locale,
                'slug' => $copy->slug,
                'status' => $copy->status,
                'blocks_count' => count($copy->blocks ?? []),
                'version' => $revision->version,
            ],
        );

        return response()->json([
            'message' => 'نسخهٔ تکثیرشده ساخته شد.',
            'data' => $copy->fresh()->load('revisions'),
        ], 201);
    }

    /**
     * WF-M4 — نخستین اسلاگِ آزادِ `-copy` در همان زبان (`-copy`، `-copy-2`، …).
     *
     * @return array{0: string, 1: int} اسلاگِ نهایی و شمارهٔ کپی (۱ = نخستین کپی)
     */
    private function uniqueCopySlug(string $slug, string $locale): array
    {
        for ($index = 1; $index <= self::COPY_SLUG_ATTEMPTS; $index++) {
            $candidate = $this->copySlugCandidate($slug, $index);
            $taken = Page::query()
                ->withTrashed()
                ->where('slug', $candidate)
                ->where('locale', $locale)
                ->exists();

            if (! $taken) {
                return [$candidate, $index];
            }
        }

        abort(response()->json(['message' => 'برای این صفحه اسلاگِ آزادی یافت نشد.'], 422));
    }

    /** `-copy` / `-copy-2` … با برشِ پایه تا از سقفِ ستونِ اسلاگ رد نشود. */
    private function copySlugCandidate(string $slug, int $index): string
    {
        $tail = $index === 1 ? '-copy' : "-copy-{$index}";
        $base = rtrim(mb_substr($slug !== '' ? $slug : 'page', 0, self::SLUG_MAX - mb_strlen($tail)), '-');

        return ($base !== '' ? $base : 'page').$tail;
    }

    /** عنوانِ کپی با پسوند فارسی؛ دو ردیفِ هم‌عنوان در لیست قابل تشخیص نباشند. */
    private function copyTitle(string $title, int $index): string
    {
        $suffix = $index === 1 ? ' (کپی)' : " (کپی {$index})";

        return mb_substr($title, 0, self::TITLE_MAX - mb_strlen($suffix)).$suffix;
    }

    public function destroy(Request $request, Page $page, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        // L-B9 — حذفِ صفحهٔ خانه باید تگ `site-homepage` را هم پاک کند، وگرنه
        // `/` تا انقضای ISR همان صفحهٔ حذف‌شده را نشان می‌دهد.
        $this->applyTrash($page, $request->user()?->id, $dispatcher, $publisher);

        return response()->json(['message' => 'صفحه به سطل زباله منتقل شد.']);
    }

    /**
     * WF-M5 — هستهٔ مشترکِ «انتقال به سطل زباله» (تکی + گروهی).
     *
     * تگ‌ها **قبل** از `delete()` حساب می‌شوند چون بعد از آن رکورد سافت‌دیلیت
     * می‌شود و slug از scope بیرون می‌افتد (L-B9: تگِ `site-homepage` صفحهٔ خانه).
     */
    private function applyTrash(Page $page, ?int $userId, RevalidateDispatcher $dispatcher, PagePublisher $publisher): void
    {
        $summary = [
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'blocks_count' => count($page->blocks ?? []),
        ];
        $tags = $publisher->tagsFor($page);
        $page->delete();
        $dispatcher->dispatch($tags);

        $this->activity->page(
            $userId,
            ActivityLog::ACTION_PAGE_DELETE,
            $page,
            "حذف صفحه «{$summary['title']}» (انتقال به سطل زباله)",
            $summary,
        );
    }

    /** لیست صفحات سافت‌دیلیت‌شده (سطل زباله) — همین الگوی MediaController. */
    public function trash(Request $request): JsonResponse
    {
        $query = Page::query()->onlyTrashed()->withCount('revisions');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%"));
        }

        $pages = $query->latest('deleted_at')->paginate((int) $request->query('per_page', 15));

        return response()->json($pages);
    }

    /** بازگردانی از سطل زباله + ابطال کش تگ‌ها (ممکن است صفحه منتشرشده باشد). */
    public function restoreTrashed(Request $request, int $id, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        $page = Page::query()->onlyTrashed()->where('id', $id)->firstOrFail();
        $tags = $publisher->tagsFor($page);

        $page->restore();
        $dispatcher->dispatch($tags);

        $this->activity->page(
            $request->user()?->id,
            ActivityLog::ACTION_PAGE_RESTORE,
            $page,
            "بازگردانی صفحه «{$page->title}» از سطل زباله",
            ['status' => $page->status],
        );

        return response()->json([
            'message' => 'صفحه بازگردانده شد.',
            'data' => $page->fresh(),
        ]);
    }

    /** حذف دائمی (غیرقابل بازگشت) + ابطال کش تگ‌ها. */
    public function forceDelete(Request $request, int $id, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        $page = Page::query()->withTrashed()->where('id', $id)->firstOrFail();
        $title = $page->title;
        $tags = $publisher->tagsFor($page);

        $page->forceDelete();
        $dispatcher->dispatch($tags);

        $this->activity->page(
            $request->user()?->id,
            ActivityLog::ACTION_PAGE_FORCE_DELETE,
            $page,
            "حذف دائم صفحه «{$title}»",
            ['title' => $title, 'slug' => $page->slug],
        );

        return response()->json(['message' => 'صفحه برای همیشه حذف شد.']);
    }

    /**
     * انتشار = revision جدید + published + ثبت لاگ revalidate امضاشده.
     *
     * منطق در `PagePublisher` است تا کامند زمان‌بند هم دقیقاً همین مسیر را بزند.
     */
    public function publish(Request $request, Page $page, PagePublisher $publisher): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        $published = $this->applyPublish($page, $request->user()?->id, $publisher);

        return response()->json([
            'message' => 'صفحه منتشر شد.',
            'data' => $published->load('publishedRevision'),
        ]);
    }

    /**
     * WF-M5 — هستهٔ مشترکِ انتشار (تکی + گروهی).
     *
     * منطق در `PagePublisher` است تا کامند زمان‌بند هم دقیقاً همین مسیر را بزند؛
     * این متد فقط نسخهٔ قبلی را برای گزارش نگه می‌دارد و فعالیت را ثبت می‌کند.
     */
    private function applyPublish(Page $page, ?int $userId, PagePublisher $publisher): Page
    {
        $previousRevision = $this->latestRevision($page);
        $blocksCount = count($page->blocks ?? []);
        $published = $publisher->publish($page, $userId, 'انتشار');

        $this->activity->page(
            $userId,
            ActivityLog::ACTION_PAGE_PUBLISH,
            $published,
            "انتشار صفحه «{$published->title}»",
            [
                'status' => $published->status,
                'blocks_count' => $blocksCount,
                'version' => $published->publishedRevision?->version,
                'previous_revision_id' => $previousRevision?->id,
            ],
        );

        return $published;
    }

    /**
     * WF-H5 — لغو انتشار (برگشت به پیش‌نویس).
     *
     * فقط `status` به `draft` برمی‌گردد؛ `published_revision_id` و `published_at`
     * عمداً حفظ می‌شوند تا صفحه حذف نشود، تاریخ انتشار برای بازگردانی بماند و
     * انتشار دوباره بدون ساخت نسخهٔ تازه امکان‌پذیر باشد. تگ‌های ISR دقیقاً
     * همان تگ‌های انتشار (`pages` + `page:{slug}` + تگ‌های صفحهٔ خانه) باطل
     * می‌شوند تا نسخهٔ عمومی از دسترس خارج شود.
     */
    public function unpublish(Request $request, Page $page, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        $unpublished = $this->applyUnpublish($page, $request->user()?->id, $dispatcher, $publisher);

        return response()->json([
            'message' => 'انتشار صفحه لغو شد.',
            'data' => $unpublished->fresh()->load('publishedRevision'),
        ]);
    }

    /** WF-M5 — هستهٔ مشترکِ لغو انتشار (تکی + گروهی): `draft` با حفظ تاریخچهٔ انتشار. */
    private function applyUnpublish(Page $page, ?int $userId, RevalidateDispatcher $dispatcher, PagePublisher $publisher): Page
    {
        $tags = $publisher->tagsFor($page);

        $page->forceFill(['status' => Page::STATUS_DRAFT])->save();

        $dispatcher->dispatch($tags);

        $this->activity->page(
            $userId,
            ActivityLog::ACTION_PAGE_UNPUBLISH,
            $page,
            "لغو انتشار صفحه «{$page->title}»",
            [
                'status' => $page->status,
                'published_revision_id' => $page->published_revision_id,
                'published_at' => $page->published_at?->toIso8601String(),
            ],
        );

        return $page;
    }

    /**
     * WF-M5 — عملیات گروهی صفحات: `publish` / `unpublish` / `trash`.
     *
     * هر شناسه از همان هستهٔ تکی (`applyPublish`/`applyUnpublish`/`applyTrash`)
     * رد می‌شود تا قواعدِ انتشار/ابطال/حذف در دو مسیر واگرا نشوند. شکستِ یک
     * شناسه کلِ دسته را نمی‌اندازد: هر ردیف نتیجهٔ خودش را می‌گیرد و UI فهرستِ
     * ناموفق‌ها را به کاربر نشان می‌دهد.
     *
     * پرمیشن در خودِ کنترلر بررسی می‌شود (نه با میان‌افزار `perm`) چون به اکشنِ
     * درخواست بستگی دارد: `pages.edit` برای انتشار/لغو انتشار و `pages.delete`
     * برای انتقال به سطل زباله.
     */
    public function bulk(Request $request, RevalidateDispatcher $dispatcher, PagePublisher $publisher): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX_IDS],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', 'string', Rule::in(array_keys(self::BULK_ACTIONS))],
        ], [
            'ids.required' => 'هیچ صفحه‌ای انتخاب نشده است.',
            'ids.array' => 'فهرست صفحه‌ها باید آرایه باشد.',
            'ids.min' => 'هیچ صفحه‌ای انتخاب نشده است.',
            'ids.max' => 'حداکثر '.self::BULK_MAX_IDS.' صفحه در یک درخواست.',
            'ids.*.integer' => 'شناسهٔ صفحه باید عدد صحیح باشد.',
            'ids.*.min' => 'شناسهٔ صفحه باید عدد صحیح مثبت باشد.',
            'action.required' => 'عملیات گروهی انتخاب نشده است.',
            'action.in' => 'عملیات گروهی نامعتبر است.',
        ]);

        $action = $validated['action'];
        $meta = self::BULK_ACTIONS[$action];

        if (! $request->user()?->can($meta['permission'])) {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $userId = $request->user()?->id;
        $ids = array_values(array_unique($validated['ids']));
        $pages = Page::query()->whereIn('id', $ids)->get()->keyBy('id');
        $results = [];
        $succeeded = 0;

        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            $page = $pages->get($id);

            if (! $page) {
                $results[] = ['id' => $id, 'ok' => false, 'message' => 'صفحه یافت نشد.'];

                continue;
            }

            try {
                match ($action) {
                    'publish' => $this->applyPublish($page, $userId, $publisher),
                    'unpublish' => $this->applyUnpublish($page, $userId, $dispatcher, $publisher),
                    'trash' => $this->applyTrash($page, $userId, $dispatcher, $publisher),
                };
                $results[] = ['id' => $id, 'ok' => true, 'message' => $meta['message']];
                $succeeded++;
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['id' => $id, 'ok' => false, 'message' => 'عملیات روی این صفحه ناموفق بود.'];
            }
        }

        return response()->json([
            'message' => "{$succeeded} صفحه {$meta['verb']} شد.",
            'results' => $results,
            'succeeded' => $succeeded,
            'failed' => count($results) - $succeeded,
        ]);
    }

    /** WF-C3 — زمان‌بندی انتشار: ذخیرهٔ موعد آینده روی صفحهٔ پیش‌نویس. */
    public function schedule(Request $request, Page $page): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ], [
            'scheduled_at.required' => 'زمان انتشار الزامی است.',
            'scheduled_at.date' => 'زمان انتشار نامعتبر است.',
            'scheduled_at.after' => 'زمان انتشار باید در آینده باشد.',
        ]);

        $page->forceFill(['scheduled_at' => $validated['scheduled_at']])->save();

        return response()->json([
            'message' => 'انتشار زمان‌بندی شد.',
            'data' => $page->fresh(),
        ]);
    }

    /** WF-C3 — لغو زمان‌بندی انتشار. */
    public function cancelSchedule(Page $page): JsonResponse
    {
        $page->forceFill(['scheduled_at' => null])->save();

        return response()->json([
            'message' => 'زمان‌بندی انتشار لغو شد.',
            'data' => $page->fresh(),
        ]);
    }

    /**
     * WF-H2 — ساخت لینکِ اشتراکِ پیش‌نویس برای آخرین revision.
     *
     * توکن HMAC کوتاه‌عمر (روی `page_id + revision_id + expiry`) با رازِ موجودِ
     * revalidate امضا می‌شود. فقط هشِ توکن ذخیره می‌گردد تا «لغو» ممکن باشد.
     * لینکِ فعالِ قبلیِ همان صفحه ابطال می‌شود تا فقط یک لینک زنده بماند.
     */
    public function share(Request $request, Page $page, PageShareSigner $signer): JsonResponse
    {
        $revision = $this->latestRevision($page);
        if (! $revision) {
            return response()->json(['message' => 'نسخه‌ای برای اشتراک‌گذاری وجود ندارد.'], 422);
        }

        $signed = $signer->sign($page->id, $revision->id);
        $expiresAt = Carbon::createFromTimestamp($signed['expires_at']);

        PageShareLink::query()->where('page_id', $page->id)->delete();
        PageShareLink::query()->create([
            'page_id' => $page->id,
            'revision_id' => $revision->id,
            'token_hash' => hash('sha256', $signed['token']),
            'expires_at' => $expiresAt,
            'created_by' => $request->user()?->id,
        ]);

        $base = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        return response()->json([
            'message' => 'لینک اشتراک پیش‌نویس ساخته شد.',
            'data' => [
                'url' => $base.'/preview/share/'.$signed['token'],
                'token' => $signed['token'],
                'expires_at' => $expiresAt->toIso8601String(),
                'revision_id' => $revision->id,
                'version' => $revision->version,
            ],
        ], 201);
    }

    /** WF-H2 — لغو همهٔ لینک‌های اشتراکِ پیش‌نویسِ یک صفحه. */
    public function revokeShare(Page $page): JsonResponse
    {
        $deleted = PageShareLink::query()->where('page_id', $page->id)->delete();

        return response()->json([
            'message' => $deleted > 0
                ? 'لینک اشتراک پیش‌نویس لغو شد.'
                : 'لینک اشتراک فعالی وجود ندارد.',
        ]);
    }

    /**
     * WF-H3 — قفلِ نرمِ ویرایشِ هم‌زمان.
     *
     * `POST` هم برای گرفتنِ قفل و هم برای heartbeat است: اگر قفلی نباشد یا
     * قفلِ خودِ کاربر باشد یا قفل کهنه شده باشد، تازه/تصاحب می‌شود. اگر مدیرِ
     * دیگری قفل را فعال نگه داشته باشد، 409 با مشخصاتِ دارنده برمی‌گردد تا
     * ویرایشگر بنر «در حال ویرایش توسط …» را نشان دهد. `force=1` تصاحبِ عمدی.
     */
    public function lock(Request $request, Page $page): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $force = $request->boolean('force');
        $now = Carbon::now();

        $existing = PageEditLock::query()->where('page_id', $page->id)->first();

        if ($existing && ! $existing->isStale($now) && (int) $existing->user_id !== $userId && ! $force) {
            return response()->json([
                'message' => 'این صفحه در حال ویرایش توسط «'.($existing->user?->name ?? 'مدیر دیگری').'» است.',
                'data' => [
                    'owned' => false,
                    'lock' => $this->lockPayload($existing),
                ],
            ], 409);
        }

        if ($existing) {
            $existing->forceFill(['user_id' => $userId, 'heartbeat_at' => $now])->save();
            $lock = $existing;
        } else {
            $lock = PageEditLock::query()->create([
                'page_id' => $page->id,
                'user_id' => $userId,
                'heartbeat_at' => $now,
            ]);
        }

        $lock->loadMissing('user');

        return response()->json([
            'message' => 'قفل ویرایش در اختیار شماست.',
            'data' => [
                'owned' => true,
                'lock' => $this->lockPayload($lock),
            ],
        ]);
    }

    /** WF-H3 — آزادسازیِ قفل؛ فقط دارنده اجازه دارد. */
    public function unlock(Request $request, Page $page): JsonResponse
    {
        $lock = PageEditLock::query()->where('page_id', $page->id)->first();

        if (! $lock) {
            return response()->json(['message' => 'قفل ویرایشی وجود ندارد.']);
        }

        if ((int) $lock->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'قفل این صفحه در اختیار مدیر دیگری است.'], 403);
        }

        $lock->delete();

        return response()->json(['message' => 'قفل ویرایش آزاد شد.']);
    }

    /** @return array{user_id: int, user_name: string, heartbeat_at: string|null} */
    private function lockPayload(PageEditLock $lock): array
    {
        return [
            'user_id' => (int) $lock->user_id,
            'user_name' => $lock->user?->name ?? 'مدیر دیگری',
            'heartbeat_at' => $lock->heartbeat_at?->toIso8601String(),
        ];
    }

    /** بازگشت = کپی بلوک‌های revision قدیمی در revision جدید (تاریخچه حفظ می‌شود). */
    public function restore(Request $request, Page $page, PageRevision $revision): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        if ($revision->page_id !== $page->id) {
            return response()->json(['message' => 'این نسخه متعلق به این صفحه نیست.'], 422);
        }

        $previousRevision = $this->latestRevision($page);

        $new = $page->snapshot(
            $revision->blocks ?? [],
            $revision->meta,
            $request->user()->id,
            "بازگشت به نسخه {$revision->version}"
        );

        $this->activity->page(
            $request->user()?->id,
            ActivityLog::ACTION_PAGE_RESTORE,
            $page,
            "بازگشت صفحه «{$page->title}» به نسخه {$revision->version}",
            [
                'from_version' => $revision->version,
                'version' => $new->version,
                'blocks_count' => count($revision->blocks ?? []),
                'previous_revision_id' => $previousRevision?->id,
            ],
        );

        return response()->json([
            'message' => "به نسخه {$revision->version} برگشت داده شد (نسخه جدید {$new->version}).",
            'data' => $page->fresh()->load('revisions'),
        ]);
    }

    public function revisions(Request $request, Page $page): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        return response()->json([
            'data' => $page->revisions()->latest('version')->paginate((int) $request->query('per_page', 20)),
        ]);
    }

    /**
     * WF-H14 — وضعیت «همزادِ ترجمه» در زبان دیگر.
     *
     * هر زبان ردیفِ صفحهٔ خودش است (F4.5)؛ همزاد با همان `slug` و localeِ مقابل
     * پیدا می‌شود. وضعیت از مقایسهٔ آخرین revisionِ مبدأ با
     * `meta.source_revision_id` همزاد محاسبه می‌شود:
     *  - `translated`   → همزاد روی همان نسخهٔ مبدأ همگام است.
     *  - `needs_update` → نسخهٔ مبدأ بعد از آخرین همگام‌سازی جلو رفته.
     *  - `none`         → همزادی ساخته نشده.
     */
    public function showTranslation(Request $request, Page $page): JsonResponse
    {
        $sibling = $this->translationSibling($page);

        return response()->json(['data' => $this->translationPayload($page, $sibling)]);
    }

    /**
     * WF-H14 — ساخت/همگام‌سازیِ همزادِ ترجمه.
     *
     * در نبود همزاد: پیش‌نویسِ خالی با همان slug در زبان مقابل ساخته می‌شود و
     * پیوندِ «مبدأ → نسخه» در `meta` ثبت می‌گردد. در حضور همزاد: فقط اشاره‌گرِ
     * `source_revision_id` به آخرین نسخهٔ مبدأ به‌روزرسانی می‌شود (بدون دست‌زدن
     * به بلوک‌های ترجمه) — یعنی «علامت‌گذاری به‌عنوان ترجمه‌شده».
     */
    public function storeTranslation(Request $request, Page $page): JsonResponse
    {
        $latest = $this->latestRevision($page);
        $sibling = $this->translationSibling($page);

        if ($sibling === null) {
            $target = $this->otherLocale((string) ($page->locale ?? Page::LOCALE_DEFAULT));
            $sibling = Page::query()->create([
                'user_id' => $request->user()->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'locale' => $target,
                'status' => Page::STATUS_DRAFT,
                'is_single' => (bool) $page->is_single,
                'blocks' => [],
                'meta' => [
                    'translation_of' => $page->id,
                    'source_revision_id' => $latest?->id,
                ],
            ]);
            // اسنپ‌شات نگه می‌دارد که این نسخهٔ ترجمه بر پایهٔ کدام نسخهٔ مبدأ بوده.
            $sibling->snapshot($sibling->blocks ?? [], $sibling->meta, $request->user()->id, 'ایجاد ترجمه');

            return response()->json([
                'message' => 'ترجمه ساخته و همگام شد.',
                'data' => $this->translationPayload($page, $sibling->fresh()),
            ], 201);
        }

        // همزاد موجود است: جهتِ مبدأ/ترجمه را از `meta.translation_of` تشخیص بده،
        // وگرنه همگام‌سازی از سمتِ ترجمه، فرادادهٔ مبدأ را بازنویسی می‌کند.
        if ((int) ($page->meta['translation_of'] ?? 0) === $sibling->id) {
            // این صفحه خودش ترجمه است ⇒ اشاره‌گرش را به آخرین نسخهٔ مبدأ جلو ببر.
            $meta = $page->meta ?? [];
            $meta['source_revision_id'] = $this->latestRevision($sibling)?->id;
            $page->forceFill(['meta' => $meta])->save();
            $page->snapshot($page->blocks ?? [], $page->meta, $request->user()->id, 'همگام‌سازی ترجمه');
        } else {
            // این صفحه مبدأ است ⇒ اشاره‌گرِ همزاد را به آخرین نسخهٔ خود جلو ببر.
            $meta = $sibling->meta ?? [];
            $meta['translation_of'] = $meta['translation_of'] ?? $page->id;
            $meta['source_revision_id'] = $latest?->id;
            $sibling->forceFill(['meta' => $meta])->save();
            $sibling->snapshot($sibling->blocks ?? [], $sibling->meta, $request->user()->id, 'همگام‌سازی ترجمه');
        }

        return response()->json([
            'message' => 'ترجمه ساخته و همگام شد.',
            'data' => $this->translationPayload($page, $sibling->fresh()),
        ], 200);
    }

    /**
     * آخرین نسخهٔ صفحه. ⚠️ `revisions()` پیش‌فرض `orderBy('version')` صعودی
     * دارد، پس `latest('version')` روی آن ترتیب صعودی را عوض نمی‌کرد و
     * نسخهٔ **قدیمی** را برمی‌گرداند. اینجا با `reorder` صریح، واقعاً آخرین
     * نسخه برگردانده می‌شود.
     */
    private function latestRevision(Page $page): ?PageRevision
    {
        return $page->revisions()->reorder('version', 'desc')->first();
    }

    /** زبانِ مقابلِ نصب (فقط fa/en طبق F4.5). */
    private function otherLocale(string $locale): string
    {
        return $locale === 'en' ? 'fa' : 'en';
    }

    /** همزادِ ترجمه: همان slug، localeِ مقابل. */
    private function translationSibling(Page $page): ?Page
    {
        $target = $this->otherLocale((string) ($page->locale ?? Page::LOCALE_DEFAULT));

        return Page::query()
            ->where('slug', $page->slug)
            ->where('locale', $target)
            ->first();
    }

    /**
     * وضعیت ترجمه + دادهٔ همزاد. مبدأ همان صفحهٔ جاری است و آخرین نسخه‌اش
     * معیارِ «به‌روز بودن» همزاد قرار می‌گیرد.
     *
     * @return array<string, mixed>
     */
    private function translationPayload(Page $page, ?Page $sibling): array
    {
        // جهت را از پیوندِ `translation_of` می‌فهمیم: ممکن است صفحهٔ جاری مبدأ
        // باشد یا خودش ترجمه. مقایسه همیشه «آخرین نسخهٔ مبدأ» ↔ «نسخهٔ ثبت‌شدهٔ
        // ترجمه» است تا از هر دو سمت درست کار کند.
        $source = $page;
        $translation = $sibling;
        if ($sibling !== null && (int) ($page->meta['translation_of'] ?? 0) === $sibling->id) {
            $source = $sibling;
            $translation = $page;
        }

        $latest = $this->latestRevision($source);
        $syncedId = $translation?->meta['source_revision_id'] ?? null;

        if ($sibling === null) {
            $status = 'none';
        } elseif ($latest === null) {
            $status = 'translated';
        } else {
            $status = ((int) $syncedId === (int) $latest->id) ? 'translated' : 'needs_update';
        }

        return [
            'source' => [
                'id' => $source->id,
                'locale' => $source->locale,
                'slug' => $source->slug,
                'latest_revision_id' => $latest?->id,
            ],
            'target_locale' => $this->otherLocale((string) ($page->locale ?? Page::LOCALE_DEFAULT)),
            'sibling' => $sibling === null ? null : [
                'id' => $sibling->id,
                'title' => $sibling->title,
                'slug' => $sibling->slug,
                'locale' => $sibling->locale,
                'status' => $sibling->status,
                'is_single' => (bool) $sibling->is_single,
                'source_revision_id' => $syncedId,
                'updated_at' => $sibling->updated_at?->toIso8601String(),
            ],
            'status' => $status,
        ];
    }

    /** اعتبارسنجی بلوک‌ها در برابر رجیستری config/blocks.php + ستون‌های کناری. */
    private function rules(?int $ignoreId = null, ?string $locale = null): array
    {
        $registry = collect(config('blocks', []))->where('active', true);
        $allowedTypes = $registry->keys()->all();

        return [
            // F0.1: `no_markup` روی عنوان و متا. عنوان صفحه مستقیماً در
            // JSON-LD صفحهٔ عمومی می‌نشیند (`buildJsonLd`)؛ بدون این قاعده
            // تنها لایهٔ دفاع escape سمت فرانت است و هر رندرر تازه سوراخ می‌شود.
            'title' => ($ignoreId ? 'sometimes' : 'required').'|string|max:'.self::TITLE_MAX.'|no_markup',
            // F4.5 — یکتاییِ slug حالا per-locale است، نه سراسری.
            'slug' => [
                'sometimes', 'string', 'max:'.self::SLUG_MAX,
                // F5.3-f — سخت‌گیریِ صریح روی کاراکترها. `string|max` تنها
                // طول را می‌سنجید، پس slugِ `//evil.example/x` یا
                // `../../admin` پذیرفته می‌شد و مستقیم به مسیرِ کلیکِ اعلانِ
                // push می‌رسید (سرویس‌ورکر آن را protocol-relative می‌کرد).
                // حروف/ارقام یونیکد + `. _ ~ -` تنها چیزی است که در مسیر
                // بی‌خطر است.
                'regex:/^[\p{L}\p{N}._~-]+$/u',
                Rule::unique('pages', 'slug')
                    ->where(fn ($q) => $q->where('locale', $locale ?? Page::LOCALE_DEFAULT))
                    ->ignore($ignoreId),
            ],
            'locale' => 'sometimes|string|max:8|in:fa,en',
            'is_single' => 'sometimes|boolean',
            'blocks' => 'sometimes|array',
            'blocks.*.type' => 'required_with:blocks|string|in:'.implode(',', $allowedTypes),
            'blocks.*.data' => 'required_with:blocks|array',
            'meta' => 'sometimes|nullable|array',
            // متا هم به JSON-LD و `<title>` می‌رود ⇒ همان قاعده.
            'meta.title' => 'sometimes|nullable|string|max:200|no_markup',
            'meta.description' => 'sometimes|nullable|string|max:400|no_markup',
            'meta.og_title' => 'sometimes|nullable|string|max:200|no_markup',
            'meta.og_description' => 'sometimes|nullable|string|max:400|no_markup',
            // WF-C1 — تصویر OG صفحه (شناسه رسانه) + کنونیکال دستی + noindex.
            'meta.og_image_media_id' => 'sometimes|nullable|integer|min:1',
            'meta.canonical' => 'sometimes|nullable|string|max:500|no_markup',
            'meta.noindex' => 'sometimes|boolean',
            'meta.robots' => 'sometimes|nullable|string|max:100|no_markup',
            // بقیهٔ کلیدهای متا فقط رشته‌اند (robots/noindex/...) و در همان سطح
            // `array` بالا گرفته می‌شوند؛ محدود کردن تک‌تکشان بی‌فایده است.
            'left_preset_id' => 'sometimes|nullable|integer|min:1',
            'right_preset_id' => 'sometimes|nullable|integer|min:1',
            'left_enabled' => 'sometimes|boolean',
            'right_enabled' => 'sometimes|boolean',
        ];
    }

    private function messages(): array
    {
        return [
            'title.required' => 'عنوان صفحه الزامی است.',
            'slug.unique' => 'این اسلاگ قبلاً استفاده شده است.',
            'is_single.boolean' => 'مقدار تک‌صفحه‌ای باید درست یا نادرست باشد.',
            'blocks.*.type.in' => 'نوع بلوک پشتیبانی نمی‌شود.',
            'left_preset_id.exists' => 'پریست ستون چپ یافت نشد.',
            'right_preset_id.exists' => 'پریست ستون راست یافت نشد.',
        ];
    }

    /**
     * پریست باید وجود داشته باشد و سمت آن با جایگاه بخواند
     * (left_preset_id فقط پریست side=left و right_preset_id فقط side=right).
     * مشترک: چک مالکیت حذف شد.
     */
    private function assertPresets(array $validated): void
    {
        $slots = ['left_preset_id' => SidePreset::SIDE_LEFT, 'right_preset_id' => SidePreset::SIDE_RIGHT];

        foreach ($slots as $field => $side) {
            if (empty($validated[$field])) {
                continue;
            }
            $preset = SidePreset::query()->find($validated[$field]);
            if (! $preset) {
                abort(response()->json(['message' => 'پریست یافت نشد.'], 404));
            }
            if ($preset->side !== $side) {
                abort(response()->json([
                    'message' => $side === SidePreset::SIDE_LEFT
                        ? 'پریست ستون چپ باید سمت «چپ» داشته باشد.'
                        : 'پریست ستون راست باید سمت «راست» داشته باشد.',
                ], 422));
            }
        }
    }
}
