<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WF-H10 — مدیریت فرم‌ساز (فرم‌های دلخواه + پاسخ‌ها).
 * خواندن `perm:forms.view`، نوشتن `perm:forms.edit`، حذف `perm:forms.delete`.
 */
class FormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Form::query()->withCount('submissions');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('slug', 'ilike', "%{$search}%"));
        }

        return response()->json($query->latest()->paginate((int) $request->query('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(null), $this->messages());
        $this->assertFields($validated['fields']);

        // اسلاگ نام فارسی خالی درمی‌آید؛ در آن حالت یک اسلاگ تصادفی امن می‌سازیم.
        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        if ($slug === '') {
            $slug = 'form-'.Str::lower(Str::random(6));
        }

        $form = Form::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'fields' => $this->normalizeFields($validated['fields']),
            'destination' => $validated['destination'] ?? Form::DEST_TICKET,
            'recipients' => $validated['recipients'] ?? null,
            'success_message' => $validated['success_message'] ?? null,
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json([
            'message' => 'فرم ساخته شد.',
            'data' => $form->loadCount('submissions'),
        ], 201);
    }

    public function show(Form $form): JsonResponse
    {
        return response()->json(['data' => $form->loadCount('submissions')]);
    }

    public function update(Request $request, Form $form): JsonResponse
    {
        $validated = $request->validate($this->rules($form->id), $this->messages());
        $this->assertFields($validated['fields']);

        $form->forceFill([
            'name' => $validated['name'] ?? $form->name,
            'slug' => $validated['slug'] ?? $form->slug,
            'fields' => $this->normalizeFields($validated['fields']),
            'destination' => $validated['destination'] ?? $form->destination,
            'recipients' => array_key_exists('recipients', $validated) ? $validated['recipients'] : $form->recipients,
            'success_message' => array_key_exists('success_message', $validated) ? $validated['success_message'] : $form->success_message,
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : $form->active,
        ])->save();

        return response()->json([
            'message' => 'فرم ذخیره شد.',
            'data' => $form->fresh()->loadCount('submissions'),
        ]);
    }

    public function destroy(Form $form): JsonResponse
    {
        $form->delete();

        return response()->json(['message' => 'فرم حذف شد.']);
    }

    /** JSON Schema تنظیمات فرم — منبع حقیقت برای SchemaForm پنل. */
    public function schema(): JsonResponse
    {
        return response()->json(['data' => [
            'type' => 'object',
            'required' => ['name', 'slug', 'destination'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 120],
                'slug' => ['type' => 'string', 'maxLength' => 120],
                'destination' => ['type' => 'string', 'enum' => [Form::DEST_TICKET, Form::DEST_EMAIL], 'default' => Form::DEST_TICKET],
                'recipients' => ['type' => 'string', 'maxLength' => 500],
                'success_message' => ['type' => 'string', 'maxLength' => 300],
                'active' => ['type' => 'boolean', 'default' => true],
            ],
        ]]);
    }

    public function submissions(Request $request, Form $form): JsonResponse
    {
        return response()->json(
            $form->submissions()->paginate((int) $request->query('per_page', 20)),
        );
    }

    /** خروجی CSV پاسخ‌ها (UTF-8 BOM برای اکسل فارسی). */
    public function exportCsv(Form $form): StreamedResponse
    {
        $filename = 'form-'.$form->slug.'-submissions-'.now()->format('Ymd-His').'.csv';
        $keys = $form->fieldKeys();
        $labels = collect($form->fieldList())
            ->mapWithKeys(fn ($f) => [($f['key'] ?? '') => ($f['label'] ?? ($f['key'] ?? ''))])
            ->all();

        return response()->streamDownload(function () use ($form, $keys, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_merge(['id', 'created_at', 'ip'], array_map(fn ($k) => $labels[$k] ?? $k, $keys)));

            $form->submissions()->orderBy('id')->chunk(500, function ($rows) use ($out, $keys) {
                foreach ($rows as $row) {
                    $payload = is_array($row->payload) ? $row->payload : [];
                    $line = [$row->id, $row->created_at?->toIso8601String(), $row->ip];
                    foreach ($keys as $key) {
                        $value = $payload[$key] ?? '';
                        if (is_array($value)) {
                            $value = implode(' | ', array_map('strval', $value));
                        } elseif (is_bool($value)) {
                            $value = $value ? '1' : '0';
                        }
                        $line[] = $this->csvCell((string) $value);
                    }
                    fputcsv($out, $line);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** محافظت از CSV injection: سلول‌های آغازشده با = + - @ با ' بی‌خطر می‌شوند. */
    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    private function rules(?int $ignoreId): array
    {
        $registry = implode(',', Form::TYPES);

        return [
            'name' => ($ignoreId ? 'sometimes' : 'required').'|string|max:120|no_markup',
            'slug' => [
                'sometimes', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('forms', 'slug')->ignore($ignoreId),
            ],
            'fields' => 'required|array|min:1|max:40',
            'fields.*.key' => ['required', 'string', 'max:60', 'not_in:'.implode(',', Form::RESERVED_KEYS), 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => 'required|string|max:120|no_markup',
            'fields.*.type' => 'required|string|in:'.$registry,
            'fields.*.required' => 'sometimes|boolean',
            'fields.*.placeholder' => 'sometimes|nullable|string|max:200',
            'fields.*.options' => 'sometimes|array|max:50',
            'fields.*.options.*' => 'string|max:120',
            'fields.*.max_length' => 'sometimes|nullable|integer|min:1|max:20000',
            'destination' => 'sometimes|string|in:'.Form::DEST_TICKET.','.Form::DEST_EMAIL,
            'recipients' => 'sometimes|nullable|array|max:20',
            'recipients.*' => 'email:rfc|max:200',
            'success_message' => 'sometimes|nullable|string|max:300|no_markup',
            'active' => 'sometimes|boolean',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'نام فرم الزامی است.',
            'slug.regex' => 'اسلاگ فقط می‌تواند حروف کوچک، عدد، خط تیره و زیرخط داشته باشد.',
            'slug.unique' => 'این اسلاگ قبلاً استفاده شده است.',
            'fields.required' => 'حداقل یک فیلد لازم است.',
            'fields.*.key.required' => 'کلید فیلد الزامی است.',
            'fields.*.key.regex' => 'کلید فیلد باید با حرف کوچک شروع شود و فقط شامل حروف کوچک، عدد و زیرخط باشد.',
            'fields.*.key.not_in' => 'این کلید رزرو شده است.',
            'fields.*.label.required' => 'برچسب فیلد الزامی است.',
            'fields.*.type.in' => 'نوع فیلد پشتیبانی نمی‌شود.',
            'recipients.*.email' => 'ایمیل گیرنده معتبر نیست.',
        ];
    }

    /**
     * اعتبارسنجی سطح-آرایه: یکتا بودن کلیدها، وجود گزینه برای select، و
     * الزام گیرنده در مقصد email.
     */
    private function assertFields(array $fields): void
    {
        $keys = [];
        foreach ($fields as $i => $field) {
            $key = $field['key'] ?? null;
            if (is_string($key)) {
                if (in_array($key, $keys, true)) {
                    throw ValidationException::withMessages(['fields' => "کلید «{$key}» تکراری است."]);
                }
                $keys[] = $key;
            }
            if (($field['type'] ?? null) === 'select') {
                $options = array_filter((array) ($field['options'] ?? []), fn ($o) => is_string($o) && $o !== '');
                if ($options === []) {
                    throw ValidationException::withMessages(["fields.{$i}.options" => 'فیلد انتخابی باید حداقل یک گزینه داشته باشد.']);
                }
            }
        }
    }

    /** فقط کلیدهای شناخته‌شده را نگه می‌دارد تا JSON ذخیره‌شده تمیز بماند. */
    private function normalizeFields(array $fields): array
    {
        return array_map(function (array $field) {
            $out = [
                'key' => (string) $field['key'],
                'label' => (string) $field['label'],
                'type' => (string) $field['type'],
                'required' => (bool) ($field['required'] ?? false),
            ];
            if (! empty($field['placeholder'])) {
                $out['placeholder'] = (string) $field['placeholder'];
            }
            if ($out['type'] === 'select') {
                $out['options'] = array_values(array_filter(
                    array_map(fn ($o) => is_string($o) ? $o : null, (array) ($field['options'] ?? [])),
                ));
            }
            if (isset($field['max_length']) && is_numeric($field['max_length'])) {
                $out['max_length'] = (int) $field['max_length'];
            }

            return $out;
        }, $fields);
    }
}
