<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\ImageOptimizer;
use App\Services\RevalidateDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * کتابخانه مدیا (بخش فایل تسک ۱.۱): لیست + presign آپلود مستقیم به MinIO/S3
 * + ویرایش alt + سطل زباله (سافت‌دیلیت) + بازگردانی + حذف دائم.
 *
 * E7 — سقف فضای مدیا **افزوده** است: `quotaState()`/`guardQuota()` کنار راه‌های
 * موجود اضافه شدند و هیچ مسیری را بازنویسی نکردند. `usage()` از همان
 * `quotaState()` می‌خواند، پس عددی که UI نشان می‌دهد دقیقاً همان عددی است که
 * آپلود را رد می‌کند — قبلاً دو منبع حقیقت جدا بودند و این یکی‌شان کرد.
 */
class MediaController extends Controller
{
    /**
     * سقف هر تیر به بایت. اعداد عمداً ثابت‌اند: این‌ها قراردادِ فروش‌اند، نه
     * کانفیگ نصب — مدیر نمی‌تواند با یک تغییر در `.env` پلنش را دور بزند.
     */
    private const TIER_QUOTAS = [
        'budget' => 1024 * 1024 * 1024,             // ۱ گیگابایت
        'standard' => 5 * 1024 * 1024 * 1024,        // ۵ گیگابایت
        'enterprise' => 20 * 1024 * 1024 * 1024,     // ۲۰ گیگابایت
    ];

    private const TIER_LABELS = [
        'budget' => 'پایه',
        'standard' => 'استاندارد',
        'enterprise' => 'سازمانی',
    ];

