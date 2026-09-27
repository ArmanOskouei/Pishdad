<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginPackageContract;
use Tests\TestCase;

/**
 * K6.7 — قرارداد دیگر «مثال معتبرِ نامعتبر» صادر نمی‌کند.
 *
 * نکته: این تست عمداً به PHPUnit نیاز دارد، پس تا وقتی Docker برنگردد اجرا
 * نمی‌شود. نوشته شد تا همان لحظهٔ برگشت، قابل اجرا باشد.
 */
class PluginContractExampleIntegrityTest extends TestCase
{
    /**
     * هر نقطه‌ای که `fields` خالی و `open_schema => false` دارد، نمی‌تواند
     * `example_ok` داشته باشد — چون validator هر اعلانی را با `no_schema` رد
     * می‌کند. یعنی مثالی که این‌جا به نویسنده نشان داده می‌شود، حتی با رعایتش
     * هم خطا می‌گیرد.
     *
     * @dataProvider points
     */
    public function test_no_point_advertises_an_example_its_own_validator_rejects(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key] ?? null;

        $this->assertIsArray($meta, "نقطهٔ «{$key}» وجود ندارد.");

        if (($meta['schema']['fields'] ?? null) !== [] || ($meta['open_schema'] ?? false) === true) {
            $this->assertSame([], $meta['example_ok'] ?? [], "نقطهٔ «{$key}» schema دارد، پس مثال معتبر داشتنش مجاز است.");

            return;
        }

        $this->assertSame(
            [],
            $meta['example_ok'] ?? null,
            "نقطهٔ «{$key}» فیلد اعلام‌نشده دارد و مثال «معتبر» هم دارد — ولی validator آن مثال را با no_schema رد می‌کند."
        );
    }

    /**
     * @dataProvider points
     */
    public function test_every_point_has_a_label_and_a_status(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key];

        $this->assertNotSame('', trim((string) ($meta['label_fa'] ?? '')), "نقطهٔ «{$key}» برچسب ندارد.");
        $this->assertContains(
            $meta['status'] ?? null,
            ['live', 'declared_only', 'deferred', 'deprecated'],
            "نقطهٔ «{$key}» وضعیت ناشناخته دارد."
        );
    }

    /**
     * نقطه‌ای که `deprecated` شده باید بگوید جایگزینش چیست — وگرنه نویسنده
     * می‌ماند با یک کلید که هیچ‌جا کار نمی‌کند و هیچ راهنمایی هم ندارد.
     *
     * @dataProvider deprecatedPoints
     */
    public function test_a_deprecated_point_says_what_replaces_it(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key];

        $this->assertSame('deprecated', $meta['status'] ?? null);
        $this->assertNotSame('', trim((string) ($meta['deprecated']['replaced_by'] ?? '')), "«{$key}» جایگزین نام‌برده نشده.");
        $this->assertNotSame('', trim((string) ($meta['deprecated']['reason_fa'] ?? '')), "«{$key}» دلیل حذف را نگفته.");
    }

    /** @return array<int, array{0: string}> */
    public static function points(): array
    {
        return array_map(
            static fn (string $key): array => [$key],
            array_keys(PluginPackageContract::EXTENSION_POINTS)
        );
    }

    /** @return array<int, array{0: string}> */
    public static function deprecatedPoints(): array
    {
        return array_map(
            static fn (string $key): array => [$key],
            array_keys(array_filter(
                PluginPackageContract::EXTENSION_POINTS,
                static fn (array $m): bool => ($m['status'] ?? null) === 'deprecated'
            ))
        );
    }
}
