<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * تسک ۵.۱ — ماتریس دسترسی: ماژول × (view|edit|delete).
 * نقش‌ها: owner (همه) / admin قدیمی (همه، سازگاری) / editor (view+edit) / viewer (فقط view).
 *
 * فهرست ماژول‌های هسته از config/modules.php خوانده می‌شود (تک‌منبع با
 * ماتریس GET /api/v1/admin/permissions)؛ const برای سازگاری نگه داشته شده.
 */
class RolesPermissionsSeeder extends Seeder
{
    public const MODULES = [
        'pages', 'media', 'settings', 'tickets', 'plugins',
        'themes', 'layouts', 'users',
    ];

    public const ACTIONS = ['view', 'edit', 'delete'];

    /** نام ماژول‌های هسته — همان ترتیبی که ماتریس برمی‌گرداند. */
    public static function moduleNames(): array
    {
        $fromConfig = array_keys(config('modules', []));

        return $fromConfig !== [] ? $fromConfig : self::MODULES;
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::moduleNames() as $module) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = Permission::firstOrCreate(
                    ['name' => "{$module}.{$action}", 'guard_name' => 'web']
                );
            }
        }

        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $owner->syncPermissions($permissions);

        // نقش قدیمی admin (سازگار با نصب‌های قبلی) = دسترسی کامل.
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);

        $editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        $editor->syncPermissions(
            collect($permissions)->filter(fn (Permission $p) => ! str_ends_with($p->name, '.delete'))->all()
        );

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions(
            collect($permissions)->filter(fn (Permission $p) => str_ends_with($p->name, '.view'))->all()
        );
    }
}
