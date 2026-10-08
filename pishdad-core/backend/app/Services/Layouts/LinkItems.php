<?php

namespace App\Services\Layouts;

use App\Models\Page;
use App\Validation\SafeUrl;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * آیتم لینک صفحه‌ای/سفارشی برای ویجت nav هدر و links فوتر.
 *
 * مدل: {kind: 'page'|'custom', page_id?, label?, href?, children?: [...همین مدل]}
 * سازگار عقب‌رو: {label, href} قدیمی = custom (بدون children).
 * تودرتویی (منوی کشویی): حداکثر عمق ۲ (سطح اول + فرزند + نوه)؛ هر والد
 * حداکثر ۸ فرزند؛ سطح اول حداکثر ۱۲ آیتم.
 *
 * - validate: اعتبارسنجی معنایی بازگشتی هنگام PUT layouts (پیام فارسی، ۴۲۲).
 * - resolve: غنی‌سازی بازگشتی خروجی عمومی کروم با title/url زنده صفحه؛
 *   page_id نامعتبر/حذف‌شده/منتشرنشده graceful رد می‌شود (کل زیرشاخه‌اش با
 *   خودش حذف می‌شود، بدون خطا).
 */
final class LinkItems
{
    public const MAX_ITEMS = 12;

    public const MAX_CHILDREN = 8;

    /** حداکثر عمق تودرتویی: ۰ = سطح اول، ۱ = فرزند، ۲ = نوه. */
    public const MAX_DEPTH = 2;

    /** نرمال‌سازی یک آیتم خام (سازگار عقب‌رو؛ children بازگشتی نرمال می‌شود). */
    public static function normalize(mixed $item): array
    {
        $item = is_array($item) ? $item : [];
        $kind = $item['kind'] ?? null;
        if (! in_array($kind, ['page', 'custom'], true)) {
            $kind = isset($item['page_id']) ? 'page' : 'custom';
        }

        $out = [
            'kind' => $kind,
            'page_id' => isset($item['page_id']) && is_numeric($item['page_id']) ? (int) $item['page_id'] : null,
            'label' => self::cleanText(isset($item['label']) && is_string($item['label']) ? $item['label'] : null),
            'href' => isset($item['href']) && is_string($item['href']) ? $item['href'] : null,
        ];

        if (isset($item['children']) && is_array($item['children'])) {
            $children = [];
            foreach (array_values($item['children']) as $child) {
                if (is_array($child)) {
                    $children[] = self::normalize($child);
                }
            }
            $out['children'] = $children;
        }

        return $out;
    }

    /**
     * پاک‌سازی متن لیبل: اگر رشته دوبار UTF-8 انکد شده باشد (نشانه: نبودِ
     * نویسه فارسی/عربی + وجودِ `Ø`/`Ã`/`Ù`)، یک بار برعکس رمزگشایی می‌شود.
     * رشته سالم دست‌نخورده می‌ماند و رشته خراب بی‌معنا null می‌شود.
     */
    public static function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $current = trim($text);
        if ($current === '') {
            return null;
        }
        $hasPersian = '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FEFF}]/u';
        for ($i = 0; $i < 3; $i++) {
            if (preg_match($hasPersian, $current) === 1) {
                return $current;
            }
            $next = @mb_convert_encoding($current, 'UTF-8', 'ISO-8859-1');
            if (! is_string($next) || $next === $current) {
                break;
            }
            $current = trim($next);
        }

