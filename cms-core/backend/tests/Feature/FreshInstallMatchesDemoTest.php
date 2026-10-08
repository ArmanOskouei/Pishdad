<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PishdadSiteSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\SiteThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * E79 — «نصبِ تازه = دقیقاً همین نسخهٔ زنده».
 *
 * ## باگی که این تست می‌گیرد
 *
 * دموی کامل در مخزن بود، ولی **بذرش با سایتِ زنده رانده شده بود**: ظاهرِ پنل
 * یک چیز بود، هدر و فوتر چیز دیگر و قالبِ فعال هم سوم. نصبِ تازه محتوا می‌گرفت،
 * ولی «همان نسخه» نبود — و هیچ تستی این را نمی‌دید، چون همه فقط وجودِ
 * کلیدها را می‌سنجیدند نه **مقدارشان را**.
 *
 * ## چرا مقادیر، نه فقط ساختار
 *
 * ادعای کاربر «همان شکل» است. اگر فردا کسی `preset` پنل را عوض کند، سایت
 * آرام‌آرام از نسخهٔ نمایشی فاصله می‌گیرد و هیچ‌چیز قرمز نمی‌شود. پس اینجا
 * **مقدارِ دقیق** قفل می‌شود.
 *
 * ## روش
 *
 * همان سه بذری که نصب‌کنندهٔ وب صدا می‌زند، به همان ترتیب، روی دیتابیسِ خالیِ
 * تست اجرا می‌شوند و بعد با چیزی که نسخهٔ زنده دارد مقایسه می‌شود.
 */
class FreshInstallMatchesDemoTest extends TestCase
{
    use RefreshDatabase;

