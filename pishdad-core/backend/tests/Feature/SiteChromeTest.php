<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * کروم عمومی سایت: بدون احراز هویت، سبک، بدون داده حساس —
 * عنوان/لوگو/شبکه‌ها از settings همان نصب + لی‌آوت header/footer + قالب فعال.
 */
class SiteChromeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر سایت',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_chrome_returns_install_settings_without_auth(): void
    {
        $user = $this->user();
        // مشترک نصب: کلیدهای سراسری (همان‌چه SiteSettingsController می‌نویسد).
        $key = 'global';

        Setting::set('site', $key, [
            'title' => 'فروشگاه نمونه',
            'description' => 'درباره فروشگاه',
            'logo_media_id' => null,
            'favicon_media_id' => null,
            'phone' => null,
            'email' => 'info@example.com',
        ]);
        Setting::set('socials', $key, ['socials' => [
            ['key' => 'instagram', 'url' => 'https://instagram.com/shop', 'active' => true, 'label' => null, 'icon_media_id' => null],
            ['key' => 'telegram', 'url' => 'https://t.me/shop', 'active' => false, 'label' => null, 'icon_media_id' => null],
        ]]);
        Setting::set('layout', $key.':header', [
            'widgets' => [['type' => 'logo', 'settings' => []], ['type' => 'nav', 'settings' => []]],
            'layout' => ['columns' => 2],
        ]);
        Setting::set('layout', $key.':footer', [
            'widgets' => [['type' => 'about', 'settings' => ['text' => 'متن درباره']], ['type' => 'copyright', 'settings' => []]],
            'layout' => ['columns' => 3],
        ]);
        Theme::query()->create([
            'user_id' => $user->id, 'name' => 'قالب روشن', 'slug' => 'light',
            'version' => '1.0.0', 'active' => true,
        ]);

        $res = $this->getJson('/api/v1/site/chrome')->assertOk();

        $res->assertJsonPath('data.title', 'فروشگاه نمونه')
            ->assertJsonPath('data.description', 'درباره فروشگاه')
            ->assertJsonPath('data.header.widgets.0.type', 'logo')
            ->assertJsonPath('data.footer.widgets.0.settings.text', 'متن درباره')
            ->assertJsonPath('data.theme.slug', 'light');

        // فقط شبکه فعال برمی‌گردد.
        $this->assertCount(1, $res->json('data.socials'));
        $this->assertSame('instagram', $res->json('data.socials.0.key'));

        // بدون نشت داده حساس.
        $body = $res->getContent();
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('token', $body);
        $this->assertStringNotContainsString($user->email, $body);
    }

    public function test_chrome_without_install_returns_defaults(): void
    {
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.title', 'وب‌سایت من')
            ->assertJsonPath('data.header.widgets.0.type', 'logo');
    }

    /**
     * K6.12 (نیمهٔ بک‌اند) — سایتِ عمومی باید رجیستری بلوکِ schema-only را
     * ببیند، بدون هیچ JS/React از افزونه.
     *
     * رگرسیونِ ثبت‌شده: `/admin/blocks/schema` پشت `auth:sanctum` بود و سایتِ
     * عمومی هیچ راهی به schema افزونه نداشت، پس `DeclaredBlock` هرگز
     * `x-pattern` را نمی‌دید. حالا کرومِ عمومی (کش‌شده) آن را می‌دهد.
     */
    public function test_chrome_exposes_plugin_block_schemas_without_js(): void
    {
        Plugin::query()->create([
            'slug' => 'blocks-plugin',
            'name' => 'بلوک‌ها',
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'active' => true,
            'manifest' => [
                'blocks' => [
                    'price-table' => ['schema' => [
                        'type' => 'object',
                        'properties' => [
                            'x-pattern' => ['type' => 'string', 'default' => 'grid'],
                            // کلید اجرایی باید حذف شود (فقط schema می‌رسد).
                            'items' => ['type' => 'array', 'render' => 'evil'],
                        ],
                    ]],
                    // بلوکِ هسته نباید با اعلانِ افزونه پوشانده شود.
                    'hero' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['x-pattern' => ['type' => 'string', 'default' => 'cta']],
                    ]],
                ],
            ],
        ]);
        ManifestRegistry::flushCache();

        $schemas = ManifestRegistry::blockSchemas();

        $this->assertArrayHasKey('price-table', $schemas);
        $this->assertSame('grid', $schemas['price-table']['properties']['x-pattern']['default']);
        $this->assertArrayNotHasKey('render', $schemas['price-table']['properties']['items']);
        $this->assertContains('price-table', ManifestRegistry::allowedBlockTypes());
        $this->assertContains('hero', ManifestRegistry::allowedBlockTypes());

        // هسته برنده است: schema هیروی افزونه نباید جای config/blocks را بگیرد.
        $this->assertArrayNotHasKey('x-pattern', $schemas['hero']['properties']);

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.blocks.price-table.properties.x-pattern.default', 'grid');
    }
}
