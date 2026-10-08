<?php

namespace App\Search;

/**
 * ابزار متن فارسی‌دوست برای جستجو: نرمال‌سازی ي/ك عربی و نیم‌فاصله،
 * و برش هوشمند snippet دور اولین تطابق (~۱۶۰ نویسه).
 */
final class PersianText
{
    /**
     * نرمال‌سازی برای مقایسه: ي→ی، ك→ک، ة→ه، نیم‌فاصله (ZWNJ) و ZWJ→فاصله،
     * collapse فاصله‌های چندتایی، trim. حروف لاتین کوچک می‌شوند.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(['ي', 'ى', 'ك', 'ڪ', 'ة'], ['ی', 'ی', 'ک', 'ک', 'ه'], $text);
        // نیم‌فاصله (U+200C) و اتصال‌گر (U+200D) → فاصله تا «می‌شود» با «می شود» یکی شود.
        $text = str_replace(["\u{200C}", "\u{200D}"], ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** متن خام بلوک‌ها/meta را به رشته قابل‌جستجو تبدیل می‌کند (تگ‌ها حذف). */
    public static function flatten(mixed $value): string
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
