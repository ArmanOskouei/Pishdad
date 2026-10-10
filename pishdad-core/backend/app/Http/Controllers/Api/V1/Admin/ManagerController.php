<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * تسک ۵.۱ — CRUD مدیران (غیر سوپرادمین) + نقش‌ها/پرمیشن‌های spatie.
 *
 * امنیت:
 * - سوپرادمین همیشه مخفی: اسکوپ visible در لیست + 403 روی هر عملیات تکی.
 * - نقش super-admin از API قابل تخصیص/ساخت نیست.
 * - حذف خود + حذف سوپرادمین ممنوع.
 */
class ManagerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $managers = User::query()->with('roles')->latest()->paginate(
            \App\Support\Pagination::perPage($request, 20)
        );

        $managers->getCollection()->transform(fn (User $u) => $this->payload($u));

        return response()->json(['data' => $managers]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:200|unique:users,email',
            'password' => [
                'required', 'string', 'min:10',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
            'role' => ['sometimes', 'string', Rule::in(['owner', 'admin', 'editor', 'viewer'])],
        ], [
            'name.required' => 'نام مدیر الزامی است.',
            'email.required' => 'ایمیل الزامی است.',
            'email.email' => 'قالب ایمیل معتبر نیست.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min' => 'رمز عبور باید حداقل ۱۰ نویسه باشد.',
            'password.regex' => 'رمز عبور باید شامل حروف بزرگ و کوچک، عدد و نویسه ویژه باشد.',
            'role.in' => 'نقش معتبر نیست.',
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
        ]);
        $this->denyUnlessOwner($request, new User(), $validated['role'] ?? 'viewer');
        $user->assignRole($validated['role'] ?? 'viewer');

        return response()->json([
            'message' => 'مدیر ساخته شد.',
            'data' => $this->payload($user->fresh()),
        ], 201);
    }

    public function show(Request $request, User $manager): JsonResponse
    {
        return response()->json(['data' => $this->payload($manager->load('roles'))]);
    }

    public function update(Request $request, User $manager): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'email' => ['sometimes', 'email', 'max:200', Rule::unique('users', 'email')->ignore($manager->id)],
            'role' => ['sometimes', 'string', Rule::in(['owner', 'admin', 'editor', 'viewer'])],
        ], [
            'email.email' => 'قالب ایمیل معتبر نیست.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'role.in' => 'نقش معتبر نیست.',
        ]);

        $this->denyUnlessOwner($request, $manager, $validated['role'] ?? null);

        if (array_key_exists('role', $validated)) {
            $manager->syncRoles([$validated['role']]);
            unset($validated['role']);
        }
        $manager->fill($validated)->save();

        return response()->json([
            'message' => 'مدیر به‌روزرسانی شد.',
            'data' => $this->payload($manager->fresh()->load('roles')),
        ]);
    }

    public function destroy(Request $request, User $manager): JsonResponse
    {
        if ((int) $manager->id === (int) $request->user()->id) {
            return response()->json(['message' => 'نمی‌توانید حساب خودتان را حذف کنید.'], 422);
        }

        $this->denyUnlessOwner($request, $manager, null);

        $manager->delete();

        return response()->json(['message' => 'مدیر حذف شد.']);
    }

    /**
     * S-13 — پیش از این، `perm:users.edit` برای ساخت/ویرایش/حذفِ یک *دیگر*
     * owner کافی بود؛ یعنی یک مدیرِ سفارشی می‌توانست خودش را (یا هر کسی را)
     * به owner ارتقا بدهد و از قیدِ سطح بیرون بیاید. حالا هر تغییری که هدفش
     * owner است (یا هدف از قبل owner است) فقط از سوی خودِ owner ممکن است.
     */
    private function denyUnlessOwner(Request $request, User $target, ?string $newRole): void
    {
        $touchesOwner = $target->hasRole('owner') || $newRole === 'owner';
        if ($touchesOwner && ! $request->user()->hasRole('owner')) {
            abort(403, 'تغییر نقش‌های owner فقط توسط خود owner امکان‌پذیر است.');
        }
    }

    /**
     * ماتریس دسترسی ماژول × اکشن (برای پنل چک‌باکس نقش‌ها).
     * داینامیک از رجیستری: هسته (config/modules.php) + ماژول‌های اعلام‌شده
     * در مانیفست پلاگین‌های فعال کاربر — هر ماژول title_fa فارسی دارد.
     */
    public function permissionsMatrix(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'modules' => ManifestRegistry::permissionModules(),
                'actions' => collect(ManifestRegistry::ACTIONS)->map(fn (string $a) => [
                    'name' => $a,
                    'title_fa' => ManifestRegistry::ACTION_FA[$a] ?? $a,
                ])->values()->all(),
            ],
        ]);
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            // دسته UIUX (افزودنی): آواتار per-row در تب مدیران.
            'avatar_media_id' => $user->avatar_media_id,
            'avatar_url' => $this->avatarUrl($user->avatar_media_id),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /** نشانی نمایشی آواتار مدیر (حل path با AWS_URL؛ ناموجود → null). */
    private function avatarUrl(mixed $mediaId): ?string
    {
        if (! is_numeric($mediaId) || (int) $mediaId <= 0) {
            return null;
        }
        $path = Media::query()->where('id', (int) $mediaId)->value('path');
        if (! $path) {
            return null;
        }
        $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

        return $base === '' ? null : $base.'/'.ltrim($path, '/');
    }
}
