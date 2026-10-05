<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\RevalidateLog;
use App\Models\Setting;
use App\Models\SidePreset;
use App\Services\RevalidateDispatcher;
use App\Services\RevalidateSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * تسک ۱.۱ — مدیریت صفحات (ویرایشگر بلوکی).
 * هر ویرایش/انتشار/بازگشت = revision append-only جدید؛ هیچ‌وقت بازنویسی تاریخچه.
 * انتشار = revision جدید + published + ردیابی revalidate امضاشده (HMAC+nonce).
 */
class PageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // CMS تک‌سایتی: داده مدیریتی مشترک است — همه مدیران همه صفحات را می‌بینند.
        // RBAC تعیین می‌کند چه کاری مجاز است، نه چه داده‌ای دیده می‌شود.
        $query = Page::query()->withCount('revisions');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%"));
        }

        $pages = $query->latest()->paginate((int) $request->query('per_page', 15));

        return response()->json($pages);
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

        $page->snapshot($page->blocks ?? [], $page->meta, $request->user()->id, 'ایجاد صفحه');

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

        $validated = $request->validate($this->rules($page->id, $request->input('locale', $page->locale)), $this->messages());
        $this->assertPresets($validated);

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

        return response()->json([
            'message' => "ویرایش ذخیره شد (نسخه {$revision->version}).",
            'data' => $page->fresh()->load('revisions'),
        ]);
    }

    public function destroy(Page $page, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $slug = $page->slug;
        // L-B9 — حذفِ صفحهٔ خانه باید تگ `site-homepage` را هم پاک کند، وگرنه
        // `/` تا انقضای ISR همان صفحهٔ حذف‌شده را نشان می‌دهد. قبل از delete
        // حساب می‌شود چون بعد از آن رکورد دیگر در دسترس نیست.
        $tags = array_merge(['pages', "page:{$slug}"], $this->homepageTags($page));
        $page->delete();
        $dispatcher->dispatch(array_values(array_unique($tags)));

        return response()->json(['message' => 'صفحه حذف شد.']);
    }

    /** انتشار = revision جدید + published + ثبت لاگ revalidate امضاشده. */
    public function publish(
        Request $request,
        Page $page,
        RevalidateSigner $signer,
        RevalidateDispatcher $dispatcher,
        \App\Services\Push\ContentPublishedNotifier $pushNotifier,
    ): JsonResponse {
        // مشترک: بدون چک مالکیت.

        $revision = $page->snapshot(
            $page->blocks ?? [],
            $page->meta,
            $request->user()->id,
            'انتشار'
        );

        $page->forceFill([
            'status' => Page::STATUS_PUBLISHED,
            'published_revision_id' => $revision->id,
            'published_at' => now(),
        ])->save();

        $signed = $signer->sign(["page:{$page->slug}"]);
        RevalidateLog::query()->create([
            'page_id' => $page->id,
            'tags' => $signed['tags'],
            'nonce' => $signed['nonce'],
            'signature' => $signed['signature'],
        ]);

        // ابطال فوری کش ISR فرانت (fire-and-forget — خرابی فرانت انتشار را نمی‌شکند).
        // L-B9 — انتشار _صفحهٔ خانه_ باید `site-homepage` را هم باطل کند؛
        // بدون آن، `/` تا ۳۰۰ ثانیه محتوای قدیمی را نگه می‌دارد و «انتشار» فقط
        // با گذر زمان دیده می‌شود.
        $tags = array_merge(['pages', "page:{$page->slug}"], $this->homepageTags($page));
        $dispatcher->dispatch(array_values(array_unique($tags)));

        // اعلام به زائرانی که opt-in کرده‌اند (P1.10).
        //
        // ⚠️ بعد از ابطال کش و **بیرون** از مسیر اصلی: خودش exception را
        // می‌گیرد و لاگ می‌کند، ولی اگر روزی نشت کرد نباید کاربر پیام
        // «انتشار ناموفق» ببیند وقتی صفحه واقعاً منتشر شده است.
        $pushNotifier->announce($page->fresh());

        return response()->json([
            'message' => 'صفحه منتشر شد.',
            'data' => $page->fresh()->load('publishedRevision'),
        ]);
    }

    /** بازگشت = کپی بلوک‌های revision قدیمی در revision جدید (تاریخچه حفظ می‌شود). */
    public function restore(Request $request, Page $page, PageRevision $revision): JsonResponse
    {
        // مشترک: بدون چک مالکیت.

        if ($revision->page_id !== $page->id) {
            return response()->json(['message' => 'این نسخه متعلق به این صفحه نیست.'], 422);
        }

        $new = $page->snapshot(
            $revision->blocks ?? [],
            $revision->meta,
            $request->user()->id,
            "بازگشت به نسخه {$revision->version}"
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
     * L-B9 — تگ‌های اختصاصیِ صفحهٔ خانه، اگر این صفحه خانهٔ سایت باشد.
     *
     * «صفحهٔ خانه» دو راه تعریف دارد (همان ترتیب `Site/PageController::homepage()`):
     * تنظیم `homepage_page_id` یا اسلاگِ `home`. اگر هیچ‌کدام نباشد خروجی خالی
     * است و تگ اضافه‌ای dispatch نمی‌شود.
     *
     * @return list<string>
     */
    private function homepageTags(Page $page): array
    {
        return $this->isHomepage($page) ? ['site-homepage', 'page:home'] : [];
    }

    private function isHomepage(Page $page): bool
    {
        if ($page->slug === 'home') {
            return true;
        }

        $site = Setting::get('site', 'global', []);

        return is_array($site)
            && (int) ($site['homepage_page_id'] ?? 0) === (int) $page->id;
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
            'title' => ($ignoreId ? 'sometimes' : 'required').'|string|max:200|no_markup',
            // F4.5 — یکتاییِ slug حالا per-locale است، نه سراسری.
            'slug' => [
                'sometimes', 'string', 'max:200',
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