    private function seedLikeTheInstaller(): void
    {
        User::query()->create([
            'name' => 'مدیر نصب',
            'email' => 'install'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        (new RolesPermissionsSeeder)->run();
        (new SiteThemeSeeder)->run();
        (new PishdadSiteSeeder)->run();
    }

    /** @return array<string, mixed> */
    private function setting(string $group, string $key): array
    {
        // `Setting` کلید `value` را به آرایه cast می‌کند، پس از خودِ مدل
        // می‌خوانیم؛ خواندنِ خام با `DB::table()` رشتهٔ JSON می‌دهد.
        $value = Setting::get($group, $key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * ⭐ ظاهرِ پنل — دقیقاً همان پیکربندیِ زنده.
     */
    public function test_the_panel_template_is_the_demo_one(): void
    {
        $this->seedLikeTheInstaller();

        $ui = $this->setting('ui', 'global');

        $this->assertSame('sahar', $ui['preset'] ?? null, 'قالبِ پنلِ نصبِ تازه باید همان «سپیده» باشد.');
        $this->assertSame('teal', $ui['accent'] ?? null);
        $this->assertSame('dark', $ui['mode'] ?? null);
        $this->assertSame('sm', $ui['font'] ?? null);
        $this->assertSame('compact', $ui['density'] ?? null);
        $this->assertSame('rtl', $ui['dir'] ?? null);

        // نوار پایین موبایل همان ترتیبِ نسخهٔ زنده.
        $this->assertSame([
            '/admin/dashboard',
            '/admin/pages',
            '/admin/media',
            '/admin/tickets',
            '/admin/header-footer',
            '/admin/settings',
        ], $ui['bottom_nav'] ?? null);
    }

    /**
     * ⭐ هدر — لوگوی بدونِ عنوانِ تکراری + منو + جست‌وجو.
     *
     * نبودِ ویجتِ `search` یعنی جست‌وجوی سایت در نسخهٔ منتشرشده اصلاً در دسترس
     * نبود؛ نبودِ `search` و تفاوتِ `show_title` هر دو رانده‌شدگیِ واقعی بودند.
     */
    public function test_the_header_is_the_demo_one(): void
    {
        $this->seedLikeTheInstaller();

        $header = $this->setting('layout', 'global:header');
        $types = array_column($header['widgets'] ?? [], 'type');

        $this->assertSame(['logo', 'nav', 'search'], $types, 'هدرِ نصبِ تازه باید لوگو/منو/جست‌وجو باشد.');

        $logo = $header['widgets'][0]['settings'] ?? [];
        $this->assertArrayHasKey('show_title', $logo);
        $this->assertFalse($logo['show_title'], 'عنوان کنارِ لوگو در نسخهٔ زنده خاموش است.');

        $this->assertTrue($header['layout']['sticky'] ?? false);

        $links = $header['widgets'][1]['settings']['links'] ?? [];
        $this->assertSame(
            ['خانه', 'امکانات', 'قیمت‌گذاری', 'دربارهٔ ما', 'سوالات متداول', 'تماس'],
            array_column($links, 'label'),
            'منوی هدر باید همان برچسب‌های نسخهٔ زنده را داشته باشد.',
        );
        $this->assertSame('/', $links[0]['href'] ?? null, 'خانه لینکِ متنیِ ریشه است، نه page_id.');
    }

    /**
     * ⭐ فوتر — ویجتِ «درباره» بالای ستون‌ها، بدون متنِ تکراری.
     *
     * `place` و `layout: []` از قابلیتِ E66 آمده‌اند؛ بذر قدیمی هر دو را نداشت
     * و در نتیجه فوترِ نصبِ تازه با چیدمانِ دیگری رندر می‌شد.
     */
    public function test_the_footer_is_the_demo_one(): void
    {
        $this->seedLikeTheInstaller();

        $footer = $this->setting('layout', 'global:footer');

        $this->assertSame([], $footer['layout'] ?? null, 'ستونِ ثابت نباید باشد؛ ویجت‌ها خودشان جایشان را می‌گویند.');

        $types = array_column($footer['widgets'] ?? [], 'type');
        $this->assertSame(['about', 'links', 'links', 'copyright'], $types);

        $about = $footer['widgets'][0];
        $this->assertSame('above', $about['place'] ?? null);
        $this->assertSame([], $about['settings'] ?? null, 'متنِ «درباره» از توضیحاتِ سایت می‌آید، نه از ویجت.');

        $groups = [$footer['widgets'][1]['settings']['title'] ?? null, $footer['widgets'][2]['settings']['title'] ?? null];
        $this->assertSame(['صفحه‌ها', 'حقوقی'], $groups);
    }

    /**
     * ⭐ هشت صفحه، همه منتشر — نه سه صفحهٔ پیش‌نویس.
     *
     * `Page::isPublished()` عمداً به‌جای بررسیِ صرفِ ستون خوانده می‌شود: حالتِ
     * «منتشر ولی بدون revision» در عمل سایتِ خالی نشان می‌دهد.
     */
    public function test_the_demo_pages_are_present_and_published(): void
    {
        $this->seedLikeTheInstaller();

        $slugs = ['home', 'about', 'contact', 'faq', 'features', 'pricing', 'rules', 'privacy'];

        $this->assertSame(8, DB::table('pages')->count());

        foreach ($slugs as $slug) {
            $page = \App\Models\Page::query()->where('slug', $slug)->first();

            $this->assertNotNull($page, "صفحهٔ «{$slug}» باید در نصبِ تازه وجود داشته باشد.");
            $this->assertTrue($page->isPublished(), "صفحهٔ «{$slug}» باید منتشر باشد، نه پیش‌نویس.");
        }
    }

    /**
     * ⭐ قالبِ فعال = همان قالبِ نسخهٔ زنده (`commerce`).
     */
    public function test_the_active_site_theme_is_the_demo_one(): void
    {
        $this->seedLikeTheInstaller();

        $active = DB::table('themes')->where('active', true)->pluck('slug')->all();

        $this->assertSame(['commerce'], $active, 'دقیقاً یک قالبِ فعال، و آن «commerce».');
    }

    /**
     * انتخابِ مدیر نباید با seed بعدی برگردد — این قاعدهٔ `settleActiveTheme` است
     * و E79 آن را به «پیش‌فرضِ محصول» تغییر داد، پس باید دوباره قفل شود.
     */
    public function test_reseeding_keeps_the_managers_own_theme(): void
    {
        $this->seedLikeTheInstaller();

        DB::table('themes')->update(['active' => false]);
        DB::table('themes')->where('slug', 'editorial')->update(['active' => true]);

        (new SiteThemeSeeder)->run();

        $this->assertSame(['editorial'], DB::table('themes')->where('active', true)->pluck('slug')->all());
    }

    /**
     * هویتِ سایت هم بخشی از «همین نسخه» است.
     */
    public function test_the_site_identity_is_the_demo_one(): void
    {
        $this->seedLikeTheInstaller();

        $site = $this->setting('site', 'global');

        $this->assertSame('پیشداد — سامانهٔ مدیریت محتوای فارسی', $site['title'] ?? null);
        $this->assertSame('info@pishdad.ir', $site['email'] ?? null);
        $this->assertSame('تهران، ایران', $site['address'] ?? null);

        // صفحهٔ خانه باید واقعاً به اسلاگِ `home` اشاره کند، نه به یک عددِ
        // تصادفیِ autoincrement.
        $home = \App\Models\Page::query()->where('slug', 'home')->first();
        $this->assertSame($home?->getKey(), $site['homepage_page_id'] ?? null);
    }

    /**
     * ⭐ نکتهٔ امنیتی: شمارهٔ واقعیِ مالک نباید در بستهٔ عمومی بنشیند.
     *
     * سایتِ زنده شماره‌ای دارد که متعلق به خودِ مالک است؛ اگر روزی کسی آن را
     * در بذر کپی کند، هر نصبِ عمومی با شمارهٔ یک شخصِ واقعی بالا می‌آید. پس
     * بذر باید placeholderِ صریح داشته باشد و این تست همان را نگه می‌دارد.
     */
    public function test_the_seeded_phone_is_a_placeholder_not_a_real_number(): void
    {
        $this->seedLikeTheInstaller();

        $phone = (string) ($this->setting('site', 'global')['phone'] ?? '');

        $this->assertNotSame('', $phone);
        $this->assertStringContainsString('0000', $phone, 'شمارهٔ بذر باید placeholder باشد، نه شمارهٔ واقعی.');
    }
}