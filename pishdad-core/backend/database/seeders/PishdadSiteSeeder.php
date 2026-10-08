<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * سایت عمومی «پیشداد»: محتوا، هویت بصری و چیدمان.
 *
 * ## چرا این seeder وجود دارد
 *
 * محتوای سایت فقط در دیتابیسِ یک نصبِ مشخص وجود داشت. برای اینکه بستهٔ
 * خام واقعاً قابل توزیع باشد، همان محتوا باید **کد** باشد — وگرنه هر
 * نصبِ تازه سایت خالی نشان می‌دهد و کاربر باید دستی ۸ صفحه بسازد.
 *
 * ## چرا لینک‌ها با slug حل می‌شوند، نه با page_id
 *
 * لینک‌های هدر/فوتر در قالب ذخیره می‌شوند و به `page_id` اشاره می‌کنند.
 * ولی `page_id` **در هر نصب فرق می‌کند** (autoincrement). اگر همین
 * اعداد را در seeder کپی کنیم، در نصب تازه به صفحهٔ اشتباه یا هیچ صفحه‌ای
 * اشاره می‌کنند — یعنی لینک‌های ناوبری سایت بی‌صدا خراب می‌شوند.
 *
 * پس اینجا فقط اسلاگ‌ها را ذخیره می‌کنیم و بعد از ساخت صفحه‌ها، `page_id`
 * واقعی را در همان لحظه جای‌گذاری می‌کنیم.
 *
 * ## چرا media_id و کلیدها وارد نمی‌شوند
 *
 * `logo_media_id` / `favicon_media_id` / `og_image_media_id` به جدول
 * `media` همان نصب اشاره می‌کنند و قابل تکرار نیستند. کلید VAPID و
 * seal کلیدها هم secret هستند و **نباید** هرگز داخل کدی که توزیع می‌شود
 * بنشینند؛ هر نصب باید کلید خودش را بسازد (که `VapidKeys::get()` همین
 * کار را می‌کند).
 *
 * ## استثنا: نمودارهای محتوا
 *
 * نمودارهای SVG فرق دارند: نه secret‌اند و نه وابسته به نصب. یک فایل
 * ثابت‌اند که با مخزن می‌آید (ساختهٔ `scripts/build-content-images.mjs`
 * در `database/seeders/assets/content/`) و دقیقاً به‌اندازهٔ متنِ صفحه
 * بخشی از محتوا هستند. اگر ثبت نشوند، نصب تازه هشت صفحهٔ خالیِ بدون
 * تصویر نشان می‌دهد — دقیقاً همان چیزی که این seeder برای جلوگیری از آن
 * نوشته شده.
 *
 * پس اینها تنها mediaهایی هستند که ساخته می‌شوند، با دو شرط:
 *
 *  1. **مسیر، هشِ محتواست** (`media/shared/<md5>.svg`). این فقط برای
 *     نام‌گذاری نیست: اگر محتوا عوض شود هش عوض می‌شود، پس URL تازه
 *     می‌شود و مرورگر نسخهٔ کهنه را از کش برنمی‌دارد. با نامِ ثابت،
 *     هر به‌روزرسانی تصویر برای بازدیدکننده‌های قبلی «نامرئی» می‌ماند.
 *  2. **idempotent**: اگر ردیفی با همان هش باشد دوباره ساخته نمی‌شود،
 *     پس اجرای دوبارهٔ `db:seed` هم فایل تکراری می‌سازد و هم رکورد کثیف.
 *
 * ## ایمنی در برابر بازنویسی محتوای مدیر
 *
 * پیرو قرارداد `DefaultPagesSeeder`: صفحه‌ای که مدیر منتشر و ویرایش
 * کرده **هرگز** بازنویسی نمی‌شود. فقط صفحهٔ غایب ساخته می‌شود یا
 * صفحهٔ draft تازه می‌گردد. پس اجرای دوبارهٔ `db:seed` امن است.
 */
