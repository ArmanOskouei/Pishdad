<?php

namespace App\Services\Plugins;

/**
 * K3.0 / B25 — اجباری کردن `requires.core`.
 *
 * تا پیش از این کلاس، `requires.core` فقط در یک کامنت `PluginPackageContract`
 * ذکر شده بود و هرگز خوانده نمی‌شد: پلاگینی که برای هستهٔ ۱.۵ نوشته شده بود بی‌صدا
 * روی هستهٔ ۱.۲ نصب می‌شد و هیچ‌کس نمی‌فهمید. این سرویس تنها جایی است که آن ادعا
 * سنجیده می‌شود.
 *
 * خالص است: بدون DB، بدون فایل، بدون I/O — فقط `version_compare`. ورودی آرایهٔ
 * مانیفست و رشتهٔ نسخهٔ هسته است، خروجی آرایهٔ خطا یا `null`.
 *
 * تصمیم‌های گرامر (fail-closed):
 *
 * - **نبودنِ اعلام = سازگاری.** `requires` غایب ⇒ `null`. پلاگینی که چیزی اعلام
 *   نکرده، با اعمال محدودیت retroactive تنبیه نمی‌شود.
 * - **اعلامِ ناخوانا = خطا، نه سکوت.** چیزی که نمی‌توان خواند را نمی‌شود «سازگار»
 *   فرض کرد؛ این دقیقاً همان وابستگی ظریفی است که B9 دربارهٔ `readManifest`
 *   داشت و امنیت را به ترتیب اجرا وابسته می‌کرد.
 * - **فقط گرامر پذیرفته‌شده.** `||` و عملگرهای ناشناخته پذیرفته نمی‌شوند چون
 *   نیمه‌پشتیبانی از آن‌ها یعنی «Evaluate کردم ولی اشتباه»، که بدتر از رد کردن
 *   صریح است.
 */
final class CoreRequirementChecker
{
    /**
     * نسخهٔ اعلام‌شدهٔ هسته، وقتی هیچ منبع دیگری آن را اعلام نکرده باشد.
     *
     * `composer.json` امروز کلید `version` ندارد و `config/app.php` هم نسخه‌ای
     * اعلام نمی‌کند، پس فعلاً این تنها نقطهٔ اعلام است. `coreVersion()` اول
     * سراغ منابع بیرونی می‌رود و فقط وقتی هیچ‌کدام نبودند به این ثابت می‌افتد.
     */
    public const CORE_VERSION = '1.5.0';

    /** عملگرهای مجاز. نبودِ عملگر یعنی «برابر». */
    private const COMPARATORS = ['>=', '<=', '==', '!=', '>', '<', '='];

