<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\CachedSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ساده‌سازی ویجت لوگو: اسکیما فقط show_title؛ لوگو همیشه از تنظیمات
 * سایت است و تنظیمات قدیمی (media_id/size) نادیده گرفته می‌شوند (نه خطا).
 */
class LogoWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_schema_has_no_media_id_or_size(): void
    {
        $schemas = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/widgets/schema')->assertOk()->json('data');

        $logo = collect($schemas)->firstWhere(fn (array $s) => $s['area'] === 'header' && $s['type'] === 'logo');
        $this->assertNotNull($logo);
        $this->assertArrayHasKey('show_title', $logo['schema']['properties']);
        $this->assertArrayNotHasKey('media_id', $logo['schema']['properties']);
        $this->assertArrayNotHasKey('size', $logo['schema']['properties']);
    }

    public function test_legacy_logo_settings_accepted_and_ignored(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        // تنظیمات قدیمی خطا نمی‌دهد…
        $auth->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [[
                'type' => 'logo',
                'settings' => ['media_id' => 999, 'size' => 'lg', 'show_title' => false],
            ]],
        ])->assertOk();

        // …ولی در ذخیره‌سازی پاک می‌شود.
        $stored = $auth->getJson('/api/v1/admin/layouts/header')->assertOk()->json('data.widgets.0.settings');
        $this->assertArrayNotHasKey('media_id', $stored);
        $this->assertArrayNotHasKey('size', $stored);
        $this->assertFalse($stored['show_title']);

        // …و در کروم عمومی هیچ media_url اختصاصی از ویجت نمی‌آید.
        CachedSettings::forget('site_chrome', 'chrome-default-global');
        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data.header.widgets.0.settings');
        $this->assertArrayNotHasKey('media_id', $chrome);
        $this->assertArrayNotHasKey('media_url', $chrome);
        $this->assertArrayNotHasKey('size', $chrome);
    }

    public function test_logo_url_still_comes_from_site_settings(): void
    {
        $user = $this->user();
        $key = 'global';

        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'original_name' => 'logo.png', 'path' => 'u/logo.png',
            'mime' => 'image/png', 'size' => 100,
        ]);
        Setting::set('site', $key, array_merge(
            SiteSettingsController::SITE_DEFAULTS,
            ['title' => 'سایت من', 'logo_media_id' => $media->id]
        ));

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/layouts/header', [
            'widgets' => [['type' => 'logo', 'settings' => ['show_title' => true]]],
        ])->assertOk();

        CachedSettings::forget('site_chrome', 'chrome-default-global');
        $chrome = $this->getJson('/api/v1/site/chrome')->assertOk()->json('data');
        $this->assertNotNull($chrome['logo_url']);
        $this->assertStringContainsString('u/logo.png', $chrome['logo_url']);
    }
}
