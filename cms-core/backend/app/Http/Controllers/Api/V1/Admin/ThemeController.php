<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Services\Plugins\PluginSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * تسک ۴.۲ — قالب‌های سایت (مشترک نصب تک‌سایتی).
 *
 * مرحله ۵ (تسک ۵.۴): اگر مانیفست فیلد signature داشته باشد، با Ed25519
 * راستی‌آزمایی می‌شود — نامعتبر = 422 + لاگ امنیتی؛ معتبر =
 * signature_valid=true. مانیفست بدون امضا (قدیمی) همچنان با
 * signature_valid=false پذیرفته می‌شود تا مسیر مهاجرت مشخص شود.
 * مورد ۶ (گردش تایید): آپلود مشتری → pending و غیرقابل فعال‌سازی تا
 * تایید مرکزی؛ سوپرادمین/اپراتور مستثنا (approved).
 */
class ThemeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureDefault($request);

        // مشترک: همه مدیران همه قالب‌های نصب را می‌بینند.
        $themes = Theme::query()
            ->orderByDesc('active')
            ->latest()
            ->get();

        return response()->json(['data' => $themes]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:zip|max:20480', // سقف ۲۰MB
        ], [
            'file.required' => 'فایل قالب الزامی است.',
            'file.mimes' => 'قالب باید فایل ZIP باشد.',
            'file.max' => 'حجم قالب بیش از حد مجاز است (۲۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $manifest = $this->readManifest($file->getRealPath());

        if ($manifest === null) {
            return response()->json(['message' => 'فایل manifest.json معتبر در ZIP یافت نشد.'], 422);
        }
        if (empty($manifest['name'])) {
            return response()->json(['message' => 'نام قالب در manifest.json الزامی است.'], 422);
        }

        $slug = Str::slug($manifest['slug'] ?? $manifest['name']) ?: 'theme-'.Str::random(6);
        $slug = substr($slug, 0, 100);

        if (Theme::query()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'قالبی با این شناسه قبلاً نصب شده است.'], 422);
        }

        $path = Storage::disk('local')->putFileAs(
            'themes/shared',
            $file,
            $slug.'_'.Str::random(8).'.zip'
        );

        // مرحله ۵ (تسک ۵.۴): راستی‌آزمایی واقعی Ed25519 وقتی امضا present است.
        $signatureValid = false;
        if (isset($manifest['signature'])) {
            $check = app(PluginSignatureVerifier::class)->verify($manifest);
            if (! $check['valid']) {
                Log::warning('theme.upload_rejected', [
                    'user_id' => $request->user()->id,
                    'slug' => $manifest['slug'] ?? null,
                ]);

                return response()->json(['message' => $check['reason']], 422);
            }
            $signatureValid = true;
        }
        $theme = Theme::query()->create([
            'user_id' => $request->user()->id,
            'name' => $manifest['name'],
            'slug' => $slug,
            'version' => $manifest['version'] ?? '1.0.0',
            'active' => false,
            'signature_valid' => $signatureValid,
            'review_status' => $this->isReviewExempt($request) ? 'approved' : 'pending',
            'review_note' => null,
            'submitted_at' => now(),
            'reviewed_at' => $this->isReviewExempt($request) ? now() : null,
            'manifest' => $manifest,
            'path' => $path,
        ]);

        if ($this->isReviewExempt($request)) {
            $message = $signatureValid ? 'قالب با امضای معتبر بارگذاری شد.' : 'قالب بارگذاری شد (امضا ندارد؛ راستی‌آزمایی نشده است).';
        } else {
            $message = 'قالب شما آپلود شد و در انتظار تأیید است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.';
        }

        return response()->json([
            'message' => $message,
            'data' => $theme,
        ], 201);
    }

    public function activate(Request $request, Theme $theme): JsonResponse
    {
        // مشترک: وجود رکورد (404 بایندینگ) + احراز هویت کافی است.

        if ($theme->review_status !== 'approved' && ! $this->isReviewExempt($request)) {
            $msg = $theme->review_status === 'rejected'
                ? 'این قالب رد شده است. دلیل رد را ببینید، اصلاح کنید و نسخه جدید آپلود کنید.'
                : 'این قالب هنوز تأیید نشده است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.';

            return response()->json(['message' => $msg], 422);
        }

        Theme::query()->update(['active' => false]);
        $theme->forceFill(['active' => true])->save();

        // NOTE: تعویض واقعی ظاهر + revalidate/purge CDN سمت فرانت (تسک ۴.۲ فرانت).

        return response()->json([
            'message' => 'قالب فعال شد.',
            'data' => $theme->fresh(),
        ]);
    }

    public function preview(Request $request, Theme $theme): JsonResponse
    {
        // مشترک: وجود رکورد (404 بایندینگ) + احراز هویت کافی است.

        $base = rtrim((string) config('app.url', 'http://localhost:8080'), '/');

        return response()->json([
            'message' => 'پیش‌نمایش آماده است.',
            'data' => [
                'theme' => $theme->only(['id', 'name', 'slug', 'version']),
                'preview_url' => "{$base}/preview?theme={$theme->slug}",
            ],
        ]);
    }

    public function destroy(Request $request, Theme $theme): JsonResponse
    {
        // مشترک: وجود رکورد (404 بایندینگ) + احراز هویت کافی است.
        if ($theme->active) {
            return response()->json(['message' => 'قالب فعال را نمی‌توان حذف کرد. ابتدا قالب دیگری را فعال کنید.'], 422);
        }

        if ($theme->path) {
            Storage::disk('local')->delete($theme->path);
        }
        $theme->delete();

        return response()->json(['message' => 'قالب حذف شد.']);
    }

    /**
     * قالب پیش‌فرض مشترک نصب (تک‌نمونه slug=default)؛ user_id فقط سازنده
     * (حسابرسی) است و در خواندن/فعال‌سازی فیلتر نمی‌شود.
     */
    private function ensureDefault(Request $request): void
    {
        if (Theme::query()->where('slug', 'default')->exists()) {
            return;
        }
        Theme::query()->create(
            [
                'user_id' => $request->user()->id,
                'name' => 'قالب پیش‌فرض',
                'slug' => 'default',
                'version' => '1.0.0',
                'active' => true,
                'signature_valid' => false,
                'review_status' => 'approved',
                'manifest' => ['name' => 'قالب پیش‌فرض', 'version' => '1.0.0'],
            ]
        );
    }

    /** سوپرادمین/اپراتور از قفل بازبینی مستثناست (سیدر/داخلی‌ها approved). */
    private function isReviewExempt(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user->is_super_admin ?? false) || (($user->role ?? null) === 'operator');
    }

    /** خواندن manifest.json از ریشه ZIP؛ null یعنی نامعتبر/غایب. */
    private function readManifest(string $zipPath): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $index = $zip->locateName('manifest.json', ZipArchive::FL_NODIR);
            if ($index === false) {
                return null;
            }
            $raw = $zip->getFromIndex($index);
            if (! is_string($raw)) {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            $zip->close();
        }
    }
}
