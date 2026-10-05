<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PishdadSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تست seeder سایت عمومی «پیشداد».
 *
 * ## چرا این تست‌ها
 *
 * seeder جایی است که محتوا از «یک نصب» به «کد توزیع‌شده» تبدیل می‌شود.
 * یعنی دقیقاً همان جایی که راحت‌ترین راه برای لو دادنِ چیزهایی است که
 * نباید توزیع شوند. این تست‌ها همان چیزها را قفل می‌کنند:
 *
 * ۱. هیچ `page_id` ثابتی وارد تنظیمات نمی‌شود (در هر نصب فرق دارد).
 * ۲. محتوای مدیر بازنویسی نمی‌شود.
 * ۳. اجرای دوباره تکراری نمی‌سازد.
 * ۴. هیچ secret یا شناسهٔ وابسته به نصب وارد فایل داده نشده.
 *
 * @internal
 */
class PishdadSiteSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_all_eight_pages_published(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $slugs = Page::query()->orderBy('slug')->pluck('slug')->all();

        $this->assertSame(
            ['about', 'contact', 'faq', 'features', 'home', 'pricing', 'privacy', 'rules'],
            $slugs
        );

        foreach (Page::query()->get() as $page) {
            $this->assertTrue(
                $page->isPublished(),
                "صفحهٔ {$page->slug} باید منتشرشده باشد"
            );
            $this->assertNotNull(
                $page->published_revision_id,
                "صفحهٔ {$page->slug} باید revision منتشرشده داشته باشد"
            );
            $this->assertNotEmpty($page->blocks, "صفحهٔ {$page->slug} باید محتوا داشته باشد");
        }
    }

    /**
     * 🔴 کلید اصلی: لینک‌های هدر/فوتر باید به `page_id` **همین نصب** اشاره
     * کنند.
     *
     * اگر شناسه‌ها کپی شده باشند، در نصبی که `page_id` متفاوت است لینک‌ها
     * بی‌صدا به صفحهٔ اشتباه می‌روند — بدترین نوع باگ چون ۴۰۴ نمی‌دهد،
     * فقط محتوای غلط نشان می‌دهد.
     */
    public function test_chrome_links_point_at_real_pages_of_this_install(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $header = Setting::get('layout', 'global:header');

        $nav = collect($header['widgets'])
            ->firstWhere('type', 'nav')['settings']['links'];

        // هر لینک صفحه‌ای باید واقعاً وجود داشته باشد.
        foreach ($nav as $link) {
            if (($link['kind'] ?? null) !== 'page') {
                continue;
            }

            $this->assertArrayHasKey('page_id', $link, "لینک «{$link['label']}» page_id ندارد");

            $this->assertDatabaseHas('pages', [
                'id' => $link['page_id'],
                'slug' => $this->expectedSlugFor($link['label']),
            ]);
        }
    }

    /**
     * خانه باید به ریشه لینک شود، نه به `page_id` — چون صفحهٔ خانه از `/`
     * سرو می‌شود نه از `/home`.
     */
    public function test_home_is_a_plain_root_link_not_a_page_link(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $header = Setting::get('layout', 'global:header');

        $nav = collect($header['widgets'])->firstWhere('type', 'nav')['settings']['links'];

        $home = collect($nav)->firstWhere('label', 'خانه');

        $this->assertSame('custom', $home['kind']);
        $this->assertSame('/', $home['href']);
    }

    /**
     * `homepage_page_id` باید به صفحهٔ `home` **همین نصب** اشاره کند.
     */
    public function test_homepage_setting_points_at_this_installs_home_page(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $site = Setting::get('site', 'global');

        $this->assertDatabaseHas('pages', [
            'id' => $site['homepage_page_id'],
            'slug' => 'home',
        ]);
    }

    /**
     * 🔴 رگرسیون محتوای مدیر: اگر مدیر صفحه‌ای را منتشر و ویرایش کرده،
     * seeder نباید آن را بازنویسی کند.
     */
    public function test_a_page_the_admin_published_is_never_rewritten(): void
    {
        $owner = User::factory()->create();

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'slug' => 'home',
            'title' => 'عنوان دستی مدیر',
            'status' => Page::STATUS_PUBLISHED,
            'blocks' => [['type' => 'text', 'data' => ['body' => 'متن دستی مدیر']]],
            'meta' => ['title' => 'متای دستی'],
        ]);

        $revision = $page->snapshot(
            [['type' => 'text', 'data' => ['body' => 'متن دستی مدیر']]],
            ['title' => 'متای دستی'],
            $owner->id,
            'ویرایش دستی مدیر'
        );

        $page->forceFill(['published_revision_id' => $revision->id])->save();

        $this->seed(PishdadSiteSeeder::class);

        $page->refresh();

        $this->assertSame('عنوان دستی مدیر', $page->title);
        $this->assertSame('متن دستی مدیر', $page->blocks[0]['data']['body']);
        $this->assertSame($revision->id, $page->published_revision_id);
    }

    /**
     * اجرای دوباره نباید صفحهٔ تکراری یا revision بی‌دلیل بسازد.
     */
    public function test_rerunning_is_idempotent(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $pages = Page::query()->count();
        $revisions = DB::table('page_revisions')->count();

        $this->seed(PishdadSiteSeeder::class);

        $this->assertSame($pages, Page::query()->count());
        $this->assertSame($revisions, DB::table('page_revisions')->count());
    }

    /**
     * 🔴 هیچ شناسهٔ وابسته به نصب نباید در seeder سخت‌گذاری شود.
     *
     * `media_id` به جدول `media` همان نصب اشاره می‌کند و در نصب تازه وجود
     * ندارد؛ اگر کپی شود، پنل به رسانهٔ ناموجود اشاره می‌کند.
     */
    public function test_no_install_specific_media_ids_are_hardcoded(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $site = Setting::get('site', 'global');

        foreach (['logo_media_id', 'favicon_media_id', 'og_image_media_id'] as $key) {
            $this->assertNull(
                $site[$key] ?? null,
                "{$key} نباید در seeder مقدار داشته باشد — به نصب وابسته است"
            );
        }
    }

    /**
     * ظاهر پنل باید راست‌به‌چپ و فارسی باشد.
     */
    public function test_it_sets_a_right_to_left_persian_ui(): void
    {
        $this->seed(PishdadSiteSeeder::class);

        $ui = Setting::get('ui', 'global');

        $this->assertSame('rtl', $ui['dir']);
        $this->assertSame('fa', Setting::get('site', 'global')['locale']);
    }

    /**
     * 🔴 فایل داده نباید هیچ secret یا شناسهٔ کاربر داشته باشد.
     *
     * این تست عمداً **فایل روی دیسک** را می‌خواند، نه فقط نتیجهٔ seeder؛
     * چون اگر کسی بعداً `user_id` یا کلیدی اضافه کند، seeder ممکن است
     * بی‌سروصدا آن را دور بیندازد و تستِ رفتاری ساکت بماند.
     */
    public function test_the_data_file_carries_no_secrets_or_user_ids(): void
    {
        $file = __DIR__.'/../../database/seeders/data/pishdad-pages.php';

        $this->assertFileExists($file);

        $source = (string) file_get_contents($file);

        foreach (['BEGIN PRIVATE KEY', 'password', 'secret', 'APP_KEY', 'vapid'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $source,
                "فایل داده نباید «{$needle}» داشته باشد"
            );
        }

        // محتوا باید واقعاً فارسی باشد، نه placeholder.
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $source);
    }

    /**
     * 🔴 هیچ کلید `page_id` نباید در فایل داده باشد.
     *
     * لینک‌ها باید در زمان اجرا با slug ساخته شوند؛ عددِ ثابت در فایل داده
     * یعنی دقیقاً همان باگی که این seeder برای جلوگیری از آن نوشته شده.
     */
    public function test_the_data_file_has_no_page_id_keys(): void
    {
        $rows = require __DIR__.'/../../database/seeders/data/pishdad-pages.php';

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('id', $row, 'شناسهٔ صفحه نباید در فایل داده باشد');
            $this->assertArrayNotHasKey('page_id', $row, 'page_id نباید در فایل داده باشد');
            $this->assertArrayNotHasKey('user_id', $row, 'user_id نباید در فایل داده باشد');
            $this->assertArrayHasKey('slug', $row);
        }
    }

    /**
     * نمودارهای محتوا باید واقعاً در `media` ثبت و روی دیسک ذخیره شوند.
     *
     * بدون این، نصب تازه هشت صفحهٔ درست دارد ولی هیچ گالری‌ای رندر نمی‌شود.
     */
    public function test_it_registers_the_content_diagrams(): void
    {
        Storage::fake('public');

        $this->seed(PishdadSiteSeeder::class);

        $this->assertSame(4, Media::query()->count(), 'هر چهار نمودار باید ثبت شوند');

        foreach (Media::query()->get() as $media) {
            $this->assertSame('public', $media->disk);
            $this->assertSame('image/svg+xml', $media->mime);
            $this->assertNotEmpty($media->alt, "{$media->original_name} alt ندارد — صفحه‌خوان چیزی برای خواندن ندارد");
            Storage::disk('public')->assertExists($media->path);
        }
    }

    /**
     * ⚠️ این تست همان چیزی را تضمین می‌کند که نام‌گذاریِ content-addressed
     * برایش ساخته شده: **مسیر، هشِ محتوای فایل است**.
     *
     * اگر روزی نام‌فایل ثابت شود، این تست می‌افتد — و بدون آن، هر
     * به‌روزرسانیِ تصویر برای بازدیدکننده‌های قبلی بی‌صدا نامرئی می‌ماند،
     * چون URL یکی است و مرورگر از کش نسخهٔ کهنه را برمی‌دارد.
     */
    public function test_media_paths_are_the_hash_of_their_own_content(): void
    {
        Storage::fake('public');

        $this->seed(PishdadSiteSeeder::class);

        foreach (Media::query()->get() as $media) {
            $bytes = Storage::disk('public')->get($media->path);

            $this->assertSame(
                'media/shared/'.md5($bytes).'.svg',
                $media->path,
                "مسار {$media->original_name} از محتوایش هش نشده است"
            );
        }
    }

    /**
 * گالری‌ها باید داخل بلوک‌های صفحه باشند و به `media_id` واقعیِ همین نصب
 * اشاره کنند.
     */
    public function test_galleries_are_injected_with_real_media_ids(): void
    {
        Storage::fake('public');

        $this->seed(PishdadSiteSeeder::class);

        foreach (['home', 'features', 'about'] as $slug) {
            $blocks = Page::query()->where('slug', $slug)->firstOrFail()->blocks;

            $galleries = array_values(array_filter(
                $blocks,
                fn (array $b): bool => ($b['type'] ?? null) === 'gallery'
            ));

            $this->assertNotEmpty($galleries, "صفحهٔ {$slug} هیچ گالری‌ای ندارد");

            foreach ($galleries as $gallery) {
                $ids = $gallery['data']['media_ids'] ?? [];

                $this->assertNotEmpty($ids, "گالری صفحهٔ {$slug} بدون تصویر است");

                foreach ($ids as $mediaId) {
                    $this->assertDatabaseHas('media', ['id' => $mediaId]);
                }

                $this->assertSame(
                    count($ids),
                    count(array_unique($ids)),
                    "گالری صفحهٔ {$slug} یک تصویر را تکرار کرده است"
                );
            }
        }
    }

    /**
     * گالری باید بعد از نثرِ صفحه و **قبل از** `cta` بنشیند، نه تهِ صفحه
     * که بعد از دکمه‌ها می‌افتد — وگرنه نمودار عملاً دیده نمی‌شود.
     *
     * بلوک‌های واقعی فایل داده: `hero`, `text`×n, `quote`/`faq`, `cta`…
     */
    public function test_galleries_sit_between_the_prose_and_the_calls_to_action(): void
    {
        Storage::fake('public');

        $this->seed(PishdadSiteSeeder::class);

        foreach (['home', 'features', 'about'] as $slug) {
            $types = array_map(
                fn (array $b): string => (string) ($b['type'] ?? ''),
                Page::query()->where('slug', $slug)->firstOrFail()->blocks
            );

            $galleryAt = array_search('gallery', $types, true);
            $this->assertNotFalse($galleryAt, "گالری در {$slug} پیدا نشد");

            // آخرین بلوکِ متنی. `array_reverse` بدون preserve_keys لازم است،
            // وگرنه کلیدِ برگشتی همان اندیسِ اصلی است و یک بار دیگر کم می‌شود.
            $lastText = count($types) - 1 - array_search('text', array_reverse($types), true);
            $this->assertGreaterThanOrEqual(0, $lastText, "بلوکِ متنی در {$slug} پیدا نشد");

            $this->assertGreaterThan(
                $lastText,
                $galleryAt,
                "گالریِ {$slug} قبل از آخرین پاراگراف نشسته است"
            );

            $firstCta = array_search('cta', $types, true);
            if ($firstCta !== false) {
                $this->assertLessThan(
                    $firstCta,
                    $galleryAt,
                    "گالریِ {$slug} بعد از دکمه‌ها افتاده است"
                );
            }
        }
    }

    /**
     * `media_id` در فایل داده hardcode نمی‌شود (چون در هر نصب فرق می‌کند) —
     * همان قراردادی که برای لینک‌های ناوبری رعایت شده.
     */
    public function test_the_data_file_has_no_gallery_media_ids(): void
    {
        $rows = require __DIR__.'/../../database/seeders/data/pishdad-pages.php';

        foreach ($rows as $row) {
            foreach ($row['blocks'] ?? [] as $block) {
                $data = $block['data'] ?? [];

                $this->assertArrayNotHasKey(
                    'media_id',
                    $data,
                    "media_id در بلوکِ «{$block['type']}» نباید hardcode شود"
                );
                $this->assertArrayNotHasKey(
                    'media_ids',
                    $data,
                    "media_ids در بلوکِ «{$block['type']}» نباید hardcode شود"
                );
            }
        }
    }

    /**
     * اجرای دوباره نباید نه media تکراری بسازد و نه فایل.
     */
    public function test_rerunning_does_not_duplicate_content_images(): void
    {
        Storage::fake('public');

        $this->seed(PishdadSiteSeeder::class);

        $media = Media::query()->count();
        $files = count(Storage::disk('public')->allFiles('media/shared'));

        $this->seed(PishdadSiteSeeder::class);

        $this->assertSame($media, Media::query()->count(), 'اجرای دوباره media تکراری ساخت');
        $this->assertSame($files, count(Storage::disk('public')->allFiles('media/shared')));
    }

    /**
     * اگر خودِ فایلِ تصویر عوض شود، هش و در نتیجه مسیر هم عوض می‌شود؛ پس
     * نسخهٔ تازه واقعاً به مرورگر می‌رسد. اینجا با یک فایلِ موقت همین را
     * می‌سنجیم تا انتظارمان فقط یک ادعای روی کاغذ نباشد.
     */
    public function test_changing_the_asset_creates_a_new_path(): void
    {
        Storage::fake('public');

        $source = __DIR__.'/../../database/seeders/assets/content/architecture.svg';
        $original = (string) file_get_contents($source);

        try {
            $this->seed(PishdadSiteSeeder::class);
            $before = Media::query()->where('original_name', 'architecture.svg')->value('path');

            file_put_contents($source, $original."\n<!-- changed -->\n");

            $this->seed(PishdadSiteSeeder::class);

            $after = Media::query()
                ->where('original_name', 'architecture.svg')
                ->orderByDesc('id')
                ->value('path');

            $this->assertNotSame($before, $after, 'مسیر با عوض‌شدن محتوا عوض نشد — کشِ مرورگر نسخهٔ کهنه را نگه می‌دارد');
        } finally {
            file_put_contents($source, $original);
        }
    }

    private function expectedSlugFor(string $label): string
    {
        return [
            'امکانات' => 'features',
            'قیمت‌گذاری' => 'pricing',
            'دربارهٔ ما' => 'about',
            'سوالات متداول' => 'faq',
            'تماس' => 'contact',
            'قوانین و مقررات' => 'rules',
            'حریم خصوصی' => 'privacy',
        ][$label] ?? $label;
    }
}