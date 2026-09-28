<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Subscription;
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

        // اشتراک پیش‌فرض نصب را به همین کاربر گره می‌زنیم تا مالک پیدا شود.
        $client = Client::query()->oldest('id')->first();
        if ($client) {
            $client->forceFill(['owner_user_id' => $user->id])->save();
        } else {
            $client = Client::query()->create([
                'name' => 'نصب تست', 'site_id' => 'site-test',
                'tier' => 'budget', 'status' => 'active', 'owner_user_id' => $user->id,
            ]);
            Subscription::query()->create([
                'client_id' => $client->id, 'site_id' => $client->site_id,
                'status' => 'active', 'due_at' => now()->addDays(30),
            ]);
        }

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
}
