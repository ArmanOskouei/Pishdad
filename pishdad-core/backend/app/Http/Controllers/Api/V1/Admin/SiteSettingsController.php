<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Services\RevalidateDispatcher;
use App\Services\Settings\CachedSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۲.۳ — تنظیمات سایت + شبکه‌های اجتماعی (مشترک نصب تک‌سایتی، کلید global).
 * خواندن کش‌دار (تگ settings)؛ ذخیره = validate فارسی + ابطال کش.
 */
class SiteSettingsController extends Controller
{
    /**
     * لیست ثابت قدیمی — فقط برای برچسب فارسی (SOCIAL_FA) نگه داشته شده؛
     * اعتبارسنجی کلید آزاد است (الگوی SOCIAL_KEY_PATTERN) تا شبکه سفارشی هم قبول شود.
     */
    public const SOCIAL_KEYS = [
        'instagram', 'telegram', 'x', 'linkedin',
        'aparat', 'youtube', 'facebook', 'whatsapp', 'website',
    ];

    public const SOCIAL_FA = [
        'instagram' => 'اینستاگرام',
        'telegram' => 'تلگرام',
        'x' => 'ایکس',
        'linkedin' => 'لینکدین',
        'aparat' => 'آپارات',
        'youtube' => 'یوتیوب',
        'facebook' => 'فیسبوک',
        'whatsapp' => 'واتساپ',
        'website' => 'وب‌سایت',
    ];

    public const SOCIAL_KEY_PATTERN = '/^[a-z0-9_-]{2,30}$/';

    /**
     * WF-M20 — متنِ پیش‌فرضِ سیاست حریم خصوصی (صفحهٔ /privacy).
     * خالی‌بودن یعنی صفحهٔ عمومی پیامِ «تنظیم نشده» نشان می‌دهد.
     */
    public const DEFAULT_PRIVACY_POLICY = "این سایت فقط از کوکی‌های ضروری استفاده می‌کند؛ هیچ کوکی تحلیلی یا تبلیغاتی گذاشته نمی‌شود. تحلیل بازدید نیز بدون کوکی و بدون شناسهٔ شخصی انجام می‌شود.\n\nاطلاعاتی که خودتان در فرم‌های تماس یا عضویت وارد می‌کنید (نام، ایمیل، شماره تماس و متن پیام) فقط برای پاسخگویی و ارائهٔ خدمات استفاده می‌شود و در اختیار اشخاص ثالث قرار نمی‌گیرد.\n\nبرای هر پرسشی دربارهٔ حریم خصوصی از صفحهٔ «تماس» با ما در ارتباط باشید.";

    /**
     * WF-M17 — متنِ پیش‌فرضِ بیانیهٔ دسترس‌پذیری (صفحهٔ /accessibility).
     * عمداً «هدفِ انطباق» + «راهِ تماس» را نام می‌برد، چون بیانیهٔ دسترس‌پذیریِ
     * بی‌راه تماس بی‌فایده است (الزامِ خودِ WCAG هم همین است: محتوای غیرقابل
     * دسترس باید مسیرِ بازخوردِ قابل استفاده داشته باشد).
     */
    public const DEFAULT_ACCESSIBILITY_STATEMENT = "هدف این وب‌سایت انطباق با «راهنمای دسترس‌پذیری محتوای وب» (WCAG 2.2) در سطح AA است. متن راست‌به‌چپ و قابل بزرگ‌نمایی، کنتراست رنگ کافی، پیمایش کامل با صفحه‌کلید، برچسب‌های خوانا برای صفحه‌خوان و رعایت نسبت کنتراست در همهٔ صفحه‌ها در طراحی رعایت شده‌اند.\n\nاگر مانعی در دسترسی به محتوای این سایت برای شما پیش آمد، از راه‌های تماسِ درج‌شده در پایین همین صفحه با ما در میان بگذارید تا در کوتاه‌ترین زمان برطرفش کنیم.\n\nاگر پاسخِ ما رضایت‌بخش نبود، می‌توانید موضوع را به مرجعِ ملی دسترس‌پذیری ارجاع دهید.";

