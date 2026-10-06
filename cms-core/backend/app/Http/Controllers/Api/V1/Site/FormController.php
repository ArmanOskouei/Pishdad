<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H10 — endpoint عمومی فرم‌ساز: خواندن تعریف فرم با اسلاگ + ثبت پاسخ.
 * honeypot: فیلد `website` (پر باشد = موفقیت جعلی بدون ثبت) + throttle:contact.
 */
class FormController extends Controller
{
    private function findActive(string $slug): ?Form
    {
        return Form::query()->where('slug', $slug)->where('active', true)->first();
    }

    public function show(string $slug): JsonResponse
    {
        $form = $this->findActive($slug);
        if (! $form) {
            return response()->json(['message' => 'فرم یافت نشد.'], 404);
        }

        return response()->json(['data' => [
            'name' => $form->name,
            'slug' => $form->slug,
            'fields' => $form->fieldList(),
            'success_message' => $form->success_message,
        ]])->header('Cache-Control', 'public, max-age=60, s-maxage=300');
    }

    public function submit(Request $request, string $slug, FormSubmissionService $service): JsonResponse
    {
        $form = $this->findActive($slug);
        if (! $form) {
            return response()->json(['message' => 'فرم یافت نشد.'], 404);
        }

        $validated = $request->validate($form->responseRules(), [
            'data.required' => 'دادهٔ فرم الزامی است.',
            'data.array' => 'قالب دادهٔ فرم معتبر نیست.',
            'data.*.required' => 'تکمیل این فیلد الزامی است.',
            'data.*.email' => 'قالب ایمیل معتبر نیست.',
            'data.*.numeric' => 'این فیلد باید عدد باشد.',
            'data.*.boolean' => 'مقدار این فیلد معتبر نیست.',
            'data.*.in' => 'مقدار انتخابی معتبر نیست.',
            'data.*.max' => 'طول مقدار بیش از حد مجاز است.',
        ]);

        // بات honeypot را پر می‌کند: پاسخ موفق جعلی، بدون هیچ رکوردی.
        if (! empty($request->input('website'))) {
            return response()->json(['message' => $this->successMessage($form)], 201);
        }

        $service->submit($form, $validated['data'], $request->ip(), $request->userAgent());

        return response()->json(['message' => $this->successMessage($form)], 201);
    }

    private function successMessage(Form $form): string
    {
        return is_string($form->success_message) && trim($form->success_message) !== ''
            ? $form->success_message
            : 'پاسخ شما ثبت شد. سپاسگزاریم.';
    }
}