        return preg_match($hasPersian, $current) === 1 ? $current : null;
    }

    /**
     * اعتبارسنجی معنایی لیست links یک ویجت.
     *
     * @param  string  $field  کلید خطا (مثل widgets.0.settings.links)
     * @param  string  $widgetLabel  نام فارسی ویجت برای پیام‌ها
     *
     * @throws ValidationException
     */
    public static function validate(string $field, mixed $links, string $widgetLabel): void
    {
        if ($links === null) {
            return;
        }
        if (! is_array($links) || ($links !== [] && ! array_is_list($links))) {
            throw ValidationException::withMessages([$field => $widgetLabel.': پیوندها باید لیست باشند.']);
        }
        if (count($links) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([$field => $widgetLabel.': حداکثر '.self::MAX_ITEMS.' پیوند مجاز است.']);
        }

        foreach (array_values($links) as $i => $raw) {
            self::validateItem($field, $raw, $widgetLabel, [$i], 0);
        }
    }

    /**
     * اعتبارسنجی بازگشتی یک آیتم + فرزندانش.
     *
     * @param  array<int>  $path  مسیر ایندکس‌ها از ریشه (برای پیام فارسی)
     *
     * @throws ValidationException
     */
    private static function validateItem(string $field, mixed $raw, string $widgetLabel, array $path, int $depth): void
    {
        $pos = self::posLabel($path);
        if (! is_array($raw)) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos} نامعتبر است."]);
        }
        $item = self::normalize($raw);
        $rawKind = $raw['kind'] ?? null;
        if ($rawKind !== null && ! in_array($rawKind, ['page', 'custom'], true)) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: نوع باید «page» یا «custom» باشد."]);
        }

        $hasChildren = array_key_exists('children', $raw) && $raw['children'] !== null;

        if ($item['kind'] === 'page') {
            self::validatePageItem($field, $widgetLabel, $pos, $item);
        } else {
            self::validateCustomItem($field, $widgetLabel, $pos, $item, $hasChildren);
        }

        if (! $hasChildren) {
            return;
        }

        if (! is_array($raw['children']) || ($raw['children'] !== [] && ! array_is_list($raw['children']))) {
            // NOTE: array_is_list روی آرایه انجمنی false می‌دهد؛ children باید لیست باشد.
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: فرزندان باید لیست باشند."]);
        }
        $children = array_values($raw['children']);
        if ($depth >= self::MAX_DEPTH) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: حداکثر عمق تودرتویی ۲ سطح است."]);
        }
        if (count($children) > self::MAX_CHILDREN) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: هر پیوند حداکثر ".self::MAX_CHILDREN.' فرزند می‌تواند داشته باشد.']);
        }
        foreach ($children as $j => $child) {
            self::validateItem($field, $child, $widgetLabel, [...$path, $j], $depth + 1);
        }
    }

    /** برچسب فارسی مسیر آیتم: «۱» یا «۱ ← فرزند ۲». */
    private static function posLabel(array $path): string
    {
        $parts = [];
        foreach ($path as $level => $idx) {
            $n = $idx + 1;
            $parts[] = $level === 0 ? "پیوند {$n}" : "فرزند {$n}";
        }

        return implode(' ← ', $parts);
    }

    /** @throws ValidationException */
    private static function validatePageItem(string $field, string $widgetLabel, string $pos, array $item): void
    {
        if (! $item['page_id'] || $item['page_id'] <= 0) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: انتخاب صفحه الزامی است."]);
        }
        $page = Page::query()
            ->where('id', $item['page_id'])
            ->first(['id', 'status', 'published_revision_id']);
        if (! $page) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: صفحه انتخاب‌شده یافت نشد."]);
        }
        if ($page->status !== Page::STATUS_PUBLISHED || $page->published_revision_id === null) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: صفحه باید منتشرشده باشد."]);
        }
        if ($item['label'] !== null && mb_strlen($item['label']) > 80) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: عنوان حداکثر ۸۰ نویسه باشد."]);
        }
    }

    /**
     * آیتم والد دارای فرزند (کشویی) فقط عنوان می‌خواهد و نشانی‌اش اختیاری
     * است (والد غیرقابل‌کلیک)؛ آیتم برگ همچنان عنوان + نشانی معتبر می‌خواهد.
     *
     * @throws ValidationException
     */
    private static function validateCustomItem(string $field, string $widgetLabel, string $pos, array $item, bool $hasChildren = false): void
    {
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: عنوان الزامی است."]);
        }
        if (mb_strlen($label) > 80) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: عنوان حداکثر ۸۰ نویسه باشد."]);
        }
        $href = trim((string) ($item['href'] ?? ''));
        if ($hasChildren && $href === '') {
            return;
        }
        if ($href === '' || mb_strlen($href) > 2048 || ! self::isValidHref($href)) {
            throw ValidationException::withMessages([$field => $widgetLabel.": {$pos}: نشانی معتبر نیست (با / یا https:// شروع شود)."]);
        }
    }

    /**
     * F0.2 — به `SafeUrl` واگذار می‌شود تا allowlist **دو نسخه** نداشته باشد.
     *
     * نسخهٔ قبلی `~^(https?://|/|#|\?|mailto:|tel:)~u` بود که `/` را بدون
     * lookahead می‌پذیرفت ⇒ `//evil.com` و `/\evil.com` را قبول می‌کرد. این
     * یعنی اگر روزی فرانت `safeHref` را دور بزند، به open-redirect تبدیل
     * می‌شود — و دو تعریف ناهمگون، مقایسه‌شان را ناممکن می‌کند.
     */
    public static function isValidHref(string $href): bool
    {
        return SafeUrl::isAllowedHref($href);
    }

    /**
     * غنی‌سازی بازگشتی خروجی عمومی: آیتم page با title/url زنده؛ خراب
     * graceful رد می‌شود (کل زیرشاخه‌اش حذف می‌شود). آیتم custom والد
     * (دارای فرزند) بدون href هم معتبر است.
     *
     * @param  array  $items  آیتم‌های خام ذخیره‌شده
     * @param  Collection<int, Page>  $pages  صفحات منتشرشده مالک (keyBy id)
     */
    public static function resolve(array $items, Collection $pages, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $out = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $item = self::normalize($raw);
            if ($item['kind'] === 'page') {
                $page = $item['page_id'] ? ($pages[$item['page_id']] ?? null) : null;
                if (! $page) {
                    continue;
                }
                $label = self::cleanText($item['label'] ?? null) ?? '';
                $entry = [
                    'kind' => 'page',
                    'page_id' => (int) $page->id,
                    'label' => $label !== '' ? mb_substr($label, 0, 80) : (string) $page->title,
                    'href' => '/'.ltrim((string) $page->slug, '/'),
                    'title' => (string) $page->title,
                    'url' => '/'.ltrim((string) $page->slug, '/'),
                ];
            } else {
                $label = self::cleanText($item['label'] ?? null) ?? '';
                $href = trim((string) ($item['href'] ?? ''));
                $rawChildren = $raw['children'] ?? $item['children'] ?? null;
                $hasChildren = is_array($rawChildren) && $rawChildren !== [];
                if ($label === '' || mb_strlen($label) > 80) {
                    continue;
                }
                if ($href === '' && ! $hasChildren) {
                    continue;
                }
                if ($href !== '' && ! self::isValidHref($href)) {
                    continue;
                }
                $entry = ['kind' => 'custom', 'label' => $label];
                if ($href !== '') {
                    $entry['href'] = $href;
                }
            }
            $rawChildren = is_array($raw['children'] ?? null) ? $raw['children'] : [];
            if ($rawChildren !== [] && $depth < self::MAX_DEPTH) {
                $resolved = self::resolve(array_values($rawChildren), $pages, $depth + 1);
                if ($resolved !== []) {
                    $entry['children'] = $resolved;
                }
            }

            $out[] = $entry;
        }

        return array_values($out);
    }

    /**
     * جمع بازگشتی همه page_idهای یک لیست links (برای eager-load یک‌جا در
     * SiteChromeController — بدون N+1 حتی با منوی کشویی).
     *
     * @return array<int>
     */
    public static function collectPageIds(array $items): array
    {
        $ids = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $n = self::normalize($raw);
            if ($n['kind'] === 'page' && $n['page_id']) {
                $ids[] = $n['page_id'];
            }
            if (isset($raw['children']) && is_array($raw['children'])) {
                $ids = [...$ids, ...self::collectPageIds(array_values($raw['children']))];
            }
        }

        return $ids;
    }

    /**
     * ستون مؤثر فوتر: layout.columns معتبر (۱ تا ۴) وگرنه تعداد واقعی
     * ویجت‌های links (راست‌به‌چپ به ترتیب) محدود به ۱ تا ۴.
     */
    public static function effectiveFooterColumns(array $footer): int
    {
        $c = $footer['layout']['columns'] ?? null;
        if (is_numeric($c) && (int) $c >= 1 && (int) $c <= 4) {
            return (int) $c;
        }
        $links = collect($footer['widgets'] ?? [])->where('type', 'links')->count();

        return max(1, min(4, $links === 0 ? 1 : $links));
    }
}
