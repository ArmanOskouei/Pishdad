<?php

namespace App\Services\Plugins;

use App\Search\SearchableProvider;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Chabokan\ChabokanClientInterface;
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
        ChabokanClientInterface::class,
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
            'desc_fa' => 'افزودن آیتم به منوی سمت راست پنل. هر پلاگینی معمولاً به این نیاز دارد.',
            'status' => 'declared_only',
            'openness' => 'closed',
            'openness_why' => 'خروجی این نقطه دقیقاً MenuItem است با چهار فیلد ثابت؛ چیزی برای «باز کردن» وجود ندارد.',
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

        'admin.dashboard_slot' => [
            'label_fa' => 'ویجت داشبورد مدیریت',
            'desc_fa' => 'افزودن کارت به صفحهٔ اصلی پنل.',
            'status' => 'deferred',
            'openness' => 'closed',
            'openness_why' => 'نه primitive دارد نه مصرف‌کننده؛ نوشتن schema برایش دروغ ساختاریافته است.',
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
        // ثبت‌نشده به‌صورت `deprecated` عمداً: حذف کامل از فهرست، راهنمای
        // توسعه‌دهنده و هر ابزاری را که این آرایه را می‌خواند می‌شکند، و آن
        // تصمیمِ خودِ مالک محصول است نه چیزی که یک commit این‌جا بگیرد.
        'admin.settings_schema' => [
            'label_fa' => 'فرم تنظیمات اختصاصی',
            'desc_fa' => 'افزودن صفحهٔ تنظیمات که از روی JSON Schema رندر می‌شود (بدون کد فرنت).',
            'status' => 'deprecated',
            'openness' => 'schema_defined',
            'openness_why' => 'حذف‌شده: به کلید سطح‌بالای `settings` در مانیفست منتقل شد (`ManifestRegistry::pluginSettingsSchemas()`)، چون میکرو-اسکیمای اینجا با `max_depth => 1` اسکیمای JSON تودرتو را حمل نمی‌کند.',
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

        'admin.data_collection' => [
            'label_fa' => 'جدول دادهٔ اختصاصی',
            'desc_fa' => 'افزودن صفحهٔ فهرست/ویرایش برای دادهٔ اختصاصی پلاگین، از روی اعلان entity + schema.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'ستون DataTable امروز تابع می‌خواهد، پس تا اعلانی نشده این نقطه روی کاغذ است.',
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
            'desc_fa' => 'افزودن بلوک به هدر صفحات عمومی سایت.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'نوع ویجت قفل است چون هسته رندرش می‌کند؛ تنظیماتش از block_type خودِ پلاگین می‌آید.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_core_name', 'title_fa']],
            'open_schema' => false,
            // K6.7 — مثال «معتبر» حذف شد. `fields` خالی + `open_schema => false`
            // یعنی `no_schema` خطای سخت، پس `['type' => 'trust_bar', …]` هرگز
            // نمی‌توانست اعلام معتبری باشد: خودِ validator آن را رد می‌کرد. یعنی
            // `POST /v1/admin/plugins/validate` به نویسنده مثالی نشان می‌داد که
            // بگیرد و باز هم خطا بگیرد.
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'برخورد با نام هسته بی‌صدا رد می‌شود، پس باید خطا بدهد نه اینکه نصب شود و دیده نشود.',
                    'decl' => ['type' => 'nav', 'title_fa' => 'منوی من'],
                ],
                [
                    'why' => 'نقطه خودش هدر است؛ `area` فیلد تکراری و فقط اختلاف‌ساز است.',
                    'decl' => ['type' => 'trust_bar', 'title_fa' => 'نوار اعتماد', 'area' => 'header'],
                ],
            ],
        ],

        'site.footer_widget' => [
            'label_fa' => 'ویجت فوتر سایت',
            'desc_fa' => 'افزودن بلوک به فوتر صفحات عمومی سایت.',
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'همان منطق هدر — نوع قفل، تنظیمات باز.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_core_name', 'title_fa']],
            'open_schema' => false,
            // K6.7 — همان دلیل `site.header_widget`: این مثال هم اعلانی بود که
            // validator با `no_schema` رد می‌کرد.
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'برخورد با ویجت هسته نصب را سبز نشان می‌دهد ولی ویجت اصلاً رندر نمی‌شود.',
                    'decl' => ['type' => 'copyright', 'title_fa' => 'کپی‌رایت من'],
                ],
            ],
        ],

        'site.page_type' => [
            'label_fa' => 'نوع صفحهٔ سایت',
            'desc_fa' => 'تعریف نوع محتوایی جدید که صفحات عمومی سایت می‌توانند از آن استفاده کنند.',
            'status' => 'live',
            'openness' => 'open_vocabulary',
            'openness_why' => 'خواستهٔ صریح کارفرما؛ داده در page.meta (ستون JSON آزاد) می‌نشیند و خودش رندر نمی‌شود.',
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

        'site.block_type' => [
            'label_fa' => 'نوع بلوک صفحه',
            'desc_fa' => 'تعریف نوع بلوک جدید برای ویرایشگر صفحه. رندر از روی JSON Schema.',
            'status' => 'declared_only',
            'openness' => 'open_vocabulary',
            'openness_why' => 'تنها جایی که «بی‌نهایت» واقعاً ارزش دارد؛ ولی گرامر بسته می‌ماند.',
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
            'desc_fa' => 'جایگزینی پیاده‌سازی یک interface هسته (درگاه پرداخت، چابکان، جستجو). فهرست interface‌ها بسته است و کلاس باید در ریشهٔ Pishdad\\Plugins\\ باشد. توجه: اینکه کلاس واقعاً آن رابط را پیاده می‌کند در لحظهٔ اعتبارسنجی بررسی نمی‌شود (کد افزونه هنوز بارگذاری نشده)؛ رجیستری آن را می‌سنجد و بی‌صدا نادیده نمی‌گیرد بلکه لاگ می‌کند.',
            // binding زنده و مؤثر است، ولی تا K5.3 (بارگذار PSR-4) کلاس افزونه
            // بارگذاری نمی‌شود. یعنی اعلان معتبر است ولی هنوز اثر ندارد —
            // و همین باید صریح گفته شود.
            'status' => 'declared_only',
            'openness' => 'schema_defined',
            'openness_why' => 'فهرست interface‌های قابل‌جایگزینی بسته و کوچک است (سه مورد)، ولی نام کلاس پیاده‌سازی باز است تا افزونه بتواند کلاس خودش را بنویسد.',
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

        'admin.plugin_tools' => [
            'label_fa' => 'ابزارهای پلاگین در هدر پنل',
            'desc_fa' => 'یک کادر مستطیل مستقل در drawer ابزارهای هدر پنل. داده و schema، نه React.',
            'status' => 'deferred',
            'openness' => 'closed',
            'openness_why' => 'خروجی یک کادر با عنوان و محتوای schema-driven است؛ هیچ آدرسی در آن نیست.',
            'since' => '1.2.0',
            'schema_version' => 1,
            'max' => ['declarations' => 10, 'bytes' => 4096, 'depth' => 1, 'properties' => 30],
            'schema' => ['fields' => [], 'cross' => ['no_href']],
            'open_schema' => false,
            'example_ok' => [],
            'example_bad' => [
                [
                    'why' => 'این نقطه deferred است — شکل نهایی بعد از ساخت drawer هدر تعریف می‌شود، ولی `href` در آن هیچ‌وقت مجاز نیست.',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'icon' => '↻'],
                ],
                [
                    'why' => '`href` در این نقطه اصلاً وجود ندارد — اگر لازم شد `notification_id` بدهید که هسته به نشانی تبدیلش کند.',
                    'decl' => ['key' => 'sync', 'title_fa' => 'همگام‌سازی', 'href' => '/admin/plugin/sync'],
                ],
            ],
        ],

        'admin.page_registry' => [
            'label_fa' => 'صفحهٔ اختصاصی پلاگین',
            'desc_fa' => 'ثبت یک مسیر زیر /admin/ که از روی page descriptor رندر می‌شود.',
            'status' => 'deferred',
            'openness' => 'schema_defined',
            'openness_why' => 'فقط مسیر + پرمیشن + layout؛ render از page descriptor می‌آید نه از پلاگین.',
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
            ['path' => self::MANIFEST, 'label_fa' => 'مانیفست (الزامی — باید در ریشهٔ ZIP باشد)'],
            ['path' => self::BACKEND_ROOT.'/src/', 'label_fa' => 'ریشهٔ PSR-4 — کلاس‌های پلاگین'],
            ['path' => self::BACKEND_ROOT.'/routes/api.php', 'label_fa' => 'مسیرهای API پلاگین'],
            ['path' => self::BACKEND_ROOT.'/database/migrations/', 'label_fa' => 'مهاجرت‌های دیتابیس (با پیشوند اجباری)'],
            ['path' => self::BACKEND_ROOT.'/config/plugin.php', 'label_fa' => 'مقادیر پیش‌فرض تنظیمات'],
            ['path' => self::FRONTEND_ROOT.'/panel/', 'label_fa' => 'داده و دارایی رابط کاربری'],
            ['path' => self::FRONTEND_ROOT.'/blocks/', 'label_fa' => 'تعریف بلوک‌های سایت'],
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
                if (isset($fields[$key]) || in_array($key, self::FORBIDDEN, true)) {
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
            if (isset($fields[$key]) || in_array($key, self::FORBIDDEN, true)) {
                continue;
            }
            foreach (self::checkOpenField($point, $key, $value) as $bad) {
                $issues[] = $bad;
            }
        }

        return $issues;
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
