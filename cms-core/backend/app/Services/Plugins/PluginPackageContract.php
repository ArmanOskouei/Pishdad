<?php

namespace App\Services\Plugins;

use Illuminate\Support\Str;

/**
 * فاز ۱.۵ — قرارداد بستهٔ پلاگین، به‌صورت کد.
 *
 * چرا کد و نه فایل JSON/جدول دیتابیس؟ چون این «قرارداد» است نه «واقعیت هسته».
 * از فایل‌سیستم نمی‌شود فهمید یک فایل `.php` کنترلر است یا سرویس یا job.
 * همچنین جدول نگاشت مثبت با هر تغییر ساختار هسته drift می‌کند، پس به‌جای آن
 * **deny-by-default** استفاده می‌شود: بستهٔ کمینهٔ مقصدهای مجاز، و هر چیز
 * دیگری رد.
 *
 * پیامد مهم: **تغییر ساختار پوشه‌های هسته اصلاً مسئلهٔ این validator نیست.**
 * دنیای validator فقط پیشوندهای داخل پلاگین را می‌شناسد. سازگاری با نسخهٔ
 * هسته کارِ `requires.core` است، نه کارِ این کلاس.
 */
final class PluginPackageContract
{
    /** فایل هویت و امضا. حتماً در ریشهٔ ZIP. */
    public const MANIFEST = 'manifest.json';

    /** پیشوندهای ریشهٔ بستهٔ بک‌اند. */
    public const BACKEND_ROOT = 'Laravel';

    /** پیشوند ریشهٔ بستهٔ فرانت‌اند. */
    public const FRONTEND_ROOT = 'Next.js';

    /** سقف‌های ایمنی. */
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;

    public const MAX_FILES = 5000;

    public const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    /** نسبت فشرده‌سازی بالاتر از این ⇒ zip bomb. */
    public const MAX_COMPRESSION_RATIO = 100;

    public const MAX_TABLE_COUNT = 20;

    public const MAX_INDEXES_PER_TABLE = 10;

    public const MAX_ACTIVE_PLUGINS = 20;

    /**
     * بستهٔ کمینهٔ مقصدهای مجاز. deny-by-default.
     *
     * هر ورودی: مسیر نسبت به ریشهٔ ZIP.
     */
    public const ALLOWED_PATHS = [
        self::MANIFEST,

        // بک‌اند
        self::BACKEND_ROOT.'/src',
        self::BACKEND_ROOT.'/routes/api.php',
        self::BACKEND_ROOT.'/database/migrations',
        self::BACKEND_ROOT.'/config/plugin.php',

        // فرانت‌اند
        self::FRONTEND_ROOT.'/panel.json',
        self::FRONTEND_ROOT.'/panel',
        self::FRONTEND_ROOT.'/blocks',
    ];

    /** نام‌های رزروشدهٔ ویندوز — کاربر ممکن است فایل‌ها را روی سرور ویندوزی مدیریت کند. */
    public const RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * نقاط اتصال افزونه در پنل و سایت.
     *
     * فهرست **بسته** است (تصمیم K1.5.6). افزودن مورد تازه یک ارتقای هسته است،
     * و این عمدی است: کنترل کیفیت و سازگاری دست پلتفرم می‌ماند.
     *
     * `json` یعنی آیا رجیستری می‌تواند اسکیمای JSON را از این نقطه بگیرد.
     * `builder` یعنی کد هسته‌ای که باید merge/dispatch را انجام دهد.
     */
    public const EXTENSION_POINTS = [
        'admin.menu' => [
            'label_fa' => 'منوی کناری پنل مدیریت',
            'desc_fa' => 'افزودن آیتم به منوی سمت راست پنل. هر پلاگینی معمولاً به این نیاز دارد.',
            'example' => ['title' => 'نوشته‌ها', 'path' => '/admin/blog', 'icon' => 'pencil', 'permission' => 'posts.view'],
        ],
        'admin.dashboard_slot' => [
            'label_fa' => 'ویجت داشبورد مدیریت',
            'desc_fa' => 'افزودن کارت به صفحهٔ اصلی پنل. اسلات‌ها: top, main, side.',
            'json' => true,
            'example' => ['key' => 'sales_summary', 'title' => 'خلاصهٔ فروش', 'slot' => 'side', 'order' => 10],
        ],
        'admin.settings_schema' => [
            'label_fa' => 'فرم تنظیمات اختصاصی',
            'desc_fa' => 'افزودن صفحهٔ تنظیمات که از روی JSON Schema رندر می‌شود (بدون کد فرانت).',
            'json' => true,
        ],
        'admin.data_collection' => [
            'label_fa' => 'جدول دادهٔ اختصاصی',
            'desc_fa' => 'افزودن صفحهٔ فهرست/ویرایش برای دادهٔ اختصاصی پلاگین، از روی اعلان entity + schema.',
            'json' => true,
        ],
        'site.header_widget' => [
            'label_fa' => 'ویجت هدر سایت',
            'desc_fa' => 'افزودن بلوک به هدر صفحات عمومی سایت.',
            'json' => true,
        ],
        'site.footer_widget' => [
            'label_fa' => 'ویجت فوتر سایت',
            'desc_fa' => 'افزودن بلوک به فوتر صفحات عمومی سایت.',
            'json' => true,
        ],
        'site.page_type' => [
            'label_fa' => 'نوع صفحهٔ سایت',
            'desc_fa' => 'تعریف نوع محتوایی جدید که صفحات عمومی سایت می‌توانند از آن استفاده کنند.',
        ],
        'site.block_type' => [
            'label_fa' => 'نوع بلوک صفحه',
            'desc_fa' => 'تعریف نوع بلوک جدید برای ویرایشگر صفحه. رندر از روی JSON Schema.',
            'json' => true,
        ],
    ];

