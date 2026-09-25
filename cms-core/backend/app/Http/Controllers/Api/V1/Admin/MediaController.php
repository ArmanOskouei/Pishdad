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
 */
class MediaController extends Controller
{
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
        [$client] = ClientSubscriptionResolver::forUser($request->user());

        $used = (int) Media::query()->sum('size');
        $quota = match ($client->tier) {
            'enterprise' => 20 * 1024 * 1024 * 1024,
            'standard' => 5 * 1024 * 1024 * 1024,
            default => 1024 * 1024 * 1024, // budget = ۱ گیگ
        };

        return response()->json([
            'data' => [
                'used_bytes' => $used,
                'quota_bytes' => $quota,
                'tier' => $client->tier,
                'percent' => $quota > 0 ? round($used / $quota * 100, 1) : 0,
            ],
        ]);
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
