<?php

namespace Tests\Feature;

use App\Services\Registry\CuratedRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * F1.2 + F6.1 — فهرستِ curated و endpointِ آن.
 *
 * ## چه چیزی اینجا سنجیده می‌شود
 *
 *  ۱. فایلِ فهرستِ واقعیِ مخزن سبز است (`registry:check`).
 *  ۲. `execution_status` در نسخهٔ ۱ **فقط** `unavailable` است — و این مهم‌ترینِ
 *     این فایل است. یک مدخل با `"available"` باید رد شود، چون همان یک کلمه
 *     در فرانت به «دکمهٔ نصبِ دروغین» تبدیل می‌شود.
 *  ۳. endpoint عمومی، فهرست را می‌دهد و `install_intent.available` را
 *     `false` نگه می‌دارد — یعنی **ادعای نصب نمی‌کند**.
 *
 * ## چرا فهرستِ واقعی خالی است و این تست عیب نیست
 *
 * هیچ بستهٔ سومی منتشر نشده. پر کردنش با ورودیِ ساختگی یعنی یک فهرستِ عمومی
 * که به کاربر وعده می‌دهد چیزی هست که وجود ندارد — دقیقاً همان چیزی که این
 * تسک می‌خواهد از آن جلوگیری کند. پس شکلِ فهرست با یک فایلِ موقتِ آزمون
 * سنجیده می‌شود، نه با دادهٔ نمایشی در مخزن.
 */
class CuratedRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = storage_path('framework/testing/registry-'.bin2hex(random_bytes(4)).'.json');
    }

    protected function tearDown(): void
    {
        File::delete($this->temp);

        parent::tearDown();
    }

    // ── ۱) فایلِ واقعی ─────────────────────────────────────────────────────

    public function test_the_committed_registry_file_passes_the_ci_gate(): void
    {
        $this->artisan('registry:check')->assertExitCode(0);
    }

    public function test_the_committed_registry_file_is_at_the_path_the_service_resolves(): void
    {
        $this->assertFileExists((new CuratedRegistry)->path());
    }

    // ── ۲) دروازهٔ صداقت ───────────────────────────────────────────────────

    public function test_an_entry_claiming_availability_is_rejected(): void
    {
        $errors = (new CuratedRegistry($this->write([
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [$this->entry(['execution_status' => 'available'])],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertNotSame([], $errors, '«available» در نسخهٔ ۱ نباید پذیرفته شود.');
        $this->assertStringContainsString('execution_status', implode(' | ', $errors));
    }

    public function test_a_registry_level_availability_claim_is_rejected(): void
    {
        $errors = (new CuratedRegistry($this->write([
            'execution_status' => 'available',
            'plugins' => [],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertNotSame([], $errors);
    }

    public function test_a_wrong_schema_version_is_rejected(): void
    {
        $errors = (new CuratedRegistry($this->write([
            '$schema_version' => 99,
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertStringContainsString('قالب', implode(' | ', $errors));
    }

    public function test_a_missing_required_field_is_rejected(): void
    {
        $entry = $this->entry();
        unset($entry['license']);

        $errors = (new CuratedRegistry($this->write([
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [$entry],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertStringContainsString('license', implode(' | ', $errors));
    }

    public function test_a_slug_the_core_would_reject_is_rejected_here_too(): void
    {
        // همان الگویی که `PluginReleaseManager::normalizeSlug()` می‌پذیرد.
        // یک slugِ ناسازگار یعنی کاربر روی شناسه‌ای کلیک می‌کند که هرگز قابل
        // نصب نیست — پس فهرست باید پیش از نمایش آن را رد کند.
        $errors = (new CuratedRegistry($this->write([
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [$this->entry(['slug' => 'Not A Slug'])],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertStringContainsString('slug', implode(' | ', $errors));
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        $errors = (new CuratedRegistry($this->write([
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [$this->entry(), $this->entry()],
        ])))->shapeErrors(json_decode((string) file_get_contents($this->temp), true));

        $this->assertStringContainsString('تکراری', implode(' | ', $errors));
    }

    public function test_a_well_formed_entry_is_accepted(): void
    {
        $registry = new CuratedRegistry($this->write([
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
            'plugins' => [$this->entry()],
        ]));

        $data = $registry->all();

        $this->assertCount(1, $data['plugins']);
        $this->assertSame('acme-shop', $data['plugins'][0]['slug']);
    }

    // ── ۳) endpoint ────────────────────────────────────────────────────────

    public function test_the_endpoint_serves_the_list_without_claiming_installation(): void
    {
        $this->getJson('/api/v1/registry')
            ->assertOk()
            ->assertJsonPath('data.execution_status', CuratedRegistry::EXECUTION_UNAVAILABLE)
            ->assertJsonPath('meta.install_intent.available', false);

        $this->assertSame(
            [],
            $this->getJson('/api/v1/registry')->json('data.plugins'),
            'فهرستِ فعلی خالی است؛ هر چیزی در آن یعنی ادعای بی‌پشتوانه.'
        );
    }

    public function test_the_endpoint_needs_no_authentication(): void
    {
        $this->getJson('/api/v1/registry')->assertOk();
    }

    public function test_the_endpoint_never_exposes_a_filesystem_path(): void
    {
        $body = $this->getJson('/api/v1/registry')->getContent();

        $this->assertStringNotContainsString(base_path(), (string) $body);
        $this->assertStringNotContainsString('/var/www', (string) $body);
    }

    // ── کمک‌کارها ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $payload */
    private function write(array $payload): string
    {
        $payload = array_merge([
            '$schema_version' => CuratedRegistry::SCHEMA_VERSION,
            'execution_status_fa' => 'نصب سیم‌کشی نشده است.',
            'themes' => [],
        ], $payload);

        File::put($this->temp, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $this->temp;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'acme-shop',
            'name_fa' => 'فروشگاه آکمی',
            'summary_fa' => 'یک فروشگاه نمونه.',
            'homepage_url' => 'https://example.test/shop',
            'source_url' => 'https://example.test/shop-src',
            'license' => 'MIT',
            'version' => '1.0.0',
            'categories' => ['commerce'],
            'execution_status' => CuratedRegistry::EXECUTION_UNAVAILABLE,
        ], $overrides);
    }
}