    /**
     * آیا نسخهٔ فعلی هسته با `requires.core` پلاگین سازگار است؟
     *
     * @param  array<string, mixed>|null  $manifest  مانیفست خام (بدون اعتبارسنجی ساختاری)
     * @return array{code: string, message: string, required: string, current: string}|null
     */
    public static function check(?array $manifest, string $currentVersion): ?array
    {
        $current = trim($currentVersion);

        // نسخهٔ خودِ هسته ناخوانا ⇒ سازگاری قابل سنجش نیست. رد کردن بسته به‌خاطر
        // نقص خودمان بد است، ولی عبور دادنش یعنی ادعای بی‌پشتوانه.
        if (self::isVersion($current) === false) {
            return self::issue(
                'core.version_invalid',
                'نسخهٔ نصب‌شدهٔ هسته («'.$current.'») قابل خواندن نیست، پس سازگاری پلاگین سنجیده نشد.',
                $current,
                $current
            );
        }

        if ($manifest === null || array_key_exists('requires', $manifest) === false) {
            return null;
        }

        $requires = $manifest['requires'];
        if (! is_array($requires)) {
            return self::issue(
                'requires.invalid',
                'کلید «requires» در مانیفست باید یک آبجکت باشد، مثلاً {"core": ">=1.4.0 <2.0.0"}، ولی مقدار دیگری دارد.',
                self::readable($requires),
                $current
            );
        }

        if (array_key_exists('core', $requires) === false) {
            return null;
        }

        $declared = $requires['core'];
        if (! is_string($declared) || trim($declared) === '') {
            return self::issue(
                'requires.core.invalid',
                'مقدار «requires.core» باید رشتهٔ غیرخالی باشد، مثلاً «^1.4.0». مقدار داده‌شده قابل خواندن نیست.',
                self::readable($declared),
                $current
            );
        }

        $required = trim($declared);
        $tokens = preg_split('/\s+/', $required, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return self::issue(self::GRAMMAR_CODE, self::GRAMMAR_HINT, $required, $current);
        }

        // هر توکن به بازه‌های `version_compare` باز می‌شود. اولین توکن ناخوانا
        // کل عبارت را رد می‌کند — نیمه‌ارزیابی یعنی ارزیابیِ غلط.
        $ranges = [];
        foreach ($tokens as $token) {
            $range = self::expand($token);
            if ($range === null) {
                return self::issue(self::GRAMMAR_CODE, self::GRAMMAR_HINT, $required, $current);
            }
            $ranges[] = $range;
        }

        foreach ($ranges as $range) {
            if (self::holds($range, $current) === false) {
                return self::issue(
                    'requires.core.unsatisfied',
                    'این پلاگین برای هستهٔ «'.$required.'» نوشته شده ولی نسخهٔ نصب‌شدهٔ هسته «'.$current.'» است. تا وقتی هسته به این بازه نرسد یا نسخهٔ سازگار پلاگین آپلود نشود، نصب انجام نمی‌شود.',
                    $required,
                    $current
                );
            }
        }

        return null;
    }

    /**
     * نسخهٔ مؤثر هسته.
     *
     * ترتیب: `config('app.core_version')` (برای نصب‌هایی که نسخه را در تنظیمات
     * اعلام می‌کنند) → `composer.json.version` → ثابت `CORE_VERSION`. اولی و دومی
     * الان وجود ندارند و افزودنشان مال فایل‌هایی است که این کلاس مالشان نیست.
     */
    public static function coreVersion(): string
    {
        $configured = config('app.core_version');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $composer = base_path('composer.json');
        if (is_file($composer)) {
            $decoded = json_decode((string) @file_get_contents($composer), true);
            $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;
            if (is_string($version) && trim($version) !== '') {
                return trim($version);
            }
        }

        return self::CORE_VERSION;
    }

    private const GRAMMAR_CODE = 'requires.core.invalid';

    private const GRAMMAR_HINT = 'شرط «requires.core» قابل خواندن نیست. شکل‌های پذیرفته‌شده: «*» برای هر نسخه، «^1.2.0»، «~1.2.0»، «1.2.*»، یک نسخهٔ دقیق مثل «1.2.0»، و چند شرط با فاصله که با «و» ترکیب می‌شوند مثل «>=1.4.0 <2.0.0».';

