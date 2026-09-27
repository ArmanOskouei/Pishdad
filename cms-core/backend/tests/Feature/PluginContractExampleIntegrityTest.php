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
     * تنها invariant لازم این است: نقطه‌ای که هیچ فیلدی برای اعلان ندارد
     * (`fields => []` و `open_schema => false`) نمی‌تواند `example_ok` داشته باشد.
     *
     * چون validator برای چنین نقطه‌ای `no_schema` خطای سخت می‌دهد، یعنی هر
     * اعلانی که آنجا گذاشته شود رد می‌شود. پس مثالی که پنل به نویسنده نشان می‌دهد
     * حتی با رعایتش هم خطا می‌گیرد.
     *
     * نقطه‌ای که اسکیمای واقعی دارد (مثل `core.service_provider`) برعکس — مثال
     * معتبر داشتنش درست و مطلوب است، پس برایش محدودیتی اعمال نمی‌کنیم.
     *
     * @dataProvider points
     */
    public function test_no_schemaless_point_advertises_an_example(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key] ?? null;

        $this->assertIsArray($meta, "نقطهٔ «{$key}» وجود ندارد.");

        $hasSchema = ($meta['schema']['fields'] ?? null) !== [] || ($meta['open_schema'] ?? false) === true;

        if ($hasSchema) {
            $this->assertNotSame(
                [],
                $meta['example_ok'] ?? [],
                "نقطهٔ «{$key}» اسکیمای واقعی دارد، پس مثال معتبر داشتنش لازم است — اگر مثال ندارد یعنی مستند ناقص است."
            );

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

    /**
     * نقطه‌ای که واقعاً اسکیما ندارد، باید **دلیلش را توضیح داده باشد** — وگرنه
     * خالی بودن `fields` شبیه یک بی‌دقتی ساده به نظر می‌رسد، درحالی که برای
     * `site.block_type` یک شکاف واقعی نیت-در-برابر-پیاده‌سازی است.
     *
     * @dataProvider schemalessPoints
     */
    public function test_a_schemaless_point_explains_itself(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key];

        $this->assertNotSame(
            '',
            trim((string) ($meta['openness_why'] ?? '')),
            "نقطهٔ «{$key}» اسکیما ندارد ولی نگفته چرا — خواننده نمی‌فهمد تصمیم است یا بی‌دقتی."
        );
    }

    /**
     * هیچ نقطه‌ای نباید هم‌زمان «واژگان باز» باشد و میکرو-اسکیمای بسته و خالی
     * داشته باشد — این ترکیب غیرقابل بیان است و نتیجه‌اش این است که نقطه هر
     * اعلانی را رد می‌کند، درحالی که برچسبش وعدهٔ باز بودن می‌دهد.
     *
     * `site.block_type` امروز دقیقاً در این وضعیت است و این یک تصمیم باز است، نه
     * یک باگ پنهان: نیت ثبت‌شده «واژگان باز، گرامر بسته» است ولی ساختار فعلی
     * `open_schema` این ترکیب را نمی‌تواند بیان کند. تا وقتی مال محصول تصمیم
     * نگیرد، این تست آن را فهرست می‌کند و بعد از رفع، از فهرست حذف می‌شود.
     *
     * @dataProvider points
     */
    public function test_open_vocabulary_never_claims_a_closed_empty_schema(string $key): void
    {
        $meta = PluginPackageContract::EXTENSION_POINTS[$key];

        if (($meta['openness'] ?? null) !== 'open_vocabulary') {
            $this->assertTrue(true, 'این نقطه واژگان باز نیست — شرط برایش معنا ندارد.');

            return;
        }

        $consistent = ($meta['open_schema'] ?? false) === true || ($meta['schema']['fields'] ?? []) !== [];

        if (in_array($key, self::KNOWN_OPEN_VOCABULARY_GAPS, true)) {
            $this->assertFalse(
                $consistent,
                "«{$key}» از فهرست شکاف‌های شناخته‌شده بیرون آمده — پس یا تصمیمش گرفته شد (این تست را سخت‌گیر کن) یا قرارداد دوباره ناسازگار شده."
            );

            return;
        }

        $this->assertTrue(
            $consistent,
            "نقطهٔ «{$key}» واژگان باز است ولی میکرو-اسکیمای بسته و خالی دارد — یعنی هر اعلانی با no_schema رد می‌شود درحالی که برچسبش وعدهٔ باز بودن می‌دهد. یا `fields` را پر کن یا `open_schema` را باز کن."
        );
    }

    /**
     * نقاطی که می‌دانیم امروز ناسازگارند و منتظر تصمیم مال محصول‌اند.
     *
     * @var list<string>
     */
    private const KNOWN_OPEN_VOCABULARY_GAPS = [
        'site.block_type',
    ];

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

    /**
     * @return array<int, array{0: string}>
     *
     * @phpstan-ignore-next-line
     */
    public static function schemalessPoints(): array
    {
        return array_map(
            static fn (string $key): array => [$key],
            array_keys(array_filter(
                PluginPackageContract::EXTENSION_POINTS,
                static fn (array $m): bool => ($m['schema']['fields'] ?? null) === [] && ($m['open_schema'] ?? false) === false
            ))
        );
    }
}
