<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * فاز ۱.۵ — تحلیل بستهٔ پلاگین، **پیش از نصب**.
 *
 * این سرویس عمداً هیچ چیزی استخراج یا نمی‌نویسد. فقط فهرست ZIP را می‌خواند و
 * می‌گوید بسته درست است یا نه، و اگر نیست، مشکل کجاست و به کجا باید برود.
 *
 * چرا مهم: کاربر **کل تجربهٔ اعتبارسنجی** را می‌گیرد بدون اینکه یک خط کد
 * پلاگینی اجرا شود. ریسک صفر.
 *
 * این همان گام ۱ از ۱۱ کنترل استخراج امن فاز ۱ است (قبل از استخراج، نه حین آن).
 * چون `ZipArchive::extractTo()` در برابر مسیرهای `..` محافظت کامل ندارد، بررسی
 * باید روی فهرست باشد.
 */
class PluginPackageValidator
{
    /**
     * نام کلیدهایی که هرگز نباید در آرتیفکت توزیع‌شده باشند.
     *
     * `key_id` عمداً نیست: شناسهٔ عمومی ناشر است و `PluginTrustStore` دقیقاً از
     * `$manifest['publisher']['key_id']` می‌خواندش.
     */
    private const SECRET_KEY_PATTERN = '/(^|_)(public_key|private_key|secret_key|seed|mnemonic|api_key|token|password|salt|passphrase|webhook_secret)$/i';

    /**
     * سقف **پیشنهادی** تعداد فایل‌های مجاز — هشدار است نه خطا.
     *
     * عمداً از `PluginPackageContract::MAX_FILES` کمتر است: آن سقف سختِ امنیتی
     * است، ولی opcache روی چند هزار فایل پر شده و بسته «معتبر ولی کند» نصب
     * می‌شود. پس دو آستانهٔ جدا با دو معنای جدا.
     */
    private const RECOMMENDED_ALLOWED_FILES = 2000;

    public function __construct(private PluginTrustStore $trustStore) {}

    /**
     * @return array{
     *   ok: bool,
     *   severity: 'error'|'warning',
     *   errors: list<array{code: string, message: string, path?: string, suggest?: array}>,
     *   warnings: list<array{code: string, message: string, path?: string, suggest?: array}>,
     *   manifest: ?array,
     *   summary: array
     * }
     */
    /**
     * @param  array{allowUnsigned?: bool}  $options
     *
     * `allowUnsigned` را **هرگز** از بدنهٔ درخواست نگیر. فقط کنترلر باید آن را
     * از وضعیت سمت سرورِ حالت تDevelopه‌دهنده بسازد و بیاورد.
     *
     * این پارامتر لازم است چون بدون آن تحلیلگر خودش یک دروازهٔ امضای دوم و
     * مستقل است: بستهٔ بی‌امضا پیش از آنکه به شاخهٔ حالت تDevelopه‌دهنده در
     * کنترلر برسد، همین‌جا با `signature.invalid` رد می‌شد و دروازهٔ کنترلر
     * تزئینی می‌شد.
     */
    public function analyze(string $zipPath, array $options = []): array
    {
        $allowUnsigned = ($options['allowUnsigned'] ?? false) === true;
        $errors = [];
        $warnings = [];
        $manifest = null;

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $this->result(false, [
                ['code' => 'zip.unreadable', 'message' => 'فایل ZIP قابل باز کردن نیست.'],
            ], [], null, $this->emptySummary());
        }

        try {
            $entries = $this->readEntries($zip);

            foreach ($this->structuralChecks($entries, $zipPath) as $issue) {
                $issue['severity'] === 'error' ? $errors[] = $issue : $warnings[] = $issue;
            }

            $manifest = $this->readManifest($zip);
            if ($manifest === null) {
                $errors[] = [
                    'code' => 'manifest.missing',
                    'message' => 'فایل manifest.json در ریشهٔ ZIP پیدا نشد. این فایل باید دقیقاً در ریشه باشد، نه داخل پوشهٔ Laravel/ یا Next.js/.',
                ];
            } else {
                foreach ($this->manifestChecks($manifest, $allowUnsigned) as $issue) {
                    $issue['severity'] === 'error' ? $errors[] = $issue : $warnings[] = $issue;
                }
            }
        } finally {
            $zip->close();
        }

