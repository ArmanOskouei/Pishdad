<?php

namespace App\Services\Plugins;

use App\Search\SearchableProvider;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Support\Str;

/**
 * فاز ۱.۵ — قرارداد بستهٔ پلاگین، به‌صورت کد.
 *
 * چرا کد و نه فایل JSON/جدول دیتابیس؟ چون این «قرارداد» است نه «واقعیت هسته».
 * از فایل‌سیستم نمی‌شود فهمید یک فایل `.php` کنترلر است یا سرویس یا job.
 * همچنین جدول نگاشت مثبت با هر تغییر ساختار هسته drift می‌کند، پس به‌جای آن
 * **deny-by-default** استفاده می‌شود: بستهٔ کمینهٔ مقصدهای مجاز، و هر چیز
 * دیگری رد.
 *
 * پیامد مهم: **تغییر ساختار پوشه‌های هسته اصلاً مسئلهٔ این validator نیست.**
 * دنیای validator فقط پیشوندهای داخل پلاگین را می‌شناسد. سازگاری با نسخهٔ
 * هسته کارِ `requires.core` است، نه کارِ این کلاس.
 */
final class PluginPackageContract
{
    /** فایل هویت و امضا. حتماً در ریشهٔ ZIP. */
    public const MANIFEST = 'manifest.json';

    /** پیشوندهای ریشهٔ بستهٔ بک‌اند. */
    public const BACKEND_ROOT = 'Laravel';

    /** پیشوند ریشهٔ بستهٔ فرانت‌اند. */
    public const FRONTEND_ROOT = 'Next.js';

