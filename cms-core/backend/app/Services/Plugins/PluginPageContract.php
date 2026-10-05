<?php

declare(strict_types=1);

namespace App\Services\Plugins;

/**
 * K7.18 — قرارداد محتوای اعلانی صفحهٔ افزونه در پنل.
 *
 * ## چرا فایل جدا و نه یک فیلد در `normalizePageRegistryEntry`
 *
 * دو دروازهٔ متفاوت لازم داریم که نمی‌شود یکی کرد:
 *
 *  1. **نصب** — `PluginPackageValidator::pageChecks()` باید fail-closed باشد،
 *     دقیقاً مثل `PluginDbContract::check()` برای `db`. یک صفحهٔ بد باید
 *     *نصب* را رد کند، نه اینکه هفتهٔ بعد هنگام بازدید گم شود.
 *  2. **خواندن** — `ManifestRegistry::normalizePageRegistryEntry()` روی
 *     افزونه‌های *نصب‌شدهٔ قبلی* هم می‌خواند، و آن‌ها از دروازهٔ (۱) رد نشده‌اند.
 *     پس نرمالایزر هم باید خودش fail-closed باشد.
 *
 * اگر فقط یکی می‌ساختیم، یا نصب‌های قدیمی می‌شکستند، یا نصب بد از در گریزان
 * بود. چون این دقیقاً همان بحثی است که در K7.13-b تصمیم گرفته شد، هر دو لایه
 * مستقل‌اند و هر دو یک منبع حقیقت دارند: همین کلاس.
 *
 * ## چرا فقط بلوک‌های هسته
 *
 * `site.block_type` عمداً رندر نمی‌شود — `lib/block-type.ts` می‌گوید
 * «no built-in block matches it … renders nothing». پس اگر `type` را به
 * افزونه واگذار کنیم، عملاً گزینهٔ ۲ را از در پشتی برگردانده‌ایم: برای رندر
 * کد لازم می‌شود. اینجا `type` فقط از `config('blocks')` انتخاب می‌شود؛ پوسته
 * دادهٔ اعلانی را تفسیر می‌کند و هیچ کدی از افزونه در مرورگر اجرا نمی‌شود.
 *
 * @see PluginDbContract — هم‌شکل برای `db`
 * @see PluginPackageContract — واژگان و الگوهای فیلد
 */
final class PluginPageContract
{
    /**
     * سقف‌ها. هم‌ردهٔ `max` در `PluginPackageContract` ولی برای این کلید خاص.
     *
     * `LayoutController.php:252` سقف ۵۰ بلوک را برای *نوع صفحه* اعمال می‌کند؛
     * اینجا ۲۴ گرفته شد چون هر بلوک `data` می‌آورد و مانیفست در ZIP است که خودش
     * سقف ۲۰ مگابایت دارد — ۲۴ بلوک با `data` واقع‌بینانه هنوز در بودجه می‌ماند.
     */
    public const MAX_PAGES = 30;

    public const MAX_BLOCKS_PER_PAGE = 24;

    public const MAX_PATH_LENGTH = 200;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_PERMISSION_LENGTH = 120;

    /**
     * فقط «default». نه یک رشتهٔ آزاد، نه یک allowlist خالی.
     *
     * مقدار از قبل در مانیفست‌ها نوشته می‌شد و هیچ‌جا مصرف نمی‌شد — یعنی
     * بی‌اثر بود. محدود کردنش یعنی افزونهٔ دوم که «wide» می‌نویسد، به‌جای یک
     * چیز بی‌اثر، خطای روشن می‌گیرد. گسترش بعدی یک مقدار به همین آرایه اضافه
     * کردن است، نه تغییر نوع.
     */
    public const LAYOUTS = ['default'];

    public const DEFAULT_LAYOUT = 'default';

    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