    public const SITE_DEFAULTS = [
        'title' => 'وب‌سایت من',
        'description' => '',
        'logo_media_id' => null,
        'favicon_media_id' => null,
        'phone' => null,
        'email' => null,
        // سئو تکنیکال + GEO (بازبینی سئو): canonical/OG و خلاصه AI و robots و نشانی.
        'site_url' => null,
        'og_image_media_id' => null,
        'ai_summary' => '',
        'robots_index' => true,
        'robots_txt' => null,
        'google_site_verification' => null,
        'address' => null,
        // WF-M20 — متن صفحهٔ سیاست حریم خصوصی (بدون HTML؛ صفحهٔ /privacy).
        'privacy_policy' => self::DEFAULT_PRIVACY_POLICY,
        // WF-M17 — متن صفحهٔ بیانیهٔ دسترس‌پذیری (بدون HTML؛ صفحهٔ /accessibility).
        'accessibility_statement' => self::DEFAULT_ACCESSIBILITY_STATEMENT,
        // دسته UIUX (افزودنی): زبان پیش‌فرض + منطقه زمانی + صفحه خانه.
        'locale' => 'fa',
        'timezone' => 'Asia/Tehran',
        'homepage_page_id' => null,
        // ECO2 — مدل دوزبانه/تک‌زبانه. `single` = فقط `locale`؛ `dual` = زبان
        // اصلی + زبان دوم (مدل a: زبان دوم زیر /en). `secondary_locale` زبانِ
        // غیرِ اصلی است و فقط وقتی `dual` باشد معنا دارد.
        'language_mode' => 'single',
        'secondary_locale' => null,
        // کلیدِ سراسریِ «کش سایت»: وقتی خاموش ⇒ تنظیمات بی‌درنگ روی سایت؛ وقتی
        // روشن ⇒ سرعت/مصرف بهینه (پیش‌فرض روشن).
        'cache_enabled' => true,
    ];

    /**
     * برندِ پیش‌فرضِ نصب — فایل‌های ثابتِ برند در `frontend/public`.
     * اگر رسانه‌ای انتخاب نشده باشد، همین‌ها نشان داده می‌شوند تا نصبِ تازه
     * (کلون از گیت‌هاب / اجرای از صفر) هم لوگو و فاوآیکون داشته باشد.
     */
    public const DEFAULT_LOGO_URL = '/pishdad-logo.png';
    public const DEFAULT_FAVICON_URL = '/favicon.ico';

    public const LOCALES = ['fa', 'en'];

    public const TIMEZONES = ['Asia/Tehran', 'Asia/Dubai', 'Europe/London', 'UTC'];

    public function showSite(Request $request): JsonResponse
    {
        $data = CachedSettings::remember('site', $this->key(), 300, function () {
            return array_merge(self::SITE_DEFAULTS, Setting::get('site', $this->key(), []) ?? []);
        });

        // نشانی نمایشی لوگو/فاوآیکون/OG (بیرون از کش تا با حذف رسانه کهنه نماند).
        $urls = $this->resolveUrls($request, [
            'logo' => $data['logo_media_id'] ?? null,
            'favicon' => $data['favicon_media_id'] ?? null,
            'og_image' => $data['og_image_media_id'] ?? null,
        ]);
        // برندِ پیش‌فرض وقتی رسانه‌ای انتخاب نشده (نصبِ تازه) — به‌جای null.
        $data['logo_url'] = $urls['logo'] ?? self::DEFAULT_LOGO_URL;
        $data['favicon_url'] = $urls['favicon'] ?? self::DEFAULT_FAVICON_URL;
        $data['og_image_url'] = $urls['og_image'];

        // ECO2 — تجزیهٔ حالتِ زبان به فهرست/اصلی؛ تک‌جا تا خواننده (پنل و سایت)
        // دو روایتِ جدا از هم نداشته باشند.
        $data['locales'] = self::resolveLocales($data);
        $data['primary_locale'] = self::resolveLocales($data)[0];

        return response()->json(['data' => $data]);
    }