    /**
     * ECO4 — نقطهٔ حقیقتِ واحدِ اسلاگِ افزونه و قالب.
     *
     * هر دو سازندهٔ اسکلت (`PluginMake` و `ThemeMake`) از همین الگو استفاده
     * می‌کنند تا «قواعد یکسان» در دو جای جدا drift نکند.
     */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,39}$/';

    /**
     * ECO4 — اسلاگِ امنِ نامِ جدول (فقط افزونه).
     *
     * نام نهایی جدول از اسلاگ ساخته می‌شود، پس نقطه/خط‌تیره مجاز نیست. قالب
     * جدول نمی‌سازد و عمداً از این الگو استفاده نمی‌کند.
     */
    public const TABLE_SAFE_SLUG_PATTERN = '/^[a-z0-9_]+$/';

    /** سقف‌های ایمنی. */
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;

    /**
     * K5.10 — سقف تعداد فایل‌های **یک بسته**.
     *
     * این سقف *per-package* است، نه aggregate. برای اینکه چرا این تفکیک حیاتی
     * است، واقعیتِ **سنجیده‌شده**ٔ این استقرار (نه حدس):
     *
     *  - opcache **روشن است** و هر دو SAPI را می‌گیرد: `Zend OPcache` در `php -m`،
     *    و `opcache.enable => On` در خروجی خودِ `php-fpm85 -i`.
     *  - `docker/php/php.ini` می‌گوید `memory_consumption = 256` و
     *    `max_accelerated_files = 20000` و `validate_timestamps = 1` و
     *    `revalidate_freq = 60` و `file_cache` خالی. یعنی کش فقط حافظهٔ مشترکِ
     *    FPM است و با ری‌استارت از بین می‌رود.
     *  - آن حافظه **بین همهٔ workerها مشترک است**، پس کرانِ واقعی روی *مجموعِ*
     *    فایل‌های PHPِ زنده است، نه روی یک بسته. و وقتی جدول پر شود، opcache
     *    فایلِ تازه را بی‌صدا کش نمی‌کند — دقیقاً همان خرابیِ خاموشی که کلِ این
     *    پروژه ازش پرهیز می‌کند.
     *
     * حسابِ خودِ K5.10: ۵۰ پلاگین × ۵۰۰۰ فایل = ۲۵۰ هزار فایل، یعنی ۱۲ برابرِ
     * کلِ جدولِ ۲۰ هزارتایی. پس این ثابت **نگهبانِ opcache نیست** و نباید خوانده شود
     * که هست. تنها سیگنالِ امروزِ نزدیک‌شدن به آن مرز،
     * `PluginPackageValidator::RECOMMENDED_ALLOWED_FILES` (۲۰۰۰) است، و آن هم
     * هشدار است نه خطا.
     *
     * سقفِ aggregate جای درستش **هنگام فعال‌سازی** است یا در خودِ تنظیماتِ opcache
     * (`max_accelerated_files`) — و امروز هیچ‌کدام enforce نمی‌شود. این یک شکافِ
     * شناخته‌شده و باز است، نه چیزی که این ثابت ادعا کند آن را می‌بندد.
     *
     * ## چرا `opcache_reset()` هرگز صدا زده نمی‌شود
     *
     * هر تماس، کشِ *هسته* را هم پاک می‌کند: همهٔ workerها باید دوباره همه‌چیز را
     * کامپایل کنند. این یک رگرسیونِ کاراییِ کلِ اپ است، نه یک درمان.
     *
     * و لازم هم نیست: مسیرِ نسخه محتوا‌محور است (`releases/{version}-{hash8}/`)، پس
     * هر نصبِ تازه روی مسیرِ *تازه* می‌نشیند و در opcache اصلاً ورودی‌ای ندارد که
     * کهنه باشد. `validate_timestamps` و `revalidate_freq` فقط وقتی معنا داشتند که
     * فایلی *در جای خودش* عوض شود، و installer مسیرِ اشغال‌شده را بازنویسی نمی‌کند
     * (`install.target_occupied` / `install.active_release_corrupt`). تنها چیزی که
     * درجا عوض می‌شود فایلِ اشاره‌گرِ `.current` است که JSON است و کامپایل نمی‌شود.
     */
    public const MAX_FILES = 5000;

    public const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    /** نسبت فشرده‌سازی بالاتر از این ⇒ zip bomb. */
    public const MAX_COMPRESSION_RATIO = 100;

    /**
     * بستهٔ کمینهٔ مقصدهای مجاز. deny-by-default.
     *
     * هر ورودی: مسیر نسبت به ریشهٔ ZIP.
     *
     * `Next.js/panel.json` حذف شد (تصمیم C2): مانیفست تنها منبع حقیقت است و
     * `panel.json` کپی دومی بود که فقط می‌توانست drift کند.
     */
    public const ALLOWED_PATHS = [
        self::MANIFEST,

        // بک‌اند
        self::BACKEND_ROOT.'/src',
        self::BACKEND_ROOT.'/routes/api.php',
        self::BACKEND_ROOT.'/database/migrations',
        self::BACKEND_ROOT.'/config/plugin.php',

        // فرانت‌اند
        self::FRONTEND_ROOT.'/panel',
        self::FRONTEND_ROOT.'/blocks',
    ];

    /** نام‌های رزروشدهٔ ویندوز — کاربر ممکن است فایل‌ها را روی سرور ویندوزی مدیریت کند. */
    public const RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * کلیدهای ممنوع در **هر** نقطهٔ اتصال.
     *
     * سراسری و نه تک‌تک در هر نقطه، چون تکرارش یعنی drift: یک روز نقطهٔ دهم
     * فراموش می‌کند و همان‌جا K1.5.8 از در بیرون می‌آید.
     */
    public const FORBIDDEN = [
        'component', 'render', 'js', 'import', 'endpoint',
        'html', 'style', 'path', 'template',
    ];

    /**
     * نسخهٔ قراردادیِ هسته — «چه چیزی را این نصب می‌فهمد».
     *
     * ⚠️ عددِ کور نیست: `since` هر نقطه و `schema_version` هر نقطه با همین
     * سنجیده می‌شوند. اگر روزی نقطه‌ای اضافه شد ولی این عدد بالا نرفت، نصبِ
     * قدیمی آن نقطه را «می‌شناسد» و اعلانش سبز می‌شود در حالی که هیچ رندرری
     * ندارد — همان «قولِ ساختاریافتهٔ دروغین». یک تست همین را نگه می‌دارد
     * (`PluginExtensionChannelTest::test_no_point_is_newer_than_the_core`).
     */
    public const CORE_CONTRACT_VERSION = '1.6.0';

    /**
     * فیلدهای **فراداده** که هر اعلان می‌تواند دربارهٔ خودش بگوید.
     *
     * این‌ها در `schema.fields` هر نقطه نیستند چون به محتوای نقطه ربطی ندارند
     * و برای همهٔ نقاط یکسان‌اند — وگرنه ده نقطه ده بار تکرارشان می‌کرد و یک روز
     * یکی جا می‌ماند. پس اینجا نگه‌داری می‌شوند و از «فیلد ناشناخته» بودن
     * معاف‌اند.
     *
     * @var list<string>
     */
    public const DECLARATION_META_FIELDS = ['since', 'schema_version'];

    /**
     * ⭐ I1.3 — چرا `permissions` نقطهٔ اتصال **نیست**.
     *
     * پرمیشن داده نیست، **.authorization** است: هسته آن را در
     * `permission_modules` می‌نویسد و در `perm:` میان‌افزار می‌سنجد. نقطهٔ
     * اتصال یعنی «هسته این را در جایی رندر می‌کند» — و هیچ چیزی پرمیشن را رندر
     * نمی‌کند. اگر نقطه‌ای برایش ساخته می‌شد، دو چیز خراب می‌شد:
     *
     *  ۱. `panel.extensions` میکرو-اسکیمایی با `max_depth => 1` است و
     *     `actions: string[]` داخلش جا نمی‌شود ⇒ یا سقف عمق برای همهٔ نقاط بالا
     *     می‌رفت، یا `actions` بیرون اعلان می‌ماند و کسی اعتبارسنجی‌اش نمی‌کرد.
     *  ۲. «باز بودن» نقطه برای نویسنده معنا دارد (`openness`). برای پرمیشن باز
     *     بودن یعنی چه؟ هر افزونه‌ای هر اسمی می‌سازد و `perm:` آن را
     *     می‌خواند — یعنی یک سطح جدید از اختیار بدون مهار.
     *
     * پس تصمیم: **فیلد جدا**، نه نقطهٔ اتصال. کانال زنده `manifest.access.*`
     * و `manifest.permissions` به‌عنوان fallback دو-کاناله باقی می‌ماند تا
     * نصب‌های موجود نشکنند (`ManifestRegistry::manifestPermissions()`).
     */
    public const PERMISSIONS_FIELD = 'access';

    /** @deprecated کانال قدیمی پرمیشن — فقط برای dual-read. */
    public const PERMISSIONS_FIELD_LEGACY = 'permissions';

    /**
     * نام‌هایی که رجیستری هسته از قبل مالکشان است.
     *
     * `ManifestRegistry.php` برخورد را **بی‌صدا** `continue` می‌کند
     * (`:99-105` و `:152-154`) ⇒ پلاگین نصب می‌شود و اصلاً دیده نمی‌شود.
     * این فهرست دستی است ولی یک تست آن را با `config/blocks.php` و
     * `config/widgets.php` می‌سنجد، پس نمی‌تواند بی‌سروصدا عقب بماند.
     */
    public const RESERVED_TYPE_NAMES = [
        // config/blocks.php
        'hero', 'text', 'image', 'cta', 'gallery', 'quote', 'video', 'contact-form', 'faq',
        // WF-H10 — بلوکِ «فرم» که بلوکِ `contact-form` را به فرم‌ساز وصل می‌کند.
        // رزرو لازم است وگرنه افزونه‌ای می‌تواند `form` را اعلام کند، بی‌صدا رد می‌شود
        // و رجیستری آن را «نصب‌شده ولی هرگز دیده‌نشده» رها می‌کند.
        'form',
        // config/widgets.php → header
        'logo', 'nav', 'search', 'socials',
        // config/widgets.php → footer
        'about', 'links', 'contact', 'newsletter', 'copyright',
    ];

    /**
     * interface هایی که افزونه مجاز است پیاده‌سازی‌اش را جایگزین کند.
     *
     * ⚠️ این فهرست با `ServiceProviderRegistry::OVERRIDABLE` **باید یکی باشد.**
     * عمداً در هر دو جا تکرار نشده: تست `test_registry_and_contract_agree_on_overridable_interfaces`
     * این را نگه می‌دارد، چون فهرست دوتایی یعنی جایی هست که افزونه
     * تأیید می‌شود ولی رجیستری ردش می‌کند، و این دقیقاً همان باگی است که
     * B30 دربارهٔ برخورد بی‌صدا هشدار می‌دهد.
     *
     * @var list<class-string>
     */
    public const OVERRIDABLE_INTERFACES = [
        PaymentGatewayInterface::class,
        SearchableProvider::class,
    ];

    /** ریشهٔ اجباری namespace کلاس افزونه — هم‌ریشه با قاعدهٔ K5.3. */
    public const PLUGIN_NAMESPACE_PREFIX = 'Pishdad\\Plugins\\';

    /**
     * سقف‌های گرامر micro-schema. بیرون از این‌ها خطاست، نه هشدار.
     */
    public const GRAMMAR_LIMITS = [
        'max_depth' => 1,
        'max_total_bytes' => 65536,
        'max_enum_members' => 50,
        'max_string_length' => 4000,
    ];

    /**
     * واژگان مجاز گرامر micro-schema — همین‌ها، نه بیشتر.
     *
     * `string_list` تنها افزودهٔ ما به فهرست مصوب است و دقیقاً همان شکلی است که
     * `media_ids` دارد (فهرست اسکالر)؛ جدا کردنش یعنی تکرار همان مفهوم با دو نام.
     * «آبجکت» و «فهرست آبجکت» در هیچ شکلی از این واژگان بیرون نمی‌آید.
     */
    public const GRAMMAR_TYPES = [
        'string', 'integer', 'number', 'boolean',
        'enum', 'media_id', 'media_ids', 'richtext', 'string_list',
    ];

    /**
     * نقاط اتصال افزونه در پنل و سایت.
     *
     * فهرست **بسته** است (تصمیم K1.5.6). افزودن مورد تازه یک ارتقای هسته است،
     * و این عمدی است: کنترل کیفیت و سازگاری دست پلتفرم می‌ماند.
     *
     * کلیدهای هر نقطه:
     *  - `status`         وضعیت واقعی runtime، نه وعدهٔ راهنما
     *  - `openness`       درجهٔ عمدی باز/بسته بودن روی محور «نام فیلد»
     *  - `open_schema`    آیا فیلدهای دلخواه هم پذیرفته می‌شوند (واژگان باز)
     *  - `schema.fields`  فهرست فیلدهای بسته (L1/L2)
     *  - `schema.cross`   قواعد مقطعی اعمال‌شده روی همین نقطه
     *
     * `forbidden` عمداً اینجا نیست: سراسری است (ثابت `FORBIDDEN`) و در
     * `extensionPoints()` تزریق می‌شود.
     */
    public const EXTENSION_POINTS = [
        'admin.menu' => [
            'label_fa' => 'منوی کناری پنل مدیریت',
            'label_en' => 'Admin panel sidebar menu',
            'desc_fa' => 'افزودن آیتم به منوی سمت راست پنل. هر پلاگینی معمولاً به این نیاز دارد.',
            'desc_en' => 'Add an item to the panel sidebar menu. Almost every plugin needs this.',
            'status' => 'declared_only',
            'openness' => 'closed',
            'openness_why' => 'خروجی این نقطه دقیقاً MenuItem است با چهار فیلد ثابت؛ چیزی برای «باز کردن» وجود ندارد.',
            'openness_why_en' => 'This point outputs exactly a MenuItem with four fixed fields; there is nothing to open.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            // `no_core_name` عمداً نیست: `key` اینجا کلید آیتم منوست، نه نوع ویجت.
            'schema' => [
                'fields' => [
                    'key' => ['type' => 'string', 'required' => true, 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                    'label' => ['type' => 'string', 'required' => true, 'maxLength' => 80],
                    // الگوی خواسته‌شده دقیقاً همین است و `[a-z0-9._-]` نقطه را هم
                    // می‌پذیرد، پس `..` از آن رد می‌شود. برای همین `is_path`
                    // جداگانه segments «..» را می‌گیرد — وگرنه `/admin/../x` از
                    // پیشوند /admin بیرون می‌زد.
                    'href' => ['type' => 'string', 'required' => true, 'maxLength' => 200, 'pattern' => '^/admin(/[a-z0-9._-]+)*$', 'is_path' => true],
                    'icon' => ['type' => 'string', 'required' => false, 'maxLength' => 16],
                    'order' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 999],
                    // `pattern` ندارد عمداً: قاعدهٔ prefix اسلاگ را `permission_prefix`
                    // می‌گوید و پیامش دقیق‌تر است. دو لایهٔ validation روی یک فیلد
                    // فقط دو خطای هم‌معنی می‌سازد.
                    'permission' => ['type' => 'string', 'required' => false, 'maxLength' => 120],
                ],
                'cross' => ['permission_prefix'],
            ],
            'open_schema' => false,
            'example_ok' => [
                [
                    'key' => 'blog', 'label' => 'نوشته‌ها', 'href' => '/admin/blog',
                    'icon' => '✎', 'order' => 10, 'permission' => 'plugin:blog:posts.view',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'href بیرونی یا javascript: هم باز-redirect است هم XSS — یک regex تنها هر دو را می‌کُشد.',
                    'decl' => ['key' => 'blog', 'label' => 'نوشته‌ها', 'href' => 'javascript:alert(1)'],
                ],
                [
                    'why' => 'این نقطه شکل MenuItem دارد: href/label/icon/key. نام فیلدهای دیگر یاد دادن خطای کاربر است، نه محدود کردن او.',
                    'decl' => ['title' => 'نوشته‌ها', 'path' => '/admin/blog', 'icon' => 'pencil', 'permission' => 'posts.view'],
                ],
                [
                    'why' => 'اینجا prefix لازم است چون نام پرمیشن **کامل** در مانیفست نوشته می‌شود. (در `permissions[].module` برعکس است: فقط بخش آخر را بنویسید، چون هسته prefix اسلاگ را خودش اضافه می‌کند.)',
                    'decl' => ['key' => 'blog', 'label' => 'نوشته‌ها', 'href' => '/admin/blog', 'permission' => 'blog.view'],
                ],
            ],
        ],

        // ⛔ K3.9 — **عمداً schema نگرفت.** دو دلیل مستقل، و هرکدام به‌تنهایی کافی:
        //
        //  ۱) مصرف‌کننده ندارد. هیچ رجیستری و هیچ رندرری `admin.dashboard_slot`
        //     را نمی‌خواند (جست‌وجوی کلید و نقطه در `app/` و فرانت: هیچ).
        //  ۲) خودِ نقطه `deferred` و `closed` است — یعنی محصول آگاهانه گفته «این
        //     شکلِ نهایی هنوز قطعی نیست».
        //
        // نوشتنِ schema برایش دقیقاً همان چیزی است که X2 آن را «بدافزار» نامید:
        // مثال معتبر + validator سبز + نصب موفق + بعد هیچی رندر نمی‌شود. یعنی
        // دروغِ ساختاریافته. تا وقتی renderer ساخته نشده، `fields => []` +
        // `no_schema` عمداً **همه‌چیز** را رد می‌کند و همین درست است.
        //
        // راه درست وقتی renderer ساخته شد: schema را از روی همان رندرر بنویسید،
        // نه از روی حدس — وگرنه دوباره سه ماه بعد دروغ می‌گوید.
        'admin.dashboard_slot' => [
            'label_fa' => 'ویجت داشبورد مدیریت',
            'label_en' => 'Admin dashboard widget',
            'desc_fa' => 'افزودن کارت به صفحهٔ اصلی پنل.',
            'desc_en' => 'Add a card to the admin dashboard home page.',
            'status' => 'deferred',
            'openness' => 'closed',
            'openness_why' => 'نه primitive دارد نه مصرف‌کننده؛ نوشتن schema برایش دروغ ساختاریافته است.',
            'openness_why_en' => 'It has neither a primitive nor a consumer; writing a schema for it would be a structured lie.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => []],
            'open_schema' => false,
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'این نقطه deferred است — اعلامش در مانیفست خطاست تا وقتی renderer واقعی ساخته شود.',
                    'decl' => ['key' => 'sales_summary', 'title_fa' => 'خلاصهٔ فروش'],
                ],
            ],
        ],

        // K6.7 — تصمیم ۲۰۲۶-۰۹-۲۷: این نقطه از فهرست نقاط اتصال **حذف شد**.
        //
        // چرا: `fields => []` با `open_schema => false` یعنی `no_schema` خطای سخت،
        // پس هیچ مانیفستی قانوناً نمی‌توانست آن را پر کند. یعنی نقطه‌ای که نه
        // می‌شود اعلامش کرد و نه مصرف‌کننده‌ای دارد — وعده‌ای بدون پشتوانه. و
        // `example_ok` خودِ این نقطه هم چنین اعلانی بود که validator رد می‌کرد،
        // پس به کاربر «مثال معتبر» نشان می‌داد که خودِ سیستم ردش می‌کند.
        //
        // مسیر درست، کلید سطح‌بالای مانیفست است — دقیقاً مثل `widgets` و
        // `page_types` که هر دو زنده‌اند. دلیل ساختاری: میکرو-اسکیمای
        // `panel.extensions` با `max_depth => 1` می‌سنجد و اسکیمای JSON شیء تودرتو
        // است، پس جا دادنش آنجا یعنی بالا بردن سقف عمق برای همهٔ نقاط.
        // مصرف‌کننده: `ManifestRegistry::pluginSettingsSchemas()`.
        //
        // ⛔ K3.9 — **عمداً schema نگرفت: هیچ مصرف‌کننده‌ای ندارد.**
        //
        // تأیید در کد: `pluginSettingsSchemas()` فقط `manifest.settings` را
        // می‌خواند، `pageRegistry()` فقط `manifest.pages` و `manifest.admin.pages`
        // را، `widgetSchemas()` فقط `manifest.widgets` را، و
        // `pluginTools()` فقط `manifest.tools` را. یعنی **کانالِ زنده برای همهٔ
        // این نقطه‌ها یک کلید سطح‌بالای مانیفست است** و `panel.extensions` فقط
        // برای دو نقطه خوانده می‌شود: `site.page_type` (تنها `live`) و
        // `site.header_widget`/`site.footer_widget` (از K6.4). ساختن schema برای
        // این نقطه یعنی معرفی **سومین** کانال، درست وقتی که I1-b (تنها‌کردن
        // `panel.extensions` به‌عنوان شکل واحد) هنوز تصمیم‌نشده است — یعنی یک
        // تصمیم معماری که جای این ردیف نیست.
        //
        // ثبت‌نشده به‌صورت `deprecated` عمداً: حذف کامل از فهرست، راهنمای
        // توسعه‌دهنده و هر ابزاری را که این آرایه را می‌خواند می‌شکند، و آن
        // تصمیمِ خودِ مالک محصول است نه چیزی که یک commit این‌جا بگیرد.
        'admin.settings_schema' => [
            'label_fa' => 'فرم تنظیمات اختصاصی',
            'label_en' => 'Custom settings form',
            'desc_fa' => 'افزودن صفحهٔ تنظیمات که از روی JSON Schema رندر می‌شود (بدون کد فرنت).',
            'desc_en' => 'Add a settings page rendered from JSON Schema (no frontend code).',
            'status' => 'deprecated',
            'openness' => 'schema_defined',
            'openness_why' => 'حذف‌شده: به کلید سطح‌بالای `settings` در مانیفست منتقل شد (`ManifestRegistry::pluginSettingsSchemas()`)، چون میکرو-اسکیمای اینجا با `max_depth => 1` اسکیمای JSON تودرتو را حمل نمی‌کند.',
            'openness_why_en' => 'Removed: moved to the top-level `settings` manifest key (`ManifestRegistry::pluginSettingsSchemas()`), because the micro-schema here cannot carry a nested JSON schema with `max_depth => 1`.',
            'since' => '1.2.0',
            'deprecated' => [
                'since' => '1.6.0',
                'replaced_by' => 'manifest.settings',
                'reason_fa' => 'اعلان از راه `panel.extensions` برای اسکیمای تودرتو ناممکن بود. از `1.6.0` به‌جای این نقطه، کلید سطح‌بالای `settings` را در مانیفست بنویسید.',
            ],
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_core_name', 'title_fa']],
            'open_schema' => false,
            // عمداً خالی: نقطه‌ای که `fields` ندارد نمی‌تواند مثال «معتبر» داشته
            // باشد، چون validator همان مثال را با `no_schema` رد می‌کند.
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'هر چیزی که به کاربر نشان داده می‌شود `title_fa` لازم دارد، وگرنه UI متن خالی می‌خواند.',
                    'decl' => ['key' => 'general', 'group' => 'عمومی'],
                ],
            ],
        ],

        // ⛔ K3.9 — **عمداً schema نگرفت: نه renderer دارد، نه کانالِ زنده.**
        //
        // تأیید در کد: `ManifestRegistry::pageRegistry()` فقط `manifest.pages` و
        // `manifest.admin.pages` را می‌خواند و **هرگز** `panel.extensions` را برای
        // این نقطه نمی‌کاود (برخلاف `widgetSchemas()` که K6.4 کانال دوم را دارد).
        // یعنی renderer دارد ولی این نقطه را نمی‌بیند — بدترین حالت ممکن: اعلانی
        // که اعتبارسنجی می‌شود و بعد بی‌سروصدا نادیده گرفته می‌شود.
        //
        // وضعیت `declared_only` و `no_schema` عمداً می‌ماند: این یک قابلیتِ
        // خواسته‌شدهٔ آینده است، نه چیزی که باید حذف شود. K3.9 هم دقیقاً به همین
        // دلیل متوقف است — TASKS.md هم یادداشت کرده که تا رفتار `next/dynamic`
        // روشن نشود، این نقطه برای افزونهٔ عمومی رد شده است.
        'admin.data_collection' => [
            'label_fa' => 'جدول دادهٔ اختصاصی',
            'label_en' => 'Custom data table',
            'desc_fa' => 'افزودن صفحهٔ فهرست/ویرایش برای دادهٔ اختصاصی پلاگین، از روی اعلان entity + schema.',
            'desc_en' => 'Add a list/edit page for plugin-specific data from an entity + schema declaration.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'ستون DataTable امروز تابع می‌خواهد، پس تا اعلانی نشده این نقطه روی کاغذ است.',
            'openness_why_en' => 'Today DataTable columns need a function, so until declared this point exists only on paper.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['entity_prefix', 'title_fa']],
            'open_schema' => false,
            // K6.7 — حذف شد. این نقطه `declared_only` است و نه میکرو-اسکیمایی
            // دارد (`fields => []`) و نه کلید سطح‌بالای متناظری در مانیفست که
            // کدی آن را بخواند — جست‌وجوی کلیدهای واقعی مصرف‌شده هیچ
            // `data_collections` ای نشان نمی‌دهد. پس مثال قبلی اعلانی بود که
            // `no_schema` ردش می‌کرد و در عمل چیزی برای نصب‌کردن وجود نداشت.
            // وضعیت `declared_only` عمداً می‌ماند: این یک قابلیت خواسته‌شدهٔ
            // آینده است، نه چیزی که باید حذف شود.
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'نام جدول باید دقیقاً {manifest.slug}_{x} باشد، وگرنه نقض K1.5.3 می‌شود.',
                    'decl' => ['entity' => 'post', 'title_fa' => 'نوشته‌ها'],
                ],
            ],
        ],

        'site.header_widget' => [
            'label_fa' => 'ویجت هدر سایت',
            'label_en' => 'Site header widget',
            'desc_fa' => 'افزودن بلوک به هدر صفحات عمومی سایت.',
            'desc_en' => 'Add a block to the public site header.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'نوع ویجت قفل است چون هسته رندرش می‌کند؛ تنظیماتش از block_type خودِ پلاگین می‌آید.',
            'openness_why_en' => 'The widget type is locked because the core renders it; its settings come from the plugin\'s own block_type.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            // ⭐ K3.9 — schema نوشته شد چون **مصرف‌کنندهٔ واقعی** دارد:
            // `ManifestRegistry::widgetSchemas()` کانال `panel.extensions` را از
            // K6.4 می‌خواند و فرانت از `/v1/admin/widgets/schema` رندرش می‌کند.
            //
            // ⛔ **`schema` و `ui` اینجا نیستند و نمی‌توانند باشند.** هر دو آبجکت
            // JSON تودرتو هستند و واژگان micro-grammar (`GRAMMAR_TYPES`) هیچ نوعِ
            // آبجکتی ندارد. بالا بردن `depth` برای همهٔ نقاط یا باز کردن گرامر، هر
            // دو تصمیم معماری‌اند نه کارِ این ردیف — همان دیواری که K6.7 برای
            // `admin.settings_schema` ثبت کرد. راهِ درست برای فرمِ واقعی، کانال
            // سطح‌بالای `manifest.widgets.header.<type>.schema` است که از
            // micro-schema عبور نمی‌کند.
            'schema' => [
                'fields' => [
                    'type' => ['type' => 'string', 'required' => true, 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                    // نامش `title` است نه `title_fa`: مصرف‌کننده (`widgetSchemas`)
                    // و کانال سطح‌بالا هر دو `title` می‌خوانند. نامی که مصرف نشود
                    // «سازگار به نظر می‌رسد» ولی همیشه خالی می‌ماند.
                    'title' => ['type' => 'string', 'required' => false, 'maxLength' => 80],
                    'description' => ['type' => 'string', 'required' => false, 'maxLength' => 200],
                ],
                'cross' => ['no_core_name'],
            ],
            'open_schema' => false,
            // K6.7 — این مثال معتبر وقتی اضافه شد که schema هنوز نبود، یعنی هر
            // اعلانی با `no_schema` رد می‌شد و مثالی که خودِ سیستم رد می‌کرد به
            // نویسنده نشان داده می‌شد. K3.9 schema را پر کرد، پس حالا مثالِ معتبر
            // واقعاً معتبر است و این تست هم آن را اعتبارسنجی می‌کند.
            'example_ok' => [
                [
                    'type' => 'trust_bar', 'title' => 'نوار اعتماد', 'description' => 'نمادهای اعتماد و شمار تماس',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'برخورد با نام هسته بی‌صدا رد می‌شود، پس باید خطا بدهد نه اینکه نصب شود و دیده نشود.',
                    'decl' => ['type' => 'nav', 'title' => 'منوی من'],
                ],
                [
                    'why' => 'نقطه خودش هدر است؛ `area` فیلد تکراری و فقط اختلاف‌ساز است.',
                    'decl' => ['type' => 'trust_bar', 'title' => 'نوار اعتماد', 'area' => 'header'],
                ],
                [
                    'why' => '⛔ `schema` آبجکت JSON تودرتو است و micro-grammar چنین چیزی ندارد؛ برای فرمِ واقعی از کانال سطح‌بالای `manifest.widgets.header.<type>.schema` استفاده کنید. اگر این‌جا پذیرفته می‌شد، بسته سبز می‌شد ولی `SchemaForm` هیچ inputی برایش نمی‌ساخت.',
                    'decl' => ['type' => 'shop_cart', 'title' => 'سبد خرید', 'schema' => ['type' => 'object', 'properties' => ['coupon' => ['type' => 'string']]]],
                ],
            ],
        ],

        'site.footer_widget' => [
            'label_fa' => 'ویجت فوتر سایت',
            'label_en' => 'Site footer widget',
            'desc_fa' => 'افزودن بلوک به فوتر صفحات عمومی سایت.',
            'desc_en' => 'Add a block to the public site footer.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'همان منطق هدر — نوع قفل، تنظیمات باز.',
            'openness_why_en' => 'Same logic as the header — locked type, open settings.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            // K3.9 — همان داستان هدر: مصرف‌کننده `widgetSchemas()` دارد، پس schema
            // نوشته شد؛ و همان دلیل، نبودِ `schema`/`ui` اینجا هم یک تصمیم است
            // نه یک فراموشی. ناحیه از خودِ نقطه می‌آید، پس `area` فیلدی نیست.
            'schema' => [
                'fields' => [
                    'type' => ['type' => 'string', 'required' => true, 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                    'title' => ['type' => 'string', 'required' => false, 'maxLength' => 80],
                    'description' => ['type' => 'string', 'required' => false, 'maxLength' => 200],
                ],
                'cross' => ['no_core_name'],
            ],
            'open_schema' => false,
            // K6.7 — این مثال معتبر هم موقعی اضافه شد که schema نبود، یعنی خودِ
            // validator ردش می‌کرد. K3.9 schema را پر کرد، پس حالا واقعاً معتبر است.
            'example_ok' => [
                [
                    'type' => 'shop_news', 'title' => 'تازه‌های فروشگاه', 'description' => 'چهار خبر آخر',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'برخورد با ویجت هسته نصب را سبز نشان می‌دهد ولی ویجت اصلاً رندر نمی‌شود.',
                    'decl' => ['type' => 'copyright', 'title' => 'کپی‌رایت من'],
                ],
                [
                    'why' => 'ناحیه از خودِ نقطه خوانده می‌شود؛ `area` فقط اختلاف‌ساز است و رجیستری آن را نادیده می‌گیرد.',
                    'decl' => ['type' => 'shop_news', 'title' => 'تازه‌های فروشگاه', 'area' => 'footer'],
                ],
            ],
        ],

        'site.page_type' => [
            'label_fa' => 'نوع صفحهٔ سایت',
            'label_en' => 'Site page type',
            'desc_fa' => 'تعریف نوع محتوایی جدید که صفحات عمومی سایت می‌توانند از آن استفاده کنند.',
            'desc_en' => 'Define a new content type that public site pages can use.',
            'status' => 'live',
            'openness' => 'open_vocabulary',
            'openness_why' => 'خواستهٔ صریح کارفرما؛ داده در page.meta (ستون JSON آزاد) می‌نشیند و خودش رندر نمی‌شود.',
            'openness_why_en' => 'Explicit owner request; data lives in page.meta (a free JSON column) and is not rendered by itself.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => [
                'fields' => [
                    'slug' => ['type' => 'string', 'required' => true, 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                    'title_fa' => ['type' => 'string', 'required' => true, 'maxLength' => 120],
                    'desc_fa' => ['type' => 'string', 'required' => false, 'maxLength' => 400],
                    'default_blocks' => ['type' => 'string_list', 'required' => false, 'maxItems' => 20, 'itemMaxLength' => 40, 'itemPattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                ],
                'cross' => ['title_fa'],
            ],
            'open_schema' => true,
            'example_ok' => [
                [
                    'slug' => 'product', 'title_fa' => 'محصول', 'desc_fa' => 'صفحهٔ تک‌محصول',
                    'default_blocks' => ['hero', 'text', 'cta'],
                    'price_label' => 'قیمت',
                    'badge' => 'تخفیف',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'گرامر micro-schema آبجکت تودرتو ندارد؛ SchemaForm هم فرمی برایش نمی‌سازد و نویسنده یک input متنی می‌بیند.',
                    'decl' => ['slug' => 'product', 'title_fa' => 'محصول', 'specs' => ['weight' => 10]],
                ],
                [
                    'why' => 'markup آزاد ممنوع است — HTML خام در همان سطح خطر JS است.',
                    'decl' => ['slug' => 'product', 'title_fa' => '<b>محصول</b>'],
                ],
            ],
        ],

        // ⛔ K3.9 — **عمداً schema نگرفت.** لیستِ نقاطی که K3.9 بررسی کرد این‌ها
        // بود: `site.block_type`, `site.header_widget`, `site.footer_widget`,
        // `admin.settings_schema`, `admin.page_registry`, `admin.dashboard_slot`,
        // `admin.data_collection`. از آن هفت تا، **فقط دو تا** مصرف‌کنندهٔ واقعیِ
        // این نقطه داشتند (همان دو ویجت) و schema گرفتند؛ بقیه یا مصرف‌کننده
        // ندارند یا کانالِ زنده‌شان جای دیگری است — و برای هر کدام بالای خودِ
        // نقطه دلیلش نوشته شده.
        //
        // `site.block_type` سخت‌ترینِ آن‌هاست: مصرف‌کننده ندارد **و** عمداً ندارد.
        // `PluginPageContract` (docblock خطوط ۲۵ تا ۳۱) می‌گوید
        // `lib/block-type.ts` برای هر type ناشناخته «renders nothing» می‌دهد،
        // پس واگذاری `type` به افزونه یعنی برگرداندن گزینهٔ «کد در مرورگر» از
        // در پشتی. `allowedBlockTypes()` هم فقط `config('blocks')` را برمی‌گرداند
        // — یعنی رجیستری هرگز مانیفست افزونه را برای این نقطه نمی‌خواند.
        'site.block_type' => [
            'label_fa' => 'نوع بلوک صفحه',
            'label_en' => 'Page block type',
            'desc_fa' => 'تعریف نوع بلوک جدید برای ویرایشگر صفحه. رندر از روی JSON Schema.',
            'desc_en' => 'Define a new block type for the page editor. Rendering is driven by JSON Schema.',
            'status' => 'declared_only',
            'openness' => 'open_vocabulary',
            'openness_why' => 'تنها جایی که «بی‌نهایت» واقعاً ارزش دارد؛ ولی گرامر بسته می‌ماند.',
            'openness_why_en' => 'The only place where "infinite" is truly worth it; but the grammar stays closed.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_core_name', 'title_fa']],
            // K6.7 — شکاف نیت در برابر پیاده‌سازی، هنوز حل‌نشده و آگاهانه.
            //
            // نیت ثبت‌شده در `openness_why` بالا «واژگان باز، گرامر بسته» است: یعنی
            // `type` آزاد باشد ولی نام فیلدها whitelisted. ولی ساختار فعلی این
            // ترکیب را نمی‌تواند بیان کند — `open_schema` فقط دو حال دارد:
            //   • false ⇒ `fields` باید بسته پر شود، وگرنه `no_schema` خطای سخت
            //   • true  ⇒ هیچ فیلدی بررسی نمی‌شود، حتی گرامر بسته
            // با `fields => []` و `open_schema => false` نتیجه این است که **هر**
            // اعلانی رد می‌شود، یعنی نقطه عملاً غیرقابل‌استفاده است.
            //
            // رفع این شکاف یک تصمیم معماری است، نه کاری که این‌جا انجام شود:
            //   ۱) `fields` را با نام فیلدهای واقعی block type پر کن (type/title_fa/render/…)
            //      و گرامر بسته را واقعاً بساز — نیازمند تعیین فیلدهای block type
            //   ۲) `open_schema => true` و واژگان را کاملاً باز کن — سقف‌های max
            //      همچنان اعمال می‌شوند ولی گرامر بسته از دست می‌رود
            //
            // چون block typeها در `BlockRenderer` و رجیستری خود مصرف می‌شوند،
            // «فیلدهای واقعی» را نمی‌شود حدس زد؛ باید از همان رجیستری خوانده شود.
            // تا آن تصمیم، `example_ok` خالی می‌ماند چون هر مثالی که این‌جا گذاشته
            // شود با `no_schema` رد می‌شود — و نمایش مثالی که سیستم خودش ردش می‌کند
            // بدتر از نبود مثال است.
            //
            // TODO(K6.7): یکی از دو گزینهٔ بالا را انتخاب و `fields` را پر کن.
            'open_schema' => false,
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'BlockRenderer برخورد `type` را بی‌صدا `continue` می‌کند؛ باید خطا بدهد.',
                    'decl' => ['type' => 'hero', 'title_fa' => 'هیروی من'],
                ],
            ],
        ],

        'core.service_provider' => [
            'label_fa' => 'پیاده‌سازی رابط هسته',
            'label_en' => 'Core interface implementation',
            'desc_fa' => 'جایگزینی پیاده‌سازی یک interface هسته (درگاه پرداخت، چابکان، جستجو). فهرست interface‌ها بسته است و کلاس باید در ریشهٔ Pishdad\\Plugins\\ باشد. توجه: اینکه کلاس واقعاً آن رابط را پیاده می‌کند در لحظهٔ اعتبارسنجی بررسی نمی‌شود (کد افزونه هنوز بارگذاری نشده)؛ رجیستری آن را می‌سنجد و بی‌صدا نادیده نمی‌گیرد بلکه لاگ می‌کند.',
            'desc_en' => 'Replace the implementation of a core interface (payment gateway, hosting, search). The interface list is closed and the class must live under the Pishdad\\Plugins\\ root. Note: whether the class actually implements that interface is not checked at validation time (the plugin code is not loaded yet); the registry checks it and logs instead of silently ignoring it.',
            // binding زنده و مؤثر است، ولی تا K5.3 (بارگذار PSR-4) کلاس افزونه
            // بارگذاری نمی‌شود. یعنی اعلان معتبر است ولی هنوز اثر ندارد —
            // و همین باید صریح گفته شود.
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'فهرست interface‌های قابل‌جایگزینی بسته و کوچک است (سه مورد)، ولی نام کلاس پیاده‌سازی باز است تا افزونه بتواند کلاس خودش را بنویسد.',
            'openness_why_en' => 'The list of overridable interfaces is closed and small (three), but the implementation class name is open so the plugin can write its own class.',
            'since' => '1.5.0',
            'schema_version' => 1,
            'max' => ['declarations' => 3, 'bytes' => 1024, 'depth' => 1, 'properties' => 4],
            'schema' => ['fields' => [
                'interface' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
                'class' => ['type' => 'string', 'required' => true, 'maxLength' => 200],
            ], 'cross' => ['title_fa']],
            'open_schema' => false,
            'example_ok' => [
                [
                    'interface' => 'App\\Services\\Billing\\PaymentGatewayInterface',
                    'class' => 'Pishdad\\Plugins\\Zarinpal\\Gateway',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'کلاس باید داخل ریشهٔ اجباری افزونه باشد. کلاسی که در ریشهٔ `App\\` بنشیند مال هسته است و رجیستری آن را رد می‌کند.',
                    'decl' => [
                        'interface' => 'App\\Services\\Billing\\PaymentGatewayInterface',
                        'class' => 'App\\Services\\Billing\\ZarinpalStubDriver',
                    ],
                ],
                [
                    'why' => 'فقط سه interface قابل جایگزینی وجود دارد. هر چیز دیگری — از جمله کلاس‌های داخلی لاراول — قابل override نیست.',
                    'decl' => [
                        'interface' => 'Illuminate\\Contracts\\Container\\Container',
                        'class' => 'Pishdad\\Plugins\\Evil\\Container',
                    ],
                ],
                // عمداً نمونه‌ای برای «کلاس رابط را پیاده نمی‌کند» نیست: این
                // قاعده در مرحلهٔ اعتبارسنجی **قابل بررسی نیست** چون کد افزونه
                // هنوز بارگذاری نمی‌شود (K5.3). رجیستری آن را می‌گیرد و لاگ
                // می‌کند. نوشتنش اینجا یعنی وعدهٔ اعتبارسنجیِ که وجود ندارد.
            ],
        ],

        // ⭐ K3.11 — schema این نقطه از روی **مصرف‌کنندهٔ واقعی** نوشته شد، نه از
        // روی حدس. مصرف‌کننده `ManifestRegistry::normalizePluginTool()` است
        // (`pluginTools()`) و رندرش `PluginToolsDrawer.tsx` در فرانت — هر دو در
        // K6.2 زنده شدند، ولی این نقطه تا امروز `deferred` مانده بود.
        //
        // ⚠️ نکته‌ای که این schema را از یک فهرستِ حدسی نجات داد: **نه `order`
        // وجود دارد و نه `body`.** ترتیب drawer از ترتیب merge رجیستری می‌آید و
        // متن کارت از `description` خوانده می‌شود. اگر اینجا `order`/`body`
        // اعلام می‌شد، `validateDeclaration` آن‌ها را می‌پذیرفت، بسته سبز می‌شد و
        // هیچ‌کدام هرگز خوانده نمی‌شدند — دقیقاً همان «دروغ ساختاریافته» که
        // K3.8 برایش بسته شد. یک `example_bad` هر دو را نام برد تا کسی دوباره
        // اضافه‌شان نکند.
        //
        // کانال زنده **سطح‌بالا** است (`manifest.tools.*`) دقیقاً مثل `widgets` و
        // `settings`؛ این میکرو-اسکیما فقط پنجرهٔ دوم (`panel.extensions`) را
        // پوشش می‌دهد.
        'admin.plugin_tools' => [
            'label_fa' => 'ابزارهای پلاگین در هدر پنل',
            'label_en' => 'Plugin tools in the panel header',
            'desc_fa' => 'یک کادر مستطیل مستقل در drawer ابزارهای هدر پنل. داده و schema، نه React.',
            'desc_en' => 'An independent rectangular card in the panel header tools drawer. Data and schema, not React.',
            'status' => 'declared_only',
            'openness' => 'closed',
            'openness_why' => 'خروجی یک کادر با عنوان و محتوای schema-driven است؛ هیچ آدرسی در آن نیست. فهرست فیلدها بسته است چون هر فیلد تازه باید اول در رندرر مصرف شود — غیر از آن، نقطه دوباره «قول بی‌پشتوانه» می‌شود.',
            'openness_why_en' => 'The output is a card with a title and schema-driven content; there is no address in it. The field list is closed because every new field must first be consumed by the renderer — otherwise the point becomes an unbacked promise again.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 2048, 'depth' => 1, 'properties' => 8],
            'schema' => [
                'fields' => [
                    'key' => ['type' => 'string', 'required' => true, 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$'],
                    'title_fa' => ['type' => 'string', 'required' => true, 'maxLength' => 80],
                    'icon' => ['type' => 'string', 'required' => false, 'maxLength' => 16],
                    'description' => ['type' => 'string', 'required' => false, 'maxLength' => 200],
                    // `hrefFor()` در فرانت این شناسه را به `page.path` می‌چسباند،
                    // پس **عدد صحیح** است نه رشته. رجیستری هم فقط `int > 0` را
                    // نگه می‌دارد و رشتهٔ عددی را بی‌صدا `null` می‌کند — یعنی
                    // «کار کرد ولی به جایی نمی‌رسد».
                    'notification_id' => ['type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 2147483647],
                    'permission' => ['type' => 'string', 'required' => false, 'maxLength' => 120],
                ],
                'cross' => ['no_href', 'title_fa', 'permission_prefix'],
            ],
            'open_schema' => false,
            'example_ok' => [
                [
                    'key' => 'sync', 'title_fa' => 'همگام‌سازی', 'icon' => '↻',
                    'description' => 'کشورها و موجودی را با مرکز هم‌گام کن',
                    'notification_id' => 12, 'permission' => 'plugin:shop:sync.run',
                ],
            ],
            'example_bad' => [
                [
                    'why' => 'این نقطه آدرس ندارد. اگر لینک لازم است `notification_id` بدهید تا هسته خودش از راه `pageRegistry()` نشانی بسازد — وگرنه افزونه می‌تواند به هر مسیری لینک بدهد، از جمله مسیری که خودش ثبت نکرده است.',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'href' => '/admin/plugin/sync'],
                ],
                [
                    'why' => '⛔ **`order` و `body` وجود ندارند.** نه در رجیستری خوانده می‌شوند و نه در drawer. نوشتنشان یعنی بسته سبز می‌شود و هیچ اثری ندارد — همان «نصب شد ولی دیده نشد». ترتیب از merge رجیستری می‌آید و متن کارت از `description`.',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'order' => 10, 'body' => ['text' => 'سلام']],
                ],
                [
                    'why' => 'بدون `title_fa` رجیستری سطر را بی‌صدا حذف می‌کند (`normalizePluginTool`)، پس باید خطا بدهد نه اینکه نصب موفق باشد و کارت غیب بماند.',
                    'decl' => ['key' => 'sync', 'icon' => '↻'],
                ],
                [
                    'why' => '`notification_id` در URL می‌رود، پس باید عدد صحیح باشد. رشتهٔ `"12"` در رجیستری `null` می‌شود و ابزار بی‌مقصد نمایش داده می‌شود — «کار کرد ولی جایی نمی‌رسد».',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'notification_id' => '12'],
                ],
                [
                    'why' => 'پرمیشن باید کامل نوشته شود، یعنی با `plugin:{اسلاگ}:` شروع شود. (در `permissions[].module` برعکس است: فقط بخش آخر را بنویسید، چون هسته prefix اسلاگ را خودش اضافه می‌کند.)',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'permission' => 'sync.run'],
                ],
                [
                    'why' => 'کلید drawer در جدول و در React key می‌نشیند، پس باید الگوی کلیدِ رایج پروژه را داشته باشد. حرف بزرگ و فاصله در نامِ فایل/کلید CSS می‌شکند.',
                    'decl' => ['key' => 'Sync Jobs', 'title_fa' => 'همگام‌سازی'],
                ],
            ],
        ],

        // ⛔ K3.9 — **عمداً schema نگرفت: نه renderer دارد، نه کانالِ زنده.**
        //
        // این با `admin.settings_schema` فرق دارد و فرقش مهم است: آن یکی
        // renderer داشت که به کانالِ دیگری منتقل شده بود (پس `deprecated`)، ولی
        // این یکی **اصلاً** خوانده نمی‌شود. `ManifestRegistry::pageRegistry()` در
        // K7.18 از `manifest.pages` و `manifest.admin.pages` می‌خواند و بلوک‌ها
        // را با `PluginPageContract::check()` می‌سنجد — و آن فیلدهای بیشتری
        // (`blocks`) دارند که اصلاً در میکرو-اسکیما جا نمی‌شوند.
        //
        // پس schema نوشتن یا یعنی سومین کانال (پیش از تصمیم I1-b) یا یعنی schema
        // ای که نیمی از شکل واقعی را پوشش می‌دهد. هر دو بدتر از نبودن است.
        'admin.page_registry' => [
            'label_fa' => 'صفحهٔ اختصاصی پلاگین',
            'label_en' => 'Plugin-specific page',
            'desc_fa' => 'ثبت یک مسیر زیر /admin/ که از روی page descriptor رندر می‌شود.',
            'desc_en' => 'Register a path under /admin/ that renders from a page descriptor.',
            'status' => 'deferred',
            'openness' => 'schema_defined',
            'openness_why' => 'فقط مسیر + پرمیشن + layout؛ render از page descriptor می‌آید نه از پلاگین.',
            'openness_why_en' => 'Path + permission + layout only; rendering comes from the page descriptor, not the plugin.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_href', 'permission_prefix', 'title_fa']],
            'open_schema' => false,
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'این نقطه deferred است تا تکلیف رفتارش با next/dynamic روشن شود.',
                    'decl' => ['title_fa' => 'سفارش‌ها', 'permission' => 'plugin:shop:orders.view', 'layout' => 'default'],
                ],
            ],
        ],
    ];

    /**
     * نقاط اتصالی که فقط در حالت سطح ۱ (کامپوننت pre-compiled) ممکن‌اند و
     * در v1 عمداً پشتیبانی نمی‌شوند. وجودشان در مانیفست خطاست، نه بی‌صدا.
     *
     * جدا از `status => 'deferred'` نگه داشته می‌شوند چون این‌ها **هرگز** وجود
     * نداشته‌اند (K1.5.8)؛ آن یکی «هنوز نیامده» است.
     */
    public const DEFERRED_EXTENSION_POINTS = [
        'admin.component' => 'کامپوننت React — در v1 رد شد (در مرورگر مدیر اجرا می‌شود ⇒ تصاحب پنل)',
    ];

    /**
     * ECO3 — دسته‌بندی کدهای خطای قابل‌برخورد برای توسعه‌دهندهٔ پلاگین/قالب.
     *
     * چرا دسته و نه متنِ جدا برای هر کد: کدهای هم‌خانواده یک درمان دارند
     * (مثلاً همهٔ `path.*`). تکرار ۱۰۰ بارِ یک متن یعنی ۱۰۰ جای drift. UI کدِ
     * خام را از `ERROR_CODES` می‌خواند و متنِ رفع را از همین دسته.
     *
     * @var array<string, array{label_fa: string, label_en: string, remedy_fa: string, remedy_en: string}>
     */
    public const ERROR_CODE_GROUPS = [
        'zip' => [
            'label_fa' => 'بسته و فشرده‌سازی',
            'label_en' => 'Archive & compression',
            'remedy_fa' => 'ZIP استاندارد بسازید، سقف حجم/تعداد فایل را رعایت کنید و از آرشیو دوبارهٔ فایل‌های تکراری بپرهیزید.',
            'remedy_en' => 'Build a standard ZIP, respect the size/file-count limits, and avoid re-archiving duplicate entries.',
        ],
        'path' => [
            'label_fa' => 'مسیرهای داخل بسته',
            'label_en' => 'Package paths',
            'remedy_fa' => 'فقط مقصدهای فهرست‌شده را بگذارید؛ نام مجاز، بدون symlink و بدون «..» و بدون تکرارِ حساس‌به‌حروف.',
            'remedy_en' => 'Keep only the listed destinations; safe names, no symlinks, no "..", and no case-insensitive duplicates.',
        ],
        'manifest' => [
            'label_fa' => 'مانیفست و امضا',
            'label_en' => 'Manifest & signature',
            'remedy_fa' => 'manifest.json را دقیقاً در ریشهٔ ZIP بگذارید و فیلدهای اجباری و امضای معتبر را کامل کنید.',
            'remedy_en' => 'Place manifest.json exactly at the ZIP root and complete the required fields and a valid signature.',
        ],
        'signature' => [
            'label_fa' => 'امضای Ed25519',
            'label_en' => 'Ed25519 signature',
            'remedy_fa' => 'امضای detached Ed25519 مانیفست (بدون خودِ فیلد signature) را با کلید ثبت‌شده بازتولید کنید.',
            'remedy_en' => 'Re-create the detached Ed25519 signature of the manifest (without the signature field itself) using the registered key.',
        ],
        'package' => [
            'label_fa' => 'اندازهٔ بسته',
            'label_en' => 'Package size',
            'remedy_fa' => 'فایل‌های غیرضروری را حذف یا فشرده کنید.',
            'remedy_en' => 'Remove or compress unnecessary files.',
        ],
        'panel' => [
            'label_fa' => 'نقاط اتصال پنل',
            'label_en' => 'Panel extension points',
            'remedy_fa' => 'نقطهٔ اتصال را از فهرست مجاز انتخاب کنید و اعلان را با شکل درست بنویسید.',
            'remedy_en' => 'Pick an extension point from the allowed list and write the declaration in the correct shape.',
        ],
        'pages' => [
            'label_fa' => 'اعلان صفحه',
            'label_en' => 'Page declarations',
            'remedy_fa' => 'صفحه را با path/title/permission معتبر و layout مجاز اعلام کنید.',
            'remedy_en' => 'Declare the page with a valid path/title/permission and an allowed layout.',
        ],
        'blocks' => [
            'label_fa' => 'اعلان بلوک',
            'label_en' => 'Block declarations',
            'remedy_fa' => 'بلوک را با type مجاز و data معتبر اعلام کنید.',
            'remedy_en' => 'Declare the block with an allowed type and valid data.',
        ],
        'db' => [
            'label_fa' => 'جدول‌های دیتابیس',
            'label_en' => 'Database tables',
            'remedy_fa' => 'نام جدول را خام و بدون پیشوند بدهید؛ پیشوند slug_ را خودتان ننویسید و سقف‌ها را رعایت کنید.',
            'remedy_en' => 'Give raw unprefixed table names; do not write the slug_ prefix yourself and respect the limits.',
        ],
        'requires' => [
            'label_fa' => 'شرط نسخهٔ هسته',
            'label_en' => 'Core version requirement',
            'remedy_fa' => 'شرط requires.core را در شکل پذیرفته‌شده بنویسید و با نسخهٔ نصب هم‌خوان کنید.',
            'remedy_en' => 'Write requires.core in an accepted form and match it to the installed core version.',
        ],
        'core' => [
            'label_fa' => 'نسخهٔ هسته',
            'label_en' => 'Core version',
            'remedy_fa' => 'نسخهٔ هسته را به‌روز کنید یا requires.core را تنظیم کنید.',
            'remedy_en' => 'Update the core version or adjust requires.core.',
        ],
        'core_contract' => [
            'label_fa' => 'سازگاری با قرارداد هسته',
            'label_en' => 'Core contract compatibility',
            'remedy_fa' => 'فیلدهای since و schema_version را نسبت به core_contract_version همین نصب درست بدهید.',
            'remedy_en' => 'Set since and schema_version correctly against this install\'s core_contract_version.',
        ],
        'install' => [
            'label_fa' => 'نصب روی سرور',
            'label_en' => 'Server-side installation',
            'remedy_fa' => 'بسته را روی سرور بررسی کنید: دسترسی مسیر، فضای ذخیره، و درستی امضا/هش.',
            'remedy_en' => 'Inspect the package on the server: path permissions, storage space, and signature/hash integrity.',
        ],
        'devmode' => [
            'label_fa' => 'حالت توسعه‌دهنده',
            'label_en' => 'Developer mode',
            'remedy_fa' => 'در حالت توسعه‌دهنده امضای گم‌شده فقط هشدار است؛ پیش از انتشار امضا را اضافه کنید.',
            'remedy_en' => 'In developer mode a missing signature is only a warning; add the signature before release.',
        ],
    ];

    /**
     * ECO3 — فهرست کامل کدهای خطای توسعه‌دهنده، هر کد ⇒ کلیدِ دسته.
     *
     * منبع کدها: `PluginPackageValidator`، `PluginPackageContract::validateDeclaration`،
     * `PluginDbContract`، `PluginPageContract`، `CoreRequirementChecker` و
     * `PluginInstaller`. عمداً کدهای داخلیِ لاگ (`plugin.*`) اینجا نیستند چون
     * هرگز به نویسندهٔ بسته نشان داده نمی‌شوند.
     *
     * @var array<string, string>
     */
    public const ERROR_CODES = [
        // zip — archive integrity
        'zip.unreadable' => 'zip',
        'zip.too_large' => 'zip',
        'zip.too_many_files' => 'zip',
        'zip.bomb_size' => 'zip',
        'zip.bomb_ratio' => 'zip',
        // path — entries inside the archive
        'path.unsafe' => 'path',
        'path.symlink' => 'path',
        'path.special_entry' => 'path',
        'path.duplicate' => 'path',
        'path.case_collision' => 'path',
        'path.not_allowed' => 'path',
        // manifest — identity and placement
        'manifest.missing' => 'manifest',
        'manifest.misplaced' => 'manifest',
        'manifest.missing_field' => 'manifest',
        'manifest.bad_slug' => 'manifest',
        'manifest.system_forbidden' => 'manifest',
        'manifest.leaks_key' => 'manifest',
        // signature
        'signature.invalid' => 'signature',
        'signature.anonymous_publisher' => 'signature',
        // package
        'package.large' => 'package',
        // panel extension points
        'panel.unknown_point' => 'panel',
        'panel.bad_point' => 'panel',
        'panel.deferred_point' => 'panel',
        // pages
        'pages.not_object' => 'pages',
        'pages.entry_not_object' => 'pages',
        'pages.unknown_key' => 'pages',
        'pages.bad_key' => 'pages',
        'pages.no_title' => 'pages',
        'pages.title_too_long' => 'pages',
        'pages.bad_path' => 'pages',
        'pages.path_not_allowed' => 'pages',
        'pages.path_too_long' => 'pages',
        'pages.bad_permission' => 'pages',
        'pages.permission_charset' => 'pages',
        'pages.permission_too_long' => 'pages',
        'pages.layout_not_allowed' => 'pages',
        'pages.duplicate_path' => 'pages',
        'pages.too_many' => 'pages',
        // blocks
        'blocks.not_list' => 'blocks',
        'blocks.entry_not_object' => 'blocks',
        'blocks.no_type' => 'blocks',
        'blocks.type_not_allowed' => 'blocks',
        'blocks.data_not_object' => 'blocks',
        'blocks.unknown_key' => 'blocks',
        'blocks.no_core_types' => 'blocks',
        'blocks.too_many' => 'blocks',
        // db
        'db.not_object' => 'db',
        'db.unknown_key' => 'db',
        'db.no_tables' => 'db',
        'db.tables_not_list' => 'db',
        'db.too_many_tables' => 'db',
        'db.slug_not_table_safe' => 'db',
        'db.table_not_object' => 'db',
        'db.table_unknown_key' => 'db',
        'db.table_no_name' => 'db',
        'db.table_bad_name' => 'db',
        'db.table_already_prefixed' => 'db',
        'db.table_collides_core' => 'db',
        'db.table_duplicate' => 'db',
        'db.table_name_too_long' => 'db',
        'db.prefixed_collides_core' => 'db',
        'db.indexes_not_list' => 'db',
        'db.too_many_indexes' => 'db',
        'db.index_bad_name' => 'db',
        // requires / core / core_contract
        'requires.invalid' => 'requires',
        'requires.core.invalid' => 'requires',
        'requires.core.unsatisfied' => 'requires',
        'core.version_invalid' => 'core',
        'core_contract.bad_since' => 'core_contract',
        'core_contract.bad_schema_version' => 'core_contract',
        'core_contract.schema_too_new' => 'core_contract',
        'core_contract.too_new' => 'core_contract',
        'core_contract.point_newer_than_core' => 'core_contract',
        // install — server side
        'install.zip_unreadable' => 'install',
        'install.manifest_missing' => 'install',
        'install.manifest_invalid' => 'install',
        'install.manifest_mismatch' => 'install',
        'install.too_large' => 'install',
        'install.too_many_files' => 'install',
        'install.too_many_entries' => 'install',
        'install.compression_bomb' => 'install',
        'install.path_traversal' => 'install',
        'install.escaped_root' => 'install',
        'install.symlink_entry' => 'install',
        'install.special_entry' => 'install',
        'install.duplicate_entry' => 'install',
        'install.case_collision' => 'install',
        'install.write_failed' => 'install',
        'install.incomplete' => 'install',
        'install.target_occupied' => 'install',
        'install.active_release_corrupt' => 'install',
        'install.locked' => 'install',
        'install.hash_mismatch' => 'install',
        'install.storage_unavailable' => 'install',
        'install.rename_failed' => 'install',
        'install.failed' => 'install',
        // developer mode
        'devmode.unsigned_allowed' => 'devmode',
    ];

    /**
     * ECO3 — نمونه‌های عینی request/response برای رابط‌های توسعه‌دهنده.
     *
     * `request`/`response` دادهٔ ساختاریافته‌اند (نه رشتهٔ JSON) تا UI خودش
     * JSON.stringify کند — یک منبع، بدون escapeِ دستی و بدون drift.
     *
     * @var list<array<string, mixed>>
     */
    public const API_EXAMPLES = [
        [
            'id' => 'validate-package',
            'title_fa' => 'اعتبارسنجی بسته پیش از نصب',
            'title_en' => 'Validate a package before install',
            'method' => 'POST',
            'path' => '/v1/admin/plugins/validate',
            'request' => [
                'Content-Type' => 'multipart/form-data',
                'file' => 'my-plugin.zip (binary)',
            ],
            'response' => [
                'analysis' => [
                    'ok' => false,
                    'errors' => [
                        ['severity' => 'error', 'code' => 'zip.too_large', 'message' => 'Package exceeds the size limit.'],
                    ],
                    'warnings' => [
                        ['severity' => 'warning', 'code' => 'package.large', 'message' => 'Package is close to the size limit.'],
                    ],
                ],
            ],
            'note_fa' => 'این endpoint هرگز ۴xx نمی‌دهد؛ همیشه ۲۰۰ برمی‌گرداند تا حتی بستهٔ خراب هم قابل نمایش باشد.',
            'note_en' => 'This endpoint never returns 4xx; it always returns 200 so even a broken package is displayable.',
        ],
        [
            'id' => 'activate-plugin',
            'title_fa' => 'فعال‌سازی پلاگین',
            'title_en' => 'Activate a plugin',
            'method' => 'POST',
            'path' => '/v1/admin/plugins/{slug}/activate',
            'request' => [],
            'response' => [
                'data' => ['slug' => 'my-plugin', 'active' => true, 'review_status' => 'approved'],
                'warning' => null,
            ],
            'note_fa' => 'پیش از فعال‌سازی باید بسته با امضای معتبر نصب شده باشد؛ فعال‌سازی خارج از حالت توسعه‌دهنده بدون امضا رد می‌شود.',
            'note_en' => 'The package must already be installed with a valid signature; outside developer mode activation without a signature is rejected.',
        ],
        [
            'id' => 'read-contract',
            'title_fa' => 'خواندن قرارداد هسته',
            'title_en' => 'Read the core contract',
            'method' => 'GET',
            'path' => '/v1/admin/plugins/contract',
            'request' => [],
            'response' => [
                'data' => [
                    'core_contract_version' => self::CORE_CONTRACT_VERSION,
                    'extension_points' => ['…'],
                    'error_codes' => ['zip.unreadable' => 'zip'],
                    'allowed_paths' => ['manifest.json'],
                ],
            ],
            'note_fa' => 'همین endpoint منبعِ حقیقتِ همین صفحه است؛ هیچ مقدار اینجا دستی کپی نشده.',
            'note_en' => 'This is the source of truth for this very page; no value here is hand-copied.',
        ],
        [
            'id' => 'plugin-route',
            'title_fa' => 'مصرف API افزونه (از مانیفست خودش)',
            'title_en' => 'Consume a plugin API (from its own manifest)',
            'method' => 'GET',
            'path' => '/v1/p/{slug}/{path}',
            'request' => [
                'Authorization' => 'Bearer <manager-token>',
                'Accept' => 'application/json',
            ],
            'response' => [
                'data' => ['…'],
            ],
            'note_fa' => 'هر افزونه می‌تواند مستندات API خودش را در فیلد «docs» مانیفست اعلام کند؛ همان‌ها پایین همین صفحه ادغام می‌شوند.',
            'note_en' => 'Each plugin can declare its own API docs in the manifest "docs" field; those are merged below on this page.',
        ],
    ];

    /**
     * کل ساختار هر نقطه، بدون هیچ map دستی.
     *
     * عمداً `array_merge` به‌جای ساخت دستی: نسخهٔ قبلی هر فیلد تازهٔ قرارداد را
     * بی‌صدا drop می‌کرد، یعنی UI هرچه اضافه می‌شد نمی‌دید.
     *
     * @return list<array<string, mixed>>
     */
    public static function extensionPoints(): array
    {
        $out = [];
        foreach (self::EXTENSION_POINTS as $key => $meta) {
            $out[] = array_merge(['key' => $key], $meta, ['forbidden' => self::FORBIDDEN]);
        }

        return $out;
    }

    public static function isKnownExtensionPoint(string $key): bool
    {
        return array_key_exists($key, self::EXTENSION_POINTS);
    }

    /**
     * آیا این نقطه در runtime واقعاً چیزی را رندر می‌کند؟
     *
     * فقط `live` بله. بقیه یا `declared_only`اند (نصب می‌شوند، اثر ندارند) یا
     * `deferred` (اصلاً پذیرفته نمی‌شوند).
     */
    public static function isLiveExtensionPoint(string $key): bool
    {
        return (self::EXTENSION_POINTS[$key]['status'] ?? null) === 'live';
    }

    /**
     * مسیر مجاز است؟
     *
     * deny-by-default: هر مسیری که زیر یکی از مقصدهای مجاز نباشد رد می‌شود.
     *
     * @param  string  $relPath  مسیر نسبتِ نرمال‌شده با `/`
     */
    public static function isAllowedPath(string $relPath): bool
    {
        // L-B5 — زنجیرهٔ تأمین: هیچ سگمنت vendor مجاز نیست.
        // `Laravel/src` مقصد پوشه‌ای است و isAllowedPath هر چیز زیرش را قبول
        // می‌کرد، پس `Laravel/src/vendor/acme/...` ALLOWED می‌شد. vendor یعنی
        // کد شخص‌ثالثِ بازبینی‌نشده داخل بسته؛ باید جدا اعلام و جدا بررسی شود.
        if (preg_match('#(^|/)vendor(/|$)#i', $relPath) === 1) {
            return false;
        }

        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($relPath === $allowed) {
                return true;
            }
            // زیرپوشه‌های مقصدهای پوشه‌ای مجازند، فایل‌های مقصدهای فایلی نه.
            if ($relPath !== $allowed
                && str_starts_with($relPath, $allowed.'/')
                && ! str_contains(substr($allowed, strrpos($allowed, '/') ?: 0), '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * نرمال‌سازی مسیر ورودی ZIP.
     *
     * جداسازی `\` جداگانه لازم است: `..\..\` روی ویندوز/آلپاین با `/` بی‌اثر است
     * ولی برعکسش خطرناک. مسیرهای مطلق و `..` باید همین‌جا رد شوند.
     *
     * @return string|null مسیر نرمال‌شده، یا null اگر ناامن باشد
     */
    public static function normalizePath(string $raw): ?string
    {
        $p = str_replace('\\', '/', $raw);

        if ($p === '' || str_contains($p, "\0")) {
            return null;
        }
        // مسیر مطلق
        if (str_starts_with($p, '/') || preg_match('#^[A-Za-z]:#', $p) === 1) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $segments[] = $seg;
        }

        if ($segments === []) {
            return null;
        }

        $normalized = implode('/', $segments);

        // نام رزروشدهٔ ویندوز
        $base = pathinfo($normalized, PATHINFO_FILENAME);
        if (in_array(strtoupper($base), self::RESERVED_NAMES, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * برخورد بزرگی/کوچکی: `A.php` و `a.php` هر دو PSR-4 معتبرند ولی روی
     * فایل‌سیستم case-insensitive یکی overwrite می‌کند. برای کاربر ایرانی روی
     * هاست ویندوزی یک باگ واقعی است.
     *
     * @param  list<string>  $paths
     * @return list<array{a: string, b: string}>
     */
    public static function findCaseCollisions(array $paths): array
    {
        $seen = [];
        $collisions = [];

        foreach ($paths as $p) {
            $fold = Str::lower($p);
            if (isset($seen[$fold]) && $seen[$fold] !== $p) {
                $collisions[] = ['a' => $seen[$fold], 'b' => $p];

                continue;
            }
            $seen[$fold] = $p;
        }

        return $collisions;
    }

    /**
     * مسیر تکراری بعد از نرمال‌سازی.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function findDuplicates(array $paths): array
    {
        $counts = array_count_values($paths);

        return array_values(array_keys(array_filter($counts, fn ($n) => $n > 1)));
    }

    /**
     * پیشنهاد محل درست برای یک فایل — «بهتر حدس»، نه الزام.
     *
     * این یک heuristic است تا بتوان به کاربر گفت «این فایل به‌احتمال زیاد
     * جای دیگری لازم است». اگر این heuristic اشتباه کند و UI آن را «الزام»
     * بنامد، توسعه‌دهنده به کل راهنما بی‌اعتماد می‌شود. enforce فقط روی
     * بستهٔ مجاز انجام می‌شود.
     *
     * ترتیب بررسی‌ها **پسوند اول، بعد نام** است: نسخهٔ قبلی نام را اول چک
     * می‌کرد و `Model.png` را به `Laravel/src/Models/Model.png` می‌فرستاد.
     *
     * @return array{path: string, reason: string}|null
     */
    public static function suggestLocation(string $relPath): ?array
    {
        $name = pathinfo($relPath, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        // manifest همیشه در ریشه
        if ($name === 'manifest') {
            return null;
        }

        // ۱) پسوند PHP
        if ($ext === 'php') {
            $lower = Str::lower($relPath);

            // نام استاندارد migration لاراول خودش را لو می‌دهد؛ بدون این الگو یک
            // مهاجرت جاافتاده اصلاً پیشنهادی نمی‌گرفت.
            $looksLikeMigration = str_ends_with(Str::lower($name), 'migration')
                || str_contains($lower, 'migration')
                || preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $name) === 1;

            if ($looksLikeMigration) {
                return [
                    'path' => self::BACKEND_ROOT.'/database/migrations/'.basename($relPath),
                    'reason' => 'نام فایل نشان می‌دهد migration است.',
                ];
            }

            $guesses = [
                'Controller' => self::BACKEND_ROOT.'/src/Http/Controllers/{name}.php',
                'Service' => self::BACKEND_ROOT.'/src/Services/{name}.php',
                'Job' => self::BACKEND_ROOT.'/src/Jobs/{name}.php',
                'Policy' => self::BACKEND_ROOT.'/src/Policies/{name}.php',
                'Model' => self::BACKEND_ROOT.'/src/Models/{name}.php',
            ];

            foreach ($guesses as $needle => $target) {
                if (str_contains($name, $needle)) {
                    return [
                        'path' => str_replace('{name}', $name, $target),
                        'reason' => 'نام فایل به «'.$needle.'» اشاره دارد.',
                    ];
                }
            }

            return null;
        }

        // ۲) دارایی فرانت‌اند
        if (in_array($ext, ['css', 'scss', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'woff2'], true)) {
            return [
                'path' => self::FRONTEND_ROOT.'/panel/'.basename($relPath),
                'reason' => 'این پسوند یک دارایی فرانت‌اند است.',
            ];
        }

        // ۳) جاوااسکریپت عمداً هیچ پیشنهادی نمی‌گیرد.
        //
        // `Next.js/panel/` هرگز اجرا نمی‌شود، پس پیشنهادش فقط یک پوشهٔ بی‌مصرف
        // جا می‌اندازد. بدتر: اگر روزی `public/` سرو شود، اسکریپت same-origin
        // روی origin ادمین می‌نشیند و K1.5.8 عملاً دور زده می‌شود. بهتر است
        // کاربر خودش تصمیم بگیرد تا فایل اصلاً نرود.
        return null;
    }

    /** بستهٔ مجاز برای نمایش به کاربر/برنامه‌نویس. */
    public static function describeAllowedPaths(): array
    {
        return [
            ['path' => self::MANIFEST, 'label_fa' => 'مانیفست (الزامی — باید در ریشهٔ ZIP باشد)', 'label_en' => 'Manifest (required — must be at the ZIP root)'],
            ['path' => self::BACKEND_ROOT.'/src/', 'label_fa' => 'ریشهٔ PSR-4 — کلاس‌های پلاگین', 'label_en' => 'PSR-4 root — plugin classes'],
            ['path' => self::BACKEND_ROOT.'/routes/api.php', 'label_fa' => 'مسیرهای API پلاگین', 'label_en' => 'Plugin API routes'],
            ['path' => self::BACKEND_ROOT.'/database/migrations/', 'label_fa' => 'مهاجرت‌های دیتابیس (با پیشوند اجباری)', 'label_en' => 'Database migrations (mandatory prefix)'],
            ['path' => self::BACKEND_ROOT.'/config/plugin.php', 'label_fa' => 'مقادیر پیش‌فرض تنظیمات', 'label_en' => 'Default settings values'],
            ['path' => self::FRONTEND_ROOT.'/panel/', 'label_fa' => 'داده و دارایی رابط کاربری', 'label_en' => 'UI data and assets'],
            ['path' => self::FRONTEND_ROOT.'/blocks/', 'label_fa' => 'تعریف بلوک‌های سایت', 'label_en' => 'Site block definitions'],
        ];
    }

    // ── اعتبارسنجی اعلان نقطهٔ اتصال (K3.4) ─────────────────────────────────

    /**
     * اعتبارسنجی **خالص** یک اعلان نقطهٔ اتصال.
     *
     * بدون DB، بدون ZIP، بدون هیچ I/O — فقط آرایه در، آرایهٔ خطا بیرون.
     *
     * `path` هر خطا **نسبت به خودِ اعلان** است (`href`، نه
     * `panel.extensions[0].href`). پیشوند مسیر را caller می‌سازد چون این متد
     * از شمارهٔ آرایه خبر ندارد و نمی‌خواهد بداند.
     *
     * @param  string  $point  کلید نقطهٔ اتصال
     * @param  array<string, mixed>  $decl  اعلان، بدون خودِ کلید `point`
     * @return list<array{severity: string, code: string, path: string, message: string, allowed?: list<string>, pattern?: string, guide?: string}>
     */
    public static function validateDeclaration(string $point, array $decl): array
    {
        $meta = self::EXTENSION_POINTS[$point] ?? null;

        if ($meta === null) {
            if (isset(self::DEFERRED_EXTENSION_POINTS[$point])) {
                return [self::issue(
                    $point, 'deferred_point', 'point',
                    'نقطهٔ اتصال «'.$point.'» در نسخهٔ ۱ پشتیبانی نمی‌شود: '.self::DEFERRED_EXTENSION_POINTS[$point],
                    ['guide' => 'plugins.extension-points'],
                )];
            }

            return [self::issue(
                $point, 'unknown_point', 'point',
                'نقطهٔ اتصال ناشناخته: «'.$point.'». فهرست معتبر: '.implode('، ', array_keys(self::EXTENSION_POINTS)),
                ['allowed' => array_keys(self::EXTENSION_POINTS), 'guide' => 'plugins.extension-points'],
            )];
        }

        if ($meta['status'] === 'deferred') {
            // قواعد مقطعی **قبل** از bail اجرا می‌شوند تا قاعده‌های اعلام‌شدهٔ همین
            // نقطه (مثلاً «`admin.plugin_tools` آدرس ندارد») کد مرده نباشند.
            $issues = self::applyCrossRules($point, $meta, $decl);

            $issues[] = self::issue(
                $point, 'deferred_point', 'point',
                'نقطهٔ اتصال «'.$point.'» هنوز deferred است و در runtime چیزی را رندر نمی‌کند. اعلامش در مانیفست خطاست — وگرنه بسته سبز می‌شود و هیچ اتفاقی نمی‌افتد.',
                ['guide' => 'plugins.extension-points'],
            );

            return $issues;
        }

        $issues = [];

        // ⭐ I5 — سازگاری نسخه. قبل از هر قاعدهٔ محتوایی، چون اگر بسته برای
        // هستهٔ دیگری نوشته شده باشد، داوری دربارهٔ محتوایش گمراه‌کننده است.
        foreach (self::applyVersionRules($point, $meta, $decl) as $versionIssue) {
            $issues[] = $versionIssue;
        }

        // سقف حجم — قبل از هر چیز، چون همهٔ قواعد پایین روی داده‌ای می‌نشینند
        // که خودش بزرگ است.
        $bytes = strlen((string) json_encode($decl, JSON_UNESCAPED_UNICODE));
        if ($bytes > $meta['max']['bytes']) {
            $issues[] = self::issue(
                $point, 'too_large', 'point',
                'حجم JSON این اعلان '.$bytes.' بایت است و بیش از سقف '.$meta['max']['bytes'].' بایت است.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        foreach (self::applyCrossRules($point, $meta, $decl) as $cross) {
            $issues[] = $cross;
        }

        $fields = $meta['schema']['fields'];

        // schema خالی یعنی «هنوز نوشته نشده»، نه «هر چیزی مجاز است». بدون
        // renderer، قول ساختاریافته دادن دروغ می‌شود ⇒ fail-closed.
        if ($fields === [] && $meta['open_schema'] === false) {
            $issues[] = self::issue(
                $point, 'no_schema', 'point',
                'برای این نقطه هنوز schema تعریف نشده است، پس هیچ اعلانی قابل بررسی نیست. تا وقتی schema نوشته نشود، اعلان یعنی وعدهٔ بی‌پشتوانه.',
                ['allowed' => [], 'guide' => 'plugins.extension-points'],
            );

            return $issues;
        }

        if (count($decl) > $meta['max']['properties']) {
            $issues[] = self::issue(
                $point, 'too_many_properties', 'point',
                'این اعلان '.count($decl).' فیلد دارد و سقف '.$meta['max']['properties'].' است.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        // کلیدهای ممنوع — سراسری، روی همهٔ نقاط و همهٔ عمق‌های L1/L2/L3
        foreach (array_keys($decl) as $key) {
            if (in_array($key, self::FORBIDDEN, true)) {
                $issues[] = self::issue(
                    $point, 'forbidden_field', (string) $key,
                    'فیلد «'.$key.'» در هیچ نقطهٔ اتصالی مجاز نیست. پلاگین در سطح ۰ کار می‌کند: داده و schema، نه کد.',
                    ['allowed' => self::FORBIDDEN, 'guide' => 'plugins.extension-points'],
                );
            }
        }

        // فیلدهای بسته
        foreach ($fields as $name => $spec) {
            $present = array_key_exists($name, $decl);

            if (! $present) {
                if ($spec['required'] ?? false) {
                    $issues[] = self::issue(
                        $point, 'missing_field', (string) $name,
                        'فیلد «'.$name.'» در این نقطه اجباری است.',
                        ['allowed' => array_keys($fields), 'guide' => 'plugins.extension-points'],
                    );
                }

                continue;
            }

            foreach (self::checkValue($point, (string) $name, $decl[$name], $spec) as $bad) {
                $issues[] = $bad;
            }
        }

        // فیلدهای ناشناخته
        if ($meta['open_schema'] === false) {
            foreach (array_keys($decl) as $key) {
                $key = (string) $key;
                if (isset($fields[$key]) || in_array($key, self::FORBIDDEN, true)
                    || in_array($key, self::DECLARATION_META_FIELDS, true)) {
                    continue;
                }
                $issues[] = self::issue(
                    $point, 'bad_field', $key,
                    'فیلد «'.$key.'» در نقطهٔ '.$point.' وجود ندارد.',
                    ['allowed' => array_keys($fields), 'guide' => 'plugins.extension-points'],
                );
            }

            return $issues;
        }

        // واژگان باز (L3) — گرامر بسته، نام فیلدها دلخواه
        foreach ($decl as $key => $value) {
            $key = (string) $key;
            if (isset($fields[$key]) || in_array($key, self::FORBIDDEN, true)
                || in_array($key, self::DECLARATION_META_FIELDS, true)) {
                continue;
            }
            foreach (self::checkOpenField($point, $key, $value) as $bad) {
                $issues[] = $bad;
            }
        }

        return $issues;
    }

    /**
     * ⭐ I5 — سازگاری نسخهٔ قرارداد (fail-closed).
     *
     * دو دستگیرهٔ مستقل، چون دو پرسش متفاوت‌اند:
     *
     *  ۱. **بسته چه هسته‌ای می‌خواهد؟** `decl['since']` اختیاری است و
     *     می‌گوید نویسنده این اعلان را برای چه نسخه‌ای از قرارداد نوشته. اگر از
     *     `CORE_CONTRACT_VERSION` تازه‌تر باشد ⇒ **خطا**، نه هشدار: این نصب
     *     نمی‌تواند معنای فیلدهایی را که آن نویسنده نوشته بفهمد. سکوت یا
     *     هشدار یعنی نصب می‌شود، اعلان سبز است، و بعداً هیچ‌کس نمی‌فهمد چرا
     *     فیلد ناشناخته‌ای بی‌اثر ماند.
     *
     *  ۲. **این نقطه چه اسکیمایی دارد؟** `decl['schema_version']` اختیاری است.
     *     بزره‌تر از `schema_version` خودِ نقطه یعنی نویسنده اسکیمایی نوشته که
     *     این هسته نمی‌شناسد ⇒ خطا. کوچک‌تر یا مساوی بی‌سروصدا پذیرفته می‌شود
     *     (سازگاری رو به عقب است، نه شکست).
     *
     * و یک guard روی خودِ قرارداد: اگر `since` نقطه از نسخهٔ هسته **تازه‌تر** باشد،
     * این نقطه عملاً در این نصب وجود ندارد و نباید اعلامش را بپذیریم. این حالت
     * یعنی عددِ `CORE_CONTRACT_VERSION` جا نیفتاده، و fail-closed یعنی خطا تا
     * وقتی یکی از دو عدد درست شود.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $decl
     * @return list<array<string, mixed>>
     */
    private static function applyVersionRules(string $point, array $meta, array $decl): array
    {
        $out = [];

        $pointSince = (string) ($meta['since'] ?? '0.0.0');
        if (version_compare($pointSince, self::CORE_CONTRACT_VERSION, '>')) {
            $out[] = self::issue(
                $point, 'core_contract.point_newer_than_core', 'point',
                'این نقطه از نسخهٔ '.$pointSince.' اضافه شده ولی این نصب روی قرارداد '
                .self::CORE_CONTRACT_VERSION.' است؛ اعلانش پذیرفته نمی‌شود.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        if (array_key_exists('since', $decl)) {
            $want = $decl['since'];

            if (! is_string($want) || ! preg_match('/^\d+\.\d+\.\d+$/', $want)) {
                $out[] = self::issue(
                    $point, 'core_contract.bad_since', 'since',
                    'فیلد «since» باید نسخهٔ معنایی MAJOR.MINOR.PATCH باشد، مثل «1.6.0».',
                    ['guide' => 'plugins.extension-points'],
                );
            } elseif (version_compare($want, self::CORE_CONTRACT_VERSION, '>')) {
                $out[] = self::issue(
                    $point, 'core_contract.too_new', 'since',
                    'این اعلان برای قراردادِ نسخهٔ '.$want.' نوشته شده ولی این نصب روی '
                    .self::CORE_CONTRACT_VERSION.' است. هسته را به‌روز کنید یا مقدار '
                    .'«since» را به نسخهٔ همین نصب برسانید.',
                    ['core_contract_version' => self::CORE_CONTRACT_VERSION, 'guide' => 'plugins.extension-points'],
                );
            }
        }

        if (array_key_exists('schema_version', $decl)) {
            $want = $decl['schema_version'];
            $supported = (int) ($meta['schema_version'] ?? 1);

            if (! is_int($want) || $want < 1) {
                $out[] = self::issue(
                    $point, 'core_contract.bad_schema_version', 'schema_version',
                    'فیلد «schema_version» باید عدد صحیحِ مثبت باشد.',
                    ['guide' => 'plugins.extension-points'],
                );
            } elseif ($want > $supported) {
                $out[] = self::issue(
                    $point, 'core_contract.schema_too_new', 'schema_version',
                    'این اعلان برای اسکیمای نسخهٔ '.$want.' نوشته شده ولی «'.$point.'» در این هسته '
                    .'اسکیمای نسخهٔ '.$supported.' دارد. فیلدهایی که این نسخه نمی‌شناسد بی‌اثر می‌مانند.',
                    ['schema_version' => $supported, 'guide' => 'plugins.extension-points'],
                );
            }
        }

        return $out;
    }

    /**
     * قواعد مقطعی — سراسری، ولی هر نقطه خودش اعلام می‌کند کدام‌ها را می‌خواهد.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $decl
     * @return list<array<string, mixed>>
     */
    private static function applyCrossRules(string $point, array $meta, array $decl): array
    {
        $out = [];
        foreach ($meta['schema']['cross'] as $rule) {
            foreach (self::applyCrossRule($point, (string) $rule, $decl) as $cross) {
                $out[] = $cross;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $decl
     * @return list<array<string, mixed>>
     */
    private static function applyCrossRule(string $point, string $rule, array $decl): array
    {
        $out = [];

        // K5.0 — دو قاعده‌ای که رجیستری هم بررسی‌شان می‌کند و بدون آن‌ها
        // اعلان «معتبر» اعلام می‌شد ولی بی‌صدا نادیده گرفته می‌شد.
        if ($rule === 'title_fa' && $point === 'core.service_provider') {
            $interface = $decl['interface'] ?? null;
            $class = $decl['class'] ?? null;

            if (is_string($interface) && ! in_array($interface, self::OVERRIDABLE_INTERFACES, true)) {
                $out[] = self::issue(
                    $point, 'interface_not_overridable', 'interface',
                    'رابط «'.$interface.'» در فهرست قابل‌جایگزینی هسته نیست. فقط این‌ها قابل override هستند: '.implode('، ', self::OVERRIDABLE_INTERFACES),
                    ['allowed' => self::OVERRIDABLE_INTERFACES, 'guide' => 'plugins.extension-points'],
                );
            }

            if (is_string($class) && ! str_starts_with($class, self::PLUGIN_NAMESPACE_PREFIX)) {
                $out[] = self::issue(
                    $point, 'bad_plugin_namespace', 'class',
                    'کلاس «'.$class.'» باید داخل ریشهٔ «'.self::PLUGIN_NAMESPACE_PREFIX.'» باشد. کلاسی که در ریشهٔ «App\» بنشیند مال هسته است، نه افزونه.',
                    ['pattern' => self::PLUGIN_NAMESPACE_PREFIX.'…', 'guide' => 'plugins.extension-points'],
                );
            }
        }

        if ($rule === 'no_core_name') {
            foreach (['type', 'key'] as $field) {
                $value = $decl[$field] ?? null;
                if (is_string($value) && in_array(Str::lower($value), self::RESERVED_TYPE_NAMES, true)) {
                    $out[] = self::issue(
                        $point, 'reserved_name', $field,
                        '«'.$value.'» نام یکی از انواع هسته است. رجیستری برخورد را بی‌صدا رد می‌کند، پس پلاگین نصب می‌شود ولی هرگز دیده نمی‌شود — باید خطا بدهد.',
                        ['allowed' => self::RESERVED_TYPE_NAMES, 'guide' => 'plugins.extension-points'],
                    );
                }
            }
        }

        // شکل prefix اینجا بررسی می‌شود، تطبیق با slug واقعی کارِ caller است چون
        // این متد خالص است و مانیفست را نمی‌بیند.
        if ($rule === 'permission_prefix' && isset($decl['permission']) && is_string($decl['permission'])) {
            if (preg_match('/^plugin:([a-z0-9][a-z0-9._-]{0,39}):/', $decl['permission']) !== 1) {
                $out[] = self::issue(
                    $point, 'bad_permission', 'permission',
                    'پرمیشن «'.$decl['permission'].'» باید با «plugin:{اسلاگ پلاگین}:» شروع شود، وگرنه دو پلاگین با ماژول هم‌نام پرمیشن مشترک می‌گیرند.',
                    ['pattern' => '^plugin:{slug}:…', 'guide' => 'plugins.extension-points'],
                );
            }
        }

        if ($rule === 'entity_prefix' && isset($decl['entity']) && is_string($decl['entity'])) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,39}_[a-z0-9][a-z0-9_]{0,39}$/', $decl['entity']) !== 1) {
                $out[] = self::issue(
                    $point, 'bad_entity', 'entity',
                    'نام موجودیت «'.$decl['entity'].'» باید دقیقاً به شکل «{اسلاگ پلاگین}_{چیزی}» باشد.',
                    ['pattern' => '{slug}_{x}', 'guide' => 'plugins.extension-points'],
                );
            }
        }

        // `title_fa` اگر باشد باید متن قابل نمایش باشد؛ اجباری‌بودنش در `required`
        // فیلدهای هر نقطه تعریف شده، این فقط کیفیت مقدار را نگه می‌دارد.
        if ($rule === 'title_fa' && isset($decl['title_fa'])) {
            if (! is_string($decl['title_fa']) || trim($decl['title_fa']) === '') {
                $out[] = self::issue(
                    $point, 'bad_title', 'title_fa',
                    '«title_fa» متن قابل نمایش به کاربر است و نمی‌تواند خالی باشد.',
                    ['guide' => 'plugins.extension-points'],
                );
            }
        }

        // `admin.plugin_tools` آدرس ندارد: اگر آدرس بدهد، خودش fetch می‌کند و
        // دورِ schema را می‌زند.
        if ($rule === 'no_href' && array_key_exists('href', $decl)) {
            $out[] = self::issue(
                $point, 'bad_field', 'href',
                'این نقطه «href» ندارد. اگر پیوندی لازم است، شناسه بدهید تا هسته خودش به نشانی تبدیلش کند.',
                ['allowed' => [], 'guide' => 'plugins.extension-points'],
            );
        }

        return $out;
    }

    /**
     * بررسی مقدار یک فیلد بسته (L1/L2) در برابر مشخصاتش.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private static function checkValue(string $point, string $field, mixed $value, array $spec): array
    {
        $type = (string) ($spec['type'] ?? 'string');
        $out = [];

        if (! in_array($type, self::GRAMMAR_TYPES, true)) {
            // نگهبان خودِ قرارداد: نوعی که در واژگان نیست یعنی یکی از ما گرامر را
            // دور زده. ساکت ردش نمی‌کنیم چون خودمان آن را نوشته‌ایم.
            return [self::issue(
                $point, 'bad_type', $field,
                'نوع «'.$type.'» در واژگان micro-schema نیست. مجاز: '.implode('، ', self::GRAMMAR_TYPES).'.',
                ['allowed' => self::GRAMMAR_TYPES, 'guide' => 'plugins.extension-points'],
            )];
        }

        $ok = match ($type) {
            'string', 'richtext' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, (array) ($spec['enum'] ?? []), true),
            'media_id' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'media_ids' => self::isListOf($value, fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v))),
            'string_list' => self::isListOf($value, fn ($v) => is_string($v)),
            default => false,
        };

        if (! $ok) {
            return [self::issue(
                $point, 'bad_type', $field,
                'فیلد «'.$field.'» باید از نوع «'.$type.'» باشد.',
                ['allowed' => self::GRAMMAR_TYPES, 'guide' => 'plugins.extension-points'],
            )];
        }

        if ($type === 'enum') {
            $members = (array) ($spec['enum'] ?? []);
            $out = array_merge($out, self::enumIssues($point, $field, $members));
        }

        if (is_string($value)) {
            $out = array_merge($out, self::stringIssues($point, $field, $value, $spec));
        }

        if (is_int($value) || is_float($value)) {
            if (isset($spec['minimum']) && $value < $spec['minimum']) {
                $out[] = self::issue(
                    $point, 'out_of_range', $field,
                    'کمینهٔ مجاز «'.$field.'» برابر '.self::faNum($spec['minimum']).' است.',
                    ['guide' => 'plugins.extension-points'],
                );
            }
            if (isset($spec['maximum']) && $value > $spec['maximum']) {
                $out[] = self::issue(
                    $point, 'out_of_range', $field,
                    'بیشینهٔ مجاز «'.$field.'» برابر '.self::faNum($spec['maximum']).' است.',
                    ['guide' => 'plugins.extension-points'],
                );
            }
        }

        if (is_array($value)) {
            $maxItems = (int) ($spec['maxItems'] ?? 0);
            if ($maxItems > 0 && count($value) > $maxItems) {
                $out[] = self::issue(
                    $point, 'too_many_items', $field,
                    '«'.$field.'» '.count($value).' عضو دارد و سقف '.$maxItems.' است.',
                    ['guide' => 'plugins.extension-points'],
                );
            }
            $itemMax = (int) ($spec['itemMaxLength'] ?? 0);
            $itemPattern = $spec['itemPattern'] ?? null;
            foreach ($value as $i => $item) {
                if ($itemMax > 0 && is_string($item) && mb_strlen($item) > $itemMax) {
                    $out[] = self::issue(
                        $point, 'too_long', $field.'['.$i.']',
                        'طول «'.$field.'['.$i.']» بیش از سقف '.$itemMax.' است.',
                        ['guide' => 'plugins.extension-points'],
                    );
                }
                if (is_string($itemPattern) && is_string($item) && preg_match('/'.str_replace('/', '\/', $itemPattern).'/', $item) !== 1) {
                    $out[] = self::issue(
                        $point, 'bad_pattern', $field.'['.$i.']',
                        'مقدار «'.$field.'['.$i.']» با الگوی مجاز نمی‌خواند.',
                        ['pattern' => $itemPattern, 'guide' => 'plugins.extension-points'],
                    );
                }
            }
        }

        return $out;
    }

    /**
     * بررسی یک فیلد از واژگان باز (L3).
     *
     * @return list<array<string, mixed>>
     */
    private static function checkOpenField(string $point, string $key, mixed $value): array
    {
        $out = [];

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            $out[] = self::issue(
                $point, 'bad_key_name', $key,
                'نام فیلد دلخواه باید با حرف کوچک انگلیسی شروع شود و فقط حرف کوچک، رقم و زیرخط داشته باشد.',
                ['pattern' => '^[a-z][a-z0-9_]{0,39}$', 'guide' => 'plugins.extension-points'],
            );

            return $out;
        }

        // عمق ۱ یعنی هیچ تودرتویی: نه آبجکت، نه فهرست آبجکت. SchemaForm و
        // BlockRenderer امروز هیچ‌کدام را رندر نمی‌کنند.
        $isScalar = is_string($value) || is_int($value) || is_float($value) || is_bool($value);
        $isScalarList = is_array($value)
            && array_is_list($value)
            && $value !== []
            && ! array_filter($value, fn ($v) => is_array($v) || is_object($v));

        if (! $isScalar && ! $isScalarList) {
            return [self::issue(
                $point, 'nested_value', $key,
                'مقدار «'.$key.'» باید اسکالر یا فهرست اسکالر باشد. گرامر micro-schema نه آبجکت تودرتو دارد نه فهرست آبجکت.',
                ['allowed' => self::GRAMMAR_TYPES, 'guide' => 'plugins.extension-points'],
            )];
        }

        if ($isScalarList) {
            if (count($value) > 50) {
                $out[] = self::issue(
                    $point, 'too_many_items', $key,
                    '«'.$key.'» بیش از ۵۰ عضو دارد.',
                    ['guide' => 'plugins.extension-points'],
                );
            }
            foreach ($value as $item) {
                if (is_string($item)) {
                    $out = array_merge($out, self::stringIssues($point, $key, $item, []));
                }
            }

            return $out;
        }

        if (is_string($value)) {
            $out = array_merge($out, self::stringIssues($point, $key, $value, []));
        }

        return $out;
    }

    /**
     * @param  list<string>  $members
     * @return list<array<string, mixed>>
     */
    private static function enumIssues(string $point, string $field, array $members): array
    {
        if (count($members) <= self::GRAMMAR_LIMITS['max_enum_members']) {
            return [];
        }

        return [self::issue(
            $point, 'enum_too_large', $field,
            'enum فیلد «'.$field.'» '.count($members).' عضو دارد و سقف '.self::GRAMMAR_LIMITS['max_enum_members'].' است. enum بزرگ عملاً واژگان باز است و باید بشکندش.',
            ['guide' => 'plugins.extension-points'],
        )];
    }

    /**
     * قاعده‌های مشترک هر رشته — شامل ممنوعیت markup.
     *
     * HTML/اسکریپت درون‌خطی ممنوع است چون همان سطح خطر JS است (K1.5.8) و
     * `BlockRenderer` امروز `href` را بدون sanitize در `<a>` می‌گذارد.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private static function stringIssues(string $point, string $field, string $value, array $spec): array
    {
        $out = [];

        $maxLength = (int) ($spec['maxLength'] ?? 0);
        if ($maxLength === 0) {
            $maxLength = self::GRAMMAR_LIMITS['max_string_length'];
        }
        if (mb_strlen($value) > $maxLength) {
            $out[] = self::issue(
                $point, 'too_long', $field,
                'طول «'.$field.'» بیش از سقف '.$maxLength.' است.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        $pattern = $spec['pattern'] ?? null;
        if (is_string($pattern) && preg_match('/'.str_replace('/', '\/', $pattern).'/u', $value) !== 1) {
            $out[] = self::issue(
                $point, 'bad_pattern', $field,
                'مقدار «'.$field.'» با الگوی مجاز نمی‌خواند.',
                ['pattern' => $pattern, 'guide' => 'plugins.extension-points'],
            );
        }

        if (preg_match('#<\s*/?\s*[a-zA-Z!][^>]*>#', $value) === 1) {
            $out[] = self::issue(
                $point, 'markup_forbidden', $field,
                '«'.$field.'» شامل تگ HTML است. markup آزاد مجاز نیست — نمایش متن باید از مسیر خودِ هسته بیاید، نه از HTML خامِ پلاگین.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $value) === 1) {
            $out[] = self::issue(
                $point, 'unsafe_value', $field,
                '«'.$field.'» با یک طرح‌وارهٔ اجرایی شروع می‌شود. نشانی بیرونی و اسکریپت درون‌خطی در هیچ نقطهٔ اتصالی پذیرفته نیست.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        // الگوهای مبتنی بر کاراکتر مجاز، «..» را رد نمی‌کنند چون نقطه جزو
        // `[a-z0-9._-]` است. برای فیلدهای مسیر باید صریح گرفته شود.
        if (($spec['is_path'] ?? false) === true && preg_match('#(^|/)\.\.($|/)#', $value) === 1) {
            $out[] = self::issue(
                $point, 'unsafe_value', $field,
                '«'.$field.'» یک بخش «..» دارد و می‌تواند از پیشوند مجاز بیرون بزود.',
                ['guide' => 'plugins.extension-points'],
            );
        }

        return $out;
    }

    /**
     * @param  callable(mixed): bool  $ok
     */
    private static function isListOf(mixed $value, callable $ok): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! $ok($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function issue(string $point, string $code, string $path, string $message, array $extra = []): array
    {
        return array_merge([
            'severity' => 'error',
            'code' => $point.'.'.$code,
            'path' => $path,
            'message' => $message,
        ], $extra);
    }

    private static function faNum(int|float $n): string
    {
        return (string) $n;
    }
}