class PishdadSiteSeeder extends Seeder
{
    /**
     * نمودارهای محتوا: کلید ⇒ نام فایل canonical.
     *
     * کلیدها در `galleries()` استفاده می‌شوند؛ هیچ `media_id`ای اینجا
     * نوشته نمی‌شود چون در هر نصب فرق می‌کند.
     *
     * @var array<string, string>
     */
    private const CONTENT_IMAGES = [
        'architecture' => 'architecture.svg',
        'publish-flow' => 'publish-flow.svg',
        'rtl' => 'rtl.svg',
        'security' => 'security.svg',
    ];

    /**
     * متن جایگزین (`alt`) هر نمودار — برای صفحه‌خوان، نه تزئین.
     *
     * @var array<string, string>
     */
    private const ALT = [
        'architecture' => 'نمودار معماری: هستهٔ خام در سمت راست و سه افزونه در سمت چپ',
        'publish-flow' => 'نمودار جریان انتشار: پیش‌نویس، بازبینی، انتشار و سایت زنده',
        'rtl' => 'نمودار راست‌به‌چپ: ترتیب آیتم‌ها و ویژگی‌هایی که از روز اول درست بود',
        'security' => 'نمودار مرزهای امنیتی: هسته، امضا و افزونه در سه لایهٔ جدا',
    ];

    public function run(): void
    {
        $ownerId = User::query()->orderBy('id')->value('id');

        // پیش از صفحه‌ها: بلوک‌های گالری به این شناسه‌ها ارجاع می‌دهند.
        $images = $this->seedContentImages($ownerId);

        /** @var array<string, int> $ids slug ⇒ page_id */
        $ids = $this->seedPages($ownerId, $images);

        $this->seedIdentity($ids);
        $this->seedChrome($ids);

        $this->command?->info(sprintf(
            'Pishdad site ready: %d pages (home=#%d), %d content images.',
            count($ids),
            $ids['home'] ?? 0,
            count($images)
        ));
    }

    /**
     * نمودارهای SVG را در دیسکِ عمومی ثبت می‌کند و «کلید ⇒ media_id» برمی‌گرداند.
     *
     * مسیر، هشِ محتوای فایل است؛ پس هم یکتا و هم ضدکهنگی است.
     *
     * @return array<string, int>
     */
    private function seedContentImages(?int $ownerId): array
    {
        $ids = [];
        $dir = __DIR__.'/assets/content';

        foreach (self::CONTENT_IMAGES as $key => $filename) {
            $source = $dir.'/'.$filename;

            if (! is_file($source)) {
                // عمداً هشدار می‌دهیم و رد می‌شویم تا یک نصب بدون تصویر
                // هم سایتِ سالم و بدون پیامِ ترسناک بسازد.
                $this->command?->warn("content image missing: {$source}");

                continue;
            }

            $bytes = (string) file_get_contents($source);
            $hash = md5($bytes);
            $path = "media/shared/{$hash}.svg";

            $media = Media::query()
                ->where('disk', 'public')
                ->where('path', $path)
                ->first();

            if ($media === null) {
                Storage::disk('public')->put($path, $bytes);

                $media = new Media([
                    'user_id' => $ownerId,
                    'disk' => 'public',
                    'path' => $path,
                    'original_name' => $filename,
                    'mime' => 'image/svg+xml',
                    'size' => strlen($bytes),
                    'alt' => self::ALT[$key] ?? $filename,
                ]);
                $media->save();
            }

            $ids[$key] = (int) $media->getKey();
        }

        return $ids;
    }