    public function updateSite(Request $request, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $validated = $request->validate([
            // F0.1: این چهار فیلد در `buildJsonLd` صفحهٔ عمومی تکرار می‌شوند
            // (WebSite.name، description، Organization.website/address) و در
            // `<meta>` هم می‌نشینند ⇒ `no_markup` لازم دارند.
            'title' => 'required|string|min:2|max:160|no_markup',
            'description' => 'nullable|string|max:500|no_markup',
            'logo_media_id' => 'nullable|integer|min:1',
            'favicon_media_id' => 'nullable|integer|min:1',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^(\+98|0)?9\d{9}$/'],
            'email' => 'nullable|email|max:200',
            'site_url' => 'nullable|url|max:2048',
            'og_image_media_id' => 'nullable|integer|min:1',
            'ai_summary' => 'nullable|string|max:1000|no_markup',
            'robots_index' => 'sometimes|boolean',
            'robots_txt' => 'nullable|string|max:5000|no_markup',
            'google_site_verification' => 'nullable|string|max:200|no_markup',
            'address' => 'nullable|string|max:500|no_markup',
            'privacy_policy' => 'nullable|string|max:20000|no_markup',
            'accessibility_statement' => 'nullable|string|max:20000|no_markup',
            'locale' => 'nullable|string|in:fa,en',
            'timezone' => 'nullable|string|in:Asia/Tehran,Asia/Dubai,Europe/London,UTC',
            'homepage_page_id' => 'nullable|integer|min:1',
            'language_mode' => 'nullable|string|in:single,dual',
            // ECO2 — زبانِ دوم باید زبانِ معتبر باشد؛ `different:locale` جلوی
            // حالتِ بی‌معنیِ «اصلی = دوم» را می‌گیرد.
            'secondary_locale' => 'nullable|string|in:fa,en|different:locale',
            'cache_enabled' => 'sometimes|boolean',
        ], [
            'title.required' => 'عنوان سایت الزامی است.',
            'title.min' => 'عنوان سایت خیلی کوتاه است.',
            'title.max' => 'عنوان سایت بیش از حد طولانی است (۱۶۰ نویسه).',
            'description.max' => 'توضیحات بیش از حد طولانی است (۵۰۰ نویسه).',
            'logo_media_id.exists' => 'لوگوی انتخاب‌شده یافت نشد.',
            'favicon_media_id.exists' => 'فاوآیکون انتخاب‌شده یافت نشد.',
            'phone.regex' => 'شماره تلفن معتبر نیست (مثل 09123456789).',
            'email.email' => 'ایمیل معتبر نیست.',
            'site_url.url' => 'نشانی سایت معتبر نیست (مثل https://example.ir).',
            'site_url.max' => 'نشانی سایت بیش از حد طولانی است.',
            'og_image_media_id.exists' => 'تصویر اشتراک‌گذاری انتخاب‌شده یافت نشد.',
            'ai_summary.max' => 'خلاصه هوش مصنوعی بیش از حد طولانی است (۱۰۰۰ نویسه).',
            'robots_index.boolean' => 'مقدار ایندکس ربات‌ها معتبر نیست.',
            'robots_txt.max' => 'محتوای robots.txt بیش از حد طولانی است (۵۰۰۰ نویسه).',
            'google_site_verification.max' => 'کد تأیید مالکیت گوگل بیش از حد طولانی است (۲۰۰ نویسه).',
            'address.max' => 'نشانی بیش از حد طولانی است (۵۰۰ نویسه).',
            'privacy_policy.max' => 'متن سیاست حریم خصوصی بیش از حد طولانی است (۲۰۰۰۰ نویسه).',
            'accessibility_statement.max' => 'متن بیانیهٔ دسترس‌پذیری بیش از حد طولانی است (۲۰۰۰۰ نویسه).',
            'locale.in' => 'زبان پیش‌فرض معتبر نیست (fa یا en).',
            'timezone.in' => 'منطقه زمانی معتبر نیست.',
            'homepage_page_id.exists' => 'صفحه خانه انتخاب‌شده یافت نشد.',
            'language_mode.in' => 'حالت زبان معتبر نیست (single یا dual).',
            'secondary_locale.in' => 'زبان دوم معتبر نیست (fa یا en).',
            'secondary_locale.different' => 'زبان دوم باید با زبان اصلی فرق داشته باشد.',
        ]);

        // مشترک: صفحه خانه فقط باید وجود داشته باشد (بدون چک مالکیت).
        if (! empty($validated['homepage_page_id']) && ! Page::query()
            ->where('id', (int) $validated['homepage_page_id'])
            ->exists()) {
            return response()->json(['message' => 'صفحه خانه انتخاب‌شده یافت نشد.'], 404);
        }

        foreach (['logo_media_id', 'favicon_media_id', 'og_image_media_id'] as $field) {
            if (! empty($validated[$field]) && ! $this->mediaExists((int) $validated[$field])) {
                return response()->json(['message' => 'رسانه انتخاب‌شده یافت نشد.'], 404);
            }
        }

        $data = array_merge(self::SITE_DEFAULTS, Setting::get('site', $this->key(), []) ?? [], $validated);
        $data['robots_index'] = (bool) ($data['robots_index'] ?? true);
        // نرمال‌سازی site_url: حذف اسلش پایانی.
        if (! empty($data['site_url'])) {
            $data['site_url'] = rtrim($data['site_url'], '/');
        }
        // ECO2 — حالت تک‌زبانه یعنی زبانِ دوم بی‌معناست؛ پاک‌سازی تا دیتای مرده نماند.
        if (($data['language_mode'] ?? 'single') !== 'dual') {
            $data['secondary_locale'] = null;
        }
        Setting::set('site', $this->key(), $data);
        CachedSettings::forget('site', $this->key());
        // کلید کش کروم سراسری (پیشوند chrome- مهم است).
        CachedSettings::forget('site_chrome', 'chrome-default-global');

        // عنوان/لوگو در هدر عمومی دیده می‌شود — ابطال فوری (fire-and-forget).
        $dispatcher->dispatch(['site-chrome']);

        return response()->json([
            'message' => 'تنظیمات سایت ذخیره شد.',
            'data' => $data,
        ]);
    }

