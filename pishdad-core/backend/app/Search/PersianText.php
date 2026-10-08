<?php

namespace App\Search;

/**
 * ابزار متن فارسی‌دوست برای جستجو: نرمال‌سازی چندفرمی‌ها و نیم‌فاصله،
 * الگوی regex برای فیلترهای SQL، و تطابق فازیِ تحمل‌کنندهٔ غلط املایی.
 */
final class PersianText
{
    /**
     * نرمال‌سازی برای مقایسه — هر دو طرف (متنِ ذخیره‌شده و کوئری) باید از
     * همین رد شوند، وگرنه یک طرف «آرمان» می‌ماند و طرف دیگر «ارمان».
     *
     *  - ي/ى → ی، ك/ڪ → ک، ة → ه (عربی به فارسی)
     *  - آ/أ/إ → ا، ؤ → و، ئ → ی (همزه‌ها به کرسیِ ساده) — E85: بدون این،
     *    «ارمان» هرگز «آرمان» را پیدا نمی‌کرد چون ا ≠ آ است.
     *  - تشدید و اعراب (U+064B–U+065F، U+0670) و کشیده (ـ U+0640) حذف.
     *  - ارقام عربی/فارسی (۰-۹/٠-٩) → لاتین، تا «۱۴۰۳» و «1403» یکی شوند.
     *  - نیم‌فاصله (U+200C) و اتصال‌گر (U+200D) → فاصله، تا «می‌شود» با
     *    «می شود» یکی شود. فاصله‌های چندتایی collapse + trim. حروف لاتین کوچک.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(
            // ي عربی، ى الف مقصوره، ې (U+06D0 — همان که در تست قدیمیِ
            // SiteSearchTest بود ولی هیچ‌وقت واقعاً تطبیق نمی‌یافت)،
            // ك/ڪ، ة، همزه‌ها، کشیده.
            ['ي', 'ى', "\u{06D0}", 'ك', 'ڪ', 'ة', 'آ', 'أ', 'إ', 'ؤ', 'ئ', 'ـ'],
            ['ی', 'ی', 'ی', 'ک', 'ک', 'ه', 'ا', 'ا', 'ا', 'و', 'ی', ''],
            $text
        );
        // اعراب و تشدید: U+064B–U+065F + U+0670 (الف خنجریه).
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        // ارقام فارسی (U+06F0–U+06F9) و عربی (U+0660–U+0669) → لاتین.
        $text = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );
        $text = str_replace(["\u{200C}", "\u{200D}"], ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** متن خام بلوک‌ها/meta را به رشته قابل‌جستجو تبدیل می‌کند (تگ‌ها حذف). */    public static function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $v) {
            $s = self::flatten($v);
            if ($s !== '') {
                $parts[] = $s;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * کوئریِ نرمال‌شده → الگوی regex برای ستونِ **خام** دیتابیس.
     *
     * چرا regex و نه نرمال‌سازیِ ستون: فیلترهای لیست (`PageController` و…)
     * روی ستونِ خام `ilike` می‌زنند و نمی‌شود همهٔ جدول‌ها را با ستونِ
     * نرمال‌شده دوباره ساخت. پس به‌جای آن، هر نویسهٔ چندفرمی به کلاسِ
     * خودش باز می‌شود: `[آاأإ]` و… — یعنی «ارمان» روی سطرِ «آرمان» هم
     * می‌نشیند، بدون اینکه داده دست بخورد.
     *
     * خروجی برای عملگر `~*` پستگرس است (case-insensitive) و دورش `.*` دارد،
     * یعنی معادلِ `%…%`. نویسه‌های خاص regex با preg_quote خنثی می‌شوند.
     */
    public static function regexPattern(string $normalizedQuery): string
    {
        $classes = [
            'ا' => '[اآأإ]',
            'ی' => '[ییى]',
            'ک' => '[کكڪ]',
            'ه' => '[هة]',
            'و' => '[وؤ]',
        ];
        $out = '';
        $len = mb_strlen($normalizedQuery);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($normalizedQuery, $i, 1);
            $out .= $classes[$ch] ?? preg_quote($ch, '/');
        }

        return '.*'.$out.'.*';
    }

    /** آیا افزونهٔ pg_trgm نصب است؟ (یک‌بار در هر ریکوئست، نه هر کوئری.) */
    public static function trigramAvailable(): bool
    {
        /** @var array<string, bool> $memo */
        static $memo = [];

        if (! array_key_exists('pg_trgm', $memo)) {
            try {
                $memo['pg_trgm'] = \Illuminate\Support\Facades\DB::table('pg_extension')
                    ->where('extname', 'pg_trgm')
                    ->exists();
            } catch (\Throwable) {
                $memo['pg_trgm'] = false;
            }
        }

        return $memo['pg_trgm'];
    }

    /**
     * شرطِ جستجوی فارسی‌دوست روی چند ستون — جایگزینِ مستقیمِ زنجیره‌های
     * `ilike "%…%"` در فیلترهای لیست.
     *
     * دو لایه، به ترتیبِ ارزان‌به‌گران:
     *  ① `~*` با الگوی چندفرمی (همیشه) — «ارمان» ↔ «آرمان».
     *  ② اگر pg_trgm نصب بود: `word_similarity(کوئری، ستون) > 0.5` —
     *     «غوانین» ↔ «قوانین». ترتیبِ آرگومان‌ها مهم است (اول کوئریِ کوتاه،
     *     بعد متنِ بلند) و آستانهٔ ۰٫۵ از اندازه‌گیریِ واقعی می‌آید: مثبتِ
     *     واقعی ۰٫۵۷، نامرتبط ۰. اگر افزونه نبود، بی‌صدا فقط لایهٔ ① می‌ماند
     *     تا نصب‌هایی که افزونه ندارند نشکنند.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     * @param  list<string>  $columns  نام ستون‌ها؛ برای فیلد JSON از `->>` استفاده کنید
     */
    public static function whereFa($q, string $rawQuery, array $columns): void
    {
        $normalized = self::normalize($rawQuery);
        if ($normalized === '') {
            return;
        }
        $pattern = self::regexPattern($normalized);
        $trgm = self::trigramAvailable();

        $q->where(function ($w) use ($pattern, $normalized, $columns, $trgm): void {
            foreach ($columns as $i => $col) {
                $clause = function ($x) use ($col, $pattern, $normalized, $trgm): void {
                    $x->whereRaw("{$col} ~* ?", [$pattern]);
                    if ($trgm) {
                        $x->orWhereRaw('word_similarity(?, '.$col.') > 0.5', [$normalized]);
                    }
                };
                if ($i === 0) {
                    $clause($w);
                } else {
                    $w->orWhere(function ($x) use ($clause): void {
                        $clause($x);
                    });
                }
            }
        });
    }

    /**
     * تطابق فازیِ سمت PHP برای providerهای جستجو (متن‌ها از قبل در حافظه‌اند).
     *
     * اول تطابقِ زیررشته‌ایِ نرمال (ارزان)؛ اگر نبود، هر توکنِ کوئری باید به
     * یکی از واژه‌های متن «نزدیک» باشد: فاصلهٔ levenshtein حداکثر
     * `max(1, floor(len/4))`. یعنی «غوانین» (۶ حرف، آستانه ۱) «قوانین» را
     * می‌گیرد، ولی «کتاب» هرگز «درخت» را نمی‌گیرد.
     */
    public static function fuzzyIncludes(string $normalizedHaystack, string $normalizedQuery): bool
    {
        if ($normalizedQuery === '') {
            return false;
        }
        if (mb_strpos($normalizedHaystack, $normalizedQuery) !== false) {
            return true;
        }

        $words = preg_split('/\s+/u', $normalizedHaystack, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return false;
        }

        foreach (preg_split('/\s+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $threshold = max(1, (int) floor(mb_strlen($token) / 4));
            // levenshtein روی بيش از ۲۵۵ بایت ۱- برمی‌گرداند؛ واژه‌های خیلی
            // بلند هیچ‌وقت «نزدیکِ» یک توکنِ کوتاه نیستند، پس ردشان می‌کنیم.
            if (mb_strlen($token) > 60) {
                return false;
            }
            $best = PHP_INT_MAX;
            foreach ($words as $w) {
                if (mb_strlen($w) > 60 || abs(mb_strlen($w) - mb_strlen($token)) > $threshold) {
                    continue;
                }
                // levenshtein بایت‌محور است؛ روی UTF-8 طولِ حروف را با
                // تبدیلِ موقت به UCS-2LE درست می‌کنیم (الگوی استاندارد).
                $d = levenshtein(
                    (string) mb_convert_encoding($token, 'UCS-2LE', 'UTF-8'),
                    (string) mb_convert_encoding($w, 'UCS-2LE', 'UTF-8')
                );
                // هر واحدِ UCS-2 دو بایت است، پس فاصله را نصف می‌کنیم.
                $d = (int) ceil($d / 2);
                if ($d < $best) {
                    $best = $d;
                }
                if ($best === 0) {
                    break;
                }
            }
            if ($best > $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * برش هوشمند ~۱۶۰ نویسه دور اولین تطابق کوئری در متن.
     * ورودی text خام (با تگ‌زدایی) و query خام؛ مقایسه نرمال‌شده است ولی
     * برش روی متن اصلی انجام می‌شود.
     */
    public static function snippet(string $text, string $query, int $length = 160): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        if ($plain === '') {
            return '';
        }
        if (mb_strlen($plain) <= $length) {
            return $plain;
        }

        $normText = self::normalize($plain);
        $normQuery = self::normalize($query);
        $pos = $normQuery === '' ? false : mb_strpos($normText, $normQuery);

        if ($pos === false) {
            return mb_substr($plain, 0, $length).'…';
        }

        $half = (int) ($length / 2);
        $start = max(0, $pos - $half);
        $slice = mb_substr($plain, $start, $length);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($plain) ? '…' : '';

        return $prefix.$slice.$suffix;
    }
}
