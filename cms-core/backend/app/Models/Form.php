<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * WF-H10 — فرم‌ساز: یک فرم با فیلدهای دلخواه (JSON) و یک مقصد ارسال.
 *
 * قرارداد `fields` (آرایهٔ مرتب):
 *   [{
 *     key: string        // یکتا، ^[a-z][a-z0-9_]*$، 'website' رزرو است (honeypot)
 *     label: string      // برچسب فارسی
 *     type: string       // text|email|tel|number|textarea|select|checkbox
 *     required?: boolean
 *     placeholder?: string
 *     options?: string[] // فقط برای select
 *     max_length?: int
 *   }]
 */
class Form extends Model
{
    public const DEST_EMAIL = 'email';

    public const DEST_TICKET = 'ticket';

    public const TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox'];

    /** کلیدهای رزرو که نمی‌توانند نام فیلد باشند. */
    public const RESERVED_KEYS = ['website', 'data', '_token', '_hp'];

    protected $fillable = [
        'name', 'slug', 'fields', 'destination', 'recipients', 'success_message', 'active',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'recipients' => 'array',
            'active' => 'boolean',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->latest();
    }

    /** @return list<array<string, mixed>> */
    public function fieldList(): array
    {
        $fields = $this->fields;

        return is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [];
    }

    /** @return list<string> */
    public function fieldKeys(): array
    {
        return array_values(array_filter(array_map(
            fn ($f) => is_array($f) && isset($f['key']) && is_string($f['key']) ? $f['key'] : null,
            $this->fieldList()
        )));
    }

    /**
     * پاسخ خام را به کلیدهای اعلان‌شده محدود می‌کند (کلید ناشناس ذخیره نمی‌شود)
     * و `null` را برای فیلد اختیاری خالی حذف می‌کند.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizePayload(array $data): array
    {
        $out = [];
        foreach ($this->fieldList() as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key)) {
                continue;
            }
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }

    /** قواعد اعتبارسنجی پاسخ عمومی (کلیدها با پیشوند `data.`). */
    public function responseRules(): array
    {
        $rules = ['data' => 'required|array'];

        foreach ($this->fieldList() as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key)) {
                continue;
            }
            $required = (bool) ($field['required'] ?? false);
            $base = $required ? ['required'] : ['sometimes', 'nullable'];
            $rules["data.{$key}"] = array_merge($base, $this->typeRules($field));
        }

        return $rules;
    }

    /** @return list<string> */
    private function typeRules(array $field): array
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
        $max = is_numeric($field['max_length'] ?? null) ? (int) $field['max_length'] : null;

        return match ($type) {
            'email' => ['string', 'email:rfc', 'max:200'],
            'number' => ['numeric'],
            'tel' => ['string', 'max:30'],
            'checkbox' => ['boolean'],
            'select' => ['string', Rule::in(array_values(array_filter(
                array_map(fn ($o) => is_string($o) ? $o : null, (array) ($field['options'] ?? [])),
            )))],
            'textarea' => ['string', 'max:'.($max ?? 5000)],
            default => ['string', 'max:'.($max ?? 2000)],
        };
    }
}
