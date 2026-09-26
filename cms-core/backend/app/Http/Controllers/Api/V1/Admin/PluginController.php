<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Services\Plugins\CoreRequirementChecker;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Services\Plugins\PluginTrustStore;
use App\Support\Plugins\PackageAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use ZipArchive;

/**
 * تسک ۵.۴ — چرخه حیات پلاگین با امضای اجباری Ed25519.
 *
 * - آپلود فقط ZIP (سقف ۲۰MB) با manifest.json دارای signature معتبر؛
 *   در غیر این صورت 422 + لاگ امنیتی (fail-closed).
 * - هوک‌های مانیفست هنگام فعال‌سازی در رجیستری plugin_hooks ثبت می‌شوند.
 * - پلاگین سیستمی (manifest.system=true) قابل deactivate/uninstall نیست (422).
 * - مورد ۶ (گردش تایید): آپلود مشتری → review_status=pending و غیرقابل
 *   فعال‌سازی تا تایید مرکزی؛ سوپرادمین/اپراتور مستثنا (approved).
 *
 * فاز ۰ — بازنویسی مدل اعتماد:
 * - `system` دیگر از مانیفست خوانده نمی‌شود؛ فقط `SystemPlugin` allowlist.
 * - امضا از trust store چندناشره (`publisher_keys`) خوانده می‌شود، نه یک کلید واحد.
 * - `signature_valid` دیگر معنای «تأییدشده» نمی‌دهد؛ فقط «امضای ناشر معتبر بود».
 */
class PluginController extends Controller
{
    public function __construct(private PluginSignatureVerifier $verifier) {}

    public function index(Request $request): JsonResponse
    {
        // مشترک: همه مدیران همه پلاگین‌های نصب را می‌بینند.
        $plugins = Plugin::query()
            ->withCount('hooks')
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            // map روی خود paginator نه collection آن، تا meta/links دست‌نخورده بماند.
            'data' => $plugins->through(fn (Plugin $p) => $this->present($p)),
        ]);
    }

    public function show(Request $request, Plugin $plugin): JsonResponse
    {
        // مشترک: وجود رکورد (404 بایندینگ) + احراز هویت کافی است.

        return response()->json(['data' => $this->present($plugin)]);
    }

    public function upload(Request $request): JsonResponse
    {
        // فاز ۱.۵: بسته قبل از نصب تحلیل می‌شود تا کاربر دقیقاً بداند کدام فایل
        // کجاست و کدام جا نیست. این کار هیچ چیزی استخراج یا نمی‌نویسد.
        $analysis = $this->analyzePackage($request);
        if ($analysis->isError()) {
            return response()->json([
                'message' => $analysis->headline(),
                'analysis' => $analysis,
            ], 422);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:zip|max:20480',
        ], [
            'file.required' => 'فایل پلاگین الزامی است.',
            'file.mimes' => 'پلاگین باید فایل ZIP باشد.',
            'file.max' => 'حجم پلاگین بیش از حد مجاز است (۲۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $manifest = $this->readManifest($file->getRealPath());

        if ($manifest === null || empty($manifest['name']) || empty($manifest['slug'])) {
            return response()->json(['message' => 'فایل manifest.json معتبر در ZIP یافت نشد.'], 422);
        }

        $check = $this->trustStore()->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            // B3: تنها راه عبور از بررسی امضا، حالت توسعه‌دهندهٔ باز و توکن
            // معتبر است که `devmode.gate` بررسی‌اش کرده. پیش از این،
            // `dev_mode` اصلاً وجود نداشت و این شاخه همیشه می‌بست.
            if ($request->attributes->get('dev_mode_granted') !== true) {
                Log::warning('plugin.upload_rejected', [
                    'user_id' => $request->user()->id,
                    'slug' => $manifest['slug'] ?? null,
                    'reason' => $check['reason'],
                ]);

                return response()->json(['message' => $check['reason']], 422);
            }

            Log::warning('plugin.upload_unsigned_via_dev_mode', [
                'user_id' => $request->user()->id,
                'slug' => $manifest['slug'] ?? null,
                'reason' => $check['reason'],
            ]);
        }

        // K3.0/B25: `requires.core` بعد از تأیید امضا سنجیده می‌شود، نه قبلش.
        // این رشته ادعای ناشر است؛ تا ندانیم فرستنده کیست، ارزیابی‌اش فقط به
        // بستهٔ جعلی اجازه می‌دهد پیام دلخواه به کاربر واقعی بدهد.
        $incompatible = CoreRequirementChecker::check($manifest, CoreRequirementChecker::coreVersion());
        if ($incompatible !== null) {
            Log::warning('plugin.upload_rejected', [
                'user_id' => $request->user()->id,
                'slug' => $manifest['slug'] ?? null,
                'reason' => $incompatible['code'],
            ]);

            return response()->json([
                'message' => $incompatible['message'],
                'requires' => $incompatible,
            ], 422);
        }

        $slug = substr(Str::slug($manifest['slug']), 0, 100);
        if (Plugin::query()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'پلاگینی با این شناسه قبلاً نصب شده است.'], 422);
        }

        $path = $this->storePackage($file, $slug.'_'.Str::random(8).'.zip');
        if ($path === false) {
            return response()->json([
                'message' => 'بستهٔ پلاگین روی دیسک ذخیره نشد، پس نصب انجام نشد. فضای دیسک و دسترسی پوشهٔ ذخیره‌سازی را بررسی کنید.',
            ], 422);
        }

        $exempt = $this->isReviewExempt($request);
        $plugin = Plugin::query()->create([
            'user_id' => $request->user()->id,
            'name' => $manifest['name'],
            'slug' => $slug,
            'version' => $manifest['version'] ?? '1.0.0',
            'active' => false,
            // فاز ۰: پرچم system فقط از allowlist می‌آید، نه از مانیفست.
            'system' => SystemPlugin::allows($slug),
            'source' => Plugin::SOURCE_LOCAL,
            'publisher_key_id' => $check['key_id'],
            'publisher_verified' => $check['publisher_verified'],
            // اصالت است، نه تأیید. تأیید در review_status جداگانه است.
            'signature_valid' => $check['valid'],
            'review_status' => $exempt ? Plugin::REVIEW_APPROVED : Plugin::REVIEW_PENDING,
            'review_note' => null,
            'submitted_at' => now(),
            'reviewed_at' => $exempt ? now() : null,
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            // content_digest در فاز ۱ پر می‌شود؛ null یعنی «یکپارچگی هنوز تأیید نشده».
            'content_digest' => null,
            'digest_verified_at' => null,
            'manifest' => $manifest,
            'path' => $path,
        ]);

        return response()->json([
            'message' => $exempt
                ? 'پلاگین با امضای معتبر نصب شد.'
                : 'پلاگین شما آپلود شد و در انتظار تأیید است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.',
            'data' => $this->present($plugin),
        ], 201);
    }

    public function activate(Request $request, Plugin $plugin): JsonResponse
    {
        if (! $this->mayActivate($request, $plugin)) {
            return response()->json(['message' => $this->activationBlockReason($request, $plugin)], 422);
        }

        $plugin->forceFill(['active' => true])->save();
        $this->syncHooks($plugin);

        $fresh = $plugin->fresh();

        // «فعال شد» بدون این فیلد دروغ می‌گوید: رجیستری هوک‌ها پر می‌شود ولی
        // dispatcher هنوز وجود ندارد (K5.5)، پس هیچ هوکی اجرا نمی‌شود. پاسخ باید
        // این را صریح بگوید تا UI بتواند به‌جای toast خوش‌بینانه، هشدار بدهد.
        return response()->json([
            'message' => 'پلاگین فعال شد.',
            'hooks_dispatched' => false,
            'warning' => $this->hooksDispatchWarning($fresh),
            'data' => $this->present($fresh),
        ]);
    }

    public function deactivate(Request $request, Plugin $plugin): JsonResponse
    {
        if ($plugin->isSystem()) {
            return response()->json(['message' => 'پلاگین سیستمی را نمی‌توان غیرفعال کرد.'], 422);
        }

        $plugin->forceFill(['active' => false])->save();

        return response()->json([
            'message' => 'پلاگین غیرفعال شد.',
            'data' => $plugin->fresh(),
        ]);
    }

    public function upgrade(Request $request, Plugin $plugin): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:zip|max:20480',
        ], [
            'file.required' => 'فایل نسخه جدید الزامی است.',
            'file.mimes' => 'پلاگین باید فایل ZIP باشد.',
            'file.max' => 'حجم پلاگین بیش از حد مجاز است (۲۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $manifest = $this->readManifest($file->getRealPath());

        if ($manifest === null || ($manifest['slug'] ?? null) !== $plugin->slug) {
            return response()->json(['message' => 'مانیفست نسخه جدید با این پلاگین مطابقت ندارد.'], 422);
        }

        $newVersion = $manifest['version'] ?? '1.0.0';
        if (version_compare($newVersion, $plugin->version, '<=')) {
            return response()->json(['message' => 'نسخه جدید باید از نسخه فعلی بالاتر باشد.'], 422);
        }

        $check = $this->trustStore()->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            // B3: ارتقا باید دقیقاً همان قاعدهٔ آپلود را داشته باشد. وگرنه
            // بستهٔ بی‌امضا راهی برای جایگزینی کدِ پلاگین نصب‌شده پیدا می‌کند.
            if ($request->attributes->get('dev_mode_granted') !== true) {
                Log::warning('plugin.upgrade_rejected', [
                    'user_id' => $request->user()->id, 'slug' => $plugin->slug,
                    'reason' => $check['reason'],
                ]);

                return response()->json(['message' => $check['reason']], 422);
            }

            Log::warning('plugin.upgrade_unsigned_via_dev_mode', [
                'user_id' => $request->user()->id, 'slug' => $plugin->slug,
                'reason' => $check['reason'],
            ]);
        }

        // K3.0/B25 — دلیل ترتیب مثل upload(): اول اصالت، بعد ادعای سازگاری.
        $incompatible = CoreRequirementChecker::check($manifest, CoreRequirementChecker::coreVersion());
        if ($incompatible !== null) {
            Log::warning('plugin.upgrade_rejected', [
                'user_id' => $request->user()->id,
                'slug' => $plugin->slug,
                'reason' => $incompatible['code'],
            ]);

            return response()->json([
                'message' => $incompatible['message'],
                'requires' => $incompatible,
            ], 422);
        }

        // B11: ترتیب «جدید → ردیف → حذف قدیم» عمدی است. حذف زودهنگام یعنی اگر
        // نوشتن شکست بخورد (دیسک پر)، رکوردی می‌ماند که `path`اش به فایل
        // ناموجود اشاره می‌کند و پلاگین فعال برای همیشه آجر می‌شود.
        $oldPath = $plugin->path;
        $path = $this->storePackage($file, $plugin->slug.'_'.Str::random(8).'.zip');
        if ($path === false) {
            return response()->json([
                'message' => 'نسخهٔ جدید روی دیسک ذخیره نشد، پس هیچ تغییری اعمال نشد و نسخهٔ فعلی دست‌نخورده ماند.',
            ], 422);
        }

        $plugin->forceFill([
            'previous_version' => $plugin->version,
            'version' => $newVersion,
            'name' => $manifest['name'],
            // فاز ۰: ارتقا هم نمی‌تواند پرچم system را از مانیفست بگیرد.
            'system' => SystemPlugin::allows($plugin->slug),
            'publisher_key_id' => $check['key_id'],
            'publisher_verified' => $check['publisher_verified'],
            'signature_valid' => $check['valid'],
            'review_status' => $this->isReviewExempt($request) ? Plugin::REVIEW_APPROVED : Plugin::REVIEW_PENDING,
            'review_note' => null,
            'submitted_at' => now(),
            'reviewed_at' => $this->isReviewExempt($request) ? now() : null,
            'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
            // نسخه عوض شده ⇒ مهر یکپارچگی قبلی بی‌اعتبار است.
            'content_digest' => null,
            'digest_verified_at' => null,
            'manifest' => $manifest,
            'path' => $path,
        ])->save();

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        if ($plugin->active) {
            $this->syncHooks($plugin->fresh());
        }

        return response()->json([
            'message' => 'پلاگین به‌روزرسانی شد.',
            'data' => $this->present($plugin->fresh()),
        ]);
    }

    public function uninstall(Request $request, Plugin $plugin): JsonResponse
    {
        if ($plugin->isSystem()) {
            return response()->json(['message' => 'پلاگین سیستمی را نمی‌توان حذف کرد.'], 422);
        }

        if ($plugin->path) {
            Storage::disk('local')->delete($plugin->path);
        }
        $plugin->delete();

        return response()->json(['message' => 'پلاگین حذف شد.']);
    }

    /**
     * فاز ۱.۵ — تحلیل بسته بدون نصب.
     *
     * کاربر می‌تواند ZIP را بدهد و ساختارش را ببیند **بدون اینکه چیزی نصب شود یا
     * یک خط کد پلاگینی اجرا شود**. همین مسیر است که به کاربر می‌گوید کدام فایل در
     * جای اشتباه است و باید کجا باشد، و به بخش مربوط راهنما لینک می‌دهد.
     */
    public function validatePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:zip|max:20480',
        ], [
            'file.required' => 'فایل پلاگین الزامی است.',
            'file.mimes' => 'پلاگین باید فایل ZIP باشد.',
            'file.max' => 'حجم پلاگین بیش از حد مجاز است (۲۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $analysis = $this->analyzePackage($request, $file);

        // endpoint تحلیل هیچ‌وقت 4xx نمی‌دهد: کاربر باید بتواند نتیجه را ببیند
        // حتی وقتی بسته خراب است — وگرنه پیام خطای قابل‌نمایش را از دست می‌دهد.
        return response()->json(['analysis' => $analysis], 200);
    }

    /** تحلیل و تبدیل به شیء نتیجه. در صورت خطای بوت/خواندن، شیء خالی می‌سازد. */
    private function analyzePackage(Request $request, ?UploadedFile $file = null): PackageAnalysis
    {
        $file ??= $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return PackageAnalysis::fromValidator([
                'ok' => false,
                'severity' => 'error',
                'errors' => [['code' => 'file.invalid', 'message' => 'فایل قابل خواندن نیست.']],
                'warnings' => [],
                'manifest' => null,
                'summary' => ['errors' => 1, 'warnings' => 0, 'slug' => null, 'version' => null, 'extension_points' => []],
                'contract' => [
                    'allowed' => PluginPackageContract::describeAllowedPaths(),
                    'extension_points' => PluginPackageContract::extensionPoints(),
                ],
            ]);
        }

        try {
            // B3: تحلیلگر دروازهٔ امضای مستقل خودش را دارد، پس باید همان وضعیت
            // حالت تDevelopه‌دهنده را ببیند. این مقدار فقط از صفتِ درخواست
            // می‌آید که middleware پس از بررسی توکن گذاشته — نه از بدنهٔ
            // درخواست، پس کاربر نمی‌تواند خودش صادق جلوه کند.
            $allowUnsigned = $request->attributes->get('dev_mode_granted') === true;

            return PackageAnalysis::fromValidator(
                $this->validator()->analyze($file->getRealPath(), ['allowUnsigned' => $allowUnsigned])
            );
        } catch (\Throwable $e) {
            Log::error('plugin.analysis_exception', ['error' => $e->getMessage()]);

            return PackageAnalysis::fromValidator([
                'ok' => false,
                'severity' => 'error',
                'errors' => [['code' => 'analysis.exception', 'message' => 'بسته قابل تحلیل نبود: '.$e->getMessage()]],
                'warnings' => [],
                'manifest' => null,
                'summary' => ['errors' => 1, 'warnings' => 0, 'slug' => null, 'version' => null, 'extension_points' => []],
                'contract' => [
                    'allowed' => PluginPackageContract::describeAllowedPaths(),
                    'extension_points' => PluginPackageContract::extensionPoints(),
                ],
            ]);
        }
    }

    /** سوپرادمین/اپراتور از قفل بازبینی مستثناست (سیدر/داخلی‌ها approved). */
    private function isReviewExempt(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user->is_super_admin ?? false) || (($user->role ?? null) === 'operator');
    }

    private function trustStore(): PluginTrustStore
    {
        return app(PluginTrustStore::class);
    }

    /**
     * نوشتن بسته روی دیسک، با تبدیل هر شکست به `false`.
     *
     * Flysystem برای «نشدن ساخت پوشه» exception می‌دهد و برای «نشدن نوشتن» `false`.
     * هر دو باید یکی باشند، وگرنه یکی از این دو حالت به ۵۰۰ می‌رسد و کاربر فقط
     * یک صفحهٔ خطای بی‌پیام می‌بیند.
     */
    private function storePackage(UploadedFile $file, string $filename): string|false
    {
        try {
            return Storage::disk('local')->putFileAs('plugins/shared', $file, $filename);
        } catch (\Throwable $e) {
            Log::error('plugin.store_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function validator(): PluginPackageValidator
    {
        return app(PluginPackageValidator::class);
    }

    private function mayActivate(Request $request, Plugin $plugin): bool
    {
        if ($this->isReviewExempt($request)) {
            return true;
        }

        return $plugin->review_status === Plugin::REVIEW_APPROVED;
    }

    private function activationBlockReason(Request $request, Plugin $plugin): string
    {
        return match ($plugin->review_status) {
            Plugin::REVIEW_REJECTED => 'این پلاگین رد شده است. دلیل رد را ببینید، اصلاح کنید و نسخه جدید آپلود کنید.',
            Plugin::REVIEW_PENDING => 'این پلاگین هنوز تأیید نشده است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.',
            default => 'این پلاگین تأییدنشده است. برای فعال‌سازی، حالت توسعه‌دهنده را فعال کنید و تأیید ریسک را بپذیرید.',
        };
    }

    /**
     * نمایش غنی برای UI.
     *
     * `trust` عمداً سه مفهوم جدا را برمی‌گرداند (اصالت / تأیید / یکپارچگی) تا
     * فرانت مجبور نباشد از روی یک boolean درباره امنیت حدس بزند.
     */
    private function present(Plugin $plugin): array
    {
        $plugin->loadMissing('hooks');

        return array_merge($plugin->toArray(), [
            'trust' => $plugin->trustSummary(),
            'is_system' => $plugin->isSystem(),
        ]);
    }

    /** ثبت هوک‌های مانیفست در رجیستری (جایگزینی کامل — اعلامی). */
    private function syncHooks(Plugin $plugin): void
    {
        $hooks = $plugin->manifest['hooks'] ?? [];
        $plugin->hooks()->delete();

        foreach (is_array($hooks) ? $hooks : [] as $hook) {
            if (is_string($hook)) {
                $plugin->hooks()->create(['hook' => $hook, 'handler' => null]);
            } elseif (is_array($hook) && isset($hook['hook'])) {
                $plugin->hooks()->create([
                    'hook' => $hook['hook'],
                    'handler' => $hook['handler'] ?? null,
                ]);
            }
        }

        $this->ensurePermissions(is_array($plugin->manifest) ? $plugin->manifest : []);
    }

    /**
     * ساخت رکوردهای spatie برای ماژول‌های اعلام‌شده در هوک permissions
     * مانیفست تا نقش‌ها بتوانند همان‌جا در ماتریس تخصیص داده شوند.
     * ماژول تکراری هسته نادیده گرفته می‌شود.
     */
    private function ensurePermissions(array $manifest): void
    {
        $core = array_keys(config('modules', []));
        $created = false;

        foreach (ManifestRegistry::manifestPermissions($manifest) as $entry) {
            if (in_array($entry['module'], $core, true)) {
                continue;
            }
            foreach ($entry['actions'] as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$entry['module']}.{$action}", 'guard_name' => 'web']
                );
                $created = true;
            }
        }

        if ($created) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * فقط مانیفست **ریشهٔ** ZIP.
     *
     * B9: `locateName('manifest.json', FL_NODIR)` هر `manifest.json` را پیدا می‌کرد،
     * حتی داخل پوشه. الان بی‌خطر است چون validator قبلش `manifest.misplaced` می‌دهد،
     * ولی این یک وابستگی ظریف است: همین مانیفست تودرتو `slug` و امضا و کل ردیف DB
     * را تعیین می‌کند، پس با جابه‌جا شدن ترتیب، یک بستهٔ امضاشدهٔ کاملاً متفاوت
     * نصب می‌شد. `getFromName` تطابق دقیق نام می‌دهد و این وابستگی را حذف می‌کند.
     */
    private function readManifest(string $zipPath): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $raw = $zip->getFromName(PluginPackageContract::MANIFEST);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * هشدار صادقانهٔ فعال‌سازی، یا `null` وقتی مانیفست هوکی اعلام نکرده.
     *
     * `syncHooks()` رجیستری را پر می‌کند ولی dispatcher هنوز نیست (K5.5)، پس
     * «هوک‌ها ثبت شدند» با «هوک‌ها اجرا می‌شوند» فرق دارد و پاسخ باید دومی را
     * نگوید.
     */
    private function hooksDispatchWarning(Plugin $plugin): ?string
    {
        $hooks = is_array($plugin->manifest) ? ($plugin->manifest['hooks'] ?? null) : null;
        if (! is_array($hooks) || $hooks === []) {
            return null;
        }

        return 'هوک‌های این پلاگین فقط در رجیستری ثبت شدند و هنوز اجرا نمی‌شوند؛ '
            .'موتور dispatch در فاز K5.5 ساخته می‌شود. تا آن زمان، هر بخشی از پلاگین که '
            .'به هوک تکیه کند کار نخواهد کرد.';
    }
}
