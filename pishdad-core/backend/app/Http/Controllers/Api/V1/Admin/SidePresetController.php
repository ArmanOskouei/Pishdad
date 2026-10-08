<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SidePreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پریست‌های ستون کناری (مشترک نصب تک‌سایتی).
 * ارجاع زنده: صفحه فقط id را نگه می‌دارد؛ بلوک‌ها همیشه از روی
 * پریست خوانده می‌شوند پس ویرایش یک پریست همه صفحات را به‌روز می‌کند.
 * حذف پریستِ در حال استفاده = 422 + شمارش مصرف (بدون حذف آبشاری محتوا).
 */
class SidePresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // مشترک: همه پریست‌ها برای همه مدیران.
        $query = SidePreset::query();

        if ($side = $request->query('side')) {
            $query->where('side', $side);
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $preset = SidePreset::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'side' => $validated['side'],
            'blocks' => $validated['blocks'] ?? [],
        ]);

        return response()->json([
            'message' => 'پریست ساخته شد.',
            'data' => $preset->fresh(),
        ], 201);
    }

    public function show(Request $request, SidePreset $preset): JsonResponse
    {
        return response()->json(['data' => $preset]);
    }

    public function update(Request $request, SidePreset $preset): JsonResponse
    {
        $validated = $request->validate($this->rules(true), $this->messages());

        $preset->forceFill([
            'name' => $validated['name'] ?? $preset->name,
            'side' => $validated['side'] ?? $preset->side,
            'blocks' => $validated['blocks'] ?? $preset->blocks,
        ])->save();

        return response()->json([
            'message' => 'پریست به‌روزرسانی شد.',
            'data' => $preset->fresh(),
        ]);
    }

    public function destroy(Request $request, SidePreset $preset): JsonResponse
    {
        $usage = $preset->usageCount();
        if ($usage > 0) {
            return response()->json([
                'message' => "این پریست در {$usage} صفحه استفاده شده و قابل حذف نیست. ابتدا صفحات را از آن جدا کنید.",
                'usage_count' => $usage,
            ], 422);
        }

        $preset->delete();

        return response()->json(['message' => 'پریست حذف شد.']);
    }

    /** اعتبارسنجی بلوک‌ها در برابر رجیستری config/blocks.php (همان قرارداد صفحات). */
    private function rules(bool $sometimes = false): array
    {
        $registry = collect(config('blocks', []))->where('active', true);
        $allowedTypes = $registry->keys()->all();
        $req = $sometimes ? 'sometimes' : 'required';

        return [
            'name' => "{$req}|string|min:2|max:100",
            'side' => "{$req}|string|in:".implode(',', SidePreset::SIDES),
            'blocks' => 'sometimes|array|max:50',
            'blocks.*.type' => 'required_with:blocks|string|in:'.implode(',', $allowedTypes),
            'blocks.*.data' => 'required_with:blocks|array',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'نام پریست الزامی است.',
            'name.min' => 'نام پریست خیلی کوتاه است.',
            'side.required' => 'سمت ستون الزامی است (راست یا چپ).',
            'side.in' => 'سمت ستون نامعتبر است (فقط راست یا چپ).',
            'blocks.*.type.in' => 'نوع بلوک پشتیبانی نمی‌شود.',
        ];
    }
}
