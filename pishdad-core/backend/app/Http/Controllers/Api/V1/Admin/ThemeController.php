<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Services\RevalidateDispatcher;
use App\Services\Settings\CachedSettings;
use App\Services\Themes\SiteThemeResolver;
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

        /*
         * ⭐ E79 — این باید **idempotent** باشد، و قبلاً نبود.
         *
         * نسخهٔ قبلی `forceFill(['active' => true])->save()` بود. اگر قالب از قبل
         * فعال بود، `active` dirty نمی‌شد ⇒ لاراول هیچ UPDATE نمی‌فرستاد، در
         * حالی که خطِ قبل همه را خاموش کرده بود. نتیجه: نصب **بدونِ هیچ قالبِ
         * فعالی** می‌ماند و `SiteChromeController` دوباره به `defaults()`
         * می‌افتد یعنی سایت بی‌قالب. با یک UPDATE صریح این اتفاق برای همیشه
         * حذف شد.
         */
        Theme::query()->update(['active' => false]);
        Theme::query()->whereKey($theme->getKey())->update(['active' => true]);

        // ⭐ `F0.7` — این خط قبلاً فقط یک NOTE بود و dispatch انجام نمی‌شد.
        //
        // نتیجه‌اش یک عیبِ بی‌صدا بود: قالب عوض می‌شد، `themes.active` در DB عوض
        // می‌شد، ولی `site_chrome` تا ۱۲۰ ثانیه کشِ قالبِ قبلی را برمی‌گرداند و
        // ISR فرانت هم تا انقضای خودش قالبِ قبلی را نشان می‌داد. پنل می‌گفت
        // «فعال شد» و سایت عوض نشده بود — و هیچ لاگی هم نبود.
        //
        // ⭐ و دو ویرایشگر نباید از هم جدا شوند. جدولِ `themes` (این مسیر) و
        // `site_themes` (`F4.1.B`) هر دو می‌گویند «پوستهٔ فعال چیست» و
        // `SiteChromeController` resolver را **بر** مانیفست می‌نشاند. پس فعال‌کردنِ
        // اینجا بدون همگام‌کردنِ resolver یعنی «پوسته عوض شد ولی چیدمانِ قبلی
        // ماند» — که دقیقاً همان رفتارِ گمراه‌کننده‌ای است که `Q5` حذفش می‌خواهد.
        //
        // رنگ‌بندی و حالت دست‌نخورده می‌مانند: این دکمه «پوسته» است نه «رنگ».
        // اسلاگِ ناشناخته (ZIPِ بیرونی) عمداً رد می‌شود — مالکیتِ آن مسیر
        // `F4.1.F` است و نباید حدس بزنیم.
        $builtin = SiteThemeResolver::isBuiltinSlug((string) $theme->slug);
        if ($builtin) {
            try {
                $current = SiteThemeResolver::selection(SiteThemeResolver::GLOBAL_OWNER);
                SiteThemeResolver::select(
                    SiteThemeResolver::GLOBAL_OWNER,
                    $builtin,
                    (string) $current['preset_slug'],
                    (string) $current['mode'],
                );
            } catch (\InvalidArgumentException $e) {
                Log::warning('theme.activate.skin_sync_failed', ['slug' => $theme->slug, 'why' => $e->getMessage()]);
            }
        }

        // سه چیز لازم است و هر سه اینجاست: ابطالِ کشِ کروم (۱۲۰ ثانیه)، ابطالِ
        // کشِ توکنِ قالب (۳۰۰ ثانیه)، و purge کشِ ISR فرانت.
        //
        // fire-and-forget: نرسیدنِ فرانت نباید فعال‌سازی را برگرداند (انقضای
        // خودکار fallback است) — و خودِ dispatcher هم `Log::warning` می‌کند.
        try {
            CachedSettings::forget('site_chrome', 'chrome-default-global');
            SiteThemeResolver::flush();
            app(RevalidateDispatcher::class)->dispatch(['site-theme', 'theme', 'site-chrome']);
        } catch (\Throwable) {
            // عمداً بی‌صدا: فعالیت انجام شده و برنمی‌گردد.
        }

        return response()->json([
            'message' => 'قالب فعال شد.',
            'data' => $theme->fresh(),
        ]);
    }

    public function preview(Request $request, Theme $theme): JsonResponse
    {
        // مشترک: وجود رکورد (404 بایندیگ) + احراز هویت کافی است.
        // مسیرِ **نسبی** برمی‌گردد تا روی همان مبدأِ پنل/سایت باز شود؛ پیش از این
        // با `config('app.url')` (مبدأ بک‌اند :8080) ساخته می‌شد و پیش‌نمایشِ
        // قالب آن‌جا اصلاً وجود نداشت.
        return response()->json([
            'message' => 'پیش‌نمایش آماده است.',
            'data' => [
                'theme' => $theme->only(['id', 'name', 'slug', 'version']),
                'preview_url' => "/preview/{$theme->slug}",
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

        return ($user->role ?? null) === 'operator';
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
