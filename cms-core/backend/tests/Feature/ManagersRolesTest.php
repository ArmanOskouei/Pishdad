<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\PluginDdlTestCase;
use ZipArchive;

/** تسک ۵.۱ — DoD: ساخت مدیر + تخصیص نقش + ماتریس نقش‌ها. */
class ManagersRolesTest extends PluginDdlTestCase
{
    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    public function test_create_manager_assigns_role(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $res = $auth->postJson('/api/v1/admin/managers', [
            'name' => 'ویراستار', 'email' => 'editor@example.com',
            'password' => 'Editor!1234', 'role' => 'editor',
        ]);

        $res->assertCreated()->assertJsonPath('message', 'مدیر ساخته شد.');
        $this->assertTrue(
            User::query()->where('email', 'editor@example.com')->first()->hasRole('editor')
        );
    }

    public function test_create_manager_rejects_weak_password(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/managers', [
            'name' => 'ضعیف', 'email' => 'weak@example.com', 'password' => 'simplepassword',
        ])->assertStatus(422);
    }

    public function test_self_delete_is_blocked(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/admin/managers/{$owner->id}")
            ->assertStatus(422);
    }

    public function test_roles_crud_and_matrix(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // ماتریس داینامیک از رجیستری: هر ماژول title_fa + هر اکشن title_fa.
        $matrix = $auth->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        $this->assertSame('tickets', $matrix['modules'][3]['name']);
        $this->assertSame('تیکت‌ها', $matrix['modules'][3]['title_fa']);
        $this->assertSame('core', $matrix['modules'][3]['source']);
        $this->assertSame(
            ['view' => 'مشاهده', 'edit' => 'ویرایش', 'delete' => 'حذف'],
            collect($matrix['actions'])->pluck('title_fa', 'name')->all()
        );

        $created = $auth->postJson('/api/v1/admin/roles', [
            'name' => 'support', 'permissions' => ['tickets.view', 'tickets.edit'],
        ])->assertCreated()->json('data');
        $perms = $created['permissions'];
        sort($perms);
        $this->assertEquals(['tickets.edit', 'tickets.view'], $perms);

        $roleId = $created['id'];
        $auth->putJson("/api/v1/admin/roles/{$roleId}", [
            'permissions' => ['tickets.view'],
        ])->assertOk()->assertJsonPath('data.permissions', ['tickets.view']);

        // نقش سیستمی قابل حذف نیست؛ نقش سفارشی خالی حذف می‌شود.
        $ownerRoleId = Role::query()->where('name', 'owner')->first()->id;
        $auth->deleteJson("/api/v1/admin/roles/{$ownerRoleId}")->assertStatus(422);
        $auth->deleteJson("/api/v1/admin/roles/{$roleId}")->assertOk();

        $auth->postJson('/api/v1/admin/roles', ['name' => 'super-admin'])->assertStatus(422);
    }

    public function test_viewer_cannot_manage_managers(): void
    {
        $this->seed(RolesPermissionsSeeder::class);
        $viewer = User::query()->create([
            'name' => 'بیننده', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Viewer!1234'), 'role' => 'admin',
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer->fresh(), 'sanctum')
            ->postJson('/api/v1/admin/managers', [
                'name' => 'x', 'email' => 'x@example.com', 'password' => 'Xx!1234567',
            ])->assertForbidden();
    }

    /**
     * تسک ۴ — ماژول پلاگین نمایشی: فعال‌سازی → حضور در ماتریس با title_fa →
     * تخصیص به نقش → بررسی دسترسی مدیر. ماتریس کاربر دیگر بدون آن ماژول.
     */
    public function test_plugin_module_appears_in_matrix_and_is_assignable(): void
    {
        $owner = $this->owner();
        $auth = $this->actingAs($owner, 'sanctum');

        $kp = sodium_crypto_sign_keypair();
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $verifier = app(PluginSignatureVerifier::class);
        $manifest = [
            'name' => 'بلاگ نمایشی', 'slug' => 'demo-blog', 'version' => '1.0.0',
            'permissions' => [['module' => 'blog', 'title_fa' => 'بلاگ']],
        ];
        $manifest['signature'] = $verifier->sign(
            $manifest, base64_encode(sodium_crypto_sign_secretkey($kp))
        );
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => new UploadedFile($path, 'plugin.zip', 'application/zip', null, true),
        ])->assertCreated()->json('data.id');

        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);
        // بازبینیِ افزونه در **هسته** است: `POST /api/v1/market/plugins/{id}/approve`
        // با `role:operator` ⇒ `ReviewService::approve()`. آپلود از مالک بوده ⇒
        // `review_status=pending`، پس approve گاردِ خودش را رد نمی‌کند.
        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$id}/approve")->assertOk();
        $auth = $this->actingAs($owner->fresh(), 'sanctum');
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        $matrix = $auth->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        $blog = collect($matrix['modules'])->firstWhere('name', 'plugin:demo-blog:blog');
        $this->assertNotNull($blog);
        $this->assertSame('بلاگ', $blog['title_fa']);
        $this->assertSame('plugin:demo-blog', $blog['source']);

        $roleId = $auth->postJson('/api/v1/admin/roles', [
            'name' => 'blogger', 'permissions' => ['plugin:demo-blog:blog.view', 'plugin:demo-blog:blog.edit'],
        ])->assertCreated()->json('data.id');
        $this->assertIsInt($roleId);

        $manager = User::query()->create([
            'name' => 'بلاگ‌نویس', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Blogger!1234'), 'role' => 'admin',
        ]);
        $manager->assignRole('blogger');
        $this->assertTrue($manager->fresh()->can('plugin:demo-blog:blog.view'));
        $this->assertTrue($manager->fresh()->can('plugin:demo-blog:blog.edit'));
        $this->assertFalse($manager->fresh()->can('plugin:demo-blog:blog.delete'));
        $this->assertFalse($manager->fresh()->can('tickets.view'));

        // مشترک نصب: مدیر دیگر هم ماژول بلاگ را در ماتریس می‌بیند.
        $other = User::query()->create([
            'name' => 'دیگری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Other!1234'), 'role' => 'admin',
        ]);
        $other->assignRole('owner');
        $otherMatrix = $this->actingAs($other->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        $this->assertNotNull(collect($otherMatrix['modules'])->firstWhere('name', 'plugin:demo-blog:blog'));
    }
}
