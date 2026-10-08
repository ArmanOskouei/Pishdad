<?php

namespace App\Services\Plugins;

use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * K5.2 — استخراج امن و atomic بستهٔ افزونه روی دیسک.
 *
 * تنها جایی در هسته که کدِ ناشناسِ یک بستهٔ ناشناس می‌نشیند. قاعدهٔ کلی:
 * **این کلاس هیچ کدی را اجرا نمی‌کند** — نه `include`، نه `require`، نه
 * فراخوانی. فقط فایل می‌نویسد و مسیر می‌سازد. اجرا با `PluginAutoloader` است که
 * مال موج بعدی است.
 *
 * ## چرا `ZipArchive::extractTo()` استفاده نمی‌شود
 *
 * چون در برابر مسیرهای `..` محافظت کامل ندارد و در برابر symlink اصلاً. ما
 * ورودی‌به‌ورودی می‌نویسیم:
 *
 *  ۱. `fopen($dest, 'xb')` یعنی `O_EXCL|O_CREAT`: اگر فایل از قبل باشد، شکست
 *     می‌خورد. پس نه بازنویسی داریم، نه دنباله‌نویسی روی فایلِ دیگر.
 *  ۲. بعد از هر نوشتن، `realpath($dest)` باید زیر `realpath($root)` باشد.
 *
 * ## سه لایهٔ دفاعی روی مسیر
 *
 *  ۱. `PluginPackageContract::normalizePath()` — `..`، مسیر مطلق، NUL و نام‌های
 *     رزروشدهٔ ویندوز را رد می‌کند.
 *  ۲. `PluginPackageContract::isAllowedPath()` — deny-by-default: هر چیزی بیرون از
 *     `ALLOWED_PATHS` نوشته نمی‌شود، **حتی اگر validator قبلاً آن را دیده
 *     باشد**. این همان لایهٔ دفاعی دومی است که `PluginPackageValidator` به آن
 *     تکیه می‌کند ولی خودش اجرایش نمی‌کند.
 *  ۳. بررسی `realpath` پیش و پس از هر نوشتن، و یک **بازبینی کامل پس از
 *     استخراج** که کل درختِ نوشته‌شده را دوباره می‌گیرد و با فهرستِ انتظاری
 *     مقایسه می‌کند.
 *
 * لایه‌های ۱ و ۲ به‌تنهایی کافی‌اند؛ لایهٔ ۳ برای روزی است که یکی از آن‌ها در یک
 * refactor سهل‌انگارانه حذف شود. یک لایه که هرگز اجرا نشود بی‌ارزش است، پس تست‌ها
 * هر سه را جدا می‌سنجند — از جمله تستی که کل symlinkِ از-پیش-کاشته‌شده را به
 * `releases/` می‌کوبد.
 *
 * ## خطِ سخت در برابر خطِ نرم
 *
 *  - **ردِ کامل (هیچ چیز نصب نمی‌شود):** مسیرِ فرار، symlink، نوعِ ویژهٔ فایل
 *    (fifo/socket/device)، برخوردِ بزرگی/کوچکی، ورودیِ تکراری، بمبِ فشرده‌سازی،
 *    سقفِ تعدادِ فایل‌ها و ورودی‌ها (K5.10)، نبودِ یا خرابیِ manifest، ناهماهنگیِ
 *    manifest با درخواست، هر شکستِ نوشتن، و مسیرِ نسخه‌ای که از قبل چیزی نامعتبر
 *    در آن است. این‌ها نشانهٔ حمله یا بستهٔ بدساخت‌اند؛ ادامه دادن یعنی حدس زدن.
 *  - **ردِ نرم (بقیه نصب می‌شود):** فایلِ تمیز ولی بیرون از `ALLOWED_PATHS`. این
 *    اشتباهِ بسته‌بندی است نه حمله، و کاربر نباید به‌خاطر یک فایل اضافه نتواند
 *    افزونهٔ سالمش را نصب کند. فایل گزارش می‌شود و روی دیسک نمی‌نشیند.
 *
 * ## atomic چگونه کار می‌کند
 *
 * `PluginReleaseManager` می‌گوید نسخهٔ فعال کجاست؛ این کلاس می‌گوید نسخهٔ جدید
 * کجا بنشیند. تنها عملیاتی که روی یک فایل‌سیستمِ معمولی «نوشتنِ قابل‌تعویض» را
 * اتمیک می‌کند `rename()` در همان پوشه است، پس:
 *
 * ```
 * releases/.staging/{label}-{uniq}/   ← استخراج اینجا؛ هیچ‌کس آن را نمی‌بیند
 *          ↓ rename()  ← تنها لحظهٔ دیده‌شدن
 * releases/{label}/                   ← نسخهٔ کامل، یک‌بار برای همیشه
 * ```
 *
 * تا وقتی `rename()` صدا نزده، هیچ فایلی در مسیرِ زندهٔ نسخه نیست. اگر هر چیزی —
 * از جمله خودِ `rename()` — شکست بخورد، پوشهٔ staging پاک می‌شود و نسخهٔ فعالِ
 * قبلی دست‌نخورده می‌ماند. **فعال‌سازی (اشاره‌گر) گامِ جداست** و فقط بعد از
 * جابجاییِ موفق انجام می‌شود، پس «نصب شد ولی اجرا نشد» یک حالتِ ممکن و بی‌خطر است.
 */