        return $this->result($errors === [], $errors, $warnings, $manifest, $this->summary($errors, $warnings));
    }

    /**
     * کنترل‌های ساختاری روی فهرست ZIP — ترتیبشان اهمیت دارد.
     *
     * @return list<array{severity: string, code: string, message: string, path?: string, suggest?: array}>
     */
    private function structuralChecks(array $entries, string $zipPath): array
    {
        $issues = [];

        // ۱) حجم ورودی
        $zipBytes = @filesize($zipPath) ?: 0;
        if ($zipBytes > PluginPackageContract::MAX_ZIP_BYTES) {
            $issues[] = [
                'severity' => 'error', 'code' => 'zip.too_large',
                'message' => 'حجم ZIP بیش از '.round(PluginPackageContract::MAX_ZIP_BYTES / 1048576).' مگابایت است.',
            ];
        }

        // ۲) تعداد فایل
        if (count($entries) > PluginPackageContract::MAX_FILES) {
            $issues[] = [
                'severity' => 'error', 'code' => 'zip.too_many_files',
                'message' => 'تعداد فایل‌ها ('.count($entries).') بیش از سقف '.PluginPackageContract::MAX_FILES.' است.',
            ];
        }

        // ۳) zip bomb — حجم باز و نسبت فشرده‌سازی
        $uncompressed = array_sum(array_column($entries, 'size'));
        if ($uncompressed > PluginPackageContract::MAX_UNCOMPRESSED_BYTES) {
            $issues[] = [
                'severity' => 'error', 'code' => 'zip.bomb_size',
                'message' => 'حجم باز شدهٔ فایل‌ها ('.round($uncompressed / 1048576).' مگابایت) بیش از سقت است.',
            ];
        }
        if ($zipBytes > 0 && $uncompressed / max($zipBytes, 1) > PluginPackageContract::MAX_COMPRESSION_RATIO) {
            $issues[] = [
                'severity' => 'error', 'code' => 'zip.bomb_ratio',
                'message' => 'نسبت فشرده‌سازی بیش از حد است — این می‌تواند نشانهٔ zip bomb باشد.',
            ];
        }

        $normalized = [];
        foreach ($entries as $e) {
            $rel = $e['raw'] === '' ? '' : PluginPackageContract::normalizePath($e['raw']);

            // ۴) zip-slip و نام ناامن
            if ($rel === null) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'path.unsafe',
                    'message' => 'مسیر ناامن در بسته: «'.$e['raw'].'». مسیر مطلق، «..» و نام‌های رزروشدهٔ سیستم‌عامل رد می‌شوند.',
                    'path' => $e['raw'],
                ];

                continue;
            }

            // ۵) symlink / device / FIFO / socket — همان گیت installer، ولی زودتر.
            if ($e['is_link']) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'path.symlink',
                    'message' => 'این بسته شامل symlink است: «'.$rel.'». برای جلوگیری از حملهٔ symlink-to-/etc/passwd رد می‌شود.',
                    'path' => $rel,
                ];

                continue;
            }
            if (($e['is_special'] ?? false)) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'path.special_entry',
                    'message' => 'ورودیِ نوع‌ویژه (FIFO/socket/device) در بسته: «'.$rel.'». فقط فایل و پوشهٔ معمولی پذیرفته است.',
                    'path' => $rel,
                ];

                continue;
            }

            // ۶) مسیر تکراری بعد از نرمال‌سازی
            $normalized[] = $rel;
        }

        foreach (PluginPackageContract::findDuplicates($normalized) as $dup) {
            $issues[] = [
                'severity' => 'error', 'code' => 'path.duplicate',
                'message' => 'مسیر «'.$dup.'» بیش از یک بار در بسته آمده است.',
                'path' => $dup,
            ];
        }

        foreach (PluginPackageContract::findCaseCollisions($normalized) as $c) {
            $issues[] = [
                'severity' => 'error', 'code' => 'path.case_collision',
                'message' => '«'.$c['a'].'» و «'.$c['b'].'» روی سیستم‌فایل case-insensitive یکی می‌شوند و یکی دیگری را بازنویسی می‌کند.',
                'path' => $c['b'],
            ];
        }

        // ۷) مسیرهای خارج از بستهٔ مجاز (deny-by-default)
        foreach ($normalized as $rel) {
            if (PluginPackageContract::isAllowedPath($rel)) {
                continue;
            }

            $suggest = PluginPackageContract::suggestLocation($rel);
            $issues[] = [
                'severity' => 'error',
                'code' => 'path.not_allowed',
                'message' => 'فایل «'.$rel.'» در بستهٔ مجاز نیست.'
                    .($suggest ? ' با توجه به ساختار این فایل، احتمالاً باید در «'.$suggest['path'].'» قرار بگیرد.' : ''),
                'path' => $rel,
                'suggest' => $suggest,
                'guide' => 'plugins.contract',
            ];
        }

        // ۸) manifest باید در ریشه باشد (K1.5.2)
        foreach ($normalized as $rel) {
            if (basename($rel) === PluginPackageContract::MANIFEST && $rel !== PluginPackageContract::MANIFEST) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'manifest.misplaced',
                    'message' => 'manifest.json باید دقیقاً در ریشهٔ ZIP باشد، ولی در «'.$rel.'» قرار دارد. اگر بین Laravel/ و Next.js/ تقسیم شده باشد، امضا معتبر نمی‌ماند.',
                    'path' => $rel,
                    'suggest' => ['path' => PluginPackageContract::MANIFEST, 'reason' => 'مانیفست هویت و امضای بسته است و یکپارچه می‌ماند.'],
                    'guide' => 'plugins.contract',
                ];
            }
        }

        // ۹) سقف حجم کل فایل‌های مجاز
        $allowed = array_filter($normalized, fn ($p) => PluginPackageContract::isAllowedPath($p));
        if (count($allowed) > self::RECOMMENDED_ALLOWED_FILES) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'package.large',
                'message' => 'بسته '.count($allowed).' فایل مجاز دارد. سقف پیشنهادی '.self::RECOMMENDED_ALLOWED_FILES.' فایل است تا opcache سرریز نکند.',
            ];
        }

        return $issues;
    }

    /**
     * بررسی‌های محتوایی مانیفست.
     *
     * @return list<array{severity: string, code: string, message: string, path?: string, suggest?: array}>
     */
    private function manifestChecks(array $manifest, bool $allowUnsigned = false): array
    {
        $issues = [];
        foreach (['slug', 'name'] as $required) {
            if (empty($manifest[$required])) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'manifest.missing_field',
                    'message' => 'فیلد «'.$required.'» در مانیفست الزامی است.',
                ];
            }
        }

        $slug = is_string($manifest['slug'] ?? null) ? $manifest['slug'] : null;

        // اسلاگ باید انگلیسی باشد. پایهٔ کاربر ایرانی است، پس «پنل» محتمل است نه
        // نظری — و `Str::slug('پنل')` رشتهٔ خالی می‌دهد که در فاز ۱ به prefix
        // بی‌نام تبدیل می‌شود. خطا است نه هشدار، چون نتیجه‌اش نصب شکسته است.
        if ($slug !== null && $slug !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $slug) !== 1) {
            $issues[] = [
                'severity' => 'error', 'code' => 'manifest.bad_slug',
                'message' => 'اسلاگ «'.$slug.'» معتبر نیست. slug باید انگلیسی (لاتین) نوشته شود: با حرف یا رقم کوچک انگلیسی شروع شود و بعد فقط a-z، 0-9، نقطه، خط تیره و زیرخل بماند (۲ تا ۴۰ نویسه).',
                'pattern' => '^[a-z0-9][a-z0-9._-]{1,39}$',
                'guide' => 'plugins.contract',
            ];
        }

        // `system` از فاز ۰ حذف شد — اگر هنوز در مانیفست باشد، نویسنده راهنمای
        // قدیمی را دنبال کرده و انتظار دارد پلاگینش غیرقابل حذف شود.
        if (array_key_exists('system', $manifest)) {
            $issues[] = [
                'severity' => 'error', 'code' => 'manifest.system_forbidden',
                'message' => 'فیلد «system» در مانیفست پذیرفته نمی‌شود. پلاگین سیستمی فقط از راه allowlist نصب می‌شود، نه از مانیفست — وگرنه هر کاربری می‌توانست پلاگینی بسازد که هرگز حذف نشود.',
                'guide' => 'plugins.contract',
            ];
        }

        // اعلام جدول‌های دیتابیس — K5.6. اختیاری، ولی اگر آمده باشد باید کامل
        // درست باشد: prefix جدول فقط وقتی «محدودیت» است که فهرستِ اعلام‌شده
        // معتبر باشد، وگرنه راستی‌آزماییِ migration چیزی برای مقایسه ندارد.
        if ($slug !== null && $slug !== '') {
            foreach (PluginDbContract::check($manifest['db'] ?? null, $slug) as $issue) {
                $issues[] = $issue;
            }
        }

        // نشت کلید — بازگشتی. مانیفست تودرتو است، پس `{"publisher":{"private_key":…}}`
        // هم باید گرفته شود؛ بررسی سطح‌بالا این را رد می‌کرد.
        //
        // کلید عمومی هم نباید داخل آرتیفکت باشد: هسته باید آن را از trust store
        // بگیرد نه از محموله‌ای که خودِ پلاگین کنترلش می‌کند.
        foreach ($this->findSecretKeys($manifest) as $leaked) {
            $issues[] = [
                'severity' => 'error', 'code' => 'manifest.leaks_key',
                'message' => 'کلید رمزنگاری در «'.$leaked.'» داخل بسته است. کلید خصوصی هرگز نباید در آرتیفکت توزیع‌شده قرار بگیرد، و کلید عمومی باید از trust store خوانده شود نه از مانیفست.',
                'path' => $leaked,
                'guide' => 'plugins.signature',
            ];
        }

        // نقاط اتصال افزونه — فهرست بسته (K1.5.6) + اعتبارسنجی هر اعلان
        $issues = array_merge($issues, $this->extensionPointChecks($manifest, $slug));

        // امضا — جدا از تأیید.
        //
        // از trust store استفاده می‌شود نه verifier خام: اگر ناشر `key_id` اعلام
        // کرده باشد، کلید عمومی‌اش در `publisher_keys` است نه در config. با فراخوانی
        // مستقیم verifier، هر بستهٔ چندناشره‌ای که key_id دارد رد می‌شد — در حالی که
        // امضایش کاملاً معتبر است.
        $check = $this->trustStore->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            // در حالت توسعه‌دهنده، نبودِ امضا **خطا نیست** — فقط هشدار است،
            // چون کاربر آگاهانه در حال تست است. ولی پیام باید صریح بگوید
            // نصب بدون امضا انجام می‌شود، وگرنه کاربر فکر می‌کند بسته
            // معتبر است.
            $issues[] = [
                'severity' => $allowUnsigned ? 'warning' : 'error',
                'code' => $allowUnsigned ? 'devmode.unsigned_allowed' : 'signature.invalid',
                'message' => $allowUnsigned
                    ? 'حالت توسعه‌دهنده باز است، پس نبودِ امضا مانع نصب نیست. این پلاگین «تأییدنشده» ثبت می‌شود و برای انتشار عمومی به امضا نیاز دارد.'
                    : $check['reason'],
                'guide' => 'plugins.signature',
            ];
        } elseif (! $check['publisher_verified']) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'signature.anonymous_publisher',
                'message' => 'امضا معتبر است ولی ناشر اعلام نشده (بدون publisher.key_id). یعنی برای ما قابل انتساب به هیچ ناشری نیست. این با «تأییدشده» فرق دارد.',
            ];
        }

        // `hooks` در قرارداد نیست و بی‌اثر است؛ سکوت بدتر است چون نویسنده ممکن است به آن تکیه کند.
        if (! empty($manifest['hooks'])) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'hooks.not_yet_dispatched',
                'message' => 'مانیفست فیلد «hooks» را اعلام می‌کند ولی این فیلد در قرارداد پلاگین نیست: نه جایی ثبت می‌شود و نه موتوری برای اجرایش وجود دارد. اگر پلاگین به هوک تکیه کند، آن بخش کار نمی‌کند؛ فیلد را از مانیفست حذف کنید.',
            ];
        }

        // ⭐ I1.3 — کانال legacy پرمیشن. رجیستری هنوز آن را می‌خواند (نصب‌های موجود
