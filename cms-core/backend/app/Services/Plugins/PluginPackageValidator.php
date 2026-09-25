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
    public function analyze(string $zipPath): array
    {
        $errors = [];
        $warnings = [];
        $manifest = null;

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $this->result(false, [
                ['code' => 'zip.unreadable', 'message' => 'فایل ZIP قابل باز کردن نیست.'],
            ], [], null, []);
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
                foreach ($this->manifestChecks($manifest, $zipPath, $entries) as $issue) {
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

            // ۵) symlink / device / FIFO
            if ($e['is_link']) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'path.symlink',
                    'message' => 'این بسته شامل symlink است: «'.$rel.'». برای جلوگیری از حملهٔ symlink-to-/etc/passwd رد می‌شود.',
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
        if (count($allowed) > 2000) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'package.large',
                'message' => 'بسته '.count($allowed).' فایل مجاز دارد. سقف پیشنهادی ۲۰۰۰ فایل است تا opcache سرریز نکند.',
            ];
        }

        return $issues;
    }

    /**
     * بررسی‌های محتوایی مانیفست.
     *
     * @return list<array{severity: string, code: string, message: string, path?: string, suggest?: array}>
     */
    private function manifestChecks(array $manifest, ?string $zipPath = null, array $entries = []): array
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

        // `system` از فاز ۰ حذف شد — اگر هنوز در مانیفست باشد، نویسنده راهنمای
        // قدیمی را دنبال کرده و انتظار دارد پلاگینش غیرقابل حذف شود.
        if (array_key_exists('system', $manifest)) {
            $issues[] = [
                'severity' => 'error', 'code' => 'manifest.system_forbidden',
                'message' => 'فیلد «system» در مانیفست پذیرفته نمی‌شود. پلاگین سیستمی فقط از راه allowlist نصب می‌شود، نه از مانیفست — وگرنه هر کاربری می‌توانست پلاگینی بسازد که هرگز حذف نشود.',
                'guide' => 'plugins.contract',
            ];
        }

        // نشت کلید — هم کلید خصوصی و هم کلید عمومی.
        //
        // کلید عمومی هم نباید داخل آرتیفکت باشد: هسته باید آن را از trust store
        // بگیرد نه از محموله‌ای که خودِ پلاگین کنترلش می‌کند.
        foreach (array_keys($manifest) as $key) {
            $k = (string) $key;
            if (preg_match('/(^|_)(public_key|private_key|secret_key|seed|mnemonic)$/i', $k) === 1) {
                $issues[] = [
                    'severity' => 'error', 'code' => 'manifest.leaks_key',
                    'message' => 'کلید رمزنگاری («'.$k.'») داخل بسته است. کلید خصوصی هرگز نباید در آرتیفکت توزیع‌شده قرار بگیرد، و کلید عمومی باید از trust store خوانده شود نه از مانیفست.',
                    'guide' => 'plugins.signature',
                ];
            }
        }

        // نقاط اتصال افزونه — فهرست بسته (K1.5.6)
        $panel = $manifest['panel']['extensions'] ?? [];
        if (is_array($panel)) {
            foreach ($panel as $point) {
                $key = is_array($point) ? ($point['point'] ?? null) : $point;
                if (! is_string($key) || $key === '') {
                    $issues[] = [
                        'severity' => 'error', 'code' => 'panel.bad_point',
                        'message' => 'هر نقطهٔ اتصال باید شکل {"point": "..."} داشته باشد.',
                    ];
                    continue;
                }
                if (isset(PluginPackageContract::DEFERRED_EXTENSION_POINTS[$key])) {
                    $issues[] = [
                        'severity' => 'error', 'code' => 'panel.deferred_point',
                        'message' => 'نقطهٔ اتصال «'.$key.'» در نسخهٔ ۱ پشتیبانی نمی‌شود: '.PluginPackageContract::DEFERRED_EXTENSION_POINTS[$key],
                        'guide' => 'plugins.extension-points',
                    ];
                } elseif (! PluginPackageContract::isKnownExtensionPoint($key)) {
                    $issues[] = [
                        'severity' => 'error', 'code' => 'panel.unknown_point',
                        'message' => 'نقطهٔ اتصال ناشناخته: «'.$key.'». فهرست معتبر: '.implode('، ', array_keys(PluginPackageContract::EXTENSION_POINTS)),
                        'guide' => 'plugins.extension-points',
                    ];
                }
            }
        }

        // امضا — جدا از تأیید.
        //
        // از trust store استفاده می‌شود نه verifier خام: اگر ناشر `key_id` اعلام
        // کرده باشد، کلید عمومی‌اش در `publisher_keys` است نه در config. با فراخوانی
        // مستقیم verifier، هر بستهٔ چندناشره‌ای که key_id دارد رد می‌شد — در حالی که
        // امضایش کاملاً معتبر است.
        $check = $this->trustStore->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            $issues[] = [
                'severity' => 'error', 'code' => 'signature.invalid',
                'message' => $check['reason'],
                'guide' => 'plugins.signature',
            ];
        } elseif (! $check['publisher_verified']) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'signature.anonymous_publisher',
                'message' => 'امضا معتبر است ولی ناشر اعلام نشده (بدون publisher.key_id). یعنی برای ما قابل انتساب به هیچ ناشری نیست. این با «تأییدشده» فرق دارد.',
            ];
        }

        // `hooks` وعدهٔ اجرا می‌دهد ولی dispatcher وجود ندارد (فاز ۱ هنوز نیامده)
        if (! empty($manifest['hooks'])) {
            $issues[] = [
                'severity' => 'warning', 'code' => 'hooks.not_yet_dispatched',
                'message' => 'مانیفست هوک اعلام می‌کند ولی موتور dispatch هوک هنوز پیاده نشده است. تا آن زمان هوک‌ها اجرا نمی‌شوند.',
            ];
        }

        return array_merge($issues, $this->panelFileChecks($manifest, $zipPath ?? null, $entries ?? []));
    }

    /**
     * سازگاری `Next.js/panel.json` با مانیفست امضاشده.
     *
     * مانیفست مرجع است چون امضا دارد. اگر `panel.json` نقطهٔ اتصالی اعلام کند که
     * در مانیفست نیست، یعنی یکی از این دو امضا نشده است — و هسته نمی‌داند کدام را
     * باور کند. این همان driftای است که باعث می‌شود راهنما بعداً دروغ بگوید.
     */
    private function panelFileChecks(array $manifest, ?string $zipPath, array $entries): array
    {
        $issues = [];
        $hasPanelFile = false;

        foreach ($entries as $e) {
            if (PluginPackageContract::normalizePath($e['raw']) === PluginPackageContract::FRONTEND_ROOT.'/panel.json') {
                $hasPanelFile = true;
                break;
            }
        }

        if (! $hasPanelFile || $zipPath === null) {
            return $issues;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $issues;
        }
        // `getFromName` بهتر از `locateName(..., FL_NODIR)` است: `FL_NODIR` روی
        // نام‌های دارای نقطه (`Next.js/panel.json`) قابل اتکا نیست.
        $raw = $zip->getFromName(PluginPackageContract::FRONTEND_ROOT.'/panel.json');
        $zip->close();

        if ($raw === false) {
            $issues[] = [
                'severity' => 'error', 'code' => 'panel.unreadable',
                'message' => 'فایل '.PluginPackageContract::FRONTEND_ROOT.'/panel.json خوانده نشد یا JSON معتبر نیست.',
                'path' => PluginPackageContract::FRONTEND_ROOT.'/panel.json',
            ];

            return $issues;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $issues[] = [
                'severity' => 'error', 'code' => 'panel.unreadable',
                'message' => 'فایل '.PluginPackageContract::FRONTEND_ROOT.'/panel.json خوانده نشد یا JSON معتبر نیست.',
                'path' => PluginPackageContract::FRONTEND_ROOT.'/panel.json',
            ];

            return $issues;
        }

        $manifestPoints = $this->extensionPointsOf($manifest);
        $panelPoints = $this->extensionPointsOf($decoded);

        sort($manifestPoints);
        sort($panelPoints);

        if ($manifestPoints !== $panelPoints) {
            $issues[] = [
                'severity' => 'error', 'code' => 'panel.diverges_from_manifest',
                'message' => 'نقاط اتصال در '.PluginPackageContract::FRONTEND_ROOT.'/panel.json با مانیفست یکسان نیستند. مانیفست امضا شده و مرجع است؛ یا panel.json را مطابق آن اصلاح کنید یا حذفش کنید.'
                    .' (مانیفست: '.(count($manifestPoints) ? implode('، ', $manifestPoints) : '—')
                    .' / panel.json: '.(count($panelPoints) ? implode('، ', $panelPoints) : '—').')',
                'path' => PluginPackageContract::FRONTEND_ROOT.'/panel.json',
                'guide' => 'plugins.extension-points',
            ];
        }

        return $issues;
    }

    /** @return list<string> */
    private function extensionPointsOf(array $arr): array
    {
        $points = [];
        foreach ((array) ($arr['panel']['extensions'] ?? $arr['extensions'] ?? []) as $p) {
            if (is_array($p) && isset($p['point'])) {
                $points[] = (string) $p['point'];
            }
        }
        $points = array_values(array_unique($points));
        sort($points);

        return $points;
    }

    /**
     * خواندن فهرست ورودی‌ها با تشخیص symlink از بیت‌های external_attributes.
     *
     * @return list<array{raw: string, size: int, is_link: bool}>
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
            ];
        }

        return $out;
    }

    /** مانیفست فقط از ریشه خوانده می‌شود (K1.5.2). */
    private function readManifest(ZipArchive $zip): ?array
    {
        $index = $zip->locateName(PluginPackageContract::MANIFEST, ZipArchive::FL_NODIR);
        if ($index === false) {
            return null;
        }
        $decoded = json_decode((string) $zip->getFromIndex($index), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function summary(array $errors, array $warnings): array
    {
        return [
            'errors' => count($errors),
            'warnings' => count($warnings),
            'slug' => null,
            'version' => null,
            'extension_points' => [],
        ];
    }

    private function result(bool $ok, array $errors, array $warnings, ?array $manifest, array $summary): array
    {
        if ($manifest !== null) {
            $summary['slug'] = $manifest['slug'] ?? null;
            $summary['version'] = $manifest['version'] ?? null;
            $points = [];
            foreach ((array) ($manifest['panel']['extensions'] ?? []) as $p) {
                if (is_array($p) && isset($p['point'])) {
                    $points[] = $p['point'];
                }
            }
            $summary['extension_points'] = $points;
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
