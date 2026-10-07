<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Setting;
use App\Services\Layouts\LinkItems;
use App\Services\Plugins\ManifestRegistry;
use App\Services\RevalidateDispatcher;
use App\Services\Settings\CachedSettings;
use App\Validation\SafeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسک ۴.۱ + تسک ۷ — هدر/فوتر + بلوک‌های پیش‌فرض انواع صفحه (مشترک نصب).
 * اعتبارسنجی type ویجت against رجیستری config/widgets.php و
 * type بلوک against رجیستری config/blocks.php. خواندن کش‌دار + ابطال هنگام ذخیره.
 * انواع صفحه از ManifestRegistry می‌آید: هسته + page_types اعلام‌شده در
 * مانیفست پلاگین‌های فعال؛ فقط typeهای enabled !== false (نه هاردکد).
 */
class LayoutController extends Controller
{
    public const AREAS = ['header', 'footer'];

    public function show(Request $request, string $area): JsonResponse
    {
        if (! in_array($area, self::AREAS, true)) {
            return response()->json(['message' => 'ناحیه چیدمان نامعتبر است.'], 404);
        }

        $data = CachedSettings::remember('layout', $this->key($area), 300, function () use ($area) {
            return array_merge(
                $this->defaults($area),
                Setting::get('layout', $this->key($area), []) ?? []
            );
        });

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $area, RevalidateDispatcher $dispatcher): JsonResponse
    {
        if (! in_array($area, self::AREAS, true)) {
            return response()->json(['message' => 'ناحیه چیدمان نامعتبر است.'], 404);
        }

        // typeهای مجاز = رجیستری هسته + ویجت‌های اعلام‌شده در مانیفست
        // پلاگین/قالب فعال کاربر (تسک ۶) — همان منبعی که widgets/schema می‌دهد.
        $core = array_keys(config("widgets.{$area}", []));
        $extra = collect(ManifestRegistry::widgetSchemas())
            ->where('area', $area)->pluck('type')->all();
        $allowed = implode(',', array_unique(array_merge($core, $extra)));

        $validated = $request->validate([
            'widgets' => 'required|array|max:30',
            'widgets.*.type' => "required|string|in:{$allowed}",
            'widgets.*.settings' => 'nullable|array',
            // E66 — ناحیهٔ ویجتِ فوتر (بالاتر از ستون‌ها / پایین‌تر). `grid`
            // مقدارِ پیش‌فرض است و صریح هم پذیرفته می‌شود.
            'widgets.*.place' => 'nullable|string|in:above,grid,below',
            'layout' => 'nullable|array',
        ], [
            'widgets.required' => 'لیست ویجت‌ها الزامی است.',
            'widgets.*.type.required' => 'نوع ویجت الزامی است.',
            'widgets.*.type.in' => "نوع ویجت برای ناحیه {$area} مجاز نیست.",
        ]);

        // اعتبارسنجی معنایی آیتم‌های لینک (page/custom) + ستون فوتر — پیام فارسی.
        foreach (array_values($validated['widgets']) as $i => $w) {
            $links = $w['settings']['links'] ?? null;
            if ($area === 'header' && $w['type'] === 'nav' && $links !== null) {
                LinkItems::validate("widgets.{$i}.settings.links", $links, 'منوی ناوبری');
            }
            if ($area === 'footer' && $w['type'] === 'links' && $links !== null) {
                LinkItems::validate("widgets.{$i}.settings.links", $links, 'ستون لینک‌ها');
            }
        }

        // F0.2 — اعتبارسنجی href برای *هر* ویجتی که چنین فیلدی در schema دارد.
        //
        // چرا از روی schema و نه `if ($type === 'cta')`: ریشهٔ XSS ویجت CTA این
        // بود که حلقهٔ بالا فقط `nav` و `links` را می‌دانست و هر نوع تازه برای
        // اضافه‌شدن به آن لیست، ویرایش دستی می‌خواست. یک بار که فیلد `href` در
        // `config/widgets.php` اعلام شود، همهٔ ویجت‌ها — **و ویجت‌هایی که پلاگین
        // یا قالب فعال از طریق `ManifestRegistry::widgetSchemas()` اعلام می‌کند** —
        // خودکار پوشش داده می‌شوند. رجیستری، تنها منبع حقیقت می‌ماند.
        $this->validateWidgetHrefs($validated['widgets'], $this->widgetSchemaIndex($area));

        $columns = $validated['layout']['columns'] ?? null;
        if ($columns !== null && (! is_numeric($columns) || (int) $columns < 1 || (int) $columns > 4)) {
            throw ValidationException::withMessages(['layout.columns' => 'تعداد ستون‌ها باید بین ۱ تا ۴ باشد.']);
        }

        $data = [
            'widgets' => collect($validated['widgets'])->map(function (array $w) use ($area): array {
                $widget = [
                    'type' => $w['type'],
                    // ویجت logo ساده‌سازی شد: لوگو همیشه از تنظیمات سایت + سایز با
                    // قالب است؛ کلیدهای قدیمی media_id/size/media_url ذخیره‌شده
                    // سازگار عقب‌رو نادیده گرفته می‌شوند (نه خطا).
                    'settings' => $area === 'header' && $w['type'] === 'logo'
                        ? self::sanitizeLogoSettings($w['settings'] ?? [])
                        : ($w['settings'] ?? []),
                ];

                // E66 — ناحیهٔ تمام‌عرضِ فوتر. `grid` صریح ذخیره **نمی‌شود** تا
                // دادهٔ ویجت‌های داخلِ ستون‌ها عیناً همان شکلِ قبلی بماند.
                // برای هدر بی‌معناست، پس هرگز ذخیره نمی‌شود.
                $place = $w['place'] ?? null;
                if ($area === 'footer' && in_array($place, ['above', 'below'], true)) {
                    $widget['place'] = $place;
                }

                return $widget;
            })->values()->all(),
            'layout' => $validated['layout'] ?? [],
        ];

        Setting::set('layout', $this->key($area), $data);
        CachedSettings::forget('layout', $this->key($area));

        // هدر/فوتر در همه صفحات عمومی رندر می‌شود — ابطال فوری (fire-and-forget).
        $dispatcher->dispatch(['site-chrome', 'pages']);

        return response()->json([
            'message' => $area === 'header' ? 'چیدمان هدر ذخیره شد.' : 'چیدمان فوتر ذخیره شد.',
            'data' => $data,
        ]);
    }

