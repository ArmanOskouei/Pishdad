<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\Plan;

/** CRUD پلن‌ها (تسک ۱.۳) — فقط operator. */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $plan = Plan::query()->create($validated);

        return response()->json(['message' => 'پلن ساخته شد.', 'data' => $plan], 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json(['data' => $plan]);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate($this->rules(true), $this->messages());

        $plan->fill($validated)->save();

        return response()->json(['message' => 'پلن به‌روزرسانی شد.', 'data' => $plan->fresh()]);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            return response()->json(['message' => 'این پلن اشتراک فعال دارد و قابل حذف نیست.'], 422);
        }

        $plan->delete();

        return response()->json(['message' => 'پلن حذف شد.']);
    }

    private function rules(bool $sometimes = false): array
    {
        $req = $sometimes ? 'sometimes' : 'required';

        return [
            'name' => "{$req}|string|max:100",
            'slug' => "{$req}|string|max:100|unique:central_plans,slug",
            'tier' => "{$req}|string|in:budget,standard,enterprise",
            'price_monthly' => 'sometimes|integer|min:0',
            'price_yearly' => 'sometimes|integer|min:0',
            'currency' => 'sometimes|string|max:10',
            'features' => 'sometimes|array',
            'active' => 'sometimes|boolean',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'نام پلن الزامی است.',
            'slug.unique' => 'این شناسه پلن تکراری است.',
            'tier.in' => 'تیر پلن معتبر نیست.',
        ];
    }
}