    public function showSocials(Request $request): JsonResponse
    {
        $data = CachedSettings::remember('socials', $this->key(), 300, function () {
            return Setting::get('socials', $this->key(), ['socials' => []]) ?? ['socials' => []];
        });

        // غنی‌سازی نمایشی لوگوها (بیرون از کش تا با حذف رسانه کهنه نماند).
        $ids = [];
        foreach ($data['socials'] ?? [] as $i => $s) {
            if (! empty($s['icon_media_id'])) {
                $ids[$i] = (int) $s['icon_media_id'];
            }
        }
        $urls = $this->resolveUrls($request, $ids);
        foreach ($urls as $i => $url) {
            $data['socials'][$i]['icon_url'] = $url;
        }
        foreach (array_keys($data['socials'] ?? []) as $i) {
            $data['socials'][$i]['icon_url'] ??= null;
            $data['socials'][$i]['label'] ??= null;
            $data['socials'][$i]['icon_media_id'] ??= null;
        }

        return response()->json(['data' => $data]);
    }

    public function updateSocials(Request $request, RevalidateDispatcher $dispatcher): JsonResponse
    {
        $validated = $request->validate([
            'socials' => 'required|array|max:20',
            'socials.*.key' => ['required', 'string', 'min:2', 'max:30', 'regex:'.self::SOCIAL_KEY_PATTERN, 'distinct'],
            'socials.*.url' => 'required|url|max:2048',
            'socials.*.active' => 'sometimes|boolean',
            'socials.*.label' => 'nullable|string|max:60',
            'socials.*.icon_media_id' => 'nullable|integer|min:1',
        ], [
            'socials.required' => 'لیست شبکه‌ها الزامی است.',
            'socials.*.key.required' => 'کلید شبکه الزامی است.',
            'socials.*.key.min' => 'کلید شبکه خیلی کوتاه است (حداقل ۲ نویسه).',
            'socials.*.key.max' => 'کلید شبکه خیلی طولانی است (حداکثر ۳۰ نویسه).',
            'socials.*.key.regex' => 'کلید شبکه معتبر نیست (فقط حروف کوچک انگلیسی، عدد، ـ و -).',
            'socials.*.key.distinct' => 'کلید شبکه تکراری است.',
            'socials.*.url.required' => 'آدرس شبکه الزامی است.',
            'socials.*.url.url' => 'آدرس شبکه معتبر نیست.',
            'socials.*.label.max' => 'برچسب نمایشی خیلی طولانی است (۶۰ نویسه).',
            'socials.*.icon_media_id.exists' => 'لوگوی انتخاب‌شده یافت نشد.',
        ]);

        foreach ($validated['socials'] as $s) {
            if (! empty($s['icon_media_id']) && ! $this->mediaExists((int) $s['icon_media_id'])) {
                return response()->json(['message' => 'رسانه انتخاب‌شده یافت نشد.'], 404);
            }
        }

        $socials = collect($validated['socials'])->map(fn (array $s) => [
            'key' => $s['key'],
            'url' => $s['url'],
            'active' => (bool) ($s['active'] ?? true),
            'label' => $s['label'] ?? null,
            'icon_media_id' => $s['icon_media_id'] ?? null,
        ])->values()->all();

        $data = ['socials' => $socials];
        Setting::set('socials', $this->key(), $data);
        CachedSettings::forget('socials', $this->key());
        CachedSettings::forget('site_chrome', 'chrome-default-global');

        // شبکه‌ها در فوتر عمومی دیده می‌شوند — ابطال فوری (fire-and-forget).
        $dispatcher->dispatch(['site-chrome']);

        // icon_url غنی‌شده در پاسخ ذخیره هم برگردد (همان شکل showSocials).
        $ids = [];
        foreach ($socials as $i => $s) {
            if (! empty($s['icon_media_id'])) {
                $ids[$i] = (int) $s['icon_media_id'];
            }
        }
        $urls = $this->resolveUrls($request, $ids);
        foreach ($socials as $i => $s) {
            $socials[$i]['icon_url'] = $urls[$i] ?? null;
        }

        return response()->json([
            'message' => 'شبکه‌های اجتماعی ذخیره شد.',
            'data' => ['socials' => $socials],
        ]);
    }

