<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Themes\SiteThemeResolver;
use Database\Seeders\SiteThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F4.1.C — resolver قالب سایت.
 *
 * سه ادعا را می‌سنجد:
 *  ۱. ادغام سه‌لایه با **اولویت** درست — `layout → preset → overrides`.
 *  ۲. نقش‌های ناشناخته و مقادیر خطرناک بی‌صدا نمی‌گذرند.
 *
 * ⚠️ **نگهبان رابطهٔ CSS و داده اینجا نیست و عمداً جای دیگری است.**
 * کانتینر بک‌اند به درخت فرانت دسترسی ندارد، پس تستی که `globals.css` را بخواند
 * فقط روی ماشینِ توسعه سبز می‌شود و در CI قرمز. آن نگهبان در
 * `pishdad-core/frontend/src/lib/site-theme-vocabulary.test.ts` است — جایی که هر دو
 * فایل را می‌بیند. اینجا فقط منطقِ داده سنجیده می‌شود.
 */
class SiteThemeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SiteThemeSeeder::class);
    }

    private function userId(): int
    {
        return $this->user()->id;
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ])->fresh();
    }

    // ------------------------------------------------------------------
    // پایه
    // ------------------------------------------------------------------

    public function test_without_a_selection_it_falls_back_instead_of_crashing(): void
    {
        $tokens = SiteThemeResolver::resolve($this->userId());

        $this->assertNotEmpty($tokens);
        $this->assertArrayHasKey('--theme-primary', $tokens);
    }

    public function test_the_seed_produces_three_themes_and_three_presets(): void
    {
        $this->assertSame(3, DB::table('site_themes')->count());
        $this->assertSame(3, DB::table('site_theme_presets')->count());
    }

    // ------------------------------------------------------------------
    // ادغام و اولویت
    // ------------------------------------------------------------------

    /**
     * ⭐ لایهٔ رنگ باید بر لایهٔ چیدمان بیفتد، و override بر هر دو. اگر ترتیب
     * عوض شود، انتخاب کاربر بی‌اثر می‌شود و او فکر می‌کند ذخیره نشده.
     */
    public function test_the_layer_order_is_layout_then_preset_then_overrides(): void
    {
        $uid = $this->userId();

        // seeder فقط theme و preset می‌سازد؛ رکوردِ «انتخاب فعال» را `select()` می‌سازد.
        // پس اول انتخاب را برقرار می‌کنیم، بعد دستی override می‌نویسیم چون خودِ
        // `select()` عمداً override را پاک می‌کند.
        SiteThemeResolver::select($uid, 'commerce', 'rose', 'dark');

        $active = DB::table('site_theme_settings')->where('user_id', $uid)->where('key', 'default')->first();

        $this->assertNotNull($active, 'precondition: an active selection must exist');

        DB::table('site_theme_settings')->where('id', $active->id)->update([
            'overrides' => json_encode(['primary' => '#000000']),
        ]);

        $tokens = SiteThemeResolver::resolve($uid);

        $this->assertSame('#000000', $tokens['--theme-primary'], 'override باید برنده باشد.');
    }

    public function test_preset_colours_land_in_the_output(): void
    {
        $uid = $this->userId();
        SiteThemeResolver::select($uid, 'commerce', 'rose', 'dark');

        $tokens = SiteThemeResolver::resolve($uid);

        $this->assertSame('#fb7185', $tokens['--theme-primary']);
        $this->assertSame('#140f11', $tokens['--theme-bg']);
    }

    /** توکنِ چیدمان از قالب می‌آید، نه از رنگ — عوض‌کردن رنگ نباید آن را ببرد. */
    public function test_layout_tokens_come_from_the_theme_not_the_preset(): void
    {
        $uid = $this->userId();
        SiteThemeResolver::select($uid, 'commerce', 'azure', 'light');

        $tokens = SiteThemeResolver::resolve($uid);

        // `commerce` شعاع ۱۰px دارد، `minimal` چهارپیکسل.
        $this->assertSame('10px', $tokens['--theme-radius-md']);
    }

    /** `system` یعنی تصمیم با CSS است، پس نباید نیمه‌ای برگردد. */
    public function test_system_mode_emits_no_colour_half(): void
    {
        $uid = $this->userId();
        SiteThemeResolver::select($uid, 'minimal', 'forest', 'system');

        $tokens = SiteThemeResolver::resolve($uid);

        $this->assertArrayNotHasKey('--theme-primary', $tokens);
        $this->assertArrayHasKey('--theme-radius-sm', $tokens, 'چیدمان باید باقی بماند.');
    }

    // ------------------------------------------------------------------
    // فیلترهای fail-closed
    // ------------------------------------------------------------------

    /** یک نقشِ ناشناخته نباید بتواند هر متغیر دلخواهی را تعریف کند. */
    public function test_an_unknown_role_is_dropped(): void
    {
        $uid = $this->userId();
        SiteThemeResolver::select($uid, 'minimal', 'azure', 'light');

        $active = DB::table('site_theme_settings')->where('user_id', $uid)->where('key', 'default')->first();
        DB::table('site_theme_settings')->where('id', $active->id)->update([
            'overrides' => json_encode(['position' => 'fixed', 'primary' => '#123456']),
        ]);

        $tokens = SiteThemeResolver::resolve($uid);

        $this->assertArrayNotHasKey('--theme-position', $tokens, 'نقشِ ناشناخته باید بیفتد.');
        $this->assertSame('#123456', $tokens['--theme-primary'], 'نقشِ مجازِ همان‌جا باید بماند.');
    }

    // ------------------------------------------------------------------
    // ECO1 — اسلاگِ فعالِ قالب (انتخابِ کامپوننتِ فرانت)
    // ------------------------------------------------------------------

    /**
     * بدون هیچ انتخابیِ کاربر باید قالبِ فعالِ نصب برگردد، نه `null` (صفحهٔ سفید).
     *
     * ⚠️ E79 — این دیگر لزوماً `FALLBACK_THEME` نیست: seeder رکوردِ «انتخابِ
     * فعال» را می‌سازد و از قبل `commerce` را فعال می‌کند. `FALLBACK_THEME`
     * فقط وقتی واقعاً هیچ سطرِ فعالی نباشد معنا دارد، که در این تست حالتِ
     * «جدول خالی» را جداگانه می‌سنجیم.
     */
    public function test_active_slug_falls_back_when_there_is_no_selection(): void
    {
        $this->assertSame(
            SiteThemeSeeder::DEFAULT_ACTIVE,
            SiteThemeResolver::activeSlug(),
        );

        // حالتِ واقعیِ fallback: نه «انتخابِ فعال» در سیستمِ جدید، نه سطرِ فعال
        // در `themes`. توجه: `site_themes` ستونِ `active` ندارد؛ فعال‌بودن در
        // `site_theme_settings` (کلیدِ `default`) نگهداری می‌شود.
        DB::table('site_theme_settings')->delete();
        DB::table('themes')->update(['active' => false]);

        $this->assertSame(
            SiteThemeResolver::FALLBACK_THEME,
            SiteThemeResolver::activeSlug(),
        );
    }

    /** ⭐ `Q4` — انتخابِ سراسریِ نصب، منبعِ اولِ اسلاگِ فعال است. */
    public function test_active_slug_follows_the_global_selection(): void
    {
        SiteThemeResolver::select(SiteThemeResolver::GLOBAL_OWNER, 'commerce', 'rose', 'dark');

        $this->assertSame('commerce', SiteThemeResolver::activeSlug());
    }

    /**
     * placeholderِ `default` (بی‌توکن، تسک ۴.۲) نباید هرگز «فعال» به‌نظر
     * برسد — وگرنه سایت بی‌رنگ می‌شود.
     */
    public function test_active_slug_ignores_the_default_placeholder(): void
    {
        // E79 — seeder یک انتخابِ فعالِ واقعی می‌سازد، پس برای سنجیدنِ حالتِ
        // «بدونِ انتخاب» باید آن را صریح پاک کنیم؛ وگرنه این تست دیگر
        // چیزی نمی‌سنجد و به‌طور تصادفی یا سبز می‌شود یا قرمز.
        DB::table('site_theme_settings')->delete();
        DB::table('themes')->update(['active' => false]);

        if (DB::table('themes')->where('slug', 'default')->doesntExist()) {
            DB::table('themes')->insert(['name' => 'placeholder', 'slug' => 'default', 'active' => true]);
        } else {
            DB::table('themes')->where('slug', 'default')->update(['active' => true]);
        }

        $this->assertSame(SiteThemeResolver::FALLBACK_THEME, SiteThemeResolver::activeSlug());
    }

    /** مسیرِ قدیمیِ ZIP: وقتی انتخابِ سه‌قالبه‌ای نیست، `themes.active` معتبر است. */
    public function test_active_slug_uses_the_legacy_active_theme_when_no_selection(): void
    {
        // E79 — مثلِ تستِ بالا: نبودِ انتخابِ سراسری را **صریح** می‌سازیم.
        DB::table('site_theme_settings')->delete();
        DB::table('themes')->update(['active' => false]);
        DB::table('themes')->insert(['name' => 'قالب من', 'slug' => 'mine-zip', 'active' => true]);

        $this->assertSame('mine-zip', SiteThemeResolver::activeSlug());
    }

    /**
     * ⭐ `;` می‌تواند یک declaration تازه بسازد و `url()` می‌تواند از مرورگر
     * کاربر درخواست بدهد. هر دو باید رد شوند.
     */
    public function test_dangerous_values_are_dropped(): void
    {
        $uid = $this->userId();
        SiteThemeResolver::select($uid, 'minimal', 'azure', 'light');

        $active = DB::table('site_theme_settings')->where('user_id', $uid)->where('key', 'default')->first();
        DB::table('site_theme_settings')->where('id', $active->id)->update([
            'overrides' => json_encode([
                'primary' => '#fff; background-image: url(https://evil.example/x)',
                'accent' => 'red',
            ]),
        ]);

        $tokens = SiteThemeResolver::resolve($uid);

        $this->assertArrayNotHasKey('--theme-primary', $tokens, 'مقدارِ دارای ; باید رد شود.');
        $this->assertArrayNotHasKey('--theme-accent', $tokens, 'مقدارِ غیررنگ باید رد شود.');
    }
}