final class PluginInstaller
{
    /** بیت‌های نوعِ فایل در ۱۶ بیتِ بالای `external_attributes` در حالت UNIX. */
    private const S_IFMT = 0xF000;

    /** S_IFLNK. */
    private const S_IFLNK = 0xA000;

    /** S_IFREG. */
    private const S_IFREG = 0x8000;

    /** S_IFDIR. */
    private const S_IFDIR = 0x4000;

    /**
     * زیر این حجم، نسبتِ فشرده‌سازی معنا ندارد.
     *
     * یک فایلِ ۱۰ بایتی با `comp_size` صفر نسبتِ ۱:۰ دارد و بمب نیست. بمب برای
     * کسبِ حجم ساخته می‌شود، پس آستانه روی «حجم» است نه «نسبت»؛ و سقفِ
     * `MAX_UNCOMPRESSED_BYTES` هم جلوی ضرر را می‌گیرد.
     */
    private const BOMB_FLOOR_BYTES = 1024;

    /** بیشترین طولِ یک segment از مسیر نسبی. */
    private const MAX_SEGMENT_BYTES = 200;

    /** بیشترین طولِ کل مسیر نسبی. */
    private const MAX_PATH_BYTES = 1000;

    /**
     * @param  float  $lockTimeout  چقدر برای قفلِ نصبِ همزمان صبر کنیم (ثانیه)
     * @param  PluginIntegritySeal|null  $seal  مهر یکپارچگی (K5.2-W). تزریق‌پذیر
     *                                        و اختیاری است تا سازندهٔ دو پارامتریِ
     *                                        قبلی نشکند؛ `null` یعنی از container.
     */
    public function __construct(
        private PluginReleaseManager $releases,
        private float $lockTimeout = 10.0,
        private ?PluginIntegritySeal $seal = null,
    ) {}

    private function seal(): PluginIntegritySeal
    {
        return $this->seal ??= app(PluginIntegritySeal::class);
    }

