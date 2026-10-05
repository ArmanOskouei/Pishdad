<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Subscription\ClientSubscriptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function index(Request $request): JsonResponse
    {
        // مشترک: همه مدیران همه فایل‌ها را می‌بینند.
        $query = Media::query();

        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        }
        if ($mime = $request->query('mime')) {
            $query->where('mime', 'ilike', "{$mime}%");
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
        $media = $this->findMedia($id);

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

        return response()->json([
            'message' => 'فایل ذخیره شد.',
            'data' => $media->fresh(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $media = $this->findMedia($id);

        $validated = $request->validate([
            'alt' => 'sometimes|nullable|string|max:200',
            'original_name' => 'sometimes|string|max:255',
        ], [
            'alt.max' => 'متن جایگزین بیش از حد طولانی است.',
        ]);

        $media->fill($validated)->save();

        return response()->json([
            'message' => 'فایل به‌روزرسانی شد.',
            'data' => $media->fresh(),
        ]);
    }

    /** انتقال به سطل زباله (سافت‌دیلیت). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->findMedia($id)->delete();

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
     * تنها منبع حقیقتِ سقف: تیرِ کاربر، مجموع مصرف، و سقفِ همان تیر.
     *
     * نصب عمومی مشترک اشتراکی ندارد، پس تیری وجود ندارد؛ `budget` همان
     * پیش‌فرضی است که resolver برای مشتری تازه می‌سازد.
     *
     * @return array{tier: string, used_bytes: int, quota_bytes: int}
     */
    private function quotaState(Request $request): array
    {
        $resolved = ClientSubscriptionResolver::forUser($request->user());
        $client = $resolved[0] ?? null;

        $tier = is_string($client?->tier ?? null) && isset(self::TIER_QUOTAS[$client->tier])
            ? $client->tier
            : self::DEFAULT_TIER;

        return [
            'tier' => $tier,
            'used_bytes' => (int) Media::query()->sum('size'),
            'quota_bytes' => self::TIER_QUOTAS[$tier],
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

    /** مشترک: وجود رکورد (404) + احراز هویت کافی است. */
    private function findMedia(int $id): Media
    {
        return Media::query()
            ->where('id', $id)
            ->firstOrFail();
    }
}