    /**
     * گالری‌هایی که به صفحه‌ها تزریق می‌شوند: slug ⇒ فهرستِ گالری.
     *
     * کلیدهای تصویر به `seedContentImages()` نگاشت می‌شوند.
     *
     * ⚠️ `after` یعنی «بعد از **آخرین** بلوک از این نوع»، نه اولین. لنگرِ
     * `'text'` گالری را می‌گذارد بعد از تمام نثرِ صفحه و قبل از `cta`ها —
     * یعنی خواننده اول حرف‌ها را می‌خواند و بعد نمودار را.
     *
     * اگر لنگر در فایل داده نبود، گالری تهِ صفحه می‌نشیند (safe fallback).
     *
     * @return array<string, array<int, array{images: array<int,string>, columns: int, caption: string, after: string}>>
     */
    private function galleries(): array
    {
        return [
            'home' => [
                [
                    'images' => ['architecture', 'security'],
                    'columns' => 2,
                    'caption' => 'معماری افزونه‌پذیر و مرزهای امنیتی',
                    'after' => 'text',
                ],
            ],
            'features' => [
                [
                    'images' => ['architecture', 'publish-flow'],
                    'columns' => 2,
                    'caption' => 'معماری افزونه‌پذیر و جریان انتشار',
                    'after' => 'text',
                ],
            ],
            'about' => [
                [
                    'images' => ['rtl', 'security'],
                    'columns' => 2,
                    'caption' => 'راست‌به‌چپ بودن و مرزهای امنیتی',
                    'after' => 'text',
                ],
            ],
        ];
    }

    /**
     * بلوک‌های گالری را با `media_id` واقعی داخلِ بلوک‌ها می‌گذارد.
     *
     * گالری بعد از آخرین بلوکی از نوعِ `after` قرار می‌گیرد تا متنِ صفحه
     * دنباله‌اش را نبیند. اگر چنین بلوکی نبود، گالری تهِ صفحه می‌نشیند.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, int>  $images
     * @param  array<int, array{images: array<int,string>, columns: int, caption: string, after: string}>  $specs
     * @return array<int, array<string, mixed>>
     */
    private function injectGalleries(array $blocks, array $images, array $specs): array
    {
        foreach ($specs as $spec) {
            $mediaIds = [];
            foreach ($spec['images'] as $key) {
                if (isset($images[$key])) {
                    $mediaIds[] = $images[$key];
                }
            }

            // هیچ تصویری ثبت نشد ⇒ گالری خالی رندر می‌شود؛ بهتر نیست.
            if ($mediaIds === []) {
                continue;
            }

            $block = [
                'type' => 'gallery',
                'data' => [
                    'media_ids' => $mediaIds,
                    'columns' => $spec['columns'],
                    'caption' => $spec['caption'],
                ],
            ];

            $insertAt = null;
            foreach ($blocks as $i => $existing) {
                if (($existing['type'] ?? null) === $spec['after']) {
                    $insertAt = $i + 1;
                }
            }

            if ($insertAt === null) {
                $blocks[] = $block;
            } else {
                array_splice($blocks, $insertAt, 0, [$block]);
            }
        }

        return $blocks;
    }

    /**
     * صفحه‌ها را می‌سازد و نگاشت `slug ⇒ id` را برمی‌گرداند.
     *
     * @param  array<string, int>  $images  کلید ⇒ media_id
     * @return array<string, int>
     */
    private function seedPages(?int $ownerId, array $images = []): array
    {
        $ids = [];

        foreach ($this->pages() as $row) {
            $slug = (string) $row['slug'];

            $page = Page::query()->where('slug', $slug)->first();

            // مدیر این صفحه را منتشر کرده ⇒ دست‌نخورده.
            if ($page && $page->isPublished()) {
                $ids[$slug] = (int) $page->getKey();

                continue;
            }

            if ($page === null) {
                $page = new Page(['slug' => $slug, 'user_id' => $ownerId]);
            }

            $page->title = (string) $row['title'];
            $page->is_single = (bool) ($row['is_single'] ?? false);
            $page->meta = $row['meta'] ?? [];
            $page->status = Page::STATUS_PUBLISHED;
            $page->save();

            // انتشار فقط از مسیر revision ممکن است؛ `snapshot` هم پیش‌نویس
            // جاری را جلو می‌برد و هم رکوردِ نسخه را می‌سازد.
            $revision = $page->snapshot(
                $this->injectGalleries(
                    $row['blocks'] ?? [],
                    $images,
                    $this->galleries()[$slug] ?? []
                ),
                $row['meta'] ?? [],
                $ownerId,
                'محتوای اولیهٔ سایت پیشداد'
            );

            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $revision->getKey(),
                'published_at' => $page->published_at ?? now(),
            ])->save();