    /**
     * @return array{code: string, message: string, required: string, current: string}
     */
    private static function issue(string $code, string $message, string $required, string $current): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'required' => $required,
            'current' => $current,
        ];
    }

    /** نمایش خوانای یک مقدار نامعتبر، بدون افشای ساختارهای عجیب. */
    private static function readable(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_scalar($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return '['.get_debug_type($value).']';
    }

    /**
     * باز کردن یک توکن به فهرست `[operator, version]`ها.
     *
     * خروجی `[]` یعنی «همیشه درست» (`*`). `null` یعنی توکن ناشناخته.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function expand(string $token): ?array
    {
        if (preg_match('/^([><=!]*)\s*(.*)$/', $token, $m) !== 1) {
            return null;
        }

        $operator = $m[1];
        $rest = trim($m[2]);

        if ($rest === '') {
            return null;
        }

        if ($operator !== '') {
            if (in_array($operator, self::COMPARATORS, true) === false) {
                return null;
            }
            if ($rest[0] === '^' || $rest[0] === '~') {
                return null;
            }
            if (self::isVersion($rest) === false) {
                return null;
            }

            return [[$operator === '==' ? '=' : $operator, $rest]];
        }

        if ($rest === '*') {
            return [];
        }

        if ($rest[0] === '^') {
            return self::caret(substr($rest, 1));
        }

        if ($rest[0] === '~') {
            return self::tilde(substr($rest, 1));
        }

        if (self::hasWildcard($rest)) {
            return self::wildcard($rest);
        }

        if (self::isVersion($rest) === false) {
            return null;
        }

        return [['=', $rest]];
    }

    private static function hasWildcard(string $value): bool
    {
        return preg_match('/[xX*]/', $value) === 1;
    }

    /**
     * `^1.2.3` ⇒ `>=1.2.3 <2.0.0`، `^0.2.3` ⇒ `>=0.2.3 <0.3.0`، `^0.0.3` ⇒
     * `>=0.0.3 <0.0.4` — یعنی هرچه چپ‌ترین رقمِ غیرصفر، عمیق‌تر می‌شود محدودیت.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function caret(string $version): ?array
    {
        $segments = self::segments($version);
        if ($segments === null) {
            return null;
        }

        $lower = implode('.', $segments);
        foreach ($segments as $i => $segment) {
            if ((int) $segment !== 0) {
                $upper = $segments;
                $upper[$i] = (string) ((int) $segment + 1);
                for ($j = $i + 1; $j < count($upper); $j++) {
                    $upper[$j] = '0';
                }

                return [['>=', $lower], ['<', implode('.', $upper)]];
            }
        }

        // همهٔ بخش‌ها صفر (`^0.0.0`) ⇒ فقط کف، سقفی برای بستن وجود ندارد.
        return [['>=', $lower]];
    }

    /**
     * `~1.2.3` ⇒ `>=1.2.3 <1.3.0`، `~1.2` ⇒ `>=1.2 <2.0.0` — یعنی تا وقتی بخش
     * دوم صریح نوشته شده، فقط همان سطح آزاد است (همان معنای composer).
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function tilde(string $version): ?array
    {
        $segments = self::segments($version);
        if ($segments === null) {
            return null;
        }

        $lower = implode('.', $segments);
        // بخش دوم از آخر بالا می‌رود: `~1.2.3` سقف ۱.۳ می‌گیرد، `~1.2` سقف ۲.۰.
        $bump = max(0, count($segments) - 2);
        $head = $segments;
        $head[$bump] = (string) ((int) $head[$bump] + 1);
        for ($i = $bump + 1; $i < count($head); $i++) {
            $head[$i] = '0';
        }

        return [['>=', $lower], ['<', implode('.', $head)]];
    }

    /**
     * `1.2.*` ⇒ `>=1.2.0 <1.3.0`، `1.*` ⇒ `>=1.0.0 <2.0.0`.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function wildcard(string $value): ?array
    {
        if (preg_match('/^([0-9]+(?:\.[0-9]+)*)\.[xX*]$/', $value, $m) !== 1) {
            return null;
        }

        $segments = self::segments($m[1]);
        if ($segments === null) {
            return null;
        }

        $lower = $segments;
        for ($i = count($lower); $i < 3; $i++) {
            $lower[] = '0';
        }

        $head = $segments;
        $last = count($head) - 1;
        $head[$last] = (string) ((int) $head[$last] + 1);

        return [
            ['>=', implode('.', $lower)],
            ['<', implode('.', $head)],
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function segments(string $version): ?array
    {
        if (self::isVersion($version) === false) {
            return null;
        }

        return explode('.', $version);
    }

    /**
     * نسخهٔ معتبر: عدد + بخش‌های عددی + پسوند اختیاری `-beta.1` / `+build`.
     *
     * عمداً `v1.2.0` و `latest` و `1.2.0 || 2` رد می‌شوند — پیشوند `v` یک
     * قرارداد نانوشته است و بقیه رشته‌های بی‌معنی‌اند؛ پذیرفتنشان یعنی تبدیل
     * ورودی مبهم به نتیجهٔ دلخواه.
     */
    private static function isVersion(string $value): bool
    {
        return preg_match(
            '/^\d+(\.\d+)*(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/',
            $value
        ) === 1;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $range
     */
    private static function holds(array $range, string $current): bool
    {
        foreach ($range as [$operator, $version]) {
            if (version_compare($current, $version, $operator) === false) {
                return false;
            }
        }

        return true;
    }
}
