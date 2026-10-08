<?php

namespace App\Services\Plugins;

use InvalidArgumentException;
use RuntimeException;

/**
 * K5.2 — چیدمان نسخه‌های افزونه روی دیسک، اشاره‌گر نسخهٔ فعال، و قفل نصب.
 *
 * این کلاس **هیچ چیزی از ZIP نمی‌داند.** کارش فقط سه چیز است: اسم‌گذاری مسیرها،
 * نگه‌داشتن «کدام نسخه فعال است»، و قفل انحصاری تا دو نصبِ همزمان یک نسخه به
 * هم نریزند. استخراج در `PluginInstaller` است.
 *
 * ## تصمیم: symlink یا فایل اشاره‌گر؟ ⇒ **فایل اشاره‌گر متنی**
 *
 * گزینهٔ هوس‌انگیزه symlink است، ولی سه ضربه می‌خورد:
 *
 *  ۱. **روی ویندوز شکننده است.** `symlink()` در ویندوز بدون Administrator یا
 *     Developer Mode فقط یک warning می‌دهد و false برمی‌گرداند. هستهٔ این پروژه
 *     روی هاست ویندوزی اجرا می‌شود (`PluginAutoloader` برای همین `realpath` را با
 *     `/` نرمال می‌کند) ⇒ گزینه‌ای که روی ویندوز بی‌صدا از کار می‌افتد، گزینهٔ
 *     درستی نیست.
 *  ۲. **تعویضش atomic نیست.** روی لینوکس `rename()` روی symlink موجود کار
 *     می‌کند، ولی روی ویندوز و در هر حالتی که symlink از قبل وجود داشته باشد باید
 *     اول `unlink` شود ⇒ یک پنجرهٔ زمانی هست که `current` اصلاً وجود ندارد و
 *     کدِ افزونه بارگذاری نمی‌شود. این دقیقاً همان خرابی است که این کلاس باید
 *     جلویش را بگیرد.
 *  ۳. **حذفِ بی‌خطر را سخت می‌کند.** بازگشت (restore) از روی بکاپ، symlink را به
 *     یک پیوند شکسته تبدیل می‌کند که فقط با بازسازی کامل درست می‌شود.
 *
 * یک فایل متنی کوچک هیچ‌کدام از این‌ها را ندارد: `rename()` روی **فایل** در هر دو
 * سیستم‌فایل موجود بودن مقصد را جایگزین می‌کند (لینوکس با `rename(2)` اتمیک؛ ویندوز
 * با `MoveFileEx` و `MOVEFILE_REPLACE_EXISTING` که برای فایل کوچکِ هم‌پوشه در
 * عمل اتمیک است). اگر `rename` شکست بخورد، فایل موقت پاک و اشاره‌گر قبلی دست‌نخورده
 * می‌ماند — یعنی نسخهٔ فعال هرگز «نیمه‌عوض‌شده» نیست.
 *
 * ## چرا تاریخ به‌جای «اشاره‌گر قبلی»
 *
 * مدل «یک اشاره‌گر قبلی» یعنی rollback معکوسِ خودش است و دو بار rollback کردن
 * افزونه را به نسخهٔ شکسته برمی‌گرداند. اینجا تاریخ یک **فهرست** است: هر
 * `activate` نسخهٔ قبلی را اول فهرست می‌کند و هر `rollback` اولین مورد را برمی‌دارد
 * و **دور می‌اندازد**. برگشت یک‌طرفه است و تکرارش بی‌خطر.
 *
 * ## چرا rename و نه «کپی در جای فعلی»
 *
 * تنها عملیاتی که روی یک فایل‌سیستم معمولی، نوشتنِ قابل‌تعویض را اتمیک می‌کند
 * `rename()` در همان پوشه است. بنابراین `PluginInstaller` در پوشهٔ موقت
 * استخراج می‌کند و این کلاس فقط نقطهٔ فرود را نگه می‌دارد.
 */
final class PluginReleaseManager
{
    /**
     * زیر‌پوشهٔ PSR-4 افزونه، نسبت به ریشهٔ نسخه.
     *
     * از `PluginPackageContract` وام گرفته می‌شود: همان مسیری که
     * `ALLOWED_PATHS` اجازه می‌دهد و همانی که `PluginAutoloader` باید بارگذاری
     * کند. تکرارِ رشتهٔ `'Laravel/src'` اینجا یعنی دو منبع حقیقت.
     */
    public const PSR4_SUBPATH = PluginPackageContract::BACKEND_ROOT.'/src';

    /** نام فایل اشاره‌گر نسخهٔ فعال. نقطه‌دار ⇒ در `releases()` دیده نمی‌شود. */
    public const POINTER_FILE = '.current';

    /** فهرست نسخه‌هایی که قبلاً فعال بوده‌اند، جدیدترین اول. */
    public const HISTORY_FILE = '.history';

    /** پوشهٔ staging؛ هر نصب یک زیرپوشهٔ یکتا در آن می‌سازد. */
    public const STAGING_DIR = '.staging';

