<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * K6.1 — منوی افزونه‌ها و پرمیشن‌های مدیر جاری.
 *
 * ## چرا این تسک وجود داشت
 *
 * `mergePluginMenu` در فرانت آماده و کامل بود و `admin/layout.tsx` آن را صدا
 * می‌زد، ولی ورودی‌اش یک آرایهٔ خالیِ هاردکد بود. نه مسیر سمت‌سروری برای داده
 * وجود داشت، نه پرمیشن‌های مدیر جاری از هیچ endpointای می‌آمد — و دروازهٔ
 * `permission` عمداً fail-closed است. نتیجه: افزونه‌ها می‌توانستند آیتم منو
 * اعلام کنند و **هیچ‌وقت دیده نمی‌شدند**، بدون هیچ پیام خطایی.
 */
class PluginMenuTest extends TestCase
{
    /**
     * `RefreshDatabase` عمدی است.
     *
     * بدون آن، افزونه‌های این تست‌ها در دیتابیس می‌مانند و هم منوی تست بعدی
     * آلوده می‌شود و هم تست‌های دیگری که انتظار جدول خالی دارند می‌شکنند.
     * پاک‌کردن دستی در `setUp` کافی نبود چون تراکنشِ بازِ تست دیگر را نمی‌بیند.
     */
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // `RefreshDatabase` دیتابیس را خالی می‌کند ولی **کش** مانیفست‌ها را نه.
        ManifestRegistry::flushCache();
    }

    private function manager(array $permissions = [], bool $super = false): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ]);

        if ($super) {
            // دیگر «سوپرادمین» وجود ندارد؛ همان پرمیشن‌های صریح کافی است.
        }

        if ($permissions !== []) {
            // `givePermissionTo` نام پرمیشن را می‌گیرد ولی رکورد Permission
            // باید **از قبل** وجود داشته باشد، وگرنه spatie در نقش اضافه‌اش
            // نمی‌کند و `can()` بی‌صدا `false` می‌شود — یعنی تست سبز می‌شد
            // در حالی که چیزی را نمی‌سنجید.
            $role = Role::query()->firstOrCreate(
                ['name' => 'r'.uniqid(), 'guard_name' => 'web'],
            );

            foreach ($permissions as $p) {
                Permission::query()->firstOrCreate(
                    ['name' => $p, 'guard_name' => 'web'],
                );
            }

            $role->givePermissionTo($permissions);
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function plugin(?string $slug = null, ?array $manifest = null): Plugin
    {
        // اسلاگ یکتا لازم است ولی تست‌ها نام مشخص می‌خواهند، پس `null` یعنی
        // «یکتا بساز» و رشته یعنی «همین را بگذار». `setUp` جدول را خالی
        // می‌کند، پس اسم ثابت دوباره مشکلی ندارد.
        $slug ??= 'p'.substr(md5(uniqid('', true)), 0, 12);

        $p = Plugin::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'manifest' => $manifest ?? [],
            'active' => true,
        ]);

        ManifestRegistry::flushCache();

        return $p;
    }

    // ── منو ────────────────────────────────────────────────────────────────

    public function test_menu_returns_declared_items(): void
    {
        $this->plugin('demo', ['menu' => [
            'reports' => ['label' => 'گزارش‌ها', 'href' => '/admin/reports', 'order' => 10],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/menu');

        $res->assertOk();
        $res->assertJsonPath('data.items.0.key', 'reports');
        $res->assertJsonPath('data.items.0.label', 'گزارش‌ها');
        $res->assertJsonPath('data.items.0.slug', 'demo');
    }

    public function test_menu_does_not_require_plugins_view_permission(): void
    {
        // این نکتهٔ اصلی تسک است: اگر مسیر `perm:plugins.view` داشت، مدیری که
        // آن پرمیشن را ندارد ۴۰۳ می‌گرفت و منویش بی‌صدا خالی می‌شد.
        $this->plugin(null, ['menu' => [
            'reports' => ['label' => 'گزارش‌ها', 'href' => '/admin/reports'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/menu')->assertOk();
    }

    public function test_menu_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/plugins/menu')->assertUnauthorized();
    }

    public function test_menu_is_empty_when_no_plugin_declares(): void
    {
        $this->plugin(null, ['name' => 'Quiet']);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/menu');

        $res->assertOk();
        $this->assertSame([], $res->json('data.items'), 'نباید آیتمی بدون اعلان برگردد.');
    }

    public function test_inactive_plugin_menu_items_are_absent(): void
    {
        $p = $this->plugin(null, ['menu' => [
            'reports' => ['label' => 'گزارش‌ها', 'href' => '/admin/reports'],
        ]]);
        $p->forceFill(['active' => false])->save();
        ManifestRegistry::flushCache();

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items'));
    }

    public function test_duplicate_keys_across_plugins_keep_the_first_not_the_last(): void
    {
        // برخورد: کلید آیتم فقط داخل یک افزونه یکتا است، ولی بین دو افزونه
        // می‌تواند تکرار شود. اولین برنده است و دومی حذف می‌شود.
        //
        // این فقط وقتی یک قرارداد واقعی است که ترتیب خواندن مانیفست‌ها قطعی
        // باشد. `activeManifests()` بدون `orderBy` ترتیب را به
        // PostgreSQL می‌سپرد و تضمینی نمی‌کرد، پس تست زیر در آن حالت
        // می‌توانست بین دو رندر جابه‌جا شود. `orderBy('slug')` این را قطعی
        // کرد و حالا ادعای «اولین برنده» چیزی است که کد تضمینش می‌کند.
        $this->plugin('a-first', ['menu' => [
            'reports' => ['label' => 'از الف', 'href' => '/admin/a'],
        ]]);
        $this->plugin('z-second', ['menu' => [
            'reports' => ['label' => 'از ب', 'href' => '/admin/b'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $items = $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items');

        $this->assertCount(1, $items, 'کلید تکراری باید یکی شود.');
        $this->assertSame('a-first', $items[0]['slug'], 'اولین افزونه (ترتیب حروفی slug) برنده است.');
    }

    public function test_top_level_menu_overrides_panel_extension_with_same_key(): void
    {
        // K6.8 قاعده را برای page_types گذاشت: کانال سطح‌بالا برنده است چون
        // مستقیم‌تر و صریح‌تر است. همان قاعده باید اینجا هم برقرار باشد وگرنه
        // دو مسیر برای یک کار پیدا می‌شود.
        $this->plugin(null, [
            'menu' => ['reports' => ['label' => 'از سطح‌بالا', 'href' => '/admin/top']],
            'panel' => ['extensions' => [
                ['point' => 'admin.menu', 'key' => 'reports', 'label' => 'از پنل', 'href' => '/admin/panel'],
            ]],
        ]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $items = $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('از سطح‌بالا', $items[0]['label']);
    }

    public function test_panel_extension_channel_is_read_when_no_top_level_key(): void
    {
        $this->plugin(null, [
            'panel' => ['extensions' => [
                ['point' => 'admin.menu', 'key' => 'billing', 'label' => 'صورتحساب', 'href' => '/admin/billing'],
            ]],
        ]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/plugins/menu');

        $res->assertJsonPath('data.items.0.key', 'billing');
    }

    public function test_other_extension_points_are_not_treated_as_menu(): void
    {
        $this->plugin(null, [
            'panel' => ['extensions' => [
                ['point' => 'site.page_type', 'key' => 'reports', 'label' => 'نوع صفحه', 'href' => '/admin/x'],
            ]],
        ]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $this->assertSame([], $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items'));
    }

    public function test_item_missing_required_fields_is_dropped_not_defaulted(): void
    {
        // `href` گمشده یعنی آیتم نمی‌تواند کار کند. ساختنش با مقدار پیش‌فرض
        // فقط یک آیتم شکسته به منو اضافه می‌کرد.
        $this->plugin(null, ['menu' => [
            'broken' => ['label' => 'بدون href'],
            'ok' => ['label' => 'سالم', 'href' => '/admin/ok'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $items = $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame('ok', $items[0]['key']);
    }

    public function test_order_defaults_to_last(): void
    {
        $this->plugin(null, ['menu' => [
            'a' => ['label' => 'الف', 'href' => '/admin/a', 'order' => 5],
            'b' => ['label' => 'ب', 'href' => '/admin/b'],
        ]]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $items = $auth->getJson('/api/v1/admin/plugins/menu')->json('data.items');

        $byKey = collect($items)->keyBy('key');
        $this->assertSame(5, $byKey['a']['order']);
        $this->assertSame(999, $byKey['b']['order'], 'بدون order باید ته بیفتد، نه اول.');
    }

    public function test_response_never_leaks_plugin_manifest_secrets(): void
    {
        // دلیل سومی که این مسیر از `/v1/admin/plugins` جدا شد: آنجا
        // `present()` کل مانیفست را می‌فرستاد.
        $this->plugin(null, [
            'menu' => ['reports' => ['label' => 'گزارش‌ها', 'href' => '/admin/reports']],
            'db' => ['tables' => ['secrets_table']],
            'signing' => ['public_key' => 'PUBLIC-KEY-LEAK'],
        ]);

        $auth = $this->actingAs($this->manager(), 'sanctum');
        $body = $auth->getJson('/api/v1/admin/plugins/menu')->getContent();

        $this->assertStringNotContainsString('PUBLIC-KEY-LEAK', $body);
        $this->assertStringNotContainsString('secrets_table', $body);
    }

    // ── پرمیشن‌های کاربر جاری ───────────────────────────────────────────────

    public function test_profile_returns_permissions_of_the_current_user(): void
    {
        $user = $this->manager(['pages.view', 'pages.edit']);

        $auth = $this->actingAs($user, 'sanctum');
        $res = $auth->getJson('/api/v1/admin/profile');

        $res->assertOk();
        $perms = $res->json('data.permissions');
        $this->assertContains('pages.view', $perms);
        $this->assertContains('pages.edit', $perms);
    }

    public function test_profile_does_not_leak_another_users_permissions(): void
    {
        // مهم‌تر: پرمیشن‌ها باید *همان* کاربر باشند. اگر endpoint فهرست کل
        // سیستم را می‌داد، هر مدیری می‌توانست سطح دسترسی بقیه را بخواند.
        $me = $this->manager(['pages.view']);
        $other = $this->manager(['media.view', 'users.view']);

        $auth = $this->actingAs($me, 'sanctum');
        $perms = $auth->getJson('/api/v1/admin/profile')->json('data.permissions');

        $this->assertContains('pages.view', $perms);
        $this->assertNotContains('media.view', $perms, 'نباید پرمیشن مدیر دیگر دیده شود.');
        $this->assertNotContains('users.view', $perms);
        $this->assertNotSame($other->id, $me->id);
    }

    public function test_profile_permissions_are_empty_for_a_user_with_no_roles(): void
    {
        $auth = $this->actingAs($this->manager(), 'sanctum');
        $perms = $auth->getJson('/api/v1/admin/profile')->json('data.permissions');

        $this->assertIsArray($perms);
        $this->assertSame([], $perms, 'بدون نقش باید خالی باشد — نه کاتالوگ کل سیستم.');
    }

    public function test_profile_no_longer_reports_super_admin_flag(): void
    {
        $auth = $this->actingAs($this->manager([], true), 'sanctum');
        $auth->getJson('/api/v1/admin/profile')->assertJsonMissingPath('data.is_super_admin');
    }

    public function test_menu_me_endpoint_reports_gate_state(): void
    {
        $auth = $this->actingAs($this->manager(), 'sanctum');
        $auth->getJson('/api/v1/admin/plugins/menu/me')
            ->assertOk()
            ->assertJsonPath('data.can_view_plugins', false);

        $auth->getJson('/api/v1/admin/plugins/menu')->assertOk();
    }
}