    /**
     * یک بستهٔ ZIP را در مسیرِ نسخهٔ خودش نصب می‌کند.
     *
     * ## چرا exception ندارد
     *
     * تنها `InvalidArgumentException` (برای slug/version/hash نامعتبر) پرتاب
     * می‌شود و آن هم **خطای برنامه‌نویسی** است نه خطای بسته. هر چیز دیگری — بستهٔ
     * خراب، بستهٔ ناسازگار، دیسک پر، قفلِ گرفته — یک `ok: false` با کدِ
     * ماشین‌خوان برمی‌گرداند. دلیل: caller یعنی `PluginController` باید بتواند
     * بدترین حالت را به کاربر نشان بدهد، و exception در آنجا یعنی صفحهٔ خطای ۵۰۰
     * به‌جای پیامِ «این بسته symlink دارد».
     *
     * ## مسیرِ نسخه محتوا‌محور است
     *
     * هش از **بایت‌های فایل ZIP** گرفته می‌شود، نه از محتوای استخراج‌شده. این
     * عمدی است: همان چیزی است که `PluginController` در ستون `checksum` می‌نویسد
     * و `PluginTrustStore` برای مهرِ یکپارچگی (`content_digest`) استفاده می‌کند،
     * پس «برچسبِ پوشه» و «مهرِ دیتابیس» یکی می‌شوند.
     *
     * هزینه‌اش را باید صریح گفت: `ZipArchive` زمانِ ویرایشِ هر ورودی را در خود
     * ذخیره می‌کند، پس **دو بار بسته‌بندیِ یک محتوای یکسان، دو فایلِ متفاوت
     * می‌دهد و دو مسیرِ متفاوت**. برای نصبِ دوبارهٔ همان فایلِ آپلودشده بی‌اشکال
     * است (idempotent)، ولی caller نباید انتظار داشته باشد دو آپلودِ مستقلِ یک
     * بسته به یک پوشه ختم شوند. اگر روزی «یک نسخه = یک پوشه» لازم شد، هش باید
     * از فهرستِ فایل‌های استخراج‌شده گرفته شود نه از فایلِ ZIP.
     *
     * @param  string  $zipPath  مسیر فایل ZIP روی دیسک
     * @param  string  $slug  شناسهٔ افزونه، از مانیفست
     * @param  string  $version  نسخه، از مانیفست
     * @param  string|null  $hash  هش محتوا. `null` یعنی از خودِ فایل حساب شود؛ داده
     *                             شود و با بایت‌های روی دیسک نخواند، نصب رد می‌شود.
     *                             مسیرِ نسخه **محتوا‌محور** است، پس هشِ دروغین یعنی
     *                             دو بستهٔ متفاوت که ادعا می‌کنند یکی‌اند.
     * @return array{
     *   ok: bool,
     *   code: string,
     *   message: string,
     *   path: ?string,
     *   release: ?string,
     *   autoload_root: ?string,
     *   extracted: list<string>,
     *   verified: int,
     *   skipped: list<array{path: string, reason: string}>,
     *   already_installed: bool
     * }
     *
     * @throws InvalidArgumentException اگر slug یا version یا hash نامعتبر باشد
     */
    public function install(string $zipPath, string $slug, string $version, ?string $hash = null): array
    {
        $digest = @hash_file('sha256', $zipPath);

        if ($digest === false) {
            return self::failure(
                'install.zip_unreadable',
                'فایل بسته خوانده نشد؛ چیزی نصب نشد و نسخهٔ فعال قبلی دست‌نخورده ماند.'
            );
        }

        if ($hash !== null && ! $this->digestMatches($hash, $digest)) {
            return self::failure(
                'install.hash_mismatch',
                'هشِ اعلام‌شده با محتوای فایل بسته نمی‌خواند؛ چیزی نصب نشد تا نسخهٔ فعال قبلی دست‌نخورده بماند.'
            );
        }

        // اعتبارسنجیِ هویت پیش از هر I/O. `InvalidArgumentException` یعنی bug.
        $slug = $this->releases->normalizeSlug($slug);
        $label = $this->releases->releaseLabel($version, $digest);
        $target = $this->releases->releasePath($slug, $version, $digest);

        try {
            $this->releases->ensureReleaseDir($slug);
        } catch (RuntimeException $e) {
            return self::failure('install.storage_unavailable', $e->getMessage());
        }

        $handle = $this->releases->acquireLock($slug, $this->lockTimeout);

        if ($handle === false) {
            return self::failure(
                'install.locked',
                "نصبِ دیگری برای افزونهٔ «{$slug}» در جریان است؛ چیزی نوشته نشد و نسخهٔ فعال قبلی دست‌نخورده ماند."
            );
        }

        try {
            // ── ۱) idempotent ──
            if (is_dir($target) && $this->releases->isUsable($target)) {
                $this->sealVersion($slug, $label, $target);

                return self::success($label, $target, alreadyInstalled: true);
            }

            // ── ۲) مسیرِ نسخه از قبل چیزی دارد که نسخهٔ ما نیست ──
            //
            // عمداً **پاک یا بازنویسی نمی‌کنیم**. تنها راهی که یک نسخه می‌تواند
            // اینجا باشد `rename()` موفقِ خودِ ماست، و آن نسخه یا کامل است یا
            // اصلاً وجود ندارد. پس چیزی که اینجا نامعتبر است یا دستکاریِ
            // بیرونی است یا خرابیِ دیسک — و هر دو، حذفِ خودکارِ بایت‌هایی که
            // خودمان نساخته‌ایم نیستند.
            if (file_exists($target) || is_link($target)) {
                $current = $this->releases->currentDir($slug);
                $targetReal = realpath($target);

                if ($current !== null && $targetReal !== false && $current === $targetReal) {
                    return self::failure(
                        'install.active_release_corrupt',
                        "پوشهٔ نسخهٔ فعالِ «{$slug}» ناقص است. بازنویسیِ مسیرِ فعال ممنوع است؛ نخست rollback کنید یا purge بزنید."
                    );
                }

                return self::failure(
                    'install.target_occupied',
                    "مسیرِ نسخهٔ «{$label}» از قبل با چیزی نامعتبر اشغال است. آن را دست نزدیم؛ اگر بازماندهٔ یک نصبِ نیمه‌کاره است با purge پاکش کنید."
                );
            }

            // ── ۳) استخراج در staging ──
            $staged = $this->extractIntoStaging($zipPath, $slug, $label, $version);

            if (! $staged['ok']) {
                return self::failure($staged['code'], $staged['message'], skipped: $staged['skipped']);
            }

            // ── ۴) جابجاییِ اتمیک ──
            if (! @rename($staged['staging'], $target)) {
                // شاید یک نصبِ همزمان در همین لحظه برنده شده باشد.
                if (is_dir($target) && $this->releases->isUsable($target)) {
                    $this->removeTree($staged['staging']);
                    $this->sealVersion($slug, $label, $target);

                    return self::success($label, $target, alreadyInstalled: true);
                }

                $this->removeTree($staged['staging']);

                return self::failure(
                    'install.rename_failed',
                    "نسخهٔ «{$label}» روی مسیرِ خودش جایگزین نشد؛ پوشهٔ موقت پاک شد و نسخهٔ فعال قبلی دست‌نخورده ماند."
                );
            }

            // K5.2-W — مهر یکپارچگی **بعد از** `rename()` زده می‌شود، نه داخل
            // staging. دلیل: اگر داخل staging بود، مهر باید داخلِ پوشهٔ نسخه
            // می‌نشست و شمارشِ فایل‌های استخراج‌شده (و قراردادِ «مهر، جزو بسته
            // نیست») می‌شکست.
            //
            // ترتیب هم عمدی است: نسخهٔ بدونِ مهر وضعیتِ `unsealed` است، یعنی
            // «هنوز تأییدنشده» و قابلِ درست‌کردن با `reseal()`. برعکسش یعنی
            // مهری که به مسیری اشاره می‌کند که هرگز وجود نداشت.
            $this->sealVersion($slug, $label, $target);

            return self::success(
                $label,
                $target,
                extracted: $staged['extracted'],
                verified: $staged['verified'],
                skipped: $staged['skipped'],
            );
        } finally {
            $this->releases->releaseLock($handle);
        }
    }

