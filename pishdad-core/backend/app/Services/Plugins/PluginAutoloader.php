<?php

namespace App\Services\Plugins;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * K5.3 — بارگذار PSR-4 کلاس‌های افزونه. همان حلقهٔ گم‌شده‌ای که بدون آن
 * `core.service_provider` (K5.0) فقط روی کاغذ کار می‌کرد.
 *
 * چرا این کلاس وجود دارد و چرا این‌قدر کوچک است: هسته هیچ namespace ای جز
 * `App\` را autoload نمی‌کند، پس کلاسی که افزونه در مانیفست اعلام کرده («پیاده‌سازی
 * `PaymentGatewayInterface` این کلاس است») در لحظهٔ `class_exists()` پیدا نمی‌شد
 * و رجیستری بی‌صدا به پیش‌فرض هسته برمی‌گشت.
 *
 * **یک loader عمومی نیست؛ بارگذار افزونه است.** ریشهٔ namespace و ریشهٔ فایل هر
 * دو قفل‌اند، پس یک manifest خراب یا یک مسیر اشتباه نمی‌تواند به `App\`، به
 * `/etc/passwd` یا به بیرون از ریشهٔ افزونه برسد. هر سه قاعده در متد خصوصی و
 * تنها `locate()` جمع شده‌اند: یک مسیر ورود به سیستم فایل، برای بازبینی امنیتی.
 *
 * تصمیم‌های غیرم obvious:
 *
 *  ۱. **`spl_autoload_register` خودمان، نه `ClassLoader::addPsr4`.** حذف prefix از
 *     `ClassLoader` composer API عمومی ندارد؛ تنها راه، `setPsr4()` با بازنویسی
 *     کل نقشه است که تغییرات هر agent دیگری روی همان loader را پاک می‌کند. و
 *     `unregister()` اینجا یک الزام است، نه راحتی: غیرفعال‌کردن افزونه باید کدش را
 *     واقعاً از دسترس خارج کند.
 *  ۲. **`append`، نه `prepend`.** loader ما اول از همهٔ کلاس‌های اپ اجرا می‌شود اگر
 *     prepend کنیم؛ با append فقط کلاس‌هایی به ما می‌رسند که composer نتوانسته
 *     پیدا کند، پس روی مسیر گرم اپ سرباری نداریم.
 *  ۳. **هوک تا آخرین ثبت باز می‌ماند و بعد از آخرین `unregister` بسته می‌شود.**
 *     بدون افزونهٔ فعال، هیچ callback اضافه‌ای در پروسه نیست.
 *  ۴. **سکوت در نبودِ کلاس.** `class_exists()` خودش false می‌دهد؛ exception دادن
 *     یعنی یک بستهٔ خراب کل سایت را می‌اندازد.
 */
final class PluginAutoloader
{
    /**
     * ریشهٔ اجباری namespace کلاس افزونه.
     *
     * از `PluginPackageContract` وام گرفته می‌شود، نه اینجا تکرار: همین دلیلی است
     * که `ServiceProviderRegistry` هم به همان ثابت تکیه می‌کند. دو تعریف جدا یعنی
     * جایی هست که افزونه تأیید می‌شود ولی بارگذار ردش می‌کند (یا برعکس) و هیچ‌کس
     * نمی‌فهمد چرا.
     */
    public const NAMESPACE_ROOT = PluginPackageContract::PLUGIN_NAMESPACE_PREFIX;

    /** یک segment از namespace باید یک شناسهٔ معتبر PHP باشد — غیر از این یعنی نیست. */
    private const SEGMENT_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';

    /** @var array<string, array{dir: string, prefix: string}> */
    private array $packages = [];

    private ?Closure $hook = null;

    /**
     * یک بستهٔ افزونه را زیر ریشهٔ namespace خودش ثبت می‌کند.
     *
     * ثبت دوبارهٔ یک slug، ثبت قبلی را **جایگزین** می‌کند (نه اضافه) — وگرنه
     * ارتقای بسته، دو ریشهٔ فایل برای یک prefix می‌ساخت و اینکه کدام برنده است به
     * ترتیب درج بستگی می‌کرد.
     *
     * @param  string  $slug  شناسهٔ بسته؛ کلید ثبت و مبنای `unregister`
     * @param  string  $baseDir  ریشهٔ PSR-4 روی دیسک
     * @param  string  $prefix  پیشوند namespace، مثلاً `Pishdad\Plugins\Zarinpal\`
     *
     * @throws InvalidArgumentException اگر slug، prefix یا ریشهٔ فایل نامعتبر باشد
     */
    public function register(string $slug, string $baseDir, string $prefix): void
    {
        $slug = $this->normalizeSlug($slug);
        $prefix = $this->normalizePrefix($prefix);

        $this->packages[$slug] = ['dir' => $this->resolveRoot($baseDir), 'prefix' => $prefix];

        $this->hook();
    }

    /**
     * ثبت گروهی بسته‌های نصب‌شده از روی دیسک.
     *
     * namespace هر بسته از slug مشتق می‌شود (`Str::studly`) تا **یک منبع حقیقت**
     * بماند؛ اگر caller خودش prefix را بدهد، دو نفر می‌توانند برای یک بسته دو
     * namespace متفاوت بسازند و هیچ‌کس نفهمد چرا کلاس پیدا نمی‌شود.
     *
     * **fail-soft عمدی، برخلاف `register()`:** نبودِ یک بسته یا حتی نبودِ کل ریشهٔ
     * نصب یعنی «افزونه‌ای نصب نیست» و باید ۰ برگرداند. این متد قرار است از `boot()`
     * یک service provider صدا زده شود؛ exception آنجا یعنی کل سایت بالا نمی‌آید و
     * یک بستهٔ خرابِ تنها دلیلش است.
     *
     * caller باید «چه چیزی ثبت نشد» را خودش لاگ کند — با `array_diff` بین کلیدهای
     * ورودی و `registeredSlugs()`. عمداً لاگ اینجا نزده، چون این کلاس باید بدون
     * facade و DB تست‌شدنی بماند؛ و سکوت دقیقاً همان باگی است که K5.3 دارد
     * درستش می‌کند.
     *
     * @param  array<string, string>  $packages  slug => مسیر نسبی ریشهٔ PSR-4 نسبت به `$rootDir`
     * @return int تعداد بسته‌هایی که واقعاً ثبت شدند
     */
    public function registerPackages(string $rootDir, array $packages): int
    {
        $root = realpath($rootDir);

        if ($root === false || ! is_dir($root)) {
            return 0;
        }

        $registered = 0;

        foreach ($packages as $slug => $relative) {
            if (! is_string($slug) || ! is_string($relative)) {
                continue;
            }

            try {
                $this->register($slug, $root.'/'.$relative, self::NAMESPACE_ROOT.Str::studly($slug).'\\');
                $registered++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $registered;
    }

    public function isRegistered(string $slug): bool
    {
        return isset($this->packages[$slug]);
    }

    public function unregister(string $slug): void
    {
        unset($this->packages[$slug]);

        if ($this->packages === []) {
            $this->unhook();
        }
    }

    /**
     * بسته‌های بارگذاری‌شده: slug => پیشوند namespace (نرمال‌شده، با `\` انتهایی).
     *
     * نقشه برمی‌گردد نه فهرست، چون «کدام افزونه زیر کدام namespace نشسته» همان چیزی
     * است که صفحهٔ تشخیص عیب لازم دارد؛ `array_keys()` هم فهرست را می‌دهد.
     *
     * @return array<string, string>
     */
    public function registeredSlugs(): array
    {
        $out = [];

        foreach ($this->packages as $slug => $package) {
            $out[$slug] = $package['prefix'];
        }

        return $out;
    }

    // ── زنجیرهٔ include ────────────────────────────────────────────────────

    private function hook(): void
    {
        if ($this->hook instanceof Closure) {
            return;
        }

        $this->hook = function (string $class): void {
            $file = $this->locate($class);

            // نبودِ کلاس یا رد شدن امنیتی هر دو یعنی «سکوت». `class_exists()`
            // خودش false می‌دهد و loader بعدی را امتحان می‌کند.
            if ($file === null) {
                return;
            }

            // مثل `ClassLoader` خود composer: اولین فایلِ پیدا‌شده include
            // می‌شود و همین‌جا تمام. اگر فایل کلاس را تعریف نکند، PHP کلاس را
            // ناموجود گزارش می‌دهد — و چون prefix هر بسته از slug مشتق است، دو
            // بسته هرگز prefix هم‌پوشان ندارند پس رقابتی هم در کار نیست.
            include $file;
        };

        spl_autoload_register($this->hook, true, false);
    }

    private function unhook(): void
    {
        if (! $this->hook instanceof Closure) {
            return;
        }

        spl_autoload_unregister($this->hook);
        $this->hook = null;
    }

    /**
     * تنها نقطهٔ ورود به سیستم فایل. هر شرط امنیتی این کلاس اینجاست.
     */
    private function locate(string $class): ?string
    {
        foreach ($this->packages as $package) {
            if (! str_starts_with($class, $package['prefix'])) {
                continue;
            }

            $file = $this->fileFor($class, $package['prefix'], $package['dir']);

            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    private function fileFor(string $class, string $prefix, string $root): ?string
    {
        $relative = substr($class, strlen($prefix));

        if (! $this->isSafeRelativePath($relative)) {
            return null;
        }

        $real = realpath($root.'/'.str_replace('\\', '/', $relative).'.php');

        if ($real === false || ! is_file($real) || ! $this->isInside($real, $root)) {
            return null;
        }

        return $real;
    }

    /**
     * بخش باقی‌ماندهٔ نام کلاس، پس از برداشتن prefix، باید یک مسیر نسبی امن باشد.
     *
     * شرط، «شناسهٔ معتبر بودن هر segment» است و نه فهرست سیاه. اندازه‌گیری نشان
     * داد موتور PHP نامی را که شناسهٔ معتبر نباشد اصلاً به autoloader نمی‌دهد
     * (`..`، `/`، `.`، `-`، `$`، NUL) — پس آن‌ها از قبل بی‌خطرند و این قاعده
     * برایشان حالت دفاعی است، نه دیوار اصلی.
     *
     * چیزی که موتور **می‌فرستد** و همین قاعده می‌گیردش: **segment تهی**. نامی مثل
     * `Pishdad\Plugins\X\\Breach` از بررسی `str_starts_with` قرارداد عبور می‌کند و
     * `class_exists` هم آن را به autoloader می‌دهد؛ `substr` تکهٔ `\Breach` را
     * می‌دهد و بدون این قاعده `{root}//Breach.php` پیدا و include می‌شود — یعنی کدِ
     * بستهٔ افزونه در مسیر autoload هسته اجرا می‌شود.
     *
     * یک شرط به‌جای فهرستی از شکل‌های traversal: هر شکل تازه همین‌جا می‌افتد.
     */
    private function isSafeRelativePath(string $relative): bool
    {
        if ($relative === '') {
            return false;
        }

        foreach (explode('\\', $relative) as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * آیا `path` **واقعاً** زیر `root` است؟
     *
     * `realpath` تنها چیزی است که symlink را می‌شکند: نام فایل می‌تواند کاملاً
     * بی‌گناه باشد و اشاره‌اش بیرون از ریشه باشد. جداکننده هم `/` می‌شود چون روی
     * ویندوز `realpath` با `\` برمی‌گردد و همان پروژه روی هاست ویندوزی هم اجرا
     * می‌شود. `rtrim` روی ریشه لازم است تا ریشهٔ `/` به `//` تبدیل نشود که هیچ
     * مسیری با آن شروع نمی‌شود.
     */
    private function isInside(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return str_starts_with(str_replace('\\', '/', $path), $root.'/');
    }

    // ── اعتبارسنجی ورودی (خطای بلند، چون خطای برنامه‌نویسی است نه ورودی کاربر) ──

    private function resolveRoot(string $baseDir): string
    {
        $real = realpath($baseDir);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException(
                "ریشهٔ افزونه پیدا نشد یا پوشه نیست: «{$baseDir}». بسته نصب‌شده نیست یا مسیرش اشتباه است."
            );
        }

        return $real;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);

        if ($prefix !== '' && ! str_ends_with($prefix, '\\')) {
            $prefix .= '\\';
        }

        if (! str_starts_with($prefix, self::NAMESPACE_ROOT)) {
            throw new InvalidArgumentException(
                "پیشوند namespace «{$prefix}» باید داخل ریشهٔ اجباری «".self::NAMESPACE_ROOT.'» باشد. کلاسی که در ریشهٔ App\\ بنشیند مال هسته است، نه افزونه.'
            );
        }

        $rest = substr($prefix, strlen(self::NAMESPACE_ROOT));

        foreach (explode('\\', trim($rest, '\\')) as $segment) {
            if ($segment !== '' && preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException("پیشوند namespace «{$prefix}» یک namespace معتبر PHP نیست.");
            }
        }

        return $prefix;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);

        // slug کلید ثبت است و callerها اغلب مسیر می‌سازند
        // (`$root.'/'.$slug`). جداکنندهٔ مسیر یعنی تبدیل به مسیر دلخواه.
        if ($slug === '' || preg_match('/[\/\\\\\0]/', $slug) === 1) {
            throw new InvalidArgumentException("شناسهٔ افزونه «{$slug}» برای ثبت بارگذار معتبر نیست.");
        }

        return $slug;
    }
}
