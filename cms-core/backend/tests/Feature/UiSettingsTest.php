<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\UiSettingsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::query()->create([
            'name' => 'کاربر تست',
            'email' => $email,
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_guest_cannot_access_ui_settings(): void
    {
        $this->getJson('/api/v1/ui-settings')->assertStatus(401);
        $this->putJson('/api/v1/ui-settings', ['mode' => 'dark'])->assertStatus(401);
    }

    public function test_show_returns_defaults_when_nothing_saved(): void
    {
        Sanctum::actingAs($this->makeUser('a@example.com'));

        $this->getJson('/api/v1/ui-settings')
            ->assertOk()
            ->assertJsonPath('data.dir', 'rtl')
            ->assertJsonPath('data.preset', 'amaliyat')
            ->assertJsonPath('data.accent', 'indigo')
            ->assertJsonPath('data.mode', 'dark')
            ->assertJsonPath('data.bottom_nav', UiSettingsController::BOTTOM_NAV_DEFAULTS);
    }

    public function test_update_stores_valid_settings_globally(): void
    {
        Sanctum::actingAs($this->makeUser('b@example.com'));

        $payload = [
            'dir' => 'rtl',
            'preset' => 'sahar',
            'accent' => 'rose',
            'mode' => 'light',
            'radius' => 'rounded',
            'density' => 'loose',
            'font' => 'lg',
            'bottom_nav' => UiSettingsController::BOTTOM_NAV_DEFAULTS,
        ];

        $this->putJson('/api/v1/ui-settings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'تنظیمات ظاهر ذخیره شد.')
            ->assertJsonPath('data', $payload);

        $this->assertDatabaseHas('settings', ['group' => 'ui', 'key' => 'global']);
        // رنگِ انتخابی باید در رفرش باقی بماند — این کلِ باگِ «رنگ اعمال نمی‌شود» بود.
        $this->getJson('/api/v1/ui-settings')
            ->assertJsonPath('data.preset', 'sahar')
            ->assertJsonPath('data.accent', 'rose');
    }

    public function test_accent_must_be_a_known_colour(): void
    {
        Sanctum::actingAs($this->makeUser('accent@example.com'));

        $this->putJson('/api/v1/ui-settings', ['accent' => 'rainbow'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accent');
    }

    public function test_update_stores_valid_bottom_nav_globally(): void
    {
        Sanctum::actingAs($this->makeUser('bottom-nav@example.com'));

        $paths = ['/admin/dashboard', '/admin/pages', '/admin/appearance', '/admin/settings'];

        $this->putJson('/api/v1/ui-settings', ['bottom_nav' => $paths])
            ->assertOk()
            ->assertJsonPath('data.bottom_nav', $paths);

        $this->getJson('/api/v1/ui-settings')
            ->assertOk()
            ->assertJsonPath('data.bottom_nav', $paths);
    }

    public function test_update_rejects_bottom_nav_outside_allowed_panel_routes(): void
    {
        Sanctum::actingAs($this->makeUser('bottom-nav-invalid@example.com'));

        $this->putJson('/api/v1/ui-settings', ['bottom_nav' => ['/outside/dashboard']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bottom_nav.0');
    }

    public function test_update_rejects_invalid_values(): void
    {
        Sanctum::actingAs($this->makeUser('c@example.com'));

        $this->putJson('/api/v1/ui-settings', ['preset' => 'windows-xp', 'mode' => 'neon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preset', 'mode']);
    }

    public function test_settings_are_shared_across_managers(): void
    {
        $userA = $this->makeUser('d@example.com');
        $userB = $this->makeUser('e@example.com');

        Sanctum::actingAs($userA);
        $this->putJson('/api/v1/ui-settings', ['preset' => 'divan'])->assertOk();

        // مدیر دوم همان قالب را می‌بیند (سراسری نصب، نه per-user).
        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/ui-settings')->assertJsonPath('data.preset', 'divan');
    }
}