    // ── استخراج ─────────────────────────────────────────────────────────────

    /**
     * کل بسته را در یک پوشهٔ موقت می‌نویسد و بازبینی‌اش می‌کند.
     *
     * شکلِ برگشتی با `install()` فرق دارد چون `path` در این مرحله هنوز مسیرِ
     * موقت است و بعد از `rename` بی‌معنا می‌شود.
     *
     * @return array{ok: bool, staging: string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, code: ?string, message: ?string}
     */
    private function extractIntoStaging(string $zipPath, string $slug, string $label, string $version): array
    {
        $staging = $this->releases->newStagingPath($slug, $label);

        if (! is_dir($staging) && ! @mkdir($staging, 0775, true) && ! is_dir($staging)) {
            return self::abortResult(
                '',
                'install.storage_unavailable',
                'پوشهٔ موقتِ استخراج ساخته نشد؛ چیزی نصب نشد.'
            );
        }

        // ریشهٔ canonical. هر نوشتنِ بعدی با همین مقایسه می‌شود.
        $root = realpath($staging);
        $releaseReal = realpath($this->releases->releaseDir($slug));

        if ($root === false || $releaseReal === false || ! $this->isInside($root, $releaseReal)) {
            $this->removeTree($staging);

            return self::abortResult(
                '',
                'install.escaped_root',
                'پوشهٔ موقت قابل‌اعتماد نیست؛ نوشتن متوقف شد.'
            );
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($opened !== true) {
            $this->removeTree($root);

            return self::abortResult(
                '',
                'install.zip_unreadable',
                'بستهٔ ZIP باز نشد؛ چیزی نصب نشد.'
            );
        }

        /** @var array<string, array{index: int, size: int}> $accepted */
        $accepted = [];
        /** @var list<array{path: string, reason: string}> $skipped */
        $skipped = [];
        $totalBytes = 0;

        try {
            // ── گذر ۱: طبقه‌بندیِ همهٔ ورودی‌ها، پیش از هر نوشتنی ──
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    return $this->abort($root, $skipped, 'install.zip_unreadable', 'ورودیِ ناخوانا در بستهٔ ZIP.');
                }

                $raw = (string) $stat['name'];

                if (str_ends_with($raw, '/')) {
                    continue;
                }

                $type = $this->entryType($zip, $i);

                if ($type === self::S_IFDIR) {
                    continue;
                }

                if ($type === self::S_IFLNK) {
                    return $this->abort($root, $skipped, 'install.symlink_entry', "ورودیِ symlink در بسته: «{$raw}».");
                }

                // هر چیزی جز فایلِ معمولی (یا بیتِ نوعِ نامشخص در ZIP ویندوزی)
                // یعنی fifo/socket/device که روی فایل‌سیستمِ میزبان خطرناک‌اند.
                if (! in_array($type, [0x0000, self::S_IFREG], true)) {
                    return $this->abort($root, $skipped, 'install.special_entry', "ورودیِ نوع‌ویژه در بسته: «{$raw}».");
                }

                $relative = $this->safeName($raw);

                if ($relative === null) {
                    return $this->abort($root, $skipped, 'install.path_traversal', "نامِ ورودی ناامن است: «{$raw}».");
                }

                if (! PluginPackageContract::isAllowedPath($relative)) {
                    $skipped[] = ['path' => $relative, 'reason' => 'not_allowed'];

                    continue;
                }

                if (isset($accepted[$relative])) {
                    return $this->abort($root, $skipped, 'install.duplicate_entry', "ورودیِ تکراری در بسته: «{$relative}».");
                }

                $size = (int) $stat['size'];
                $totalBytes += $size;

                if ($totalBytes > PluginPackageContract::MAX_UNCOMPRESSED_BYTES) {
                    return $this->abort(
                        $root,
                        $skipped,
                        'install.too_large',
                        'حجمِ بازشدهٔ بسته از سقفِ مجاز فراتر رفت (zip bomb).'
                    );
                }

                if ($this->isCompressionBomb($stat)) {
                    return $this->abort(
                        $root,
                        $skipped,
                        'install.compression_bomb',
                        "نسبتِ فشرده‌سازیِ «{$relative}» غیرمنطقی است (zip bomb)."
                    );
                }

                $accepted[$relative] = ['index' => $i, 'size' => $size];
            }

            if (count($accepted) > PluginPackageContract::MAX_FILES) {
                return $this->abort($root, $skipped, 'install.too_many_files', 'تعداد فایل‌های بسته از سقفِ مجاز فراتر رفت.');
            }

            // K5.10 — سقف روی **کل فهرست**، که جمعیتِ دیگری از شرطِ بالاست.
            //
            // شرطِ بالا فقط *پذیرفته‌شده‌ها* را می‌شمارد. ورودی‌های بیرون از
            // `ALLOWED_PATHS` ردِ نرم می‌خورند (به `$skipped` می‌روند) و اصلاً در آن
            // شمارش نیستند، پس بسته‌ای با ۱۰ فایلِ مجاز و ۵۰۰۰ ورودیِ زائد از
            // `install.too_many_files` رد می‌شد. `PluginPackageValidator` همین
            // جمعیت را می‌سنجد (`PluginPackageValidator.php:121` — روی همهٔ
            // ورودی‌ها از جمله پوشه‌ها) ولی این کلاس عمداً به هیچ لایهٔ بیرونی تکیه
            // نمی‌کند: وگرنه با حذفِ آن تحلیلگر، این سقف بی‌صدا می‌شد.
            //
            // این دو رقیب نیستند و هرکدام یک چیز را می‌سنجند: اولی فایل‌هایی که
            // واقعاً نوشته می‌شوند، دومی کلِ چیزی که مجبوریم طبقه‌بندی کنیم. ترتیب
            // عمداً همین است تا کدِ خطای بسته‌ای که از قبل رد می‌شد تغییر نکند.
            if ($zip->numFiles > PluginPackageContract::MAX_FILES) {
                return $this->abort(
                    $root,
                    $skipped,
                    'install.too_many_entries',
                    'بسته '.$zip->numFiles.' ورودی دارد و سقف '.PluginPackageContract::MAX_FILES.' است.'
                );
            }

            // روی لینوکس هر دو فایل نوشته می‌شوند؛ روی هاستِ ویندوزی دومی بی‌صدا
            // اولی را overwrite می‌کند. هستهٔ این CMS روی هاستِ ویندوزی هم اجرا
            // می‌شود، پس این یک باگِ واقعی است نه یک نگرانیِ نظری.
            $collisions = PluginPackageContract::findCaseCollisions(array_keys($accepted));

            if ($collisions !== []) {
                return $this->abort(
                    $root,
                    $skipped,
                    'install.case_collision',
                    "برخوردِ بزرگی/کوچکی در بسته: «{$collisions[0]['a']}» و «{$collisions[0]['b']}»."
                );
            }

            if (! isset($accepted[PluginPackageContract::MANIFEST])) {
                return $this->abort(
                    $root,
                    $skipped,
                    'install.manifest_missing',
                    PluginPackageContract::MANIFEST.' در بسته نیست؛ بدون آن چیزی قابل‌شناسایی نیست.'
                );
            }

            // ── گذر ۲: نوشتن ──
            foreach ($accepted as $relative => $entry) {
                $written = $this->writeEntry($zip, $relative, $entry, $root);

                if ($written !== true) {
                    return $this->abort($root, $skipped, $written['code'], $written['message']);
                }
            }

            // ── گذر ۳: بازبینیِ کاملِ آنچه واقعاً روی دیسک نشست ──
            $verified = $this->verifyStaging($root, $accepted);

            if (! $verified['ok']) {
                return $this->abort($root, $skipped, $verified['code'], $verified['message']);
            }

            // ── گذر ۴: هویتِ بسته ──
            $manifest = $this->readStagedManifest($root);

            if ($manifest === null) {
                return $this->abort(
                    $root,
                    $skipped,
                    'install.manifest_invalid',
                    PluginPackageContract::MANIFEST.' خوانده نشد یا JSON معتبر ندارد.'
                );
            }

            if (($manifest['slug'] ?? null) !== $slug || (string) ($manifest['version'] ?? '') !== trim($version)) {
                return $this->abort(
                    $root,
                    $skipped,
                    'install.manifest_mismatch',
                    'مانیفستِ داخل بسته با هویتِ درخواست‌شده نمی‌خواند؛ یعنی بین تحلیل و نصب، بسته عوض شده است.'
                );
            }
        } finally {
            $zip->close();
        }