    /**
     * نقاط اتصالی که فقط در حالت سطح ۱ (کامپوننت pre-compiled) ممکن‌اند و
     * در v1 عمداً پشتیبانی نمی‌شوند. وجودشان در مانیفست خطاست، نه بی‌صدا.
     */
    public const DEFERRED_EXTENSION_POINTS = [
        'admin.component' => 'کامپوننت React — در v1 رد شد (در مرورگر مدیر اجرا می‌شود ⇒ تصاحب پنل)',
    ];

    /**
     * @return list<array{key: string, label_fa: string, desc_fa: string, example?: array, json?: bool}>
     */
    public static function extensionPoints(): array
    {
        $out = [];
        foreach (self::EXTENSION_POINTS as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label_fa' => $meta['label_fa'],
                'desc_fa' => $meta['desc_fa'],
                'json' => (bool) ($meta['json'] ?? false),
                'example' => $meta['example'] ?? null,
            ];
        }

        return $out;
    }

    public static function isKnownExtensionPoint(string $key): bool
    {
        return array_key_exists($key, self::EXTENSION_POINTS);
    }

    /**
     * مسیر مجاز است؟
     *
     * deny-by-default: هر مسیری که زیر یکی از مقصدهای مجاز نباشد رد می‌شود.
     *
     * @param  string  $relPath  مسیر نسبتِ نرمال‌شده با `/`
     */
    public static function isAllowedPath(string $relPath): bool
    {
        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($relPath === $allowed) {
                return true;
            }
            // زیرپوشه‌های مقصدهای پوشه‌ای مجازند، فایل‌های مقصدهای فایلی نه.
            if ($relPath !== $allowed
                && str_starts_with($relPath, $allowed.'/')
                && ! str_contains(substr($allowed, strrpos($allowed, '/') ?: 0), '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * نرمال‌سازی مسیر ورودی ZIP.
     *
     * جداسازی `\` جداگانه لازم است: `..\..\` روی ویندوز/آلپاین با `/` بی‌اثر است
     * ولی برعکسش خطرناک. مسیرهای مطلق و `..` باید همین‌جا رد شوند.
     *
     * @return string|null  مسیر نرمال‌شده، یا null اگر ناامن باشد
     */
    public static function normalizePath(string $raw): ?string
    {
        $p = str_replace('\\', '/', $raw);

        if ($p === '' || str_contains($p, "\0")) {
            return null;
        }
        // مسیر مطلق
        if (str_starts_with($p, '/') || preg_match('#^[A-Za-z]:#', $p) === 1) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $segments[] = $seg;
        }

        if ($segments === []) {
            return null;
        }

        $normalized = implode('/', $segments);

        // نام رزروشدهٔ ویندوز
        $base = pathinfo($normalized, PATHINFO_FILENAME);
        if (in_array(strtoupper($base), self::RESERVED_NAMES, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * برخورد بزرگی/کوچکی: `A.php` و `a.php` هر دو PSR-4 معتبرند ولی روی
     * فایل‌سیستم case-insensitive یکی overwrite می‌کند. برای کاربر ایرانی روی
     * هاست ویندوزی یک باگ واقعی است.
     *
     * @param  list<string>  $paths
     * @return list<array{a: string, b: string}>
     */
    public static function findCaseCollisions(array $paths): array
    {
        $seen = [];
        $collisions = [];

        foreach ($paths as $p) {
            $fold = Str::lower($p);
            if (isset($seen[$fold]) && $seen[$fold] !== $p) {
                $collisions[] = ['a' => $seen[$fold], 'b' => $p];
                continue;
            }
            $seen[$fold] = $p;
        }

        return $collisions;
    }

    /**
     * مسیر تکراری بعد از نرمال‌سازی.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function findDuplicates(array $paths): array
    {
        $counts = array_count_values($paths);

        return array_values(array_keys(array_filter($counts, fn ($n) => $n > 1)));
    }

    /**
     * پیشنهاد محل درست برای یک فایل — «بهتر حدس»، نه الزام.
     *
     * این یک heuristic است تا بتوان به کاربر گفت «این فایل به‌احتمال زیاد
     * جای دیگری لازم است». اگر این heuristic اشتباه کند و UI آن را «الزام»
     * بنامد، توسعه‌دهنده به کل راهنما بی‌اعتماد می‌شود. enforce فقط روی
     * بستهٔ مجاز انجام می‌شود.
     *
     * @return array{path: string, reason: string}|null
     */
    public static function suggestLocation(string $relPath): ?array
    {
        $name = pathinfo($relPath, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        // manifest همیشه در ریشه
        if ($name === 'manifest') {
            return null;
        }

        // نام فایل در ریشهٔ بسته گم شده — حدس بر اساس نام و پسوند
        $guesses = [
            'Controller' => self::BACKEND_ROOT.'/src/Http/Controllers/{name}.php',
            'Service' => self::BACKEND_ROOT.'/src/Services/{name}.php',
            'Job' => self::BACKEND_ROOT.'/src/Jobs/{name}.php',
            'Policy' => self::BACKEND_ROOT.'/src/Policies/{name}.php',
            'Model' => self::BACKEND_ROOT.'/src/Models/{name}.php',
        ];

        foreach ($guesses as $needle => $target) {
            if (str_contains($name, $needle)) {
                return [
                    'path' => str_replace('{name}', $name, $target),
                    'reason' => 'نام فایل به «'.$needle.'» اشاره دارد.',
                ];
            }
        }

        if (str_ends_with(strtolower($name), 'migration') || $ext === 'php' && str_contains(strtolower($relPath), 'migration')) {
            return [
                'path' => self::BACKEND_ROOT.'/database/migrations/'.basename($relPath),
                'reason' => 'نام فایل نشان می‌دهد migration است.',
            ];
        }

        if ($ext === 'tsx' || $ext === 'jsx' || $ext === 'ts' || $ext === 'js') {
            return [
                'path' => self::FRONTEND_ROOT.'/panel/'.basename($relPath),
                'reason' => 'پسوند فایل جاوااسکریپت/تایپ‌اسکریپت است و در بستهٔ فرانت‌اند قرار می‌گیرد.',
            ];
        }

        if (in_array(strtolower($ext), ['css', 'scss', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'woff2'], true)) {
            return [
                'path' => self::FRONTEND_ROOT.'/panel/'.basename($relPath),
                'reason' => 'این پسوند یک دارایی فرانت‌اند است.',
            ];
        }

        return null;
    }

    /** بستهٔ مجاز برای نمایش به کاربر/برنامه‌نویس. */
    public static function describeAllowedPaths(): array
    {
        return [
            ['path' => self::MANIFEST, 'label_fa' => 'مانیفست (الزامی — باید در ریشهٔ ZIP باشد)'],
            ['path' => self::BACKEND_ROOT.'/src/', 'label_fa' => 'ریشهٔ PSR-4 — کلاس‌های پلاگین'],
            ['path' => self::BACKEND_ROOT.'/routes/api.php', 'label_fa' => 'مسیرهای API پلاگین'],
            ['path' => self::BACKEND_ROOT.'/database/migrations/', 'label_fa' => 'مهاجرت‌های دیتابیس (با پیشوند اجباری)'],
            ['path' => self::BACKEND_ROOT.'/config/plugin.php', 'label_fa' => 'مقادیر پیش‌فرض تنظیمات'],
            ['path' => self::FRONTEND_ROOT.'/panel.json', 'label_fa' => 'قرارداد نقاط اتصال رابط کاربری'],
            ['path' => self::FRONTEND_ROOT.'/panel/', 'label_fa' => 'داده و دارایی رابط کاربری'],
            ['path' => self::FRONTEND_ROOT.'/blocks/', 'label_fa' => 'تعریف بلوک‌های سایت'],
        ];
    }
}