// نباید بشکنند)، ولی سکوت دربارهٔش همان دروغی است که `hooks` بود: نویسنده فکر
// می‌کند یک مسیر رسمی را نوشته و بعداً غافلگیر می‌شود.
//
// فقط وقتی `access` غایب باشد — اگر نویسنده هر دو را نوشته باشد، کانال زنده
// برنده است و گفتنِ «کانال قدیمی را هم نوشته‌ای» هشدارِ بی‌مورد است.
if (! empty($manifest[PluginPackageContract::PERMISSIONS_FIELD_LEGACY])
        && ! array_key_exists(PluginPackageContract::PERMISSIONS_FIELD, $manifest)) {
        $issues[] = [
            'severity' => 'warning',
            'code' => 'access.legacy_permissions_field',
            'message' => 'مانیفست فیلد «'.PluginPackageContract::PERMISSIONS_FIELD_LEGACY.'» را اعلام می‌کند که کانال legacy پرمیشن است. '
                .'کانال زنده «'.PluginPackageContract::PERMISSIONS_FIELD.'» است. هر دو کار می‌کنند و '
                .'«'.PluginPackageContract::PERMISSIONS_FIELD.'» برنده است، ولی کلید قدیمی در نسخهٔ بعدی '
                .'حذف می‌شود؛ همین حالا مهاجرت کن تا بسته‌ات یک‌باره نشکند.',
            'guide' => 'plugins.extension-points',
        ];
    }

    // K7.18 — محتوای اعلانی صفحهٔ پنل. fail-closed در **نصب**: صفحه‌ای که
        // `type` غیرمجاز یا `data` بد دارد باید همین‌جا رد شود، نه هفتهٔ بعد
        // هنگام بازدیدِ ادمین که رندرر آن را خالی می‌بیند و منشأ سکوت نامرئی است.
        //
        // `admin.pages` هم خوانده می‌شود (`ManifestRegistry.php:508-510`) ولی
        // `pages` کانال رسمی است و همان است که نرمالایزر می‌خواند.
        foreach (PluginPageContract::check($manifest['pages'] ?? null) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * نقاط اتصال: وجود، شکل، و اعتبارسنجی محتوای هر اعلان.
     *
     * کلاسِ اعلان‌ها **فقط آبجکت** پذیرفته می‌شود — همین‌جا و در همان شکلی که
     * `extensionPointsOf()` می‌خواند. نسخهٔ قبلی رشتهٔ ساده را اینجا می‌پذیرفت ولی
     * آنجا نامرئی بود، یعنی اختلاف کاذب می‌ساخت.
     *
     * @return list<array<string, mixed>>
     */
    private function extensionPointChecks(array $manifest, ?string $slug): array
    {
        $issues = [];
        $extensions = $manifest['panel']['extensions'] ?? [];
        if (! is_array($extensions)) {
            return [[
                'severity' => 'error', 'code' => 'panel.bad_point',
                'message' => '«panel.extensions» باید آرایه‌ای از اعلان‌ها باشد.',
                'guide' => 'plugins.extension-points',
            ]];
        }

        $perPoint = [];

        foreach ($extensions as $i => $entry) {
            if (! is_array($entry)) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'panel.bad_point',
                    'message' => 'هر نقطهٔ اتصال باید آبجکت باشد: {"point": "...", …}. رشتهٔ ساده پذیرفته نمی‌شود.',
                    'path' => 'panel.extensions['.$i.']',
                    'guide' => 'plugins.extension-points',
                ];

                continue;
            }

            $key = $entry['point'] ?? null;
            if (! is_string($key) || $key === '') {
                $issues[] = [
                    'severity' => 'error', 'code' => 'panel.bad_point',
                    'message' => 'هر نقطهٔ اتصال باید شکل {"point": "..."} داشته باشد.',
                    'path' => 'panel.extensions['.$i.']',
                    'guide' => 'plugins.extension-points',
                ];

                continue;
            }

            if (isset(PluginPackageContract::DEFERRED_EXTENSION_POINTS[$key])) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'panel.deferred_point',
                    'message' => 'نقطهٔ اتصال «'.$key.'» در نسخهٔ ۱ پشتیبانی نمی‌شود: '.PluginPackageContract::DEFERRED_EXTENSION_POINTS[$key],
                    'path' => 'panel.extensions['.$i.']',
                    'guide' => 'plugins.extension-points',
                ];

                continue;
            }

            if (! PluginPackageContract::isKnownExtensionPoint($key)) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'panel.unknown_point',
                    'message' => 'نقطهٔ اتصال ناشناخته: «'.$key.'». فهرست معتبر: '.implode('، ', array_keys(PluginPackageContract::EXTENSION_POINTS)),
                    'path' => 'panel.extensions['.$i.']',
                    'guide' => 'plugins.extension-points',
                ];

                continue;
            }

            if ((PluginPackageContract::EXTENSION_POINTS[$key]['status'] ?? '') === 'deferred') {
                $issues[] = [
                    'severity' => 'error', 'code' => 'panel.deferred_point',
                    'message' => 'نقطهٔ اتصال «'.$key.'» هنوز deferred است: در runtime چیزی را رندر نمی‌کند. اعلامش فقط بسته را سبز می‌کند بدون هیچ اثری.',
                    'path' => 'panel.extensions['.$i.']',
                    'guide' => 'plugins.extension-points',
                ];

                continue;
            }

            $perPoint[$key] = ($perPoint[$key] ?? 0) + 1;

            $decl = $entry;
            unset($decl['point']);

            foreach (PluginPackageContract::validateDeclaration($key, $decl) as $issue) {
                $issues[] = array_merge($issue, ['path' => 'panel.extensions['.$i.'].'.$issue['path']]);
            }

            // تطبیق prefix با اسلاگ واقعی — `validateDeclaration` خالص است و
            // مانیفست را نمی‌بیند، پس فقط شکل prefix را می‌سنجد.
            if ($slug !== null && $slug !== '') {
                $issues = array_merge($issues, $this->slugBoundChecks($key, $i, $decl, $slug));
            }
        }

        // سقف تعداد اعلان هر نقطه — این شمارش بین‌اعلانی است، پس کارِ همین‌جاست
        // نه `validateDeclaration`.
        foreach ($perPoint as $key => $count) {
            $max = (int) (PluginPackageContract::EXTENSION_POINTS[$key]['max']['declarations'] ?? 10);
            if ($count > $max) {
                $issues[] = [
                    'severity' => 'error', 'code' => $key.'.too_many_declarations',
                    'path' => 'panel.extensions',
                    'message' => 'نقطهٔ «'.$key.'» '.$count.' بار اعلام شده و سقف '.$max.' اعلان است.',
                    'guide' => 'plugins.extension-points',
                ];
            }
        }

        return $issues;
    }

    /**
     * قواعدی که به اسلاگ واقعی نیاز دارند و از `validateDeclaration` بیرون‌اند.
     *
     * @param  array<string, mixed>  $decl
     * @return list<array<string, mixed>>
     */
    private function slugBoundChecks(string $point, int $index, array $decl, string $slug): array
    {
        $out = [];

        if (isset($decl['permission']) && is_string($decl['permission'])
            && ! str_starts_with($decl['permission'], 'plugin:'.$slug.':')) {
            $out[] = [
                'severity' => 'error', 'code' => $point.'.bad_permission',
                'path' => 'panel.extensions['.$index.'].permission',
                'message' => 'پرمیشن «'.$decl['permission'].'» باید با «plugin:'.$slug.':» شروع شود، وگرنه دو پلاگین با ماژول هم‌نام پرمیشن مشترک می‌گیرند.',
                'pattern' => 'plugin:'.$slug.':…',
                'guide' => 'plugins.extension-points',
            ];
        }

        if (isset($decl['entity']) && is_string($decl['entity'])
            && ! str_starts_with($decl['entity'], $slug.'_')) {
            $out[] = [
                'severity' => 'error', 'code' => $point.'.bad_entity',
                'path' => 'panel.extensions['.$index.'].entity',
                'message' => 'نام موجودیت «'.$decl['entity'].'» باید دقیقاً با «'.$slug.'_» شروع شود.',
                'pattern' => $slug.'_{x}',
                'guide' => 'plugins.extension-points',
            ];
        }

        return $out;
    }

    /**
     * اسکن بازگشتی نشت کلید در کل مانیفست.
     *
     * @return list<string> مسیر نقطه‌دار کلیدهای لو رفته
     */
    private function findSecretKeys(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            $key = (string) $key;
            $here = $path === '' ? $key : $path.'.'.$key;

            if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $found[] = $here;
            }

            foreach ($this->findSecretKeys($value, $here) as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function extensionPointsOf(array $arr): array
    {
        $points = [];
        foreach ((array) ($arr['panel']['extensions'] ?? []) as $p) {
            // فقط آبجکت — همین شکلی که `extensionPointChecks()` می‌پذیرد.
            if (is_array($p) && is_string($p['point'] ?? null) && $p['point'] !== '') {
                $points[] = $p['point'];
            }
        }
        $points = array_values(array_unique($points));
        sort($points);

        return $points;
    }

    /**
     * خواندن فهرست ورودی‌ها با تشخیص نوع فایل از بیت‌های external_attributes.
     *
     * L-B10 — فقط S_IFLNK گرفته می‌شد؛ FIFO/socket/char/block تأیید می‌گرفتند
     * و بعد installer ردشان می‌کرد (سبزِ کاذبِ validator). حالا هر چیز
     * غیر از فایل/دایرکتوری معمولی همان‌جا خطاست تا دو لایه یکی بگویند.
     *
     * @return list<array{raw: string, size: int, is_link: bool, is_special: bool}>
     */
    private function readEntries(ZipArchive $zip): array
    {
        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $attrs = $zip->getExternalAttributesIndex($i, $opsys, $attr);
            // ۴ بیت بالا = نوع فایل در UNIX
            $type = ($attr >> 16) & 0xF000;

            $out[] = [
                'raw' => (string) $stat['name'],
                'size' => (int) $stat['size'],
                'is_link' => $type === 0xA000, // S_IFLNK
                // S_IFREG=0x8000، S_IFDIR=0x4000، صفر=نامشخص (سازگار عقب‌رو).
                'is_special' => ! in_array($type, [0x0000, 0x8000, 0x4000], true),
            ];
        }

        return $out;
    }

    /** مانیفست فقط از ریشه خوانده می‌شود (K1.5.2). */
    private function readManifest(ZipArchive $zip): ?array
    {
        // `locateName` حتی با `FL_NODIR` بر اساس basename جست‌وجو می‌کند، پس
        // `evil/manifest.json` هم پیدا می‌شود و یک بسته می‌تواند دو مانیفست
        // همسان در ریشه و اعماق داشته باشد. تطبیق دقیق نام یعنی فقط ریشه.
        $index = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if ($zip->getNameIndex($i) === PluginPackageContract::MANIFEST) {
                $index = $i;
                break;
            }
        }

        if ($index === false) {
            return null;
        }
        $decoded = json_decode((string) $zip->getFromIndex($index), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{errors: int, warnings: int, slug: ?string, version: ?string, extension_points: list<string>} */
    private function emptySummary(): array
    {
        return [
            'errors' => 0,
            'warnings' => 0,
            'slug' => null,
            'version' => null,
            'extension_points' => [],
        ];
    }

    private function summary(array $errors, array $warnings): array
    {
        return array_merge($this->emptySummary(), [
            'errors' => count($errors),
            'warnings' => count($warnings),
        ]);
    }

    private function result(bool $ok, array $errors, array $warnings, ?array $manifest, array $summary): array
    {
        // روی ZIP باز‌نشدنی این مسیر با `summary` خالی می‌آید؛ بدون این merge
        // هر `$summary['slug']` یک undefined-index بود.
        $summary = array_merge($this->emptySummary(), $summary);

        if ($manifest !== null) {
            $summary['slug'] = is_string($manifest['slug'] ?? null) ? $manifest['slug'] : null;
            $summary['version'] = is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
            $summary['extension_points'] = $this->extensionPointsOf($manifest);
        }

        if ($ok) {
            Log::info('plugin.package_validated', ['slug' => $summary['slug'], 'warnings' => count($warnings)]);
        } else {
            Log::info('plugin.package_rejected', [
                'slug' => $summary['slug'],
                'errors' => array_column($errors, 'code'),
            ]);
        }

        return [
            'ok' => $ok,
            'severity' => $ok ? ($warnings === [] ? 'ok' : 'warning') : 'error',
            'errors' => $errors,
            'warnings' => $warnings,
            'manifest' => $manifest,
            'summary' => $summary,
            'contract' => [
                'allowed' => PluginPackageContract::describeAllowedPaths(),
                'extension_points' => PluginPackageContract::extensionPoints(),
            ],
        ];
    }
}
