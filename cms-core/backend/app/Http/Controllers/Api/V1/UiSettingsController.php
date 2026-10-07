<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * DEV-TASKS 0.2 / 2.4 — ظاهر پنل مدیریت (settings group `ui`).
 *
 * قالب سراسری نصب است (نه per-user): انتخاب هر مدیر برای همه مدیران اعمال می‌شود.
 * Value enums mirror docs/DESIGN-DIRECTIONS.md:
 * - preset: sahar | amaliyat | arya | narm | divan (5 visual directions)
 * - mode:   light | dark | system
 * - radius: sharp | default | rounded
 * - density: compact | comfortable | loose
 * - font:   sm | md | lg (Vazirmatn scale)
 * - dir:    rtl | ltr
 */
class UiSettingsController extends Controller
{
    public const BOTTOM_NAV_ALLOWED = [
        '/admin/dashboard',
        '/admin/pages',
        '/admin/media',
        '/admin/tickets',
        '/admin/header-footer',
        '/admin/settings',
        '/admin/managers',
        '/admin/profile',
        '/admin/blocks',
        '/admin/themes',
        '/admin/appearance',
        '/admin/plugins',
        '/admin/socials',
    ];

    public const BOTTOM_NAV_DEFAULTS = [
        '/admin/dashboard',
        '/admin/pages',
        '/admin/media',
        '/admin/tickets',
        '/admin/header-footer',
        '/admin/settings',
    ];

    public const DEFAULTS = [
        'dir' => 'rtl',
        'preset' => 'amaliyat', // dark ops console is the shipped default
        'accent' => 'indigo',   // رنگِ درونِ جهت (قابل انتخاب؛ باید ذخیره شود وگرنه با رفرش برمی‌گردد)
        'mode' => 'dark',
        'radius' => 'sharp',
        'density' => 'compact',
        'font' => 'md',
        'bottom_nav' => self::BOTTOM_NAV_DEFAULTS,
    ];

    public const RULES = [
        'dir' => 'in:rtl,ltr',
        'preset' => 'in:sahar,amaliyat,arya,narm,divan',
        'accent' => 'in:teal,indigo,violet,blue,amber,rose,clay',
        'mode' => 'in:light,dark,system',
        'radius' => 'in:sharp,default,rounded',
        'density' => 'in:compact,comfortable,loose',
        'font' => 'in:sm,md,lg',
        'bottom_nav' => ['sometimes', 'array', 'max:6'],
    ];

    public function show(Request $request): JsonResponse
    {
        $settings = array_merge(
            self::DEFAULTS,
            Setting::get('ui', $this->key(), []) ?? []
        );
        $settings['bottom_nav'] = $this->sanitizeBottomNav($settings['bottom_nav'] ?? null);

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = self::RULES;
        $rules['bottom_nav.*'] = ['string', 'distinct', Rule::in(self::BOTTOM_NAV_ALLOWED)];
        $validated = $request->validate($rules, $this->messages());

        $settings = array_merge(
            self::DEFAULTS,
            Setting::get('ui', $this->key(), []) ?? [],
            $validated
        );
        $settings['bottom_nav'] = $this->sanitizeBottomNav($settings['bottom_nav'] ?? null);

        Setting::set('ui', $this->key(), $settings);

        return response()->json([
            'message' => 'تنظیمات ظاهر ذخیره شد.',
            'data' => $settings,
        ]);
    }

    private function key(): string
    {
        // کلید سراسری نصب — عمداً بدون user_id تا همه مدیران یک قالب مشترک داشته باشند.
        return 'global';
    }

    private function messages(): array
    {
        return [
            'dir.in' => 'مقدار dir معتبر نیست.',
            'preset.in' => 'قالب بصری انتخاب‌شده معتبر نیست.',
            'accent.in' => 'رنگ انتخاب‌شده معتبر نیست.',
            'mode.in' => 'حالت نمایش معتبر نیست.',
            'radius.in' => 'مقدار شعاع گوشه معتبر نیست.',
            'density.in' => 'مقدار تراکم معتبر نیست.',
            'font.in' => 'مقدار اندازه فونت معتبر نیست.',
            'bottom_nav.array' => 'فهرست نوار پایین باید آرایه باشد.',
            'bottom_nav.max' => 'نوار پایین حداکثر ۶ آیتم دارد.',
            'bottom_nav.*.string' => 'هر مسیر نوار پایین باید متن باشد.',
            'bottom_nav.*.distinct' => 'مسیرهای نوار پایین نباید تکراری باشند.',
            'bottom_nav.*.in' => 'مسیر انتخاب‌شده برای نوار پایین مجاز نیست.',
        ];
    }

    private function sanitizeBottomNav(mixed $value): array
    {
        if ($value === null) {
            return self::BOTTOM_NAV_DEFAULTS;
        }

        if (! is_array($value)) {
            return self::BOTTOM_NAV_DEFAULTS;
        }

        $paths = [];
        foreach ($value as $path) {
            if (is_string($path) && in_array($path, self::BOTTOM_NAV_ALLOWED, true) && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return array_slice($paths, 0, 6);
    }
}