    /** تیر پیش‌فرض نصبِ مستقل (بدون افزونهٔ مرکزی ⇒ مشتری تازه ⇒ `budget`). */
    private const DEFAULT_TIER = 'budget';

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        // مشترک: همه مدیران همه فایل‌ها را می‌بینند.
        $query = Media::query()->with([
            'folder:id,name,parent_id',
            'tags:id,name,color',
        ]);

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }
        if ($mime = $request->query('mime')) {
            $query->where('mime', 'ilike', "{$mime}%");
        }
        // WF-H6 — فیلتر نوع (alias کوتاه `mime`؛ مثلاً `image`).
        if ($type = $request->query('type')) {
            $query->where('mime', 'ilike', "{$type}%");
        }
        // WF-H6 — فیلتر پوشه: `none`/خالی ⇒ بدون پوشه، وگرنه id پوشه.
        if ($request->has('folder')) {
            $folder = $request->query('folder');
            if ($folder === 'none' || $folder === '' || $folder === '0') {
                $query->whereNull('folder_id');
            } elseif (ctype_digit((string) $folder)) {
                $query->where('folder_id', (int) $folder);
            }
        }
        // WF-H6 — فیلتر برچسب (هر برچسبی که فایل دارد).
        if ($tag = $request->query('tag')) {
            if (ctype_digit((string) $tag)) {
                $query->whereHas('tags', fn ($q) => $q->where('media_tags.id', (int) $tag));
            }
        }
        if ($search = $request->query('search')) {
            $query->where('original_name', 'ilike', "%{$search}%");
        }

        return response()->json(
            $query->latest()->paginate((int) $request->query('per_page', 24))
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $media = $this->findMedia($id)->load(['folder:id,name,parent_id', 'tags:id,name,color']);

        return response()->json(['data' => $media]);
    }

    /**
     * صدور URL امضاشده آپلود مستقیم (PUT) به MinIO/S3.
     * رکورد media از همان ابتدا ساخته می‌شود تا MediaPicker بلافاصله id داشته باشد.
     */
    public function presign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'mime' => 'required|string|max:100',
            'size' => 'required|integer|min:1|max:52428800', // سقف ۵۰MB
            'folder_id' => 'sometimes|nullable|integer|exists:media_folders,id',
        ], [
            'filename.required' => 'نام فایل الزامی است.',
            'mime.required' => 'نوع فایل الزامی است.',
            'size.max' => 'حجم فایل بیش از حد مجاز است (۵۰ مگابایت).',
        ]);

        $path = 'media/shared/'.Str::uuid().'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $validated['filename']);

        if ($response = $this->guardQuota($request, (int) $validated['size'])) {
            return $response;
        }

        $media = Media::query()->create([
            'user_id' => $request->user()->id,
            'folder_id' => $validated['folder_id'] ?? null,
            'disk' => 's3',
            'path' => $path,
            'original_name' => $validated['filename'],
            'mime' => $validated['mime'],
            'size' => $validated['size'],
        ]);

        try {
            $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
        } catch (\Throwable) {
            $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');
            $url = $base."/{$path}?stub-presigned=1&expires=".now()->addMinutes(15)->timestamp;
        }

        return response()->json([
            'message' => 'آدرس آپلود آماده شد.',
            'data' => [
                'id' => $media->id,
                'upload_url' => $url,
                'method' => 'PUT',
                'path' => $path,
                'expires_in' => 900,
            ],
        ], 201);
    }

    /**
     * دریافت بایت‌های فایل از مسیر پروکسی بک‌اند و ذخیره در path همان رکورد.
     * (جایگزین مطمئن PUT مستقیم مرورگر به S3 — مستقل از hostname/شبکه کلاینت.
     * presign همچنان رکورد-first می‌سازد تا MediaPicker بلافاصله id داشته باشد.)
     */
    public function content(Request $request, int $id): JsonResponse
    {
        $media = $this->findMedia($id);

        $validated = $request->validate([
            'file' => 'required|file|max:51200', // سقف ۵۰MB (کیلوبایت)
        ], [
            'file.required' => 'فایل الزامی است.',
            'file.max' => 'حجم فایل بیش از حد مجاز است (۵۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        // رکورد از `presign` از قبل به اندازهٔ اعلامی‌اش در `used` نشسته، پس
        // فقط *اختلاف* را حساب می‌کنیم؛ وگرنه یک آپلود عادی خودش را دوبار
        // می‌شمارد و مدیر را بی‌دلیل به سقف می‌رساند.
        if ($response = $this->guardQuota($request, (int) $file->getSize(), (int) $media->size)) {
            return $response;
        }

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk('s3')->writeStream($media->path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $media->fill([
            'mime' => $file->getMimeType() ?: $media->mime,
            'size' => $file->getSize(),
        ])->save();

        // WF-C4 — نسخه‌های واکنش‌گرا (WebP/AVIF + چند عرض) روی همان دیسک.
        // fail-soft: نبودِ GD/Imagick یا هر خطای پردازش، آپلود را نمی‌شکند.
        app(ImageOptimizer::class)->optimize($media);

        $fresh = $media->fresh();

        $this->activity->media(
            $request->user()?->id,
            ActivityLog::ACTION_MEDIA_UPLOAD,
            $fresh,
            "آپلود فایل «{$fresh->original_name}»",
            [
                'mime' => $fresh->mime,
                'size' => (int) $fresh->size,
                'folder_id' => $fresh->folder_id,
            ],
        );

        return response()->json([
            'message' => 'فایل ذخیره شد.',
            'data' => $fresh,
        ]);
    }

    /**
     * WF-M6 — جایگزینی بایت‌های یک فایلِ موجود با **حفظ مسیر و URL**.
     *
     * رکورد و مسیر دست‌نخورده می‌مانند؛ فقط بایت‌ها روی همان `disk`+`path`
     * بازنویسی می‌شوند. پس هر مصرف‌کننده‌ای که URL قدیمی را کش کرده، همچنان
     * همان URL را می‌گیرد و فقط محتوایش تازه است. به همین دلیل نسخه‌های قبلی
     * باطل/بازتولید می‌شوند و کشِ صفحاتی که این مدیا را رندر می‌کنند purge
     * می‌شود.
     *
     * سهمیه: اندازهٔ جدید جای اندازهٔ قدیم را می‌گیرد (`creditBack`) — دوباره
     * شمرده نمی‌شود.
     */
    public function replace(Request $request, int $id, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $media = $this->findMedia($id);

        $validated = $request->validate([
            'file' => 'required|file|max:51200', // سقف ۵۰MB (کیلوبایت)
        ], [
            'file.required' => 'فایل الزامی است.',
            'file.max' => 'حجم فایل بیش از حد مجاز است (۵۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        if ($response = $this->guardQuota($request, (int) $file->getSize(), (int) $media->size)) {
            return $response;
        }

        $diskName = (string) ($media->disk ?: config('filesystems.default', 'local'));
        $oldVariants = $media->variants;

        $stream = fopen($file->getRealPath(), 'r');
        Storage::disk($diskName)->writeStream((string) $media->path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $media->fill([
            'mime' => $file->getMimeType() ?: $media->mime,
            'size' => $file->getSize(),
            'original_name' => $file->getClientOriginalName() ?: $media->original_name,
            // فرادادهٔ نسخه‌های قبلی باطل است؛ اگر بهینه‌سازی موفق شود بازنویسی می‌شود.
            'variants' => null,
        ])->save();

        // WF-C4 — بازتولید نسخه‌ها روی همان دیسک، fail-soft.
        $newVariants = app(ImageOptimizer::class)->optimize($media);

        // پاک‌کردن نسخه‌های قدیمی‌ای که بازتولید نشده‌اند (مثلاً عرض‌های بزرگ‌تر
        // وقتی تصویر جدید کوچک‌تر است) — پاک‌نشدنشان جایگزینی را نمی‌شکند.
        $stale = array_values(array_diff($this->variantPaths($oldVariants), $this->variantPaths($newVariants)));
        if ($stale !== []) {
            try {
                Storage::disk($diskName)->delete($stale);
            } catch (\Throwable) {
                // فایل یتیم مهم نیست؛ ولی جایگزینیِ انجام‌شده باید موفق بماند.
            }
        }

        $fresh = $media->fresh();

        // کش صفحات/کرومِ سایت که این مدیا را رندر می‌کنند (HMAC، fail-soft).
        $dispatcher->dispatch(['pages', 'site-chrome']);

        $this->activity->media(
            $request->user()?->id,
            ActivityLog::ACTION_MEDIA_REPLACE,
            $fresh,
            "جایگزینی فایل «{$fresh->original_name}»",
            ['mime' => $fresh->mime, 'size' => (int) $fresh->size],
        );

        return response()->json([
            'message' => 'فایل جایگزین شد.',
            'data' => $fresh,
        ]);
    }

    /** @return list<string> همهٔ مسیرهای نسخه‌های یک فرادادهٔ `variants`. */
    private function variantPaths(?array $meta): array
    {
        $paths = [];

        foreach (($meta['items'] ?? []) as $item) {
            foreach (($item['sources'] ?? []) as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $media = $this->findMedia($id);

        $validated = $request->validate([
            'alt' => 'sometimes|nullable|string|max:200',
            'original_name' => 'sometimes|string|max:255',
            'folder_id' => 'sometimes|nullable|integer|exists:media_folders,id',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:media_tags,id',
            // WF-L1 — نقطهٔ کانونی نرمال‌شده (0..1). nullable = پاک‌کردن/وسط.
            'focal_x' => 'sometimes|nullable|numeric|between:0,1',
            'focal_y' => 'sometimes|nullable|numeric|between:0,1',
        ], [
            'alt.max' => 'متن جایگزین بیش از حد طولانی است.',
            'focal_x.numeric' => 'مختصات افقی نقطهٔ کانونی باید عدد باشد.',
            'focal_y.numeric' => 'مختصات عمودی نقطهٔ کانونی باید عدد باشد.',
            'focal_x.between' => 'مختصات افقی نقطهٔ کانونی باید بین ۰ و ۱ باشد.',
            'focal_y.between' => 'مختصات عمودی نقطهٔ کانونی باید بین ۰ و ۱ باشد.',
        ]);

        $tagIds = $validated['tag_ids'] ?? null;
        unset($validated['tag_ids']);

        $media->fill($validated)->save();

        if ($tagIds !== null) {
            $media->tags()->sync($tagIds);
        }

        return response()->json([
            'message' => 'فایل به‌روزرسانی شد.',
            'data' => $media->fresh()->load(['folder:id,name,parent_id', 'tags:id,name,color']),
        ]);
    }

    /** انتقال به سطل زباله (سافت‌دیلیت). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $media = $this->findMedia($id);

        $this->activity->media(
            $request->user()?->id,
            ActivityLog::ACTION_MEDIA_DELETE,
            $media,
            "حذف فایل «{$media->original_name}»",
            ['mime' => $media->mime],
        );

        $media->delete();

        return response()->json(['message' => 'فایل به سطل زباله منتقل شد.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $media = Media::query()
            ->onlyTrashed()
            ->where('id', $id)
            ->firstOrFail();

        $media->restore();

        return response()->json([
            'message' => 'فایل بازگردانده شد.',
            'data' => $media->fresh(),
        ]);
    }

    /** حذف دائم از رکورد (پاک‌سازی آبجکت S3 در Job جداگانه — TODO مرحله ۵). */
    public function permanent(Request $request, int $id): JsonResponse
    {
        $media = Media::query()
            ->withTrashed()
            ->where('id', $id)
            ->firstOrFail();

        $media->forceDelete();

        return response()->json(['message' => 'فایل برای همیشه حذف شد.']);
    }

    /**
     * مصرف فضای پلن (دسته ۱ برابری UI/UX — کارت progress «۲٫۴ از ۱۰ گیگ»).
     * مشترک: used = جمع size همه فایل‌ها (بدون سطل زباله)؛ quota از تیر مشتری.
     */
    public function usage(Request $request): JsonResponse
    {
        $state = $this->quotaState($request);

        return response()->json([
            'data' => $state + [
                'percent' => $state['quota_bytes'] > 0
                    ? round($state['used_bytes'] / $state['quota_bytes'] * 100, 1)
                    : 0,
            ],
        ]);
    }

    /**
     * تنها منبع حقیقتِ سقف: مجموع مصرف و سقفِ همین نصب.
     *
     * این نصب تیرِ کاربر ندارد، پس سقف ثابت و برابرِ `DEFAULT_TIER` است. کلیدِ
     * `tier` در پاسخ نگه داشته شد چون UI آن را می‌خواند.
     *
     * @return array{tier: string, used_bytes: int, quota_bytes: int}
     */
    private function quotaState(Request $request): array
    {
        return [
            'tier' => self::DEFAULT_TIER,
            'used_bytes' => (int) Media::query()->sum('size'),
            'quota_bytes' => self::TIER_QUOTAS[self::DEFAULT_TIER],
        ];
    }

    /**
     * ردِ آپلودی که از سقف می‌گذرد — یا `null` یعنی جا شد.
     *
     * فقط روی دو راهِ **افزودنِ فضا** اعمال می‌شود: `presign` (رزرو) و `content`
     * (آپلود واقعی). بازگردانی از سطل زباله (`restore`) عمداً گارد ندارد: فضای
     * آزادشده را برنمی‌گرداند و بازگرداندنِ فایلِ خودِ همان کاربر است. TODO: اگر
     * روزی `restore` هم باید سقف را بشکند، همین `guardQuota` کافی است.
     *
     * @param  int  $creditBack  اندازه‌ای که از قبل در `used` نشسته (رکوردِ
     *                          `presign`) و باید از محاسبه کم شود.
     */
    private function guardQuota(Request $request, int $incoming, int $creditBack = 0): ?JsonResponse
    {
        if ($incoming <= 0) {
            return null;
        }

        $state = $this->quotaState($request);
        $projected = $state['used_bytes'] - max(0, $creditBack) + $incoming;

        if ($projected <= $state['quota_bytes']) {
            return null;
        }

        $label = self::TIER_LABELS[$state['tier']] ?? $state['tier'];

        return response()->json([
            'message' => 'فضای ذخیره‌سازی پلن «'.$label.'» پر است. سقف این پلن '
                .$this->humanBytes($state['quota_bytes']).' است و '
                .$this->humanBytes($state['used_bytes']).' از آن پر شده. '
                .'برای ادامه یک فایل را حذف کنید یا پلن را ارتقا دهید.',
            'data' => $state + [
                'quota_exceeded' => true,
                'remaining_bytes' => max(0, $state['quota_bytes'] - $state['used_bytes']),
                'requested_bytes' => $incoming,
            ],
        ], 422);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }


    /** بازگردانی همه فایل‌های سطل زباله (دسته ۱ — دکمه «بازیابی همه»). */
    public function restoreAll(Request $request): JsonResponse
    {
        $count = Media::query()
            ->onlyTrashed()
            ->count();

        Media::query()
            ->onlyTrashed()
            ->restore();

        return response()->json([
            'message' => $count > 0 ? "{$count} فایل بازگردانده شد." : 'فایلی در سطل زباله نیست.',
            'data' => ['restored' => $count],
        ]);
    }

    // ───────────────────────── WF-H6: پوشه و برچسب رسانه ─────────────────────────

    /** لیست تختِ پوشه‌ها (parent_id ⇒ فرانت درخت می‌سازد) + شمارش فایل. */
    public function folders(Request $request): JsonResponse
    {
        $folders = MediaFolder::query()
            ->withCount('media')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'created_at']);

        return response()->json(['data' => $folders]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:media_folders,id',
        ], [
            'name.required' => 'نام پوشه الزامی است.',
            'name.max' => 'نام پوشه بیش از حد طولانی است.',
        ]);

        $folder = MediaFolder::query()->create([
            'name' => $validated['name'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'پوشه ساخته شد.',
            'data' => $folder->loadCount('media'),
        ], 201);
    }

    public function updateFolder(Request $request, int $id): JsonResponse
    {
        $folder = MediaFolder::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'parent_id' => 'sometimes|nullable|integer|exists:media_folders,id',
        ], [
            'name.max' => 'نام پوشه بیش از حد طولانی است.',
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null
            && $this->isDescendant((int) $validated['parent_id'], $folder->id)) {
            return response()->json(['message' => 'پوشه نمی‌تواند داخل خودش یا زیرشاخه‌اش قرار گیرد.'], 422);
        }

        $folder->fill($validated)->save();

        return response()->json([
            'message' => 'پوشه به‌روزرسانی شد.',
            'data' => $folder->fresh()->loadCount('media'),
        ]);
    }

    /** حذف پوشه: فرزندان به ریشه و فایل‌ها «بدون پوشه» می‌شوند (نان‌مخرب). */
    public function destroyFolder(Request $request, int $id): JsonResponse
    {
        $folder = MediaFolder::query()->findOrFail($id);
        $folder->delete();

        return response()->json(['message' => 'پوشه حذف شد.']);
    }

    /** لیست برچسب‌ها + تعداد فایل هر برچسب. */
    public function tags(Request $request): JsonResponse
    {
        $tags = MediaTag::query()
            ->withCount('media')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return response()->json(['data' => $tags]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:80|unique:media_tags,name',
            'color' => 'nullable|string|max:20',
        ], [
            'name.required' => 'نام برچسب الزامی است.',
            'name.unique' => 'این برچسب از قبل وجود دارد.',
        ]);

        $tag = MediaTag::query()->create($validated);

        return response()->json([
            'message' => 'برچسب ساخته شد.',
            'data' => $tag->loadCount('media'),
        ], 201);
    }

    public function updateTag(Request $request, int $id): JsonResponse
    {
        $tag = MediaTag::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('media_tags', 'name')->ignore($tag->id)],
            'color' => 'sometimes|nullable|string|max:20',
        ], [
            'name.unique' => 'این برچسب از قبل وجود دارد.',
        ]);

        $tag->fill($validated)->save();

        return response()->json([
            'message' => 'برچسب به‌روزرسانی شد.',
            'data' => $tag->fresh()->loadCount('media'),
        ]);
    }

    public function destroyTag(Request $request, int $id): JsonResponse
    {
        MediaTag::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'برچسب حذف شد.']);
    }

    /** انتقال گروهی: ids را به folder_id می‌برد (`null` = بدون پوشه). */
    public function bulkMove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'folder_id' => 'nullable|integer|exists:media_folders,id',
        ], [
            'ids.required' => 'هیچ فایلی انتخاب نشده است.',
        ]);

        $count = Media::query()
            ->whereIn('id', $validated['ids'])
            ->update(['folder_id' => $validated['folder_id'] ?? null]);

        return response()->json([
            'message' => $count > 0 ? "{$count} فایل منتقل شد." : 'فایلی برای انتقال نبود.',
            'data' => ['moved' => $count],
        ]);
    }

    /** برچسب گروهی: mode = add (افزودن) | remove (حذف) | sync (جایگزینی). */
    public function bulkTag(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:media_tags,id',
            'mode' => 'sometimes|in:add,remove,sync',
        ], [
            'ids.required' => 'هیچ فایلی انتخاب نشده است.',
            'tag_ids.required' => 'هیچ برچسبی انتخاب نشده است.',
        ]);

        $mode = $validated['mode'] ?? 'add';
        $count = 0;

        Media::query()
            ->whereIn('id', $validated['ids'])
            ->each(function (Media $media) use ($validated, $mode, &$count): void {
                match ($mode) {
                    'sync' => $media->tags()->sync($validated['tag_ids']),
                    'remove' => $media->tags()->detach($validated['tag_ids']),
                    default => $media->tags()->syncWithoutDetaching($validated['tag_ids']),
                };
                $count++;
            });

        $verbs = ['add' => 'برچسب خورد', 'remove' => 'برچسب برداشته شد', 'sync' => 'برچسب جایگزین شد'];

        return response()->json([
            'message' => $count > 0 ? "{$count} فایل {$verbs[$mode]}." : 'فایلی برای برچسب نبود.',
            'data' => ['tagged' => $count, 'mode' => $mode],
        ]);
    }

    // ───────────────────────── WF-H7: گزارش alt + ویرایش گروهی ─────────────────────────

    /**
     * WF-H7 — گزارش تصاویر با `alt` خالی/فاصله‌ای (هدف دوگانه: WCAG + سئوی تصویر).
     *
     * فقط ردیف‌های زنده و تصویری (`mime` با `image/` شروع می‌شود). سطل زباله
     * عمداً بیرون است: فایلِ حذف‌شده در صفحهٔ عمومی رندر نمی‌شود، پس نبودِ altش
     * مسئلهٔ دسترس‌پذیری نیست. `alt` با فاصله هم «خالی» حساب می‌شود تا متنِ
     * ناخواستهٔ `"   "` از گزارش فرار نکند.
     */
    public function altReport(Request $request): JsonResponse
    {
        $paginator = Media::query()
            ->where('mime', 'ilike', 'image/%')
            ->where(function ($q): void {
                $q->whereNull('alt')->orWhereRaw("trim(alt) = ''");
            })
            ->with('folder:id,name,parent_id')
            ->latest()
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'message' => $paginator->total() > 0
                ? $paginator->total().' تصویر بدون متن جایگزین (alt) است.'
                : 'همهٔ تصاویر متن جایگزین دارند.',
            'data' => $paginator,
        ]);
    }

    /**
     * WF-H7 — ویرایش گروهی `alt` روی همان گزارش.
     *
     * دو شکل پذیرفته می‌شود:
     *  - `{items:[{id, alt}, …]}` ⇒ هر فایل متن خودش (صفحهٔ گزارش، ذخیرهٔ همه).
     *  - `{ids:[…], alt:"…"}`     ⇒ یک متن برای همه (ابزار گروهی).
     *
     * فقط `perm:media.edit`. ردیف‌های ناموجود/سافت‌دیلیت‌شده بی‌صدا رد می‌شوند
     * (شمارش فقط ردیف‌های واقعاً به‌روزشده را برمی‌گرداند).
     */
    public function bulkAlt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required_without:ids|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.alt' => 'nullable|string|max:200',
            'ids' => 'required_without:items|array|min:1',
            'ids.*' => 'integer',
            'alt' => 'nullable|string|max:200',
        ], [
            'items.required_without' => 'هیچ فایلی انتخاب نشده است.',
            'ids.required_without' => 'هیچ فایلی انتخاب نشده است.',
            'items.*.alt.max' => 'متن جایگزین بیش از حد طولانی است.',
            'alt.max' => 'متن جایگزین بیش از حد طولانی است.',
        ]);

        $count = 0;

        if (isset($validated['items'])) {
            foreach ($validated['items'] as $item) {
                $count += Media::query()
                    ->whereKey($item['id'])
                    ->update(['alt' => $item['alt'] ?? null]);
            }
        } else {
            $count = Media::query()
                ->whereIn('id', $validated['ids'])
                ->update(['alt' => $validated['alt'] ?? null]);
        }

        return response()->json([
            'message' => $count > 0 ? "{$count} متن جایگزین به‌روزرسانی شد." : 'فایلی برای به‌روزرسانی نبود.',
            'data' => ['updated' => $count],
        ]);
    }

    /** آیا $candidate زیرشاخهٔ $ancestor است؟ (جلوگیری از حلقه در درخت پوشه) */
    private function isDescendant(int $candidate, int $ancestor): bool
    {
        if ($candidate === $ancestor) {
            return true;
        }

        $seen = [];
        $current = $candidate;

        while ($current !== 0 && ! isset($seen[$current])) {
            $seen[$current] = true;
            $parent = MediaFolder::query()->whereKey($current)->value('parent_id');

            if ($parent === null) {
                return false;
            }
            if ((int) $parent === $ancestor) {
                return true;
            }

            $current = (int) $parent;
        }

        return false;
    }

    /** مشترک: وجود رکورد (404) + احراز هویت کافی است. */
    private function findMedia(int $id): Media
    {
        return Media::query()
            ->where('id', $id)
            ->firstOrFail();
    }
}
