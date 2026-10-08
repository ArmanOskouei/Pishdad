<?php

namespace App\Services\Plugins;

/**
 * K6.7 — اعتبارسنجی مقدار تنظیمات افزونه در برابر اسکیمای اعلام‌شده.
 *
 * ## چرا این کلاس وجود دارد و چرا `checkValue` کافی نیست
 *
 * `PluginPackageContract::checkValue()` برای **اعلان** است: ورودی‌اش یک فیلد از
 * مانیفست است که خودِ افزونه نوشته و امضای بسته باید تأییدش کند. اینجا برعکس
 * است — ورودی **کاربر** است و باید در برابر چیزی که افزونه اعلام کرده سنجیده شود.
 *
 * تفاوت عملی: اعلان را می‌توان به کاربر نشان داد و خطایش را به نویسنده برگرداند؛
 * مقدار ذخیره‌شده مستقیم رندر می‌شود. یعنی یک اعتبارسنجی سست اینجا یعنی
 * **ذخیره‌شدن دادهٔ بی‌شکل**، نه فقط پیام خطای بد.
 *
 * ## سه قاعده که این کلاس را fail-closed نگه می‌دارد
 *
 *  ۱) **کلید اعلام‌نشده رد می‌شود.** اگر کلیدی در اسکیما نبود، هرگز ذخیره نمی‌شود.
 *     وگرنه یک درخواست دستی می‌توانست هر کلیدی تزریق کند و آن کلید بعداً در UI
 *     رندر می‌شد — راه دور زدن اعتبارسنجی بدون هیچ باگی در افزونه.
 *  ۲) **نوع دقیق، نه coerces.** `'true'` رشته است نه boolean. چون این مقدار بعداً
 *     مستقیم به `SchemaForm` می‌رود و آن‌جا `type` فیلد را می‌خواند.
 *  ۳) **فیلد `required` نبودن مقدار ندارد.** نبودنش خطاست، نه اینکه بی‌صدا
 *     از پیش‌فرض رد شود.
 */
final class PluginSettingsValidator
{
    /**
     * انواعی که `SchemaForm` می‌فهمد. هم‌تراز با `BlockPropSchema` در
     * `pishdad-core/frontend/src/lib/domain.ts` — نه با `GRAMMAR_TYPES` قرارداد،
     * چون اینجا داریم **مقدار** را می‌سنجیم نه اعلان را.
     */
    private const KNOWN_TYPES = [
        'string', 'integer', 'number', 'boolean', 'enum',
        'media_id', 'media_ids', 'richtext', 'string_list',
    ];

    /**
     * @param  array<string, mixed>  $schema  اسکیمای اعلام‌شده (`properties` + `required`).
     * @param  mixed  $input  بدنهٔ درخواست.
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(array $schema, mixed $input): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        if (! is_array($input)) {
            return ['values' => [], 'errors' => ['__form' => 'مقدار ارسالی باید یک شیء باشد.']];
        }

        $values = [];
        $errors = [];

        // ── ۱) کلیدهای اعلام‌نشده ────────────────────────────────────────
        // اول این را می‌سنجیم تا خطای کاربر «کلید ناشناخته» نباشد و اصلاح
        // دقیق‌تری بگیرد.
        foreach (array_keys($input) as $key) {
            if (! isset($properties[$key]) || ! is_array($properties[$key])) {
                $errors[$key] = 'این تنظیم در مانیفست افزونه اعلام نشده است.';
            }
        }

        // ── ۲) فیلدهای اعلام‌شده ─────────────────────────────────────────
        foreach ($properties as $key => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $present = array_key_exists($key, $input);
            $isRequired = in_array($key, $required, true);

            if (! $present) {
                if ($isRequired) {
                    $errors[$key] = 'این تنظیم اجباری است.';

                    continue;
                }

                // پیش‌فرض اعلام‌شده ذخیره می‌شود تا UI بعد از بارگذاری مجدد
                // همان چیزی را ببیند که افزونه اعلام کرده.
                if (array_key_exists('default', $spec)) {
                    $values[$key] = $spec['default'];
                }

                continue;
            }

            $problem = self::checkField($spec, $input[$key]);

            if ($problem !== null) {
                $errors[$key] = $problem;

                continue;
            }

            $values[$key] = $input[$key];
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function checkField(array $spec, mixed $value): ?string
    {
        $type = (string) ($spec['type'] ?? 'string');

        if (! in_array($type, self::KNOWN_TYPES, true)) {
            // نوع ناشناخته یعنی قرارداد خراب است. رد کردن امن‌تر از پذیرفتن
            // بی‌شکل است — همان نگهبانی که `checkValue` هم دارد.
            return 'نوع این تنظیم شناخته‌شده نیست.';
        }

        $ok = match ($type) {
            'string', 'richtext' => is_string($value),
            'integer', 'media_id' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'enum' => is_string($value) || is_int($value),
            'media_ids', 'string_list' => is_array($value) && ! self::hasNested($value),
        };

        if (! $ok) {
            return 'نوع مقدار با نوع اعلام‌شده نمی‌خواند.';
        }

        if ($type === 'enum') {
            $allowed = is_array($spec['enum'] ?? null) ? $spec['enum'] : [];
            if ($allowed !== [] && ! in_array($value, $allowed, true)) {
                return 'مقدار جزو گزینه‌های مجاز نیست.';
            }
        }

        foreach (['minLength', 'maxLength'] as $bound) {
            if (isset($spec[$bound]) && is_string($value)) {
                $limit = (int) $spec[$bound];
                $length = mb_strlen($value);
                if ($bound === 'minLength' && $length < $limit) {
                    return "حداقل طول مجاز {$limit} نویسه است.";
                }
                if ($bound === 'maxLength' && $length > $limit) {
                    return "حداکثر طول مجاز {$limit} نویسه است.";
                }
            }
        }

        foreach (['minimum', 'maximum'] as $bound) {
            if (isset($spec[$bound]) && (is_int($value) || is_float($value))) {
                $limit = (float) $spec[$bound];
                if ($bound === 'minimum' && $value < $limit) {
                    return 'مقدار کمتر از حد مجاز است.';
                }
                if ($bound === 'maximum' && $value > $limit) {
                    return 'مقدار بیشتر از حد مجاز است.';
                }
            }
        }

        if (($type === 'media_ids' || $type === 'string_list') && is_array($value)) {
            $max = isset($spec['maxItems']) ? (int) $spec['maxItems'] : 64;
            if (count($value) > $max) {
                return "حداکثر {$max} مورد مجاز است.";
            }
            $itemMax = isset($spec['itemMaxLength']) ? (int) $spec['itemMaxLength'] : 0;
            if ($itemMax > 0) {
                foreach ($value as $item) {
                    if (is_string($item) && mb_strlen($item) > $itemMax) {
                        return 'یکی از موارد بیش از حد بلند است.';
                    }
                }
            }
        }

        return null;
    }

    /** فهرست باید تخت باشد: آرایهٔ آرایه یا آبجکت، مقدار تنظیمات نیست. */
    private static function hasNested(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return true;
            }
        }

        return false;
    }
}