        return self::stagedResult($root, array_keys($accepted), count($accepted), $skipped);
    }

    /**
     * نوشتنِ یک ورودی، با قفلِ انحصاری روی مقصد.
     *
     * @param  array{index: int, size: int}  $entry
     * @return true|array{code: string, message: string}
     */
    private function writeEntry(ZipArchive $zip, string $relative, array $entry, string $root): true|array
    {
        $destination = $root.'/'.$relative;
        $parent = dirname($destination);

        if (! is_dir($parent) && ! @mkdir($parent, 0775, true) && ! is_dir($parent)) {
            return ['code' => 'install.write_failed', 'message' => "پوشهٔ «{$relative}» ساخته نشد."];
        }

        // لایهٔ ۳: شاید یکی از پوشه‌های میانی symlink به بیرون باشد. «یا خودِ
        // ریشه» هم مجاز است چون `manifest.json` مستقیم در ریشهٔ staging می‌نشیند.
        $parentReal = realpath($parent);

        if ($parentReal === false || ! $this->isAtOrInside($parentReal, $root)) {
            return ['code' => 'install.escaped_root', 'message' => "مسیرِ «{$relative}» از ریشهٔ استخراج بیرون می‌زود."];
        }

        // `x` یعنی O_EXCL|O_CREAT: هرگز بازنویسی نمی‌کند و هرگز دنباله نمی‌نویسد.
        $handle = @fopen($destination, 'xb');

        if ($handle === false) {
            return ['code' => 'install.write_failed', 'message' => "«{$relative}» نوشته نشد؛ از قبل وجود دارد یا دسترسی ندارد."];
        }

        $source = $zip->getStreamIndex($entry['index']);

        if ($source === false) {
            fclose($handle);
            @unlink($destination);

            return ['code' => 'install.zip_unreadable', 'message' => "دادهٔ «{$relative}» از ZIP خوانده نشد."];
        }

        $copied = @stream_copy_to_stream($source, $handle);
        fclose($source);
        $closed = fclose($handle);

        if ($copied === false || $closed === false) {
            @unlink($destination);

            return ['code' => 'install.write_failed', 'message' => "«{$relative}» کامل نوشته نشد."];
        }

        if ($copied !== $entry['size']) {
            @unlink($destination);

            return [
                'code' => 'install.incomplete',
                'message' => "«{$relative}» ناقص نوشته شد ({$copied} از {$entry['size']} بایت).",
            ];
        }

        $real = realpath($destination);

        if ($real === false || ! $this->isInside($real, $root)) {
            @unlink($destination);

            return ['code' => 'install.escaped_root', 'message' => "«{$relative}» بیرون از ریشهٔ استخراج افتاد."];
        }

        return true;
    }

    /**
     * بازبینیِ درختِ استخراج‌شده.
     *
     * اینجاست که «واقعاً چه چیزی روی دیسک هست» با «چه چیزی باید باشد» مقایسه
     * می‌شود: نه فقط شمارش، بلکه هر نام و هر اندازه. یعنی یک نوشتهٔ نیمه‌کاره، یک
     * فایلِ غیرمنتظره، یا یک symlink که somehow روی دیسک نشسته باشد ⇒ ردِ کلِ
     * نصب، پیش از آنکه `rename` آن را به مسیرِ زنده ببرد.
     *
     * @param  array<string, array{index: int, size: int}>  $expected
     * @return array{ok: bool, count?: int, code?: string, message?: string}
     */
    private function verifyStaging(string $root, array $expected): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            // SELF_FIRST لازم است: پیمایشگر به‌طور پیش‌فرض داخلِ symlinkِ یک
            // پوشه نمی‌رود، پس آن symlink هرگز دیده نمی‌شود — ولی خودِ عنصر در
            // پیمایش هست و `isLink()` می‌گیرد.
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return [
                    'ok' => false,
                    'code' => 'install.symlink_entry',
                    'message' => 'پوشهٔ استخراج‌شده حاویِ symlink است.',
                ];
            }

            if ($file->isDir()) {
                continue;
            }

            $path = $this->normalizeSeparators($file->getPathname());

            if (! $this->isInside($path, $root)) {
                return [
                    'ok' => false,
                    'code' => 'install.escaped_root',
                    'message' => "فایلِ استخراج‌شده بیرون از ریشه است: «{$path}».",
                ];
            }

            $real = realpath($file->getPathname());

            if ($real === false || ! $this->isInside($this->normalizeSeparators($real), $root)) {
                return [
                    'ok' => false,
                    'code' => 'install.escaped_root',
                    'message' => "فایلِ استخراج‌شده به بیرون اشاره می‌کند: «{$path}».",
                ];
            }

            $found[ltrim(substr($path, strlen($root)), '/')] = (int) $file->getSize();
        }

        if (count($found) !== count($expected)) {
            return [
                'ok' => false,
                'code' => 'install.incomplete',
                'message' => 'تعداد فایل‌های روی دیسک با تعداد ورودی‌های بسته نمی‌خواند.',
            ];
        }

        foreach ($expected as $relative => $entry) {
            if (! array_key_exists($relative, $found)) {
                return [
                    'ok' => false,
                    'code' => 'install.incomplete',
                    'message' => "«{$relative}» روی دیسک نیست.",
                ];
            }

            if ($found[$relative] !== $entry['size']) {
                return [
                    'ok' => false,
                    'code' => 'install.incomplete',
                    'message' => "«{$relative}» با اندازهٔ ناقص نوشته شده.",
                ];
            }
        }

        return ['ok' => true, 'count' => count($found)];
    }

    // ── کمک‌کارها ───────────────────────────────────────────────────────────

    /**
     * زدنِ مهر روی نسخهٔ تازه‌نشسته، بدون اینکه شکستش نصب را بیندازد.
     *
     * ⚠️ **چرا شکستِ مهر نصب را رد نمی‌کند.** نصب یعنی «فایل‌ها روی دیسک نشستند» و
     * تا وقتی فعال نشود هیچ کدی اجرا نمی‌شود. اگر نوشتنِ مهر نصب را رد می‌کرد،
     * یک مشکلِ کلیدِ مهر (که ارتباطی با بستهٔ کاربر ندارد) جلوی نصبِ یک افزونهٔ
     * سالم را می‌گرفت. در عوض نسخه `unsealed` می‌ماند، و **فعال‌سازی** — که دروازهٔ
     * اعتماد است — مهر را لازم دارد و پیامِ روشن می‌دهد.
     */
    private function sealVersion(string $slug, string $label, string $releaseDir): void
    {
        try {
            $result = $this->seal()->seal($slug, $releaseDir, $label);
        } catch (\Throwable $e) {
            Log::warning('plugin.seal_threw', [
                'slug' => $slug,
                'release' => $label,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $result['ok']) {
            Log::warning('plugin.seal_not_written', [
                'slug' => $slug,
                'release' => $label,
                'code' => $result['code'],
                'reason' => $result['message'],
            ]);
        }
    }

    /**
     * نوعِ فایلِ ورودی، از `external_attributes`.
     *
     * `ZipArchive::statIndex()` این بیت‌ها را **نمی‌دهد**؛ تنها
     * `getExternalAttributesIndex()` آن‌ها را دارد. صفر یعنی «بیتِ نوعِ
     * نامشخص» که حالتِ عادیِ ZIPهای ساختهٔ ویندوز است و باید پذیرفته شود.
     */
    private function entryType(ZipArchive $zip, int $index): int
    {
        $opsys = 0;
        $attr = 0;

        if (@$zip->getExternalAttributesIndex($index, $opsys, $attr) !== true) {
            return 0x0000;
        }

        return ($attr >> 16) & self::S_IFMT;
    }

    /**
     * نامِ ورودی به یک مسیرِ نسبیِ قابل‌اعتماد تبدیل می‌شود، یا `null`.
     *
     * `PluginPackageContract::normalizePath()` بخشِ اول است؛ این‌ها بخشِ دوم و
     * عمداً **مستقل** از آن‌اند، چون هر قاعده‌ای که فقط در یک لایه باشد با یک
     * refactor بی‌سروصدا از کار می‌افتد:
     *
     *  - کاراکترِ کنترلی (نامِ فایل با `\n` می‌تواند لاگ و ترمینال را آلوده کند)
     *  - فاصله یا نقطه در انتهای segment (ویندوز بی‌صدا حذفشان می‌کند ⇒ نامی که ما
     *    اعتبارسنجی کردیم با نامی که روی دیسک نشست فرق دارد)
     *  - کاراکترهای ممنوعِ ویندوز (`<>:"|?*`)
     *  - کرانِ طول (ورودیِ ۳۰۰ کیلوبایتی در یک نام، DoS است نه خطا)
     */
    private function safeName(string $raw): ?string
    {
        $normalized = PluginPackageContract::normalizePath($raw);

        if ($normalized === null) {
            return null;
        }

        if (strlen($normalized) > self::MAX_PATH_BYTES) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            return null;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
                return null;
            }
            if (trim($segment) !== $segment) {
                return null;
            }
            if (preg_match('/[ .]$/', $segment) === 1) {
                return null;
            }
            if (preg_match('/[<>:"|?*]/', $segment) === 1) {
                return null;
            }
        }

        return $normalized;
    }

    private function isCompressionBomb(array $stat): bool
    {
        $size = (int) $stat['size'];

        if ($size <= self::BOMB_FLOOR_BYTES) {
            return false;
        }

        $compressed = (int) $stat['comp_size'];

        if ($compressed <= 0) {
            return true;
        }

        return ($size / $compressed) > PluginPackageContract::MAX_COMPRESSION_RATIO;
    }

    private function readStagedManifest(string $root): ?array
    {
        $raw = @file_get_contents($root.'/'.PluginPackageContract::MANIFEST);

        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function digestMatches(string $expected, string $actual): bool
    {
        $expected = strtolower(trim($expected));

        if (preg_match('/^[0-9a-f]{8,64}$/', $expected) !== 1) {
            throw new InvalidArgumentException(
                "هشِ محتوا نامعتبر است: «{$expected}». باید hex با طولِ ۸ تا ۶۴ باشد."
            );
        }

        return str_starts_with($actual, $expected);
    }

    /**
     * شکستِ سخت: پوشهٔ staging پاک می‌شود و نصب رد می‌شود.
     *
     * پاک کردنِ staging **اجباری** است، نه تزئینی: پوشهٔ نیمه‌کارِ موقت اگر بماند
     * فضای دیسک می‌خورد و در کنار نسخه‌های واقعی بی‌صدا می‌ماند.
     *
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array{ok: bool, staging: string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, code: ?string, message: ?string}
     */
    private function abort(string $root, array $skipped, string $code, string $message): array
    {
        $this->removeTree($root);

        return self::abortResult(
            '',
            $code,
            $message.' چیزی نصب نشد و نسخهٔ فعالِ قبلی دست‌نخورده ماند.',
            $skipped
        );
    }

    private function isInside(string $path, string $root): bool
    {
        $root = rtrim($this->normalizeSeparators($root), '/');

        return str_starts_with($this->normalizeSeparators($path), $root.'/');
    }

    /** مثل `isInside`، ولی خودِ ریشه را هم می‌پذیرد. برای پوشهٔ والدِ سطحِ اول. */
    private function isAtOrInside(string $path, string $root): bool
    {
        $path = $this->normalizeSeparators($path);
        $root = rtrim($this->normalizeSeparators($root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
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

    /**
     * @param  list<string>  $extracted
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array{ok: bool, path: ?string, release: ?string, autoload_root: ?string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, already_installed: bool, code: string, message: string}
     */
    private static function success(
        string $label,
        string $path,
        array $extracted = [],
        int $verified = 0,
        array $skipped = [],
        bool $alreadyInstalled = false,
    ): array {
        $real = realpath($path);
        $resolved = $real === false ? $path : self::normalize($real);

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => $alreadyInstalled
                ? "نسخهٔ «{$label}» از قبل کامل روی دیسک بود؛ هیچ چیزی بازنویسی نشد."
                : "نسخهٔ «{$label}» نصب شد. تا وقتی فعال نشود اجرا نمی‌شود.",
            'path' => $resolved,
            'release' => $label,
            'autoload_root' => self::psr4Root($resolved),
            'extracted' => $extracted,
            'verified' => $verified,
            'skipped' => $skipped,
            'already_installed' => $alreadyInstalled,
        ];
    }

    /**
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array{ok: bool, code: string, message: string, path: null, release: null, autoload_root: null, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, already_installed: false}
     */
    private static function failure(string $code, string $message, array $skipped = []): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'path' => null,
            'release' => null,
            'autoload_root' => null,
            'extracted' => [],
            'verified' => 0,
            'skipped' => $skipped,
            'already_installed' => false,
        ];
    }

    /**
     * @param  list<string>  $extracted
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array{ok: bool, staging: string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, code: null, message: null}
     */
    private static function stagedResult(string $staging, array $extracted, int $verified, array $skipped): array
    {
        return [
            'ok' => true,
            'staging' => $staging,
            'extracted' => $extracted,
            'verified' => $verified,
            'skipped' => $skipped,
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return array{ok: false, staging: string, extracted: list<string>, verified: int, skipped: list<array{path: string, reason: string}>, code: string, message: string}
     */
    private static function abortResult(string $staging, string $code, string $message, array $skipped = []): array
    {
        return [
            'ok' => false,
            'staging' => $staging,
            'extracted' => [],
            'verified' => 0,
            'skipped' => $skipped,
            'code' => $code,
            'message' => $message,
        ];
    }

    private static function psr4Root(string $releaseDir): ?string
    {
        $real = realpath(self::normalize($releaseDir).'/'.PluginReleaseManager::PSR4_SUBPATH);

        return $real === false ? null : self::normalize($real);
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