    /**
     * F0.2 — ایندکس «کدام ویجت چه فیلدهایی دارد» از رجیستری.
     *
     * خروجی: [type => ['href', 'url', ...]] — فقط فیلدهایی که در
     * `schema.properties` اعلام شده‌اند. هسته از `config/widgets.php` و
     * ویجت‌های پلاگین/قالب از `ManifestRegistry::widgetSchemas()` می‌آید، پس
     * یک منبع حقیقت واحد می‌ماند و افزونه هم رایگان پوشش داده می‌شود.
     *
     * @return array<string, list<string>>
     */
    private function widgetSchemaIndex(string $area): array
    {
        $index = [];

        foreach ((array) config("widgets.{$area}", []) as $type => $def) {
            $index[(string) $type] = $this->urlFieldsOf((array) ($def['schema']['properties'] ?? []));
        }

        foreach (ManifestRegistry::widgetSchemas() as $widget) {
            if (($widget['area'] ?? null) !== $area) {
                continue;
            }
            $type = (string) ($widget['type'] ?? '');
            if ($type === '' || isset($index[$type])) {
                continue;
            }
            $index[$type] = $this->urlFieldsOf((array) ($widget['schema']['properties'] ?? []));
        }

        return $index;
    }

    /**
     * کدام کلیدهای schema واقعاً نشانی‌اند.
     *
     * فقط نام فیلد ملاک است، نه `type` — چون اعتبارسنجی باید با نام شروع شود
     * (`href`، `url`، `link`، `target_url`) و الگوی allowlist برای `src`
     * سخت‌گیرانه‌تر است. سقف عمق ۲ در آیتم‌های تودرتوی `links` را
     * `LinkItems::validate` پوشش می‌دهد؛ اینجا فقط سطح تنظیمات ویجت است.
     *
     * @return list<string>
     */
    private function urlFieldsOf(array $properties): array
    {
        $out = [];
        foreach (array_keys($properties) as $name) {
            $name = (string) $name;
            if (preg_match('/^(href|url|link|target_url|to)$/i', $name) === 1) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * F0.2 — اجرای allowlist روی هر فیلد URL که رجیستری برای آن ویجت اعلام کرده.
     *
     * نکتهٔ مهم: فیلدهایی که *اعلام نشده‌اند* همچنان ذخیره می‌شوند (سازگاری
     * عقب‌رو)، ولی در رندر `safeHref` می‌گیرند — یعنی لایهٔ فرانت سوراخ را
     * نمی‌بندد. این کلاس فقط کاری را می‌کند که رجیستری *قول* داده بود.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @param  array<string, list<string>>  $schemaIndex
     */
    private function validateWidgetHrefs(array $widgets, array $schemaIndex): void
    {
        foreach (array_values($widgets) as $i => $widget) {
            $fields = $schemaIndex[(string) ($widget['type'] ?? '')] ?? [];
            $settings = is_array($widget['settings'] ?? null) ? $widget['settings'] : [];

            foreach ($fields as $field) {
                if (! array_key_exists($field, $settings)) {
                    continue;
                }
                SafeUrl::assertHref(
                    "widgets.{$i}.settings.{$field}",
                    $settings[$field],
                    'نشانی «'.$field.'»'
                );
            }
        }
    }

    /** انواع صفحه + بلوک جاری هر نوع (پیش‌فرض کانفیگ یا override ذخیره‌شده). */
    public function pageTypes(): JsonResponse
    {
        $types = ManifestRegistry::pageTypes();

        $data = collect($types)->map(function (array $def, string $type) {
            $override = Setting::get('page_blocks', $this->key($type));

            return [
                'type' => $type,
                'title' => $def['title'] ?? $type,
                'description' => $def['description'] ?? '',
                'enabled' => true,
                'source' => $def['source'] ?? 'core',
                'blocks' => $override ?? $def['default_blocks'] ?? [],
                'customized' => $override !== null,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function showBlocks(Request $request, string $type): JsonResponse
    {
        $def = ManifestRegistry::pageTypes()[$type] ?? null;

        if (! $def) {
            return response()->json(['message' => 'نوع صفحه نامعتبر است.'], 404);
        }

        $blocks = Setting::get('page_blocks', $this->key($type))
            ?? $def['default_blocks'] ?? [];

        return response()->json(['data' => ['type' => $type, 'blocks' => $blocks]]);
    }

    public function updateBlocks(Request $request, string $type, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $definition = ManifestRegistry::pageTypes()[$type] ?? null;
        if (! $definition) {
            return response()->json(['message' => 'نوع صفحه نامعتبر است.'], 404);
        }

        $oldBlocks = Setting::get('page_blocks', $this->key($type))
            ?? $definition['default_blocks'] ?? [];
        $allowed = implode(',', array_keys(config('blocks', [])));

        $validated = $request->validate([
            'blocks' => 'required|array|max:50',
            'blocks.*.type' => "required|string|in:{$allowed}",
            'blocks.*.data' => 'required|array',
        ], [
            'blocks.required' => 'لیست بلوک‌ها الزامی است.',
            'blocks.*.type.required' => 'نوع بلوک الزامی است.',
            'blocks.*.type.in' => 'نوع بلوک مجاز نیست.',
            'blocks.*.data.required' => 'داده بلوک الزامی است.',
        ]);

        $blocks = collect($validated['blocks'])->map(fn (array $b) => [
            'type' => $b['type'],
            'data' => $b['data'],
        ])->values()->all();

        $appliedPages = DB::transaction(function () use ($type, $blocks, $oldBlocks): array {
            Setting::set('page_blocks', $this->key($type), $blocks);
            $applied = [];

            // در نصب تک‌سایتی، بلوک‌های پیش‌فرض نوع صفحه باید روی همه صفحات
            // آن نوع اعمال شود — مگر صفحه‌ای که صریحاً «override» شده باشد.
            foreach ($this->pagesForType($type) as $page) {
                if (! $this->pageUsesDefaultBlocks($page, $oldBlocks)) {
                    continue;
                }

                $revision = $page->snapshot(
                    $blocks,
                    $page->meta,
                    null,
                    'همگام‌سازی با پیش‌فرض نوع صفحه '.$type,
                );

                if ($page->isPublished()) {
                    $page->forceFill([
                        'published_revision_id' => $revision->id,
                        'published_at' => now(),
                    ])->save();
                }

                $applied[] = ['id' => $page->id, 'slug' => $page->slug];
            }

            return $applied;
        });

        CachedSettings::forget('page_blocks', $this->key($type));

        $tags = ['pages'];
        if ($type === 'home') {
            $tags[] = 'site-homepage';
            $tags[] = 'page:home';
        }
        foreach ($appliedPages as $page) {
            $tags[] = 'page:'.$page['slug'];
        }
        $dispatcher->dispatch(array_values(array_unique($tags)));

        return response()->json([
            'message' => 'بلوک‌های نوع صفحه ذخیره شد.',
            'data' => [
                'type' => $type,
                'blocks' => $blocks,
                'applied_pages' => $appliedPages,
            ],
        ]);
    }

    private function pagesForType(string $type)
    {
        return Page::query()
            ->with('publishedRevision')
            ->get()
            ->filter(fn (Page $page) => $this->pageTypeFor($page) === $type)
            ->values();
    }

    private function pageTypeFor(Page $page): string
    {
        $types = ManifestRegistry::pageTypes();
        $meta = $page->meta ?? [];
        $declared = $meta['page_type'] ?? $meta['_page_type'] ?? null;

        if (is_string($declared) && isset($types[$declared])) {
            return $declared;
        }
        if (isset($types[$page->slug])) {
            return $page->slug;
        }
        if (isset($types['single'])) {
            return 'single';
        }

        return (string) (array_key_first($types) ?? $page->slug);
    }

    /**
     * آیا صفحه از بلوک‌های پیش‌فرض نوع صفحه استفاده می‌کند (نه override مستقل)؟
     *
     * در نصب تک‌سایتی، «بلوک‌های پیش‌فرض» مرجع مشترک همه صفحات آن نوع است؛
     * ویرایش آن باید روی همه صفحاتی که override نشده‌اند اعمال شود — وگرنه
     * کاربر تغییر را در سایت نمی‌بیند. تشخیص override صریحاً با
     * `meta.blocks_overridden = true` انجام می‌شود (کاربر خودش می‌گذارد)،
     * وگرنه صفحه از پیش‌فرض پیروی می‌کند.
     */
    private function pageUsesDefaultBlocks(Page $page, array $defaults): bool
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        if (($meta['blocks_overridden'] ?? false) === true) {
            return false;
        }

        return true;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalValue($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    /**
     * تنظیمات ویجت logo: فقط show_title نگه داشته می‌شود؛ کلیدهای قدیمی
     * (media_id/size/media_url) بی‌صدا حذف می‌شوند تا داده قدیمی خطا ندهد.
     */
    public static function sanitizeLogoSettings(mixed $settings): array
    {
        if (! is_array($settings)) {
            return [];
        }
        $out = [];
        if (array_key_exists('show_title', $settings)) {
            $out['show_title'] = (bool) $settings['show_title'];
        }

        return $out;
    }

    private function key(string $suffix): string
    {
        // کلید سراسری نصب — مشترک بین همه مدیران.
        return 'global:'.$suffix;
    }

    private function defaults(string $area): array
    {
        if ($area === 'footer') {
            // مبنای فوتر = پیش‌فرض قالب فعال (manifest.footer) وگرنه هسته.
            return ManifestRegistry::footerBase();
        }

        return $area === 'header'
            ? [
                'widgets' => [
                    ['type' => 'logo', 'settings' => []],
                    ['type' => 'nav', 'settings' => []],
                ],
                'layout' => ['columns' => 2],
            ]
            : [
                'widgets' => [
                    ['type' => 'about', 'settings' => []],
                    ['type' => 'copyright', 'settings' => []],
                ],
                'layout' => ['columns' => 3],
            ];
    }
}
