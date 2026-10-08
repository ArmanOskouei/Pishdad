<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Services\Plugins\CoreRequirementChecker;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginDdlProvisioner;
use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginIntegritySeal;
use App\Services\Plugins\PluginLifecycle;
use App\Services\Plugins\PluginMigrator;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Services\Plugins\PluginTrustStore;
use App\Services\Plugins\PluginUpdateResolver;
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
 * - فیلد `hooks` در مانیفست پذیرفته ولی نادیده گرفته می‌شود (K5.5): نه رجیستری
 *   می‌سازد، نه dispatcher دارد. جدولش حذف شد.
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
    public function __construct(
        private PluginSignatureVerifier $verifier,
        private PluginMigrator $migrator,
        private PluginReleaseManager $releases,
        private PluginInstaller $installer,
        private PluginDdlProvisioner $ddl,
    ) {}

    /**
     * مهر یکپارچگی، تنبل.
     *
     * تنبل و نه constructor-injected: این کنترلر در `routes/api.php` به‌صورت
     * closure نیست و Laravel خودش حلش می‌کند، ولی افزودنِ پارامتر به سازنده
     * یعنی هر سازندهٔ دستیِ این کنترلر (در تست یا در یک job) می‌شکند. دسترسیِ
     * تنبل این ریسک را ندارد.
     */
    /**
     * حل‌کنندهٔ «آپدیت موجود»، تنبل و memoized در طول همین درخواست.
     *
     * همان دلیلِ `seal()`: تزریق در سازنده هر سازندهٔ دستیِ این کنترلر را
     * می‌شکند. memoize هم لازم است چون `present()` به‌ازای هر ردیفِ فهرست صدا
     * زده می‌شود و هر فراخوانی نباید یک کوئری بازار تازه بزند.
     */
    private ?PluginUpdateResolver $updateResolver = null;

    private function updates(): PluginUpdateResolver
    {
        return $this->updateResolver ??= app(PluginUpdateResolver::class);
    }

    private function seal(): PluginIntegritySeal
    {
        return app(PluginIntegritySeal::class);
    }

    /**
     * چرخهٔ عمرِ بارگذار، تنبل.
     *
     * همان دلیلِ `seal()`: افزودن پارامتر به سازندهٔ این کنترلر هر سازندهٔ دستی
     * (تست یا job) را می‌شکند.
     */
    private function lifecycle(): PluginLifecycle
    {
        return app(PluginLifecycle::class);
    }

    /**
     * K5.2-W — دروازهٔ مهر یکپارچگی **پیش از** نوشتنِ اشاره‌گرِ نسخهٔ فعال.
     *
     * ## چرا فعال‌سازی سخت‌گیر است ولی dispatch نه
     *
     * فعال‌سازی دروازهٔ اعتماد است: مدیرِ سایت دارد تصمیم می‌گیرد «این کد اجرا
     * شود». نبودِ مهر یعنی «هیچ‌کس تضمین نکرده فایل‌های روی دیسک همان بستهٔ
     * امضاشده‌اند»، و پاسخِ درست به این **رد کردن با راهنمای دقیق** است — نه
     * بازکردنِ راه و نه یک ۵۰۰ بی‌معنا.
     *
     * `dispatch` برعکس: یک افزونهٔ فعالِ پیش از این تسک مهر ندارد، و اگر آن‌جا
     * fail-closed می‌کردیم هر ارتقای هسته همهٔ سایت‌های نصب‌شده را می‌انداخت.
     * پس dispatch نبودِ مهر را «تأییدنشده» می‌خواند و رد می‌کند.
     *
     * `null` یعنی «بررسی ممکن نبود، ولی دلیلِ مسدودکردن هم نیست» — یعنی نسخه‌ای
     * که اصلاً برچسبِ محتوا‌محور ندارد. آن مسیر را مسدود نمی‌کنیم چون رفتارِ
     * موجودِ آن‌جا را عوض کردن، خارج از این تسک است.
     */
    private function integrityGate(string $slug, string $version, ?string $checksum, string $action): ?JsonResponse
    {
        $checksum = $checksum === null ? '' : trim($checksum);

        if (trim($version) === '' || $checksum === '') {
            return null;
        }

        try {
            $label = $this->releases->releaseLabel($version, $checksum);
        } catch (\Throwable) {
            return null;
        }

        $verdict = $this->seal()->verify($slug, $label);

        if ($verdict['ok']) {
            return null;
        }

        Log::warning('plugin.integrity_gate_blocked', [
            'slug' => $slug,
            'release' => $label,
            'action' => $action,
            'status' => $verdict['status'],
            'code' => $verdict['code'],
        ]);

        $message = $verdict['status'] === 'unsealed'
            ? "این نسخه هنوز مهر یکپارچگی ندارد، پس {$action} انجام نشد. اگر این افزونه پیش از دریافت مهر نصب شده، یک بار با «php artisan plugin-seal {$slug} --reseal» مهرش را بزنید."
            : "فایل‌های این نسخه با مهر یکپارچگی نمی‌خواند، پس {$action} انجام نشد و نسخهٔ فعال دست‌نخورده ماند. تا وقتی علت دستکاری روشن نشده، مهر را دوباره نزنید.";

        return response()->json([
            'message' => $message.($verdict['reason'] !== '' ? ' ('.$verdict['reason'].')' : ''),
            'code' => $verdict['code'],
        ], 422);
    }

    public function index(Request $request): JsonResponse
    {
        // مشترک: همه مدیران همه پلاگین‌های نصب را می‌بینند.
        $plugins = Plugin::query()
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

        // L-B3/F0.3 — پین ناشر پلاگین سیستمی، **پیش از** storePackage.
        $pinError = $this->assertSystemPublisherPin($slug, $check);
        if ($pinError !== null) {
            Log::warning('plugin.system_publisher_pin_mismatch', [
                'user_id' => $request->user()->id,
                'slug' => $slug,
                'key_id' => $check['key_id'] ?? null,
            ]);

            return response()->json(['message' => $pinError, 'code' => 'plugin.system_publisher_mismatch'], 422);
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
            // B35 — اختیاری. رشتهٔ غیرخالی ذخیره می‌شود، وگرنه `null` تا UI
            // بتواند «توضیحی نیست» را از «توضیح خالی است» تشخیص دهد.
            'description' => is_string($manifest['description'] ?? null) && trim((string) $manifest['description']) !== ''
                ? mb_substr(trim((string) $manifest['description']), 0, 400)
                : null,
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

        // K5.9/K0.4: مهر یکپارچگی per-install ساخته می‌شود. بعد از `create`
        // است چون قبل از آن اصلاً نصبی وجود ندارد که بخورد، و داخل همان
        // تراکنشِ ساخت رکورد می‌ماند تا نیمه‌کاره نصب نشود.
        $this->trustStore()->ensureSealKeyFor($plugin);

        // K5.2-W: بسته روی دیسک هم نصب می‌شود، نه فقط در storage.
        //
        // تا این لحظه `upload()` فقط رکورد DB می‌ساخت و ZIP را در storage می‌گذاشت،
        // پس **هیچ نسخه‌ای روی دیسک نبود** — یعنی `PluginRouter`، autoloader و
        // `PluginMigrator` هیچ‌کدام چیزی برای کار کردن نداشتند. و سقف‌های
        // امنیتی نصب (zip-slip، symlink، bomb، file count) روی مسیر زنده
        // اجرا نمی‌شدند چون `PluginInstaller` هیچ caller نداشت.
        //
        // ترتیب عمداً این است: اول نصب، بعد رکورد. اگر نصب رد شود، رکورد DB هم
        // ساخته نمی‌شود و نیمه‌نصب باقی نمی‌ماند.
        $installed = $this->installer->install(
            Storage::disk('local')->path($path),
            $slug,
            (string) ($manifest['version'] ?? '1.0.0'),
            hash_file('sha256', Storage::disk('local')->path($path)) ?: null
        );

        if (! ($installed['ok'] ?? false)) {
            // رکورد را بردار تا نصب نیمه‌کاره در پنل دیده نشود.
            $plugin->delete();
            $this->trustStore()->forgetSealKeyFor($slug);

            Log::warning('plugin.upload_rejected', [
                'user_id' => $request->user()->id,
                'slug' => $slug,
                'reason' => $installed['code'] ?? 'install.failed',
            ]);

            return response()->json([
                'message' => 'بسته روی دیسک نصب نشد، پس پلاگینی ثبت نشد: '.($installed['message'] ?? ''),
                'code' => $installed['code'] ?? 'install.failed',
            ], 422);
        }

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

        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];

        // K5.12 — نقش DDL افزونه، **پیش از** هر چیز دیگر.
        //
        // این عمداً اول است. اگر نقش نباشد، `PluginMigrator` خودش را رد می‌کند
        // ولی آن خطا دربارهٔ **افزونه** است، در حالی که ریشه، نبودِ پیکربندیِ
        // سرور است. تشخیص این دو از هم، کارِ مدیر سایت نیست.
        //
        // ⚠️ `grantMinimal` **بیرون از تراکنشِ باز** باید اجرا شود و این مسیر
        // تضمینش را ندارد: `RefreshDatabase` هر تست را در تراکنش می‌پیچد و
        // `REVOKE … ON ALL TABLES` قفلِ انحصاری می‌خواهد — یعنی قفلِ خاموش.
        //
        // پس اینجا فقط **وجودِ نقش** چک می‌شود (که ارزان و بی‌خطر است). ترمیمِ
        // امتیازها کارِ `plugin-ddl:reset-password` است — یک عملیاتِ عمدی، نه
        // اثر جانبیِ هر فعال‌سازی.
        try {
            $this->ddl->ensureRole();
        } catch (\Throwable $e) {
            Log::error('plugin.ddl_provision_failed', [
                'slug' => $plugin->slug,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'نقش دیتابیسِ افزونه آماده نشد، پس فعال‌سازی انجام نشد: '.$e->getMessage(),
                'code' => 'plugin.ddl_unavailable',
            ], 422);
        }

        // K5.2-W: مهر یکپارچگی فایل‌های روی دیسک، **پیش از** اشاره‌گر.
        //
        // ترتیب عمداً این است: مهر روی فایل‌های *همین* نسخه سنجیده می‌شود و
        // نوشتنِ اشاره‌گر بعد از آن است. برعکس، یک نسخهٔ دستکاری‌شده «فعال»
        // می‌شد و بعداً می‌دیدیم مهرش نمی‌خواند — یعنی لحظه‌ای که کدِ بد اجرا شد.
        $blocked = $this->integrityGate(
            $plugin->slug,
            (string) $plugin->version,
            $plugin->checksum === null ? null : (string) $plugin->checksum,
            'فعال‌سازی'
        );

        if ($blocked !== null) {
            return $blocked;
        }

        // K5.2-W: اشاره‌گر نسخهٔ فعال، **پیش از** migration.
        //
        // ترتیب مهم است: `PluginMigrator` نسخه را از روی `currentDir()` پیدا
        // می‌کند، و `currentDir()` اشاره‌گر `.current` را می‌خواند. تا وقتی این
        // نوشته نشود، `currentDir()` مقدار `null` می‌دهد و migration نمی‌تواند
        // فایل‌ها را ببیند — یعنی فعال‌سازی همیشه با `migration.no_release`
        // رد می‌شد. این همان شکافی بود که مسیر سرتاسری را از کار انداخته بود.
        //
        // اگر نوشتن اشاره‌گر شکست بخورد، فعال‌سازی کامل رد می‌شود: نیمه‌فعال
        // یعنی افزونه‌ای که در DB فعال است ولی کدش بارگذاری نمی‌شود.
        try {
            $this->releases->activate($plugin->slug, $plugin->version, (string) $plugin->checksum);
        } catch (\Throwable $e) {
            Log::warning('plugin.activate_pointer_failed', [
                'slug' => $plugin->slug,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'نسخهٔ فعال روی دیسک تعیین نشد، پس فعال‌سازی انجام نشد: '.$e->getMessage(),
                'code' => 'plugin.pointer_failed',
            ], 422);
        }

        // K5.6 — پیش از هر تغییر وضعیت. اگر migration رد شود، فعال‌سازی هم رد
        // می‌شود: فعال کردن افزونه‌ای که جدول‌هایش ساخته نشده یعنی افزونه‌ای که
        // در اولین درخواست می‌میرد. ترتیب عمداً این است و نه بعد از `save()`.
        $migrated = $this->migrator->run(
            $this->releases,
            $plugin->slug,
            $this->migrator->declaredTables($manifest)
        );

        if (! $migrated['ok']) {
            // اشاره‌گر را برگردان تا فعال‌سازیِ ناموفق ردپای نیمه‌کاره نگذارد.
            // نسخهٔ قبلی دست‌نخورده می‌ماند چون `swapPointer` تاریخ نگه می‌دارد.
            $this->pointerBack($plugin);

            // ⚠️ `fresh()` می‌تواند `null` بدهد و `present()` نوعش
            // `Plugin` است — پس یک `TypeError` می‌دهیم که **۵۰۰** می‌شود و
            // کاربر به‌جای دلیلِ واقعیِ رد شدنِ migration، یک خطای بی‌معنا
            // می‌بیند. این در واقع رخ داد: `PluginMigrator` روی اتصالِ جدا
            // تراکنش باز می‌کند، پس یک تستِ `RefreshDatabase` رکورد را
            // از دیدِ تراکنشِ خودش پاک می‌بیند.
            //
            // پاسخ باید همیشه `data` داشته باشد ولی اگر رکورد نیست، بدون آن
            // بهتر از کرش کردن است — کدِ خطا همان چیزی است که کاربر لازم دارد.
            $fresh = $plugin->fresh();

            return response()->json(array_filter([
                'message' => 'فعال‌سازی انجام نشد: '.$migrated['message'],
                'code' => $migrated['code'],
                'data' => $fresh === null ? null : $this->present($fresh),
            ], static fn ($v) => $v !== null), 422);
        }

        $plugin->forceFill(['active' => true])->save();

        // ⭐ کشِ رجیستری، هم‌قدم با تغییر وضعیت.
        //
        // `ManifestRegistry::activeManifests()` نتیجه را ۳۰۰ ثانیه کش می‌کند و
        // منو، پرمیشن‌ها، ویجت‌ها و رجیستریِ صفحه‌ها همه از همان یک کش می‌خوانند.
        // بدون این پاک‌سازی، فعال‌کردنِ افزونه در پنل بی‌اثر می‌ماند تا ۵ دقیقه
        // بعد — و غیرفعال‌کردنش هم به همان اندازه بی‌اثر.
        

        // ⭐ کشِ رجیستری، هم‌قدم با تغییر وضعیت.
        //
        // `ManifestRegistry::activeManifests()` نتیجه را ۳۰۰ ثانیه کش می‌کند
        // و منو، پرمیشن‌ها، ویجت‌ها و رجیستری صفحه‌ها همه از همان می‌خوانند.
        // بدون این پاک‌سازی، فعال‌کردنِ افزونه هیچ اثری در پنل نداشت تا ۵
        // دقیقه بعد — و بدتر، غیرفعال‌کردنش هم منو را نگه می‌داشت.
        

        // K5.0-W — ثبتِ بارگذارِ کلاس، هم‌قدم با تغییر وضعیت.
        //
        // اینجا و نه در `boot()`، چون `bootBundledPlugins()` عمداً فقط افزونه‌های
        // **داخلی** را زودهنگام بارگذاری می‌کند (بستهٔ داخلی با `git pull`
        // می‌آید و قابل غیرفعال‌سازی نیست). بستهٔ بازاری باید تنبل بماند تا
        // بسته‌ای را که کاربر می‌تواند غیرفعال کند بی‌اختیار بارگذاری نکنیم —
        // و این تنها جایی است که آن تنبلی به «بارگذار شد» تبدیل می‌شود.
        //
        // بعد از `save()` و نه قبلش: اگر DB نوشته نشد، بارگذار هم نباید ثبت
        // شود، وگرنه یک درخواستِ ناموفق وضعیتی می‌سازد که هیچ‌کس بازنمی‌گرداند.
        $this->lifecycle()->activate($plugin->slug);

        // این فراخوانی قبلاً تهِ `syncHooks()` بود و با حذف آن، تنها جایی که
        // پرمیشن‌های مانیفست ساخته می‌شدند از بین رفت. `ManifestRegistry` روی
        // همین سخت‌گیری تکیه می‌کند، پس جایگزینش الزامی است نه اختیاری.
        $this->ensurePermissions($manifest, $plugin->slug);

        $fresh = $plugin->fresh();

        // «فعال شد» بدون این فیلد دروغ می‌گوید. K5.5 هوک‌ها را از قرارداد برداشت:
        // نه رجیستری ساخته می‌شود و نه موتوری برای اجرا وجود دارد. فیلد عمداً
        // می‌ماند تا UI به‌جای toast خوش‌بینانه هشدار بدهد.
        return response()->json([
            'message' => 'پلاگین فعال شد.'.($migrated['applied'] === [] ? '' : ' '.$migrated['message']),
            'hooks_dispatched' => false,
            'warning' => $this->hooksDispatchWarning($fresh),
            'data' => $this->present($fresh),
        ]);
    }

    /**
     * برگرداندن اشاره‌گر پس از فعال‌سازی ناموفق.
     *
     * `activate()` نسخهٔ قبلی را در تاریخ می‌گذارد، پس `rollback()` دقیقاً همان
     * کاری را می‌کند که لازم است — بدون اینکه مجبور باشیم برچسب را دوباره به
     * version/hash بشکنیم و حدس بزنیم کدام تکه hash بوده.
     *
     * اگر نسخهٔ قبلی‌ای نبود، اشاره‌گر حذف می‌شود: «اشاره‌گر به نسخه‌ای که هرگز
     * فعال نشد» خودش دروغ است و `currentDir()` را طوری نشان می‌دهد که انگار
     * فعال است — و `PluginRouter` و autoloader همان را می‌خوانند.
     */
    private function pointerBack(Plugin $plugin): void
    {
        try {
            if ($this->releases->rollback($plugin->slug) !== null) {
                return;
            }

            $pointer = $this->releases->pointerPath($plugin->slug);

            if (is_file($pointer)) {
                @unlink($pointer);
            }
        } catch (\Throwable $e) {
            // ناتوانی در برگرداندن اشاره‌گر نباید خطای تازه‌ای بسازد؛ نسخهٔ فعال
            // قبلی اگر وجود داشته باشد دست‌نخورده می‌ماند چون تاریخ نگه داشته شده.
            Log::warning('plugin.pointer_rollback_failed', [
                'slug' => $plugin->slug,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public function deactivate(Request $request, Plugin $plugin): JsonResponse
    {
        if ($plugin->isSystem()) {
            return response()->json(['message' => 'پلاگین سیستمی را نمی‌توان غیرفعال کرد.'], 422);
        }

        $plugin->forceFill(['active' => false])->save();

        // ⭐ بدون این، غیرفعال‌کردن هیچ اثری نداشت: رجیستری نتیجهٔ «افزونه‌های
        // فعال» را ۳۰۰ ثانیه کش می‌کرد، پس اعلان‌های افزونه تا ۵ دقیقه بعدِ
        // غیرفعال‌سازی در پنل می‌ماندند.
        

        // ⭐ بدون این، غیرفعال‌کردن هیچ اثری نداشت: `ManifestRegistry` نتیجهٔ
        // «افزونه‌های فعال» را ۳۰۰ ثانیه کش می‌کرد، پس آیتم منو، پرمیشن‌ها
        // و صفحه‌های افزونه تا ۵ دقیقه بعدِ غیرفعال‌سازی در پنل می‌ماندند.
        // گزارشِ کاربر دقیقاً همین بود: «اشتراک من» بعد از غیرفعال‌کردن
        // هنوز در منو ماند.
        

        // K5.0-W — برداشتنِ ثبتِ بارگذار.
        //
        // بدون این، «غیرفعال» فقط یک پرچم در DB بود و کدِ افزونه در همان
        // درخواست و در ادامهٔ عمرِ پروسه همچنان resolve می‌شد. قراردادِ
        // «افزونهٔ غیرفعال هیچ مسیری ندارد» باید روی کلاس‌ها هم برقرار باشد،
        // وگرنه یک افزونهٔ خراب بعد از غیرفعال‌سازی هم می‌تواند
        // `ServiceProvider`اش را اجرا کند.
        $this->lifecycle()->deactivate($plugin->slug);

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

        // L-B3/F0.3 — همان گارد آپلود، ولی با رکورد موجود به‌عنوان مرجع دوم.
        // بدون این، `publisher_key_id` از مانیفست تازه بازنویسی می‌شد و پلاگین
        // سیستمی با کلیدِ هر ناشرِ تأییدشدهٔ دیگری تصاحب می‌شد (RCE ماندگار،
        // چون سیستمی حذف‌ناپذیر است). **پیش از** storePackage تا فایل تازه
        // اصلاً روی دیسک ننشیند (اثبات «فایل جدید نوشته نشد» در تست).
        $pinError = $this->assertSystemPublisherPin($plugin->slug, $check, $plugin->publisher_key_id);
        if ($pinError !== null) {
            Log::warning('plugin.system_publisher_pin_mismatch', [
                'user_id' => $request->user()->id,
                'slug' => $plugin->slug,
                'from' => $oldVersion ?? $plugin->version,
                'to' => $newVersion,
                'key_id' => $check['key_id'] ?? null,
            ]);

            return response()->json(['message' => $pinError, 'code' => 'plugin.system_publisher_mismatch'], 422);
        }

        // B11: ترتیب «جدید → ردیف → حذف قدیم» عمدی است. حذف زودهنگام یعنی اگر
        // نوشتن شکست بخورد (دیسک پر)، رکوردی می‌ماند که `path`اش به فایل
        // ناموجود اشاره می‌کند و پلاگین فعال برای همیشه آجر می‌شود.
        $oldPath = $plugin->path;
        $oldVersion = $plugin->version;
        $path = $this->storePackage($file, $plugin->slug.'_'.Str::random(8).'.zip');
        if ($path === false) {
            return response()->json([
                'message' => 'نسخهٔ جدید روی دیسک ذخیره نشد، پس هیچ تغییری اعمال نشد و نسخهٔ فعلی دست‌نخورده ماند.',
            ], 422);
        }

        // ── K5.11: ارتقا هم باید نسخه را روی دیسک داشته باشد ──────────────
        //
        // تا این‌جا `upgrade()` هیچ‌کدام از `PluginInstaller` / `PluginReleaseManager`
        // / `PluginMigrator` را صدا نمی‌زد. نتیجه یک وضعیت متناقض بود: رکورد DB
        // می‌گفت نسخهٔ ۲.۰.۰ و `path` اش به ZIP تازه، ولی روی دیسک فقط نسخهٔ
        // ۱.۰.۰ استخراج‌شده وجود داشت و ZIP جدید هرگز extract نشده بود. یعنی
        // `currentDir()` نسخهٔ قدیم را برمی‌گرداند، `PluginRouter` و autoloader
        // **کد قدیم** را اجرا می‌کردند، و مدیر فکر می‌کرد ارتقا انجام شده.
        //
        // نصب **پیش از** به‌روزرسانی رکورد انجام می‌شود تا اگر بستهٔ جدید رد شد،
        // رکورد قدیمی دست‌نخورده بماند و نسخهٔ در حال اجرا هم عوض نشود.
        $absolute = Storage::disk('local')->path($path);
        $newChecksum = hash_file('sha256', $absolute) ?: null;
        $installed = $this->installer->install($absolute, $plugin->slug, $newVersion, $newChecksum);

        if (! ($installed['ok'] ?? false)) {
            Storage::disk('local')->delete($path);

            Log::warning('plugin.upgrade_rejected', [
                'user_id' => $request->user()->id,
                'slug' => $plugin->slug,
                'from' => $oldVersion,
                'to' => $newVersion,
                'reason' => $installed['code'] ?? 'install.failed',
            ]);

            return response()->json([
                'message' => 'نسخهٔ جدید روی دیسک نصب نشد، پس ارتقا انجام نشد و نسخهٔ فعلی دست‌نخورده ماند: '.($installed['message'] ?? ''),
                'code' => $installed['code'] ?? 'install.failed',
            ], 422);
        }

        // ── K5.11: اگر افزونه فعال بود، اشاره‌گر و migration هم باید جلو بروند ──
        //
        // تا وقتی این نبود، ارتقا یک افزونهٔ فعال را در وضعیتی می‌گذاشت که DB و
        // دیسک دربارهٔ نسخه اختلاف دارند. و مهم‌تر: اگر migration نسخهٔ جدید
        // شکست بخورد، `pointerBack()` برمی‌گردد و نسخهٔ قبلی — که به‌خاطر
        // content-addressed بودن هنوز روی دیسک است — دوباره فعال می‌شود.
        if ($plugin->active) {
            // K5.2-W — ارتقا هم دروازهٔ مهر دارد. `install()` نسخهٔ جدید را
            // مهر می‌زند، ولی اینجا نسخه‌ای است که **اجرا** می‌شود؛ بدون
            // بازبینی، یک ارتقای دستکاری‌شده بلافاصله اجرا می‌شد.
            $blocked = $this->integrityGate($plugin->slug, $newVersion, $newChecksum, 'ارتقا');

            if ($blocked !== null) {
                return $blocked;
            }

            try {
                $this->releases->activate($plugin->slug, $newVersion, (string) $newChecksum);
            } catch (\Throwable $e) {
                Log::warning('plugin.upgrade_pointer_failed', [
                    'slug' => $plugin->slug,
                    'reason' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'نسخهٔ فعال روی دیسک تعیین نشد، پس ارتقا انجام نشد: '.$e->getMessage(),
                    'code' => 'plugin.pointer_failed',
                ], 422);
            }

            $migrated = $this->migrator->run(
                $this->releases,
                $plugin->slug,
                $this->migrator->declaredTables($manifest)
            );

            if (! $migrated['ok']) {
                $this->pointerBack($plugin);

                return response()->json([
                    'message' => 'ارتقا انجام نشد: '.$migrated['message'],
                    'code' => $migrated['code'],
                ], 422);
            }
        }

        $plugin->forceFill([
            'previous_version' => $oldVersion,
            'version' => $newVersion,
            'name' => $manifest['name'],
            // B35 — ارتقا هم توضیح را تازه می‌کند، وگرنه متن نسخهٔ قبلی می‌ماند
            // و کاربر توضیحی می‌بیند که با کد فعلی هم‌خوان نیست.
            'description' => is_string($manifest['description'] ?? null) && trim((string) $manifest['description']) !== ''
                ? mb_substr(trim((string) $manifest['description']), 0, 400)
                : $plugin->description,
            // فاز ۰: ارتقا هم نمی‌تواند پرچم system را از مانیفست بگیرد.
            'system' => SystemPlugin::allows($plugin->slug),
            'publisher_key_id' => $check['key_id'],
            'publisher_verified' => $check['publisher_verified'],
            'signature_valid' => $check['valid'],
            'review_status' => $this->isReviewExempt($request) ? Plugin::REVIEW_APPROVED : Plugin::REVIEW_PENDING,
            'review_note' => null,
            'submitted_at' => now(),
            'reviewed_at' => $this->isReviewExempt($request) ? now() : null,
            'checksum' => $newChecksum,
            // نسخه عوض شده ⇒ مهر یکپارچگی قبلی بی‌اعتبار است.
            'content_digest' => null,
            'digest_verified_at' => null,
            'manifest' => $manifest,
            'path' => $path,
        ])->save();

        // ارتقا مانیفست را عوض می‌کند ⇒ کشِ رجیستری باید برود، وگرنه منو و
        // صفحه‌های جدیدِ نسخهٔ تازه تا ۳۰۰ ثانیه دیده نمی‌شوند.
        

        if ($oldPath && $oldPath !== $path) {
            // فقط ZIP قدیمی پاک می‌شود، نه پوشهٔ نسخهٔ قبلی. پوشه‌ها content-addressed
            // هستند (`releases/{version}-{hash}/`) و عمداً باقی می‌مانند — همان چیزی
            // که `pointerBack()` روی آن حساب می‌کند. اگر این‌ها هم پاک شوند،
            // rollback دوباره به رشته‌ای بدون آرتیفکت تبدیل می‌شود، یعنی دقیقاً
            // همان چیزی که K5.11 برای رفعش انتخاب شد.
            //
            // نتیجهٔ عمدی: حجم دیسک با تعداد نسخه‌های ارتقایافته رشد می‌کند.
            // `PluginReleaseManager::purge()` برای همین کار وجود دارد و باید
            // با سیاست نگه‌داری صریح (نه «هر چه قدیمی بود حذف کن») صدا زده شود.
            Storage::disk('local')->delete($oldPath);
        }

        if ($plugin->active) {
            // ارتقا مانیفست را عوض می‌کند، پس پرمیشن‌های اعلام‌شدهٔ نسخهٔ جدید
            // باید دوباره ساخته شوند — وگرنه ماژول تازه در ماتریس نقش‌ها غایب می‌ماند.
            $this->ensurePermissions(is_array($plugin->manifest) ? $plugin->manifest : [], $plugin->slug);
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

        // K0.7/K8.3 — yanked غیرقابل حذف است (incident امنیتی): رکورد می‌ماند،
        // نصب تازه بسته است. اول unyank بعد حذف.
        try {
            app(\App\Services\Market\ReviewService::class)->ensureDeletable($plugin);
        } catch (\App\Services\Market\MarketException $e) {
            return response()->json(['message' => 'پلاگین yank شده را نمی‌توان حذف کرد. اول unyank کنید.', 'code' => 'plugin.yanked'], $e->status);
        }

        if ($plugin->path) {
            Storage::disk('local')->delete($plugin->path);
        }
        $plugin->delete();

        // کلید مهر هم باید برود، وگرنه ردیف settings یتیم می‌ماند و اگر
        // افزونه‌ای با همان slug دوباره نصب شود به کلید قبلی می‌خورد.
        $this->trustStore()->forgetSealKeyFor($plugin->slug);

        // K5.12 — رجیستریِ نام‌های مجاز هم پاک می‌شود.
        //
        // لازم است چون رجیستری مرجعِ «مجاز» است و نامِ ثبت‌شده **برای همیشه**
        // مجاز می‌ماند. اگر پاک نشود، یک افزونهٔ حذف‌شده نام‌هایش را در رجیستری
        // رها می‌کند و آن‌ها بی‌صاحب می‌مانند — یعنی قاعده هر بار بازتر می‌شود و
        // هیچ‌کس نمی‌داند چرا.
        //
        // ⚠️ **جدول‌ها عمداً پاک نمی‌شوند.** حذف دادهٔ کاربر کارِ مخربی است و
        // باید مسیرِ تأییدِ خودش را داشته باشد (این endpoint فقط فایل و رکورد
        // را برمی‌دارد). اگر بعداً راه افتاد، باید جدول‌ها را **قبل** از
        // پاک کردنِ رجیستری بیندازد وگرنه افزونه دیگر هرگز نمی‌تواند همان
        // جدول‌ها را بسازد.
        try {
            $this->ddl->unregisterTables($plugin->slug);
        } catch (\Throwable $e) {
            // نباید uninstall را شکست بدهد. ولی باید دیده شود.
            Log::warning('plugin.ddl_registry_cleanup_failed', [
                'slug' => $plugin->slug,
                'reason' => $e->getMessage(),
            ]);
        }

        // ⭐ کشِ رجیستری. بدون این، منو/پرمیشن/صفحه‌های افزونهٔ حذف‌شده تا
        // ۳۰۰ ثانیه در پنل می‌ماندند — و آیتم منو به مسیری لینک می‌کرد که
        // دیگر وجود ندارد.
        

        // ⭐ کشِ رجیستری. بدون این، آیتم منوی افزونهٔ حذف‌شده تا ۳۰۰ ثانیه می‌ماند
        // و به مسیری لینک می‌کند که دیگر وجود ندارد.
        

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

    /**
     * ⭐ I2 — قرارداد کامل، بدون آپلود بسته.
     *
     * تا پیش از این، تنها راهِ گرفتنِ قراردادِ نقاط اتصال، `POST
     * plugins/validate` بود — یعنی هر صفحه‌ای در فرانت که می‌خواست نقطه‌ها را
     * نشان بدهد (راهنمای توسعه‌دهنده، جدول نقاط در تحلیلگر، صفحهٔ خالیِ «بدون
     * بسته») **مجبور** بود یک ZIP بسازد و بفرستد. راه‌حل این بود که فرانت فهرست
     * نقاط را **hardcode** کند — و آن‌وقت دو نسخه از حقیقت داشتیم که فقط با یک
     * `--check` دستیِ زمان build هم‌گام می‌شدند.
     *
     * این مسیر، همان منبعِ حقیقت را بدون بسته می‌دهد:
     *
     *  - `core_contract_version` — نسخه‌ای که این نصب می‌فهمد (I5). فرانت باید
     *    همین را با `since` بسته مقایسه کند.
     *  - `extension_points[]` — برای هر نقطه: `key`, `status`, `openness`,
     *    `openness_why`, `since`, `schema_version`, `schema`, `max`,
     *    `example_ok`, `example_bad`, `deprecated`.
     *  - `channels` — کدام کانال برای هر نقطه زنده است و کدام کلید سطح‌بالا
     *    فقط fallback است (جدول I1-b). فرانت نباید این را حدس بزند.
     *  - `declaration_meta_fields` — `since`/`schema_version`: فیلدهایی که
     *    فرانت باید در فرمِ «نمونهٔ آماده» بگذارد تا بسته از I5 رد شود.
     *  - `forbidden`, `reserved_type_names`, `overridable_interfaces`.
     *  - `package`, `limits`, `grammar` — نام مانیفست، ریشه‌های ZIP، سقف‌های
     *    حجم/تعداد/نسبت فشرده‌سازی، و واژگانِ micro-schema. راهنمای توسعه‌دهنده
     *    این سه را هم لازم دارد؛ بدونشان یا باید عدد را حدس بزند یا راهنما
     *    ناقص شود.
     *
     * سطح دسترسی: `plugins.view` — فقط خواندن قرارداد، نه نصب چیزی.
     */
    public function contract(): JsonResponse
    {
        return response()->json(['data' => $this->contractPayload()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contractPayload(): array
    {
        return [
            'core_contract_version' => PluginPackageContract::CORE_CONTRACT_VERSION,
            'declaration_meta_fields' => PluginPackageContract::DECLARATION_META_FIELDS,
            'forbidden' => PluginPackageContract::FORBIDDEN,
            'reserved_type_names' => PluginPackageContract::RESERVED_TYPE_NAMES,
            'overridable_interfaces' => PluginPackageContract::OVERRIDABLE_INTERFACES,
            'deferred_extension_points' => PluginPackageContract::DEFERRED_EXTENSION_POINTS,
            'permissions_field' => PluginPackageContract::PERMISSIONS_FIELD,
            'permissions_field_legacy' => PluginPackageContract::PERMISSIONS_FIELD_LEGACY,
            'channels' => [
                'primary' => 'panel.extensions',
                'declarable_points' => ManifestRegistry::EXTENSION_POINT_DECLARABLE,
                'legacy_fallback' => [
                    'admin.menu' => 'menu',
                    'admin.plugin_tools' => 'tools',
                    'site.header_widget' => 'widgets.header',
                    'site.footer_widget' => 'widgets.footer',
                    'site.page_type' => 'page_types',
                    PluginPackageContract::PERMISSIONS_FIELD => PluginPackageContract::PERMISSIONS_FIELD_LEGACY,
                ],
                'removed' => ['hooks'],
            ],
            'allowed_paths' => PluginPackageContract::describeAllowedPaths(),
            'extension_points' => PluginPackageContract::extensionPoints(),
            // ECO3 — قرارداد توسعه‌دهنده: کدهای خطا + راهنمای رفع، نمونه‌های
            // عینی request/response، و مستندات API که افزونه‌های فعال خودشان
            // اعلام کرده‌اند (فقط داده، بدون کد افزونه). افزودنی‌اند ⇒ تستِ
            // وجود کلیدها همچنان سبز است.
            'error_code_groups' => PluginPackageContract::ERROR_CODE_GROUPS,
            'error_codes' => PluginPackageContract::ERROR_CODES,
            'api_examples' => PluginPackageContract::API_EXAMPLES,
            'plugin_docs' => ManifestRegistry::developerDocs(),
            // I2 — سه زیرمجموعه‌ای که راهنمای توسعه‌دهنده لازم دارد و بدون
            // آن‌ها نمی‌تواند «ساختار ZIP»، «سقف‌ها» و «واژگان micro-schema» را
            // بنویسد. عمداً از همان ثابت‌های قرارداد خوانده می‌شوند، نه از یک
            // بازنویسی: یک منبعِ حقیقت، و یک endpoint که واقعاً «کل قرارداد» است.
            // افزودنی‌اند ⇒ تستِ `test_the_contract_endpoint_exposes_the_whole_contract`
            // که فقط وجودِ کلیدها را می‌سنجد (assertArrayHasKey) همچنان سبز است.
            'package' => [
                'manifest_name' => PluginPackageContract::MANIFEST,
                'backend_root' => PluginPackageContract::BACKEND_ROOT,
                'frontend_root' => PluginPackageContract::FRONTEND_ROOT,
                'plugin_namespace_prefix' => PluginPackageContract::PLUGIN_NAMESPACE_PREFIX,
            ],
            'limits' => [
                'max_zip_bytes' => PluginPackageContract::MAX_ZIP_BYTES,
                'max_files' => PluginPackageContract::MAX_FILES,
                'max_uncompressed_bytes' => PluginPackageContract::MAX_UNCOMPRESSED_BYTES,
                'max_compression_ratio' => PluginPackageContract::MAX_COMPRESSION_RATIO,
            ],
            'grammar' => [
                'types' => PluginPackageContract::GRAMMAR_TYPES,
                'limits' => PluginPackageContract::GRAMMAR_LIMITS,
            ],
        ];
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

        return ($user->role ?? null) === 'operator';
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
            return $this->withinActiveCap($plugin);
        }

        return $plugin->review_status === Plugin::REVIEW_APPROVED
            && $this->withinActiveCap($plugin);
    }

    /**
     * K0.8 — سقف ۲۰ پلاگین فعال غیرسیستمی.
     * خودِ پلاگینِ در حال فعال‌سازی اگر از قبل فعال است شمرده نمی‌شود
     * (فعال‌سازی مجدد نباید به‌خاطر سقف رد شود).
     */
    private function withinActiveCap(Plugin $plugin): bool
    {
        if ($plugin->active || $plugin->isSystem()) {
            return true;
        }

        $active = Plugin::query()->where('active', true)->get(['slug']);

        $nonSystem = $active->reject(fn (Plugin $p) => $p->isSystem())->count();

        return $nonSystem < Plugin::MAX_ACTIVE_PLUGINS;
    }

    /**
     * L-B3/F0.3 — گارد پین ناشر برای slug سیستمی.
     *
     * - غیرسیستمی ⇒ null (این گارد کاری ندارد؛ چرخهٔ عادی امضا/بازبینی).
     * - سیستمی + امضای نامعتبر ⇒ خطا، **حتی در حالت توسعه‌دهنده**: پلاگین
     *   غیرقابل حذف + بستهٔ بی‌امضا = تصاحب دائمی با یک POST.
     * - سیستمی + پین ثبت‌شده + key_id متفاوت ⇒ خطا (publisher-confusion).
     * - سیستمی + بدون پین ⇒ اولین ناشر معتبر پین می‌شود (TOFU)؛ اگر رکورد
     *   موجود کلید قدیمیِ متفاوتی دارد، آن مغایرت هم خطاست (بک‌فیلِ نصب‌های
     *   قدیمی‌تر از ستون پین).
     *
     * @return string|null پیام خطای فارسی، یا null اگر مجاز است.
     */
    private function assertSystemPublisherPin(string $slug, array $check, ?string $recordKeyId = null): ?string
    {
        if (! SystemPlugin::allows($slug)) {
            return null;
        }

        if (($check['valid'] ?? false) !== true) {
            return 'پلاگین سیستمی فقط با امضای معتبر ناشر قابل نصب یا ارتقاست؛ حالت توسعه‌دهنده برای آن مجاز نیست.';
        }

        // هویت پین: `key_id` ناشر، یا `self:config-key` برای بسته‌ای که با کلید
        // خودِ نصب امضا شده (key_id ندارد ولی امضایش معتبر است — همان جریانی که
        // همهٔ پلاگین‌های محلی امروز دارند). null هویت نیست و پین نمی‌شود،
        // وگرنه هر بستهٔ بعدی هم null می‌بود و پین بی‌اثر می‌شد.
        $keyId = $check['key_id'] ?? null;
        $identity = (is_string($keyId) && $keyId !== '') ? $keyId : 'self:config-key';

        $pinned = SystemPlugin::pinnedFingerprint($slug);
        if ($pinned !== null) {
            return hash_equals($pinned, $identity)
                ? null
                : 'ناشر این نسخه با ناشر ثبت‌شدهٔ پلاگین سیستمی مطابقت ندارد؛ نصب/ارتقا رد شد.';
        }

        $recordIdentity = (is_string($recordKeyId) && $recordKeyId !== '') ? $recordKeyId : 'self:config-key';
        if ($recordKeyId !== null && ! hash_equals($recordIdentity, $identity)) {
            return 'ناشر این نسخه با ناشر ثبت‌شدهٔ پلاگین سیستمی مطابقت ندارد؛ ارتقا رد شد.';
        }

        SystemPlugin::pinFingerprint($slug, $identity);

        return null;
    }

    private function activationBlockReason(Request $request, Plugin $plugin): string
    {
        if (! $this->withinActiveCap($plugin)) {
            return 'سقف پلاگین فعال ('.Plugin::MAX_ACTIVE_PLUGINS.') پر است. ابتدا یک پلاگین را غیرفعال کنید.';
        }

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
        // B35 — توضیح از ستون خوانده می‌شود، ولی اگر خالی باشد از مانیفست
        // برداشته می‌شود. دلیل دوم: افزونه‌هایی که **قبل از** این ستون نصب شدند
        // ستون‌شان `null` است ولی مانیفستشان `description` دارد. بدون این fallback
        // بک‌فیل migration کافی نبود — چون هر نصب بعدی دوباره `null` می‌ماند تا
        // وقتی کسی ارتقا دهد.
        //
        // و اگر هیچ‌کدام نبود، `null` برمی‌گردد نه رشتهٔ خالی: «نویسنده ننوشته»
        // با «نوشته ولی خالی است» فرق دارد و UI باید این دو را جدا رندر کند.
        $description = $plugin->description;

        if (! is_string($description) || trim($description) === '') {
            $manifestDescription = is_array($plugin->manifest)
                ? ($plugin->manifest['description'] ?? null)
                : null;

            $description = is_string($manifestDescription) && trim($manifestDescription) !== ''
                ? $manifestDescription
                : null;
        }

        return array_merge($plugin->toArray(), [
            'trust' => $plugin->trustSummary(),
            'is_system' => $plugin->isSystem(),
            'description' => $description,
            // WF-H17 — مقایسهٔ نسخهٔ نصب‌شده با آخرین نسخهٔ بازار (و اطلاعات
            // انتشار مانیفست). افزودنی است، پس مصرف‌کننده‌های قبلی بی‌اثر
            // می‌مانند. `latest_version` وقتی null است یعنی «اطلاعات انتشار
            // نیست»، نه «نسخهٔ ۰».
            ...$this->updates()->forPlugin($plugin),
        ]);
    }

    /**
     * ساخت رکوردهای spatie برای ماژول‌های اعلام‌شده در هوک permissions
     * مانیفست تا نقش‌ها بتوانند همان‌جا در ماتریس تخصیص داده شوند.
     * ماژول تکراری هسته نادیده گرفته می‌شود.
     */
    private function ensurePermissions(array $manifest, string $slug): void
    {
        $core = array_keys(config('modules', []));
        $created = false;

        foreach (ManifestRegistry::manifestPermissions($manifest, $slug) as $entry) {
            // B16: این چک باید روی نامِ نهایی باشد نه `module` خام. وگرنه
            // افزونه‌ای با `module: "blog"` رد می‌شد در حالی که نامش
            // `plugin:alpha:blog` است و هیچ تداخلی با هسته ندارد.
            if (in_array($entry['module'], $core, true)) {
                continue;
            }
            foreach ($entry['actions'] as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$entry['name']}.{$action}", 'guard_name' => 'web']
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
     * K5.5 تصمیم گرفت dispatcher ساخته نشود، پس «هنوز اجرا نمی‌شوند» وعدهٔ
     * آینده بود و خودش همان دروغی که می‌خواست حذف کند. حالا اعلام هوک یعنی اعلام
     * یک چیز بی‌اثر؛ گفتنش بهتر از سکوت است چون شاید نویسنده به آن تکیه کرده باشد.
     */
    private function hooksDispatchWarning(Plugin $plugin): ?string
    {
        $hooks = is_array($plugin->manifest) ? ($plugin->manifest['hooks'] ?? null) : null;
        if (! is_array($hooks) || $hooks === []) {
            return null;
        }

        return 'فیلد «hooks» در قرارداد پلاگین نیست: نه جایی ثبت می‌شود و نه موتوری '
            .'برای اجرایش وجود دارد. اگر بخشی از این پلاگین به هوک تکیه کند کار نخواهد کرد.';
    }
}