            $ids[$slug] = (int) $page->getKey();
        }

        return $ids;
    }

    /**
     * عنوان، توضیح و اطلاعات تماس + انتخاب صفحهٔ خانه.
     *
     * @param  array<string, int>  $ids
     */
    private function seedIdentity(array $ids): void
    {
        $email = 'info@pishdad.ir';
        $phone = '+982100000000';

        Setting::set('site', 'global', array_merge(
            $this->identity(),
            [
                'title' => 'پیشداد — سامانهٔ مدیریت محتوای فارسی',
                'description' => 'پیشداد یک سامانهٔ مدیریت محتوای فارسی، راست‌به‌چپ و افزونه‌پذیر است. ساخت سایت شرکتی، فروشگاه اینترنتی و پنل مدیریت بدون یک خط کدنویسی.',
                'email' => $email,
                'phone' => $phone,
                'address' => 'تهران، ایران',
                'locale' => 'fa',
                'timezone' => 'Asia/Tehran',
                'robots_index' => true,

                // ‎⚠️ اگر این را از دادهٔ کپی‌شده بگیریم، به صفحهٔ اشتباه
                // این نصب اشاره می‌کند. پس همین‌جا و با slug حل می‌شود.
                'homepage_page_id' => $ids['home'] ?? null,

                // ‎media_id عمداً تنظیم نمی‌شود: به جدول media همین نصب وابسته
                // است و در نصب تازه وجود ندارد. مدیر از پنل انتخاب می‌کند.
                'logo_media_id' => null,
                'favicon_media_id' => null,
                'og_image_media_id' => null,
                'site_url' => null,
                'ai_summary' => null,
            ]
        ));

        // ردیف‌های تک‌کلیدی قدیمی هم خوانده می‌شوند؛ برای سازگاری به‌روز.
        Setting::set('site', 'title', 'پیشداد — سامانهٔ مدیریت محتوای فارسی');
        Setting::set('site', 'description', $this->identity()['description']);
        Setting::set('site', 'email', $email);
        Setting::set('site', 'phone', $phone);
        Setting::set('site', 'address', 'تهران، ایران');

        Setting::set('socials', 'global', [
            'socials' => [
                ['name' => 'ایمیل', 'url' => 'mailto:'.$email],
            ],
        ]);

        // ظاهر پنل: راست‌به‌چپ، فونت فارسی، حالت تیره.
        //
        // ⭐ E79 — این مقادیر **همان چیزی** هستند که سایتِ زندهٔ پیشداد با آن
        // بالا می‌آید؛ قبلاً `arya`/`md`/`comfortable` بود و نصبِ تازه با ظاهرِ
        // متفاوتی از نسخهٔ نمایشی باز می‌شد. پس هر تغییری در پنلِ زنده باید
        // اینجا هم بیاید، وگرنه «همان نسخه» ادعا نمی‌شود.
        //
        // ‎⚠️ `bottom_nav` فقط مسیرهای *پنل* است و به صفحهٔ عمومی ربطی ندارد،
        // پس ثابت است و قابل تطبیق نیست.
        Setting::set('ui', 'global', [
            'dir' => 'rtl',
            'font' => 'sm',
            'mode' => 'dark',
            'accent' => 'teal',
            'preset' => 'sahar',
            'radius' => 'default',
            'density' => 'compact',
            'bottom_nav' => [
                '/admin/dashboard',
                '/admin/pages',
                '/admin/media',
                '/admin/tickets',
                '/admin/header-footer',
                '/admin/settings',
            ],
        ]);
    }

    /**
     * هدر و فوتر — با `page_id` واقعیِ همین نصب.
     *
     * @param  array<string, int>  $ids
     */
    private function seedChrome(array $ids): void
    {
        /** لینک صفحه‌ای می‌سازد، ولی اگر صفحه‌ای نبود به لینک متنی می‌افتد. */
        $page = fn (string $slug, string $label): array => isset($ids[$slug])
            ? ['kind' => 'page', 'label' => $label, 'page_id' => $ids[$slug]]
            : ['kind' => 'custom', 'label' => $label, 'href' => '/'.$slug];

        Setting::set('layout', 'global:header', [
            'layout' => ['sticky' => true, 'transparent' => false],
            'widgets' => [
                // عنوان کنارِ لوگو در نسخهٔ زنده خاموش است (E79): خودِ لوگو
                // هویت را می‌گوید و تکرارش شلوغ بود.
                ['type' => 'logo', 'settings' => ['show_title' => false]],
                ['type' => 'nav', 'settings' => ['links' => [
                    // خانه لینکِ متنی `/` است، نه `page_id` — چون صفحهٔ
                    // خانه از مسیر ریشه سرو می‌شود و اسلاگش با URL فرق دارد.
                    ['kind' => 'custom', 'label' => 'خانه', 'href' => '/'],
                    $page('features', 'امکانات'),
                    $page('pricing', 'قیمت‌گذاری'),
                    $page('about', 'دربارهٔ ما'),
                    $page('faq', 'سوالات متداول'),
                    $page('contact', 'تماس'),
                ]]],
                // جست‌وجو در سربرگ (E79) — بدونِ آن، نوار بالا با نسخهٔ زنده فرق
                // می‌کرد و جست‌وجوی سایت عملاً غیرقابل دسترس می‌شد.
                ['type' => 'search', 'settings' => []],
            ],
        ]);

        Setting::set('layout', 'global:footer', [
            // بدون ستونِ ثابت: ویجت‌ها خودشان با `place` بالا/پایینِ ستون‌ها
            // را تعیین می‌کنند (E66/E79)، پس قالبِ سخت‌کدشده فقط محدودیت بود.
            'layout' => [],
            'widgets' => [
                [
                    'type' => 'about',
                    // متنِ «درباره» از توضیحاتِ سایت خوانده می‌شود؛ اینجا
                    // تکرارش نمی‌کنیم که دو منبعِ حقیقت نداشته باشیم.
                    'place' => 'above',
                    'settings' => [],
                ],
                ['type' => 'links', 'settings' => [
                    'title' => 'صفحه‌ها',
                    'links' => [
                        $page('features', 'امکانات'),
                        $page('pricing', 'قیمت‌گذاری'),
                        $page('about', 'دربارهٔ ما'),
                        $page('faq', 'سوالات متداول'),
                        $page('contact', 'تماس'),
                    ],
                ]],
                ['type' => 'links', 'settings' => [
                    'title' => 'حقوقی',
                    'links' => [
                        $page('rules', 'قوانین و مقررات'),
                        $page('privacy', 'حریم خصوصی'),
                    ],
                ]],
                ['type' => 'copyright', 'settings' => [
                    'text' => 'پیشداد — تمامی حقوق محفوظ است.',
                ]],
            ],
        ]);
    }

    /** @return array{title: string, description: string} */
    private function identity(): array
    {
        return [
            'title' => 'پیشداد — سامانهٔ مدیریت محتوای فارسی',
            'description' => 'پیشداد یک سامانهٔ مدیریت محتوای فارسی، راست‌به‌چپ و افزونه‌پذیر است. ساخت سایت شرکتی، فروشگاه اینترنتی و پنل مدیریت بدون یک خط کدنویسی.',
        ];
    }

    /**
     * محتوای واقعی صفحات.
     *
     * در فایل جدا نگه داشته شده تا این کلاس خوانا بماند و حجم HTML
     * بدنه را قاطی منطق نکند.
     *
     * @return array<int, array{slug: string, title: string, is_single?: bool, blocks?: array, meta?: array}>
     */
    private function pages(): array
    {
        /** @var array<int, array{slug: string, title: string, is_single?: bool, blocks?: array, meta?: array}> $data */
        $data = require __DIR__.'/data/pishdad-pages.php';

        return $data;
    }
}