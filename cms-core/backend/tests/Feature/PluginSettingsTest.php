<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\Setting;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * K6.7 — تنظیمات افزونه‌ها.
 *
 * پیش از این، سه تکه بود: مصرف‌کننده (`pluginSettingsSchemas`)، فرم
 * (`SchemaForm`)، و **هیچ** مسیر ذخیره‌ای. پس افزونه می‌توانست فرم اعلام کند و
 * هیچ‌جا رندر نمی‌شد — همان «نصب شد ولی دیده نشد».
 */
class PluginSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManifestRegistry::flushCache();
    }

    private function manager(array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ]);

        if ($permissions !== []) {
            $role = Role::query()->firstOrCreate(['name' => 'r'.uniqid(), 'guard_name' => 'web']);
            foreach ($permissions as $p) {
                Permission::query()->firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            }
            $role->givePermissionTo($permissions);
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /** افزونهٔ فعال با یک اسکیمای تخت و واقعی. */
    private function pluginWithSettings(?array $schema = null, ?string $slug = null, string $key = 'shop'): Plugin
    {
        $p = Plugin::query()->create([
            'slug' => $slug ?? ('p'.substr(md5(uniqid('', true)), 0, 12)),
            'name' => 'Shop',
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'manifest' => ['settings' => [
                // کلید باید یکتا باشد: `pluginSettingsSchemas()` برخورد کلید
                // را مثل هر نقطهٔ دیگر حذف می‌کند. پس دو افزونهٔ متفاوت باید
                // کلید متفاوت بدهند، وگرنه دومی بی‌صدا ناپدید می‌شود — همان
                // «نصب شد ولی دیده نشد» که کل این نقطه را بازسازی می‌کند.
                $key => [
                    'title_fa' => 'فروشگاه',
                    'group' => 'عمومی',
                    'schema' => $schema ?? [
                        'type' => 'object',
                        'properties' => [
                            'currency' => ['type' => 'enum', 'enum' => ['IRR', 'USD'], 'default' => 'IRR'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                            'active' => ['type' => 'boolean', 'default' => true],
                        ],
                        'required' => ['currency'],
                    ],
                ],
            ]],
            'active' => true,
        ]);

        ManifestRegistry::flushCache();

        return $p;
    }

    private function asEditor(): User
    {
        return $this->manager(['settings.view', 'settings.edit']);
    }

    // ── خواندن ─────────────────────────────────────────────────────────────

    public function test_index_returns_schema_and_empty_values(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/settings');

        $res->assertOk();
        $res->assertJsonPath('data.schemas.0.slug', $p->slug);
        $res->assertJsonPath('data.schemas.0.key', 'shop');
        // هنوز چیزی ذخیره نشده ⇒ کلید افزونه هست ولی مقدارش خالی است.
        $res->assertJsonPath('data.values.'.$p->slug, []);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/plugins/settings')->assertUnauthorized();
    }

    public function test_index_requires_settings_view_permission(): void
    {
        // برخلاف منو و صفحه، اینجا مقدار خوانده می‌شود پس مجوز لازم است.
        $this->pluginWithSettings();

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/settings')->assertForbidden();
    }

    public function test_inactive_plugin_schema_is_absent(): void
    {
        $p = $this->pluginWithSettings();
        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/settings')->json('data.schemas'));
    }

    // ── ذخیره ──────────────────────────────────────────────────────────────

    public function test_valid_values_are_persisted(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, [
            'currency' => 'USD',
            'per_page' => 50,
            'active' => false,
        ])->assertOk();

        $this->assertSame('USD', PluginSettingsStore::read($p->slug)['currency'] ?? null);
        $this->assertDatabaseHas('settings', [
            'group' => 'plugin:'.$p->slug,
            'key' => 'global',
        ]);
    }

    public function test_update_requires_settings_edit_permission(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->manager(['settings.view']), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'USD'])
            ->assertForbidden();
    }

    public function test_update_on_plugin_without_schema_returns_404(): void
    {
        // ۴۰۴ نه ۴۲۲: مشکل «این فرم وجود ندارد» است، نه «ورودی‌ات بد است».
        $p = Plugin::query()->create([
            'slug' => 'quiet', 'name' => 'Q', 'author' => 't', 'version' => '1.0.0',
            'signature' => 'x', 'public_key' => 'x', 'manifest' => [], 'active' => true,
        ]);
        ManifestRegistry::flushCache();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['x' => 1])->assertNotFound();
    }

    // ── مرزهای اعتبارسنجی ─────────────────────────────────────────────────

    public function test_undeclared_key_is_rejected(): void
    {
        // مهم‌ترین مرز: اگر کلید اعلام‌نشده رد نمی‌شد، یک درخواست دستی هر
        // کلیدی تزریق می‌کرد و آن کلید بعداً در UI رندر می‌شد.
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, [
            'currency' => 'USD',
            'is_admin' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_admin');

        $this->assertArrayNotHasKey('is_admin', PluginSettingsStore::read($p->slug));
    }

    public function test_enum_value_outside_the_list_is_rejected(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'BTC'])
            ->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_numeric_bounds_are_enforced(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, [
            'currency' => 'IRR', 'per_page' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_type_is_not_coerced(): void
    {
        // رشتهٔ 'true' نباید boolean ذخیره شود — `SchemaForm` نوع فیلد را
        // می‌خواند و یک رشته در فیلد boolean رندر اشتباه می‌سازد.
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, [
            'currency' => 'IRR', 'active' => 'true',
        ])->assertStatus(422)->assertJsonValidationErrors('active');
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['per_page' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_absent_optional_field_falls_back_to_declared_default(): void
    {
        // پیش‌فرض ذخیره می‌شود تا UI بعد از بارگذاری مجدد همان چیزی را ببیند
        // که افزونه اعلام کرده، نه اینکه فیلد خالی بماند.
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'USD'])
            ->assertOk();

        $stored = PluginSettingsStore::read($p->slug);
        $this->assertSame(20, $stored['per_page'] ?? null);
        $this->assertTrue($stored['active'] ?? null);
    }

    public function test_nested_values_in_a_list_are_rejected(): void
    {
        $p = $this->pluginWithSettings([
            'type' => 'object',
            'properties' => ['tags' => ['type' => 'string_list']],
        ]);

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, [
            'tags' => [['nested' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors('tags');
    }

    public function test_a_rejected_write_leaves_the_previous_value_intact(): void
    {
        // اعتبارسنجی باید **قبل** از نوشتن اتفاق بیفتد، وگرنه یک درخواست
        // ناموفق تنظیمات قبلی را پاک می‌کند.
        $p = $this->pluginWithSettings();

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'USD'])->assertOk();

        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'BTC'])
            ->assertStatus(422);

        $this->assertSame('USD', PluginSettingsStore::read($p->slug)['currency'] ?? null);
    }

    // ── مرز ذخیره ──────────────────────────────────────────────────────────

    public function test_settings_are_namespaced_by_slug(): void
    {
        // دو افزونه نباید روی هم بنویسند. `group` پیشوند دارد دقیقاً برای همین.
        $a = $this->pluginWithSettings(null, 'shop-a', 'shop');
        $b = $this->pluginWithSettings(null, 'shop-b', 'orders');

        $this->assertNotSame($a->id, $b->id, 'هر دو باید رکورد جدا باشند.');

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$a->slug, ['currency' => 'USD'])->assertOk();
        $auth->putJson('/api/v1/admin/plugins/settings/'.$b->slug, ['currency' => 'IRR'])->assertOk();

        $this->assertSame('USD', PluginSettingsStore::read($a->slug)['currency'] ?? null);
        $this->assertSame('IRR', PluginSettingsStore::read($b->slug)['currency'] ?? null);
    }

    public function test_plugin_settings_cannot_overwrite_ui_settings(): void
    {
        // اسلاگی که با یک گروه موجود برخورد کند نباید به آن دست بیندازد.
        //
        // `write()` **همیشه** پیشوند می‌زند، پس فراخوانی با اسلاگ `ui` رکورد
        // `plugin:ui` می‌سازد نه `ui`. این تست همان را قفل می‌کند: هم گروه
        // واقعی موجود دست‌نخورده می‌ماند، هم گروه تازه جداگانه ساخته می‌شود.
        Setting::query()->delete();

        Setting::set('ui', 'user_1', ['theme' => 'dark']);
        PluginSettingsStore::write('ui', ['x' => 1]);

        $this->assertSame(['theme' => 'dark'], Setting::get('ui', 'user_1', null), 'تنظیمات ظاهر کاربر نباید تغییر کند.');

        $rows = Setting::query()->pluck('group')->all();
        $this->assertContains('ui', $rows, 'گروه اصلی باید سر جایش بماند.');
        $this->assertContains('plugin:ui', $rows, 'گروه افزونه باید جدا باشد.');
    }

    public function test_a_colliding_settings_key_drops_the_second_plugin(): void
    {
        // این رفتار **عمدی** است و همین‌جا قفل می‌شود، ولی باید بدانیم چه
        // هزینه‌ای دارد: دو افزونه که هر دو کلید `shop` می‌دهند، دومی بی‌صدا
        // حذف می‌شود — نه خطا، نه اعلان. نویسندهٔ افزونه نمی‌فهمد فرم تنظیماتش
        // هرگز رندر نمی‌شود.
        //
        // این با تصمیم محصول (کلید سطح‌بالا به‌جای نقطهٔ micro-schema) سازگار
        // است: بدون نقطهٔ مجزا، برخورد فقط در سطح کلید قابل تشخیص است. باقی
        // نقاط رجیستری (`widgetSchemas`, `pageTypes`) همین قاعده را دارند.
        $a = $this->pluginWithSettings(null, 'shop-a', 'shared');
        $b = $this->pluginWithSettings(null, 'shop-b', 'shared');

        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/settings');

        $res->assertOk();
        $slugs = collect($res->json('data.schemas'))->pluck('slug');
        $this->assertTrue($slugs->contains($a->slug), 'برنده (اولین بر اساس slug) زنده است.');
        $this->assertFalse($slugs->contains($b->slug), 'بازنده حذف می‌شود.');
    }

    public function test_index_omits_values_for_plugins_that_were_removed(): void
    {
        $p = $this->pluginWithSettings();
        $auth = $this->actingAs($this->asEditor(), 'sanctum');
        $auth->putJson('/api/v1/admin/plugins/settings/'.$p->slug, ['currency' => 'USD'])->assertOk();

        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $res = $auth->getJson('/api/v1/admin/plugins/settings');
        $res->assertOk();
        $this->assertSame([], $res->json('data.values'), 'افزونهٔ غیرفعال نباید در پاسخ بیاید.');
    }
}