    /**
     * دروازهٔ نصب (fail-closed).
     *
     * اختیاری است: نبودن `pages` خطا نیست، چون بیشتر افزونه‌ها صفحهٔ پنل ندارند.
     * ولی اگر آمده باشد باید کامل درست باشد — همان منطقی که
     * `PluginDbContract::check()` برای `db` دارد.
     *
     * @return list<array{severity: string, code: string, message: string, path?: string}>
     */
    public static function check(mixed $pages): array
    {
        if ($pages === null) {
            return [];
        }

        // آرایهٔ خالی یعنی «هیچ صفحه‌ای اعلام نشده» — نه «ساختار اشتباه».
        // `array_is_list([])` صفر است پس بدون این guard، مانیفست خالی خطا می‌گرفت
        // و هر افزونه‌ای که کلید خالی می‌گذارد رد می‌شد.
        if (is_array($pages) && $pages === []) {
            return [];
        }

        if (! is_array($pages) || array_is_list($pages)) {
            return [self::issue(
                'pages.not_object',
                'بخش «pages» باید آبجکتی باشد که کلید هر صفحه نامش است، مثلاً {"billing": {"title_fa": "صورتحساب"}}.'
            )];
        }

        if (count($pages) > self::MAX_PAGES) {
            return [self::issue(
                'pages.too_many',
                'حداکثر '.self::MAX_PAGES.' صفحه در «pages» مجاز است؛ '.count($pages).' اعلام شده است.'
            )];
        }

        $issues = [];
        $seenPaths = [];

        foreach ($pages as $key => $def) {
            $where = 'pages.'.$key;

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                $issues[] = self::issue(
                    'pages.bad_key',
                    'کلید صفحه «'.(is_string($key) ? $key : '(عدد)').'» معتبر نیست: باید با حرف یا رقم کوچک انگلیسی شروع شود و بعد فقط a-z، 0-9، نقطه، خط تیره و زیرخل بماند (۱ تا ۴۰ نویسه).',
                    $where
                );

                continue;
            }

            if (! is_array($def) || array_is_list($def)) {
                $issues[] = self::issue(
                    'pages.entry_not_object',
                    'تعریف صفحهٔ «'.$key.'» باید آبجکت باشد.',
                    $where
                );

                continue;
            }

            $unknown = array_diff(array_keys($def), ['path', 'title_fa', 'permission', 'layout', 'blocks']);
            if ($unknown !== []) {
                $issues[] = self::issue(
                    'pages.unknown_key',
                    'کلید ناشناخته در «'.$where.'»: «'.implode('», «', $unknown).'». تنها «path»، «title_fa»، «permission»، «layout» و «blocks» پذیرفته می‌شوند.',
                    $where
                );
            }

            // --- path -------------------------------------------------------
            //
            // نبودنش خطا نیست: مسیر پیش‌فرض از کلید ساخته می‌شود. ولی اگر آمده
            // باشد باید همان قواعد `normalizePageRegistryEntry` را داشته باشد،
            // وگرنه دو لایه دو جور حرف می‌زنند — دقیقاً همان باگی که کامنت
            // `ManifestRegistry.php:560-564` برای نقطهٔ انتهایی ثبت کرده.
            if (array_key_exists('path', $def)) {
                if (! is_string($def['path']) || $def['path'] === '') {
                    $issues[] = self::issue('pages.bad_path', '«path» صفحهٔ «'.$key.'» باید رشتهٔ نا‌خالی باشد.', $where.'.path');
                } elseif (strlen($def['path']) > self::MAX_PATH_LENGTH) {
                    $issues[] = self::issue('pages.path_too_long', '«path» صفحهٔ «'.$key.'» بلندتر از '.self::MAX_PATH_LENGTH.' نویسه است.', $where.'.path');
                } else {
                    $normalized = self::normalizePath($def['path'], (string) $key);
                    if ($normalized === null) {
                        $issues[] = self::issue(
                            'pages.path_not_allowed',
                            '«path» صفحهٔ «'.$key.'» («'.$def['path'].'») معتبر نیست: باید داخل «/admin/» باشد و «..»، «//» و نقطهٔ انتهایی نداشته باشد. مسیر بیرون از /admin/ یعنی افزونه خودش را جای مسیرهای هسته جا می‌زند.',
                            $where.'.path'
                        );
                    } elseif (isset($seenPaths[$normalized])) {
                        $issues[] = self::issue(
                            'pages.duplicate_path',
                            'مسیر «'.$normalized.'» هم در صفحهٔ «'.$seenPaths[$normalized].'» و هم «'.$key.'» اعلام شده است. دو افزونه یا دو صفحه نمی‌توانند یک URL بگیرند — وگرنه اینکه کدام رندر شود به ترتیب بارگذاری بستگی می‌کند.',
                            $where.'.path'
                        );
                    } else {
                        $seenPaths[$normalized] = (string) $key;
                    }
                }
            }

            // --- title_fa --------------------------------------------------
            //
            // الزامی، چون هر چیزی که به ادمین نشان داده می‌شود از این می‌خواند
            // (`ManifestRegistry.php:574` بدون آن کلید را جایگزین می‌کند، و
            // `example_bad` خود `admin.settings_schema` همین را گفته بود).
            if (! is_string($def['title_fa'] ?? null) || trim((string) ($def['title_fa'] ?? '')) === '') {
                $issues[] = self::issue('pages.no_title', '«title_fa» صفحهٔ «'.$key.'» الزامی است — بدون آن رابط کاربری متن خالی می‌خواند.', $where.'.title_fa');
            } elseif (strlen((string) $def['title_fa']) > self::MAX_TITLE_LENGTH) {
                $issues[] = self::issue('pages.title_too_long', '«title_fa» صفحهٔ «'.$key.'» بلندتر از '.self::MAX_TITLE_LENGTH.' نویسه است.', $where.'.title_fa');
            }

            // --- permission ------------------------------------------------
            if (array_key_exists('permission', $def)) {
                if (! is_string($def['permission']) || $def['permission'] === '') {
                    $issues[] = self::issue('pages.bad_permission', '«permission» صفحهٔ «'.$key.'» باید رشتهٔ نا‌خالی باشد؛ برای نداشتن محدودیت کلید را کلاً حذف کنید.', $where.'.permission');
                } elseif (strlen($def['permission']) > self::MAX_PERMISSION_LENGTH) {
                    $issues[] = self::issue('pages.permission_too_long', '«permission» صفحهٔ «'.$key.'» بلندتر از '.self::MAX_PERMISSION_LENGTH.' نویسه است.', $where.'.permission');
                } elseif (! self::isSafePermission($def['permission'])) {
                    $issues[] = self::issue(
                        'pages.permission_charset',
                        '«permission» صفحهٔ «'.$key.'» («'.$def['permission'].'») فقط باید a-z، 0-9، نقطه، خط تیره، زیرخل و دونقطه باشد. نام پرمیشن یک شناسه است، نه یک رشتهٔ آزاد که بعداً در جایی به query تبدیل شود.',
                        $where.'.permission'
                    );
                }
            }

            // --- layout ----------------------------------------------------
            if (array_key_exists('layout', $def) && ! in_array($def['layout'], self::LAYOUTS, true)) {
                $issues[] = self::issue(
                    'pages.layout_not_allowed',
                    '«layout» صفحهٔ «'.$key.'» («'.(is_scalar($def['layout']) ? (string) $def['layout'] : '(غیرشاخه‌ای)').'») مجاز نیست. تنها «'.implode('» و «', self::LAYOUTS).'» پذیرفته می‌شود.',
                    $where.'.layout'
                );
            }

            // --- blocks ----------------------------------------------------
            foreach (self::checkBlocks($def['blocks'] ?? null, $where.'.blocks') as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * آرایهٔ بلوک. اختیاری است؛ اگر نباشد صفحه بدون محتاست، که الان همان
     * placeholder قدیمی است و پس از K7.19 خالی می‌ماند.
     *
     * قواعد عمداً همان `LayoutController.php:251-260` است: `type` از کلیدهای
     * `config('blocks')`، `data` آرایه، سقف ۵۰. اینجا سقف ۲۴ (سقف مانیفست).
     *
     * @return list<array{severity: string, code: string, message: string, path?: string}>
     */
    public static function checkBlocks(mixed $blocks, string $where): array
    {
        if ($blocks === null) {
            return [];
        }

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return [self::issue(
                'blocks.not_list',
                '«'.$where.'» باید آرایه‌ای از فهرست باشد، نه آبجکت. هر عضو: {"type": "text", "data": {…}}.',
                $where
            )];
        }

        if (count($blocks) > self::MAX_BLOCKS_PER_PAGE) {
            return [self::issue(
                'blocks.too_many',
                'حداکثر '.self::MAX_BLOCKS_PER_PAGE.' بلوک در «'.$where.'» مجاز است؛ '.count($blocks).' آمده است.',
                $where
            )];
        }

        $allowed = self::allowedBlockTypes();
        if ($allowed === []) {
            // رجیستری بلوک هسته خالی است ⇒ هیچ صفحه‌ای نمی‌تواند بلوک داشته باشد.
            // این باید خطا باشد نه سکوت: سکوت یعنی «صفحهٔ خالی» و منشأ آن نامرئی می‌ماند.
            return [self::issue(
                'blocks.no_core_types',
                'هیچ نوع بلوکی در هسته فعال نیست، پس «'.$where.'» نمی‌تواند بلوک داشته باشد. یا رجیستری `config/blocks.php` خالی است، یا همهٔ بلوک‌ها غیرفعال‌اند.',
                $where
            )];
        }

        $issues = [];

        foreach ($blocks as $i => $block) {
            $at = $where.'['.$i.']';

            if (! is_array($block) || array_is_list($block)) {
                $issues[] = self::issue('blocks.entry_not_object', 'هر عضو «'.$where.'» باید آبجکت باشد.', $at);

                continue;
            }

            $unknown = array_diff(array_keys($block), ['type', 'data']);
            if ($unknown !== []) {
                $issues[] = self::issue(
                    'blocks.unknown_key',
                    'کلید ناشناخته در «'.$at.'»: «'.implode('», «', $unknown).'». تنها «type» و «data» پذیرفته می‌شوند — کلید دیگری یعنی تلاش برای تزریق چیزی که پوسته نمی‌شناسد.',
                    $at
                );
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || $type === '') {
                $issues[] = self::issue('blocks.no_type', '«type» در «'.$at.'» الزامی است.', $at.'.type');
            } elseif (! in_array($type, $allowed, true)) {
                $issues[] = self::issue(
                    'blocks.type_not_allowed',
                    'نوع بلوک «'.$type.'» در هسته پیاده‌سازی نشده است. انواع مجاز: «'.implode('» و «', $allowed).'». یک افزونه نمی‌تواند نوع بلوک تازه تعریف کند — رندرش به کد افزونه در مرورگر نیاز دارد که دقیقاً همان چیزی است که در K7.13-b رد شد.',
                    $at.'.type'
                );
            }

            $data = $block['data'] ?? null;
            if (! is_array($data) || (array_is_list($data) && $data !== [])) {
                $issues[] = self::issue('blocks.data_not_object', '«data» در «'.$at.'» باید آبجکت باشد.', $at.'.data');
            }
        }

        return $issues;
    }

    /**
     * انواع بلوکی که واقعاً رندر می‌شوند.
     *
     * @return list<string>
     */
    public static function allowedBlockTypes(): array
    {
        return array_keys(config('blocks', []));
    }

    /**
     * مسیر نرمال‌شده، یا null اگر نامعتبر. همان قاعدهٔ
     * `ManifestRegistry::normalizePageRegistryEntry` — اینجا هم به‌عنوان
     * منبع حقیقت استفاده می‌شود تا دو لایه دو جور حرف نزنند.
     */
    public static function normalizePath(string $path, string $key): ?string
    {
        $path = rtrim($path, '/');
        $path = $path === '' || $path === '/' ? '/admin/'.$key : $path;

        if (! str_starts_with($path, '/admin/') || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        return rtrim($path, '/');
    }

    private static function isSafePermission(string $permission): bool
    {
        return preg_match('/^[a-z0-9._:-]{1,120}$/', $permission) === 1;
    }

    /** @return array{severity: string, code: string, message: string, path?: string} */
    private static function issue(string $code, string $message, ?string $path = null): array
    {
        $issue = ['severity' => 'error', 'code' => $code, 'message' => $message];
        if ($path !== null) {
            $issue['path'] = $path;
        }

        return $issue;
    }
}