    /** فایل قفل انحصاری هر slug. */
    public const LOCK_FILE = '.lock';

    /**
     * چند نسخهٔ قبلی نگه داشته شود؟
     *
     * ۱۰ یعنی کاربر می‌تواند ده بار پشت‌سرهم برگردد؛ بیشترش فقط فضای دیسک را
     * می‌خورد و راه برگشت را بی‌معنا می‌کند.
     */
    public const MAX_HISTORY = 10;

    /**
     * همان الگوی `PluginPackageValidator` برای slug، و عمداً نه چیز دیگر.
     *
     * این الگو سه چیز را همزمان تضمین می‌کند که برای یک **قطعه از مسیر** لازم
     * است: با حرف یا رقم شروع می‌شود (پس `..` و پوشه‌های مخفی غیرممکن‌اند)،
     * `/` و `\` ندارد، و طولش کران‌دار است. هر چیزی سست‌تر از این یعنی مسیرِ
     * قابلِ فرار.
     */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,39}$/';

    /** نسخه باید یک قطعهٔ مسیرِ بی‌خطر باشد. `+` برای semver پیشونددار. */
    private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/';

    /** هش محتوا: hex کوچک. ۸ برای نام پوشه، تا ۶۴ برای مقایسهٔ کامل. */
    private const HASH_PATTERN = '/^[0-9a-f]{8,64}$/';

    /** برچسب نسخه روی دیسک: `{version}-{hash8}`. */
    private const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+--]{0,119}$/';

    /** فاصلهٔ بین تلاش‌های قفل. */
    private const LOCK_POLL_MICROSECONDS = 50_000;

    private ?string $resolvedBase = null;

    private ?string $resolvedSourceBase = null;

    /**
     * @param  string|null  $basePath  ریشهٔ نصب. `null` یعنی
     *                                 `storage/app/plugins`. تزریق‌پذیر است تا
     *                                 تست بتواند sandbox خودش را بدهد و به
     *                                 `config/plugins.php` وابسته نباشد.
     * @param  string|null  $sourcePath  ریشهٔ **افزونه‌های داخلی**. `null` یعنی
     *                                   `base_path('plugins')`. جدا از `basePath`
     *                                   تزریق می‌شود تا تست بتواند برای
     *                                   هر دو یک sandbox بسازد و ناخواسته
     *                                   `plugins/` واقعیِ مخزن را نبیند.
     */
    public function __construct(
        private ?string $basePath = null,
        private ?string $sourcePath = null,
    ) {}

    // ── چیدمان مسیر ─────────────────────────────────────────────────────────

    /**
     * ریشهٔ نصب، canonical.
     *
     * تا وقتی پوشه وجود ندارد، مسیرِ خام برمی‌گردد تا `ensureReleaseDir()` بتواند
     * بسازدش — و **عمداً memoize نمی‌کنیم**، چون بعد از ساخت باید canonical شود.
     * اگر زود memoize می‌کردیم، `autoloadPackages()` مسیر نسبی را با `substr()`
     * روی یک پایهٔ غیرcanonical حساب می‌کرد و رشتهٔ غلط به بارگذار می‌داد.
     */
    public function basePath(): string
    {
        if ($this->resolvedBase !== null) {
            return $this->resolvedBase;
        }

        $base = $this->basePath ?? storage_path('app/plugins');
        $real = realpath($base);

        if ($real === false) {
            return $this->normalizeSeparators($base);
        }

        $this->resolvedBase = $this->normalizeSeparators($real);

        return $this->resolvedBase;
    }

    public function slugDir(string $slug): string
    {
        return $this->basePath().'/'.$this->normalizeSlug($slug);
    }

    public function releaseDir(string $slug): string
    {
        return $this->slugDir($slug).'/releases';
    }

    /** پوشهٔ staging مشترکِ یک slug. */
    public function stagingDir(string $slug): string
    {
        return $this->releaseDir($slug).'/'.self::STAGING_DIR;
    }

    /**
     * یک مسیر staging یکتا.
     *
     * یکتا بودن (`random_bytes`) همان چیزی است که دو نصبِ همزمان را از
     * برخورد روی یک پوشهٔ موقت نجات می‌دهد، حتی اگر قفل هم به هر دلیلی از
     * کار افتاده باشد.
     */
    public function newStagingPath(string $slug, string $label): string
    {
        $label = $this->assertLabel($label);

        return $this->stagingDir($slug).'/'.$label.'-'.bin2hex(random_bytes(8));
    }

    public function pointerPath(string $slug): string
    {
        return $this->releaseDir($slug).'/'.self::POINTER_FILE;
    }

    public function historyPath(string $slug): string
    {
        return $this->releaseDir($slug).'/'.self::HISTORY_FILE;
    }

    public function lockPath(string $slug): string
    {
        return $this->releaseDir($slug).'/'.self::LOCK_FILE;
    }

    /**
     * ریشهٔ PSR-4 نسخهٔ فعال — همان چیزی که `PluginAutoloader::register()` می‌خواهد.
     *
     * `null` یعنی «افزونه‌ای فعال نیست» و `PluginAutoloader` چیزی ثبت نمی‌کند.
     * اگر پوشهٔ `Laravel/src` نبود (بستهٔ فقط-فرانت‌اند) هم `null` است، وگرنه
     * بارگذار یک ریشهٔ وجود‌نداشتنی ثبت می‌کرد.
     *
     * ## ترتیب: **source اول، بعد release**
     *
     * دو ریشهٔ متفاوت وجود دارد و هرکدام دلیل خودش را دارد:
     *
     *  - `storage/app/plugins/{slug}/releases/…` — افزونهٔ بازاری. کد از ZIP
     *    امضاشده آمده و نسخه‌بندی دارد.
     *  - `plugins/{slug}/` — افزونهٔ **داخلی** (K7.8). کد در مخزن است، با
     *    `git pull` عوض می‌شود، و امضا ندارد چون خودِ مخزن مرجع اعتماد است.
     *
     * اگر release اول بررسی می‌شد، یک بستهٔ بازاری با همان slug می‌توانست کدِ
     * داخلی را زیر خودش پنهان کند — یعنی عملاً یک **tier جدید اعتماد** که
     * K7.9 صریحاً آن را رد کرده بود. پس source مقدم است.
     *
     * نگهبانِ «source مقدم است» تست `PluginReleaseManagerTest` است، و این
     * نکته یک تصمیم معماری است نه جزئیات پیاده‌سازی: اگر روزی کسی ترتیب را
     * برعکس کند، هیچ تستِ رفتاریِ دیگری نمی‌شکند.
     */
    public function autoloadRoot(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);

        $fromSource = $this->sourceAutoloadRoot($slug);
        if ($fromSource !== null) {
            return $fromSource;
        }

        $current = $this->currentDir($slug);
        if ($current === null) {
            return null;
        }

        $src = $this->normalizeSeparators($current.'/'.self::PSR4_SUBPATH);
        $real = realpath($src);

        if ($real === false || ! is_dir($real) || ! $this->isInside($real, $current)) {
            return null;
        }

        return $this->normalizeSeparators($real);
    }

    // ── افزونه‌های داخلی (K7.8) ────────────────────────────────────────────

    /**
     * ریشهٔ پوشهٔ source، canonical.
     *
     * برخلاف `basePath()` اگر پوشه وجود نداشته باشد `realpath` شکست می‌خورد و
     * مسیرِ نرمال‌شده برگردانده می‌شود — عمداً، چون نبودنش یک وضعیتِ عادی است
     * (یک نصب که هیچ افزونهٔ داخلی ندارد) نه خطا.
     */
    public function sourceBasePath(): string
    {
        if ($this->resolvedSourceBase !== null) {
            return $this->resolvedSourceBase;
        }

        $base = $this->sourcePath ?? base_path('plugins');
        $real = realpath($base);

        if ($real === false) {
            return $this->normalizeSeparators($base);
        }

        $this->resolvedSourceBase = $this->normalizeSeparators($real);

        return $this->resolvedSourceBase;
    }

    /** پوشهٔ source یک slug، یا `null` اگر چنین افزونهٔ داخلی‌ای نباشد. */
    public function sourceDir(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);
        $base = $this->sourceBasePath();
        $dir = $base.'/'.$slug;
        $real = realpath($dir);

        if ($real === false || ! is_dir($real) || ! $this->isInside($real, $base)) {
            return null;
        }

        return $this->normalizeSeparators($real);
    }

    /**
     * آیا این slug **افزونهٔ داخلی** است؟
     *
     * تفاوتش با `sourceDir() !== null` عمدی است: `sourceDir` می‌پرسد «پوشه هست؟»
     * و این می‌پرسد «پوشه یک بستهٔ کامل است؟» — یعنی ریشهٔ PSR-4 دارد.
     *
     * همین تفاوت، slugهای رزرو را می‌سازد: یک بستهٔ بازاری نمی‌تواند slugای را
     * بگیرد که در `plugins/` جای دارد، چون `sourceAutoloadRoot()` همیشه مقدم
     * می‌ماند و کدِ بازاریِ هم‌نام هرگز بارگذاری نمی‌شد — یعنی نصب می‌شد ولی
     * بی‌اثر بود.
     */
    public function isSourceSlug(string $slug): bool
    {
        return $this->sourceAutoloadRoot($slug) !== null;
    }

    /**
     * ریشهٔ PSR-4 افزونهٔ داخلی، یا `null`.
     *
     * `realpath` + `isInside` همان محافظتی است که شاخهٔ release دارد: یک
     * `Laravel/src` که با لینکِ نمادین به بیرون از `plugins/{slug}` اشاره کند
     * رد می‌شود، نه بارگذاری می‌شود.
     */
    public function sourceAutoloadRoot(string $slug): ?string
    {
        $dir = $this->sourceDir($slug);
        if ($dir === null) {
            return null;
        }

        $src = $this->normalizeSeparators($dir.'/'.self::PSR4_SUBPATH);
        $real = realpath($src);

        if ($real === false || ! is_dir($real) || ! $this->isInside($real, $dir)) {
            return null;
        }

        return $this->normalizeSeparators($real);
    }

    /**
     * slugهای افزونهٔ داخلی، از روی دیسک — نه از جدول `plugins`.
     *
     * جدول برای **مانیفست** است (فعال/غیرفعال، نام فارسی، مجوزها). مسیر کد از
     * دیسک می‌آید، دقیقاً مثل بستهٔ بازاری. اگر این را از جدول می‌خواندیم،
     * افزونه‌ای که رکوردش پاک شده ولی پوشه‌اش هست همچنان کدش بارگذاری می‌شد.
     */
    public function sourceSlugs(): array
    {
        return $this->sourceSlugsAt($this->sourceBasePath());
    }

    /**
     * slugهای افزونهٔ داخلی زیر یک پایهٔ مشخص.
     *
     * @return list<string>
     */
    public function sourceSlugsAt(string $base): array
    {
        $base = $this->normalizeSeparators($base);
        if (! is_dir($base)) {
            return [];
        }

        $slugs = [];

        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (preg_match(self::SLUG_PATTERN, $entry) !== 1) {
                continue;
            }
            if ($this->sourceAutoloadRoot($entry) === null) {
                continue;
            }
            $slugs[] = $entry;
        }

        sort($slugs);

        return $slugs;
    }

    /**
     * نقشهٔ `slug => مسیر نسبی` برای `PluginAutoloader::registerPackages()`.
     *
     * فقط افزونه‌هایی که **هم** نسخهٔ فعال دارند **هم** ریشهٔ PSR-4. نبودنِ یکی
     * یعنی آن بسته بارگذاری نمی‌شود و باقی بقیه سالم می‌مانند.
     *
     * ⚠️ **مسیر نسبی دیگر کافی نیست.** نقشهٔ قدیمی مسیر را نسبت به **یک** پایه
     * حساب می‌کرد، ولی حالا دو پایه داریم: `storage/app/plugins` برای بستهٔ
     * بازاری و `plugins/` مخزن برای افزونهٔ داخلی. اگر هنوز نسبت به پایهٔ
     * نخست می‌ساختیم، `substr()` روی مسیرِ پوشهٔ source رشته‌ای می‌داد که به
     * جایی اشاره می‌کرد که اصلاً وجود ندارد — و بی‌صدا رد می‌شد.
     *
     * پس کلید نقشه حالا **ریشهٔ نصب** است و داشته‌ای به‌ازای هر slug درونش.
     * شکل خروجی عمداً عوض شد و این یک تغییرِ شکننده است: `PluginInstallerTest`
     * آن را با شکل تازه می‌سنجد.
     *
     * @return array<string, array<string, string>> basePath => [slug => مسیر نسبی]
     */
    public function autoloadPackages(): array
    {
        $out = [];

        foreach ([$this->basePath(), $this->sourceBasePath()] as $base) {
            $real = realpath($base);
            $base = $real === false ? $this->normalizeSeparators($base) : $this->normalizeSeparators($real);

            foreach (array_merge($this->installedSlugsAt($base), $this->sourceSlugsAt($base)) as $slug) {
                $root = $this->autoloadRoot($slug);
                if ($root === null) {
                    continue;
                }
                $relative = ltrim(substr($root, strlen($base)), '/');
                $out[$base][$slug] = $relative === '' ? '.' : $relative;
            }
        }

        ksort($out);

        // `array_map('ksort', …)` از PHP 8.0 خطا می‌دهد: `ksort` آرگومان اولش را
        // **by-reference** می‌گیرد و `array_map` مقدار می‌دهد. یعنی این خط یک
        // `ErrorException` می‌انداخت و `autoloadPackages()` همیشه می‌مرد.
        //
        // `PluginInstallerTest:137` این را گرفت — و فقط چون آن تست شکلِ جدیدِ
        // خروجی را می‌سنجید. یعنی هر مصرف‌کنندهٔ دیگری هم می‌مرد، ولی اصلاً
        // مصرف‌کنندهٔ دیگری نبود: این تنها جایی بود که این متد صدا زده می‌شد.
        foreach ($out as $base => $packages) {
            ksort($packages);
            $out[$base] = $packages;
        }

        return $out;
    }

    /** @return list<string> */
    public function installedSlugs(): array
    {
        return $this->installedSlugsAt($this->basePath());
    }

    /**
     * slugهای نصب‌شده زیر یک پایهٔ مشخص.
     *
     * پارامتری شدنش به‌خاطر `autoloadPackages()` است که باید هر دو ریشه را
     * جداگانه بگردد و در نسبی‌سازی قاطی نشوند.
     *
     * @return list<string>
     */
    public function installedSlugsAt(string $base): array
    {
        $base = $this->normalizeSeparators($base);
        if (! is_dir($base)) {
            return [];
        }

        $slugs = [];

        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (preg_match(self::SLUG_PATTERN, $entry) !== 1) {
                continue;
            }
            if (! is_dir($base.'/'.$entry)) {
                continue;
            }
            $slugs[] = $entry;
        }

        sort($slugs);

        return $slugs;
    }

    // ── وضعیت نسخه‌ها ───────────────────────────────────────────────────────

    /**
     * نسخهٔ فعال روی دیسک، یا `null`.
     *
     * `realpath` + بررسیcontained بودن، غیرقابل دور زدن است: برچسبی که در فایل
     * اشاره‌گر باشد ولی به بیرون از `releases/` اشاره کند ⇒ `null`، نه مسیر.
     */
    public function currentDir(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);
        $label = $this->readPointer($slug);

        if ($label === null) {
            return null;
        }

        return $this->resolveRelease($slug, $label);
    }

    /** اولین نسخهٔ قابل‌بازگشت، یا `null`. */
    public function previousDir(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);

        foreach ($this->history($slug) as $label) {
            $resolved = $this->resolveRelease($slug, $label);
            if ($resolved !== null && $this->isUsable($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * فهرست نسخه‌های نصب‌شده، برای نمایش در پنل.
     *
     * @return list<array{name: string, path: string, active: bool, usable: bool}>
     */
    public function releases(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $releaseDir = $this->releaseDir($slug);

        if (! is_dir($releaseDir)) {
            return [];
        }

        $active = $this->currentDir($slug);
        $out = [];

        foreach (scandir($releaseDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $releaseDir.'/'.$entry;
            if (! is_dir($path)) {
                continue;
            }
            $resolved = $this->resolveRelease($slug, $entry);
            if ($resolved === null) {
                continue;
            }
            $out[] = [
                'name' => $entry,
                'path' => $resolved,
                'active' => $resolved === $active,
                'usable' => $this->isUsable($resolved),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $out;
    }

    /** @return list<string> نام پوشه‌های staging که هنوز جا مانده‌اند */
    public function stagingEntries(string $slug): array
    {
        $staging = $this->stagingDir($slug);

        if (! is_dir($staging)) {
            return [];
        }

        $out = [];

        foreach (scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $out[] = $entry;
        }

        sort($out);

        return $out;
    }

    /**
     * آیا این پوشه یک نسخهٔ قابل‌استفاده است؟
     *
     * **حداقلِ لازم، نه بازبینی کامل.** غرضش این است که `activate()` هرگز
     * نسخه‌ای را روشن نکند که `manifest.json` معتبر ندارد. بازبینی کامل
     * (هیچ فایلی بیرون از ریشه نیست، هیچ symlink‌ای نیست) کار
     * `PluginInstaller` است، چون فقط همان‌جا فایل‌ها را می‌نویسد.
     */
    public function isUsable(string $releaseDir): bool
    {
        $manifest = $this->normalizeSeparators($releaseDir).'/'.PluginPackageContract::MANIFEST;

        if (! is_file($manifest)) {
            return false;
        }

        $raw = @file_get_contents($manifest);
        if ($raw === false || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);

        return is_array($data)
            && is_string($data['slug'] ?? null)
            && $data['slug'] !== '';
    }

    // ── فعال‌سازی، بازگشت، پاک‌سازی ───────────────────────────────────────

    /**
     * نسخهٔ فعال را عوض می‌کند.
     *
     * نسخهٔ قبلی در تاریخ می‌رود تا `rollback()` کار کند.
     *
     * @throws InvalidArgumentException اگر slug/version/hash نامعتبر باشد
     * @throws RuntimeException اگر نسخه روی دیسک نباشد یا قابل‌استفاده نباشد
     */
    public function activate(string $slug, string $version, string $hash): void
    {
        $slug = $this->normalizeSlug($slug);

        // ── پلاگینِ داخلی: بدون نسخهٔ استخراج‌شده ──────────────────────
        //
        // ⚠️ اینجا یک بن‌بست واقعی شکسته شد.
        //
        // افزونه‌های داخلی (مرکز، اشتراک) با migration ثبت می‌شوند و
        // `checksum` آن‌ها **`null`** است — چون هیچ بستهٔ ZIPای نبوده که
        // `hash_file` بخورد. ولی `activate()` هش را `string` می‌گیرد، پس
        // `(string) null` می‌شد `''` و `releaseLabel()` با
        // «هش نسخه نامعتبر است» می‌پرید. نتیجه: **هر پلاگین داخلی از پنل
        // قابل فعال‌سازی نبود** — نه به‌خاطر امنیت، فقط به‌خاطر یک نوعِ
        // ناسازگار.
        //
        // راه درست: هش خالی یعنی «این نسخه از دیسکِ منبع آمده، نه از
        // بازار»، و برچسبِ ثابتِ `source` می‌گیرد. گاردهای امنیتی دست‌نخورده
        // می‌مانند: `normalizeHash` هنوز هر هشِ نامعتبرِ غیرخالی را رد
        // می‌کند، و بعدش هنوز باید نسخه واقعاً روی دیسک باشد و قابل‌استفاده.
        if (trim($hash) === '') {
            $this->activateFromSource($slug, $version);

            return;
        }

        $label = $this->releaseLabel($version, $hash);

        $target = $this->resolveRelease($slug, $label);

        if ($target === null) {
            throw new RuntimeException(
                "نسخهٔ «{$label}» افزونهٔ «{$slug}» روی دیسک نیست؛ فعال‌سازی انجام نشد و نسخهٔ فعال قبلی دست‌نخورده ماند."
            );
        }

        if (! $this->isUsable($target)) {
            throw new RuntimeException(
                "نسخهٔ «{$label}» ناقص است (".PluginPackageContract::MANIFEST.' معتبر ندارد)؛ فعال‌سازی انجام نشد و نسخهٔ فعال قبلی دست‌نخورده ماند.'
            );
        }

        $this->ensureReleaseDir($slug);
        $this->swapPointer($slug, $label, remember: true);
    }

    /**
     * فعال‌سازیِ پلاگینِ داخلی از پوشهٔ منبع، بدون نسخهٔ استخراج‌شده.
     *
     * افزونهٔ داخلی یک شاخهٔ ثابت است (`plugins/{slug}`) که با `git pull` عوض
     * می‌شود؛ «نسخه» یعنی همان شاخهٔ فعلی. پس چیزی برای تعویضِ اشاره‌گر
     * وجود ندارد و نوشتنِ برچسبِ ساختگی هم **دروغ** بود — بعداً
     * `currentDir()` به شاخه‌ای اشاره می‌کرد که وجود نداشت.
     *
     * کاری که می‌کند فقط **وجودِ واقعیِ کد** را می‌سنجد: مانیفست معتبر و یک
     * `Laravel/src` که داخل همان پوشه است. اگر نبود، همان پیامِ
     * «روی دیسک نیست» را می‌دهد، پس رفتار برای پلاگینِ خراب همچنان fail-closed
     * می‌ماند.
     */
    private function activateFromSource(string $slug, string $version): void
    {
        $dir = $this->sourceDir($slug);

        if ($dir === null || ! $this->isUsable($dir)) {
            throw new RuntimeException(
                "افزونهٔ «{$slug}» روی دیسک نیست (".PluginPackageContract::MANIFEST.' معتبر ندارد)؛ فعال‌سازی انجام نشد.'
            );
        }

        // فقط اعتبار مسیر را می‌سنجد، نه می‌سازد: `sourceAutoloadRoot()`
        // همان محافظتِ symlink-escape را دارد که شاخهٔ release دارد.
        if ($this->sourceAutoloadRoot($slug) === null) {
            throw new RuntimeException(
                "افزونهٔ «{$slug}» کدِ قابل بارگذاری ندارد (Laravel/src نامعتبر یا بیرون از پوشه)؛ فعال‌سازی انجام نشد."
            );
        }
    }

    /**
     * برگشت به نسخهٔ فعالِ قبلی.
     *
     * تاریخ مصرف می‌شود: دومین `rollback()` به نسخهٔ خراب برنمی‌گردد، چون آن
     * دیگر در فهرست نیست.
     *
     * @return string|null مسیر نسخه‌ای که فعال شد، یا `null` اگر چیزی برای
     *                     بازگشت نبود (در آن حالت وضعیت دست‌نخورده می‌ماند)
     */
    public function rollback(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);
        $history = $this->history($slug);

        $label = null;
        $remaining = [];

        foreach ($history as $candidate) {
            if ($label === null) {
                $resolved = $this->resolveRelease($slug, $candidate);
                if ($resolved !== null && $this->isUsable($resolved)) {
                    $label = $candidate;
                }

                continue;
            }
            $remaining[] = $candidate;
        }

        // نسخه‌های خراب از تاریخ پاک می‌شوند تا هرگز دوباره امتحان نشوند.
        $this->writeHistory($slug, $remaining);

        if ($label === null) {
            return null;
        }

        $this->swapPointer($slug, $label, remember: false);

        return $this->resolveRelease($slug, $label);
    }

    /**
     * حذف کامل همهٔ نسخه‌ها، اشاره‌گر، تاریخ و staging یک افزونه.
     *
     * زیر قفل انجام می‌شود تا با نصبِ همزمان تلاقی نکند؛ اگر قفل در مهلت
     * نگرفته شد `RuntimeException` می‌دهد چون حذف در حینِ نوشتن یعنی نیمه‌پوشهٔ
     * بی‌اسم روی دیسک.
     */
    public function purge(string $slug): void
    {
        $slug = $this->normalizeSlug($slug);

        $handle = $this->acquireLock($slug, 5.0);
        if ($handle === false) {
            throw new RuntimeException(
                "نصب دیگری برای افزونهٔ «{$slug}» در جریان است؛ حذف انجام نشد و نسخهٔ فعال دست‌نخورده ماند."
            );
        }

        try {
            $baseReal = realpath($this->basePath());
            $releaseReal = realpath($this->releaseDir($slug));

            // مرز ایمنی: فقط چیزی پاک می‌شود که واقعاً زیر پایهٔ افزونه است.
            // اگر `releases` یک symlink به بیرون بود، `realpath` بیرون را برمی‌گرداند
            // و ما اصلاً دست نمی‌زنیم — پاک کردن در آن حالت یعنی حذف فایل‌های
            // ناشناسِ کاربر.
            if ($baseReal === false || $releaseReal === false) {
                return;
            }
            if (! $this->isInside($releaseReal, $baseReal)) {
                return;
            }

            $this->removeTree($releaseReal);

            $slugDir = $this->slugDir($slug);
            if (is_dir($slugDir) && realpath($slugDir) === $baseReal.'/'.$slug) {
                @rmdir($slugDir);
            }
        } finally {
            $this->releaseLock($handle);
        }
    }

    // ── قفل ─────────────────────────────────────────────────────────────────

    /**
     * قفل انحصاریِ آن slug، با انتظارِ کران‌دار.
     *
     * کران‌دار و نه بی‌نهایت: این متد از یک درخواست وب صدا زده می‌شود و باید
     * منتظر بماند تا کاربر پیام خطا ببیند، نه تا timeout وب تمام شود.
     *
     * قفل روی **یک پوشهٔ هر slug** است، نه یک قفل سراسری ⇒ نصب دو افزونهٔ
     * مختلف همدیگر را قفل نمی‌کنند.
     *
     * @return resource|false
     */
    public function acquireLock(string $slug, float $timeoutSeconds = 0.0): mixed
    {
        $slug = $this->normalizeSlug($slug);

        try {
            $this->ensureReleaseDir($slug);
        } catch (RuntimeException) {
            return false;
        }

        $handle = @fopen($this->lockPath($slug), 'c');
        if ($handle === false) {
            return false;
        }

        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(self::LOCK_POLL_MICROSECONDS);
        }

        @fclose($handle);

        return false;
    }

    /** @param  resource  $handle */
    public function releaseLock(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    // ── نام‌گذاری نسخه ──────────────────────────────────────────────────────

    public function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                "slug افزونه نامعتبر است: «{$slug}». باید با a-z یا 0-9 شروع شود، فقط a-z 0-9 نقطه خط‌تیره داشته باشد و ۲ تا ۴۰ نویسه باشد."
            );
        }

        return $slug;
    }

    /**
     * برچسب پوشهٔ نسخه: `{version}-{hash8}`.
     *
     * هش به ۸ نویسهٔ اول کوتاه می‌شود، پس caller می‌تواند sha256 کامل بدهد و
     * نتیجه همان مسیری است که با ۸ نویسه هم به دست می‌آید — یعنی «همان محتوا،
     * همان مسیر» واقعاً یکی است و نه دو تا.
     */
    public function releaseLabel(string $version, string $hash): string
    {
        $version = $this->normalizeVersion($version);
        $hash = $this->normalizeHash($hash);

        return $version.'-'.substr($hash, 0, 8);
    }

    public function releasePath(string $slug, string $version, string $hash): string
    {
        return $this->releaseDir($slug).'/'.$this->releaseLabel($version, $hash);
    }

    // ── تضمین ساختار ────────────────────────────────────────────────────────

    /**
     * مطمئن می‌شود `releases/` وجود دارد **و** داخل پایهٔ افزونه است.
     *
     * این لایهٔ دومِ مسیر است و عمداً افزونه‌ای است که از قبل روی دیسک کار
     * گذاشته‌اند: اگر `releases` یک symlink به بیرون باشد (یا حتی یک فایل
     * معمولی باشد)، `realpath` بیرون را برمی‌گرداند و ما پیش از هر نوشتنی
     * می‌ایستیم. بدون این بررسی، `mkdir` و `rename` بی‌سروصدا بیرون از
     * sandbox می‌نوشتند.
     *
     * @throws RuntimeException
     */
    public function ensureReleaseDir(string $slug): void
    {
        $slug = $this->normalizeSlug($slug);
        $base = $this->basePath();

        foreach ([$base, $this->slugDir($slug), $this->releaseDir($slug)] as $dir) {
            if (is_dir($dir)) {
                continue;
            }
            if (@mkdir($dir, 0775, true) || is_dir($dir)) {
                continue;
            }

            throw new RuntimeException(
                "پوشهٔ نصب افزونه ساخته نشد: «{$dir}». فضای دیسک و دسترسی نوشتن روی storage را بررسی کنید."
            );
        }

        $baseReal = realpath($base);
        $releaseReal = realpath($this->releaseDir($slug));

        if ($baseReal === false || $releaseReal === false) {
            throw new RuntimeException("مسیر نصب افزونه قابل‌resolve نیست: «{$this->releaseDir($slug)}».");
        }

        if (! $this->isInside($releaseReal, $baseReal)) {
            throw new RuntimeException(
                'مسیر نصب افزونه به بیرون از پایه اشاره می‌کند؛ نوشتن متوقف شد.'
            );
        }

        $slugReal = realpath($this->slugDir($slug));

        if ($slugReal === false || ! $this->isInside($slugReal, $baseReal)) {
            throw new RuntimeException(
                'پوشهٔ افزونه به بیرون از پایه اشاره می‌کند؛ نوشتن متوقف شد.'
            );
        }
    }

    // ── خصوصی ───────────────────────────────────────────────────────────────

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);

        if ($version === '' || strlen($version) > 40) {
            throw new InvalidArgumentException("نسخهٔ افزونه نامعتبر است: «{$version}».");
        }

        if (preg_match(self::VERSION_PATTERN, $version) !== 1 || str_contains($version, '..')) {
            throw new InvalidArgumentException(
                "نسخهٔ افزونه نامعتبر است: «{$version}». فقط a-z A-Z 0-9 نقطه خط‌تیره و + مجاز است و نباید شامل «..» باشد."
            );
        }

        return $version;
    }

    private function normalizeHash(string $hash): string
    {
        $hash = strtolower(trim($hash));

        if (preg_match(self::HASH_PATTERN, $hash) !== 1) {
            throw new InvalidArgumentException(
                "هش نسخه نامعتبر است: «{$hash}». باید hex کوچک با طول ۸ تا ۶۴ باشد."
            );
        }

        return $hash;
    }

    private function assertLabel(string $label): string
    {
        $label = trim($label);

        if ($label === ''
            || strlen($label) > 120
            || str_contains($label, '..')
            || preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw new InvalidArgumentException("برچسب نسخه نامعتبر است: «{$label}».");
        }

        return $label;
    }

    /**
     * برچسب ⇒ مسیر واقعی، یا `null` اگر چیزی امن نیست.
     *
     * fail-closed است: برچسبِ خراب، مسیرِ بیرون، مسیرِ ناموجود و حتی symlink
     * بیرونی همگی `null` می‌شوند.
     */
    private function resolveRelease(string $slug, string $label): ?string
    {
        try {
            $label = $this->assertLabel($label);
        } catch (InvalidArgumentException) {
            return null;
        }

        $releaseReal = realpath($this->releaseDir($slug));
        if ($releaseReal === false) {
            return null;
        }

        $candidate = realpath($releaseReal.'/'.$label);

        if ($candidate === false || ! is_dir($candidate) || ! $this->isInside($candidate, $releaseReal)) {
            return null;
        }

        return $this->normalizeSeparators($candidate);
    }

    private function readPointer(string $slug): ?string
    {
        $raw = $this->readJson($this->pointerPath($slug));

        $label = $raw['release'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /** @return list<string> */
    private function history(string $slug): array
    {
        $raw = $this->readJson($this->historyPath($slug));
        $out = [];

        foreach ($raw as $label) {
            if (! is_string($label)) {
                continue;
            }
            try {
                $out[] = $this->assertLabel($label);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $out;
    }

    /**
     * جابجایی اشاره‌گر.
     *
     * نوشتن موقت + `rename()` در همان پوشه: یا اشاره‌گر عوض می‌شود یا هیچ.
     * حالت سوم (فایل موقت ماندن) با `@unlink` پاک می‌شود و اشاره‌گر قبلی
     * می‌ماند.
     */
    private function swapPointer(string $slug, string $label, bool $remember): void
    {
        $current = $this->readPointer($slug);

        if ($remember && $current !== null && $current !== $label) {
            $history = $this->history($slug);
            array_unshift($history, $current);
            $this->writeHistory($slug, array_slice(array_values(array_unique($history)), 0, self::MAX_HISTORY));
        }

        $path = $this->pointerPath($slug);
        $payload = (string) json_encode(
            ['release' => $label, 'at' => gmdate('c')],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);

            throw new RuntimeException('اشاره‌گر نسخهٔ فعال نوشته نشد؛ نسخهٔ فعال قبلی دست‌نخورده ماند.');
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException('اشاره‌گر نسخهٔ فعال جایگزین نشد؛ نسخهٔ فعال قبلی دست‌نخورده ماند.');
        }
    }

    /** @param  list<string>  $labels */
    private function writeHistory(string $slug, array $labels): void
    {
        $path = $this->historyPath($slug);
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
        $payload = (string) json_encode(array_values($labels), JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * آیا `path` **واقعاً** زیر `root` است؟
     *
     * فقط `realpath` می‌تواند symlink را بشکند: نام یک پوشه می‌تواند کاملاً
     * بی‌گناه باشد و اشاره‌اش بیرون باشد. جداکنندهٔ `/` هم لازم است چون
     * `realpath` روی ویندوز با `\` برمی‌گردد.
     */
    private function isInside(string $path, string $root): bool
    {
        $root = rtrim($this->normalizeSeparators($root), '/');

        return str_starts_with($this->normalizeSeparators($path), $root.'/');
    }

    private function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
