<?php

namespace App\Support\Plugins;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * نتیجهٔ تحلیل بستهٔ پلاگین، به‌شکل قابل ارسال به فرانت.
 *
 * عمداً به‌صورت یک شیء مستقل است تا فرانت بتواند بدون دانستن ساختار خام
 * آرایه، فهرست خطاها/هشدارها را رندر کند و هر مورد را به بخش مربوطهٔ راهنما
 * لینک بدهد (`guide`).
 *
 * `Arrayable` لازم است: بدون آن `response()->json()` شیء را به `{}` تبدیل
 * می‌کند (همهٔ propertyها خصوصی‌اند) و فرانت هیچ جزئیاتی نمی‌بیند.
 */
class PackageAnalysis implements Arrayable, JsonSerializable
{
    public function __construct(private array $data) {}

    public static function fromValidator(array $result): self
    {
        return new self($result);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function isError(): bool
    {
        return ! $this->data['ok'];
    }

    public function isWarning(): bool
    {
        return $this->data['ok'] && $this->data['warnings'] !== [];
    }

    /** خلاصهٔ یک‌خطی برای پیام اصلی. */
    public function headline(): string
    {
        $n = count($this->data['errors']);
        if ($n === 0) {
            $w = count($this->data['warnings']);

            return $w === 0
                ? 'بسته معتبر است و می‌توان نصبش کرد.'
                : 'بسته معتبر است با '.$w.' هشدار.';
        }

        $first = $this->data['errors'][0];

        return $n === 1
            ? $first['message']
            : $n.' مشکل در بسته پیدا شد. مهم‌ترین: '.$first['message'];
    }

    /**
     * فهرست خطاها و هشدارها با نشانگر اینکه آیا پیشنهاد جایگزین دارند.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $out = [];
        foreach (['errors', 'warnings'] as $bucket) {
            foreach ($this->data[$bucket] as $issue) {
                $out[] = $issue + ['severity' => $bucket === 'errors' ? 'error' : 'warning'];
            }
        }

        return $out;
    }
}
