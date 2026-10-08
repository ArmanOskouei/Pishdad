<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WF-C2 — CRUD ریدایرکت‌های سایت. زیر `perm:settings.edit`.
 */
class RedirectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Redirect::query()->orderByDesc('id');

        if ($search = $request->query('search')) {
            // E85 — فارسی‌دوست (مسیرها معمولاً لاتین‌اند؛ الگو بی‌ضرر است).
            \App\Search\PersianText::whereFa($query, (string) $search, ['from_path', 'to_path']);
        }

        if ($request->query('active') !== null && $request->query('active') !== '') {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->paginate((int) $request->query('per_page', 30)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeInput($request);
        $validated = $request->validate($this->rules(), $this->messages());

        $redirect = Redirect::query()->create([
            'from_path' => $validated['from_path'],
            'to_path' => trim($validated['to_path']),
            'status_code' => (int) ($validated['status_code'] ?? Redirect::STATUS_MOVED_PERMANENTLY),
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json(['message' => 'ریدایرکت ثبت شد.', 'data' => $redirect], 201);
    }

    public function update(Request $request, Redirect $redirect): JsonResponse
    {
        $this->normalizeInput($request);
        $validated = $request->validate($this->rules($redirect->id), $this->messages());

        if (array_key_exists('to_path', $validated)) {
            $validated['to_path'] = trim($validated['to_path']);
        }

        $redirect->fill($validated)->save();

        return response()->json(['message' => 'ریدایرکت به‌روز شد.', 'data' => $redirect->fresh()]);
    }

    public function destroy(Redirect $redirect): JsonResponse
    {
        $redirect->delete();

        return response()->json(['message' => 'ریدایرکت حذف شد.']);
    }

    /** ورودی را پیش از اعتبارسنجی یکنواخت می‌کند تا «old» هم بپذیرد. */
    private function normalizeInput(Request $request): void
    {
        if ($request->filled('from_path')) {
            $request->merge(['from_path' => Redirect::normalizePath((string) $request->input('from_path'))]);
        }
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'from_path' => [
                $ignoreId ? 'sometimes' : 'required',
                'string', 'max:500', 'no_markup', 'regex:/^\//',
                Rule::unique('redirects', 'from_path')->ignore($ignoreId),
            ],
            'to_path' => [($ignoreId ? 'sometimes' : 'required'), 'string', 'max:1000', 'no_markup'],
            'status_code' => 'sometimes|integer|in:301,302',
            'active' => 'sometimes|boolean',
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'from_path.required' => 'مسیر مبدأ الزامی است.',
            'from_path.regex' => 'مسیر مبدأ باید با «/» شروع شود.',
            'from_path.unique' => 'برای این مسیر قبلاً ریدایرکت ثبت شده است.',
            'to_path.required' => 'مسیر مقصد الزامی است.',
            'status_code.in' => 'کد وضعیت فقط ۳۰۱ یا ۳۰۲ می‌تواند باشد.',
        ];
    }
}