    /**
     * ECO2 — فهرست زبان‌های سایت از حالتِ زبان.
     *
     * `single` ⇒ فقط زبانِ اصلی در ریشه. `dual` ⇒ زبانِ اصلی + زبانِ دوم؛ اگر
     * زبانِ دوم نبود یا با اصلی یکی بود، به `single` برمی‌گردیم (fail-safe،
     * نه فهرستِ خالی که سایت را بی‌زبان می‌کند).
     *
     * @return array<int, string> زبانِ اصلی همیشه اول است (خروجی مدل a).
     */
    public static function resolveLocales(array $data): array
    {
        $primary = in_array($data['locale'] ?? null, self::LOCALES, true) ? $data['locale'] : 'fa';
        if (($data['language_mode'] ?? 'single') !== 'dual') {
            return [$primary];
        }
        $secondary = $data['secondary_locale'] ?? null;
        if (! in_array($secondary, self::LOCALES, true) || $secondary === $primary) {
            return [$primary];
        }

        return [$primary, $secondary];
    }

    private function key(): string
    {
        // کلید سراسری نصب — مشترک بین همه مدیران (مثل ui با کلید global).
        return 'global';
    }

    /** مشترک: فقط وجود رسانه مهم است. */
    private function mediaExists(int $mediaId): bool
    {
        return Media::query()
            ->where('id', $mediaId)
            ->exists();
    }

    /**
     * حل path رسانه‌ها به نشانی عمومی (پایه AWS_URL).
     * ورودی: [کلید => media_id] — خروجی: [کلید => url|null].
     * رسانه ناموجود → null (بدون نشت path).
     */
    private function resolveUrls(Request $request, array $map): array
    {
        $out = [];
        $wanted = [];
        foreach ($map as $k => $id) {
            $out[$k] = null;
            if (is_numeric($id) && (int) $id > 0) {
                $wanted[$k] = (int) $id;
            }
        }
        if ($wanted === []) {
            return $out;
        }

        $rows = Media::query()
            ->whereIn('id', array_values($wanted))
            ->get(['id', 'disk', 'path'])
            ->keyBy('id');

        foreach ($wanted as $k => $id) {
            $row = $rows[$id] ?? null;
            if ($row) {
                $out[$k] = \App\Support\MediaUrl::for((string) $row->disk, (string) $row->path);
            }
        }

        return $out;
    }
}
