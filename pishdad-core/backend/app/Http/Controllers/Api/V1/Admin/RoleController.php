<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * تسک ۵.۱ — CRUD نقش‌ها + تخصیص پرمیشن‌ها.
 * نقش‌های سیستمی (super-admin/owner/admin) قابل حذف نیستند؛
 * نقش super-admin از API قابل ساخت/تخصیص نیست.
 */
class RoleController extends Controller
{
    private const SYSTEM_ROLES = ['super-admin', 'owner', 'admin'];

    public function index(): JsonResponse
    {
        $roles = Role::query()->where('guard_name', 'web')
            ->where('name', '!=', 'super-admin')
            ->with('permissions')
            ->get()
            ->map(fn (Role $r) => $this->payload($r));

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'name')],
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
            'requires_2fa' => 'sometimes|boolean',
        ], [
            'name.required' => 'نام نقش الزامی است.',
            'name.regex' => 'نام نقش فقط حروف کوچک انگلیسی، عدد و خط تیره.',
            'name.unique' => 'این نقش قبلاً وجود دارد.',
            'permissions.*.exists' => 'یکی از دسترسی‌ها معتبر نیست.',
            'requires_2fa.boolean' => 'مقدار «۲FA اجباری» باید بله یا خیر باشد.',
        ]);

        if ($validated['name'] === 'super-admin') {
            return response()->json(['message' => 'این نقش سیستمی است و قابل ساخت نیست.'], 422);
        }

        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'requires_2fa' => (bool) ($validated['requires_2fa'] ?? false),
        ]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return response()->json([
            'message' => 'نقش ساخته شد.',
            'data' => $this->payload($role->fresh()->load('permissions')),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        if ($role->name === 'super-admin') {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        return response()->json(['data' => $this->payload($role->load('permissions'))]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'super-admin') {
            return response()->json(['message' => 'دسترسی مجاز نیست.'], 403);
        }

        $validated = $request->validate([
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
            'requires_2fa' => 'sometimes|boolean',
        ], [
            'permissions.*.exists' => 'یکی از دسترسی‌ها معتبر نیست.',
            'requires_2fa.boolean' => 'مقدار «۲FA اجباری» باید بله یا خیر باشد.',
        ]);

        if (array_key_exists('requires_2fa', $validated)) {
            $role->forceFill(['requires_2fa' => (bool) $validated['requires_2fa']])->save();
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'message' => 'نقش به‌روزرسانی شد.',
            'data' => $this->payload($role->fresh()->load('permissions')),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return response()->json(['message' => 'نقش سیستمی قابل حذف نیست.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'این نقش به مدیرانی تخصیص یافته و قابل حذف نیست.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'نقش حذف شد.']);
    }

    private function payload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'system' => in_array($role->name, self::SYSTEM_ROLES, true),
            'permissions' => $role->permissions->pluck('name')->all(),
            'managers_count' => $role->users()->count(),
            'requires_2fa' => (bool) $role->requires_2fa,
        ];
    }
}
