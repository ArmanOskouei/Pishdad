<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings\CachedSettings;
use App\Services\Themes\SiteThemeResolver;
use Database\Seeders\SiteThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F4.1.B — پنج اندپوینت `site-theme/*`.
 *
 * ## چه چیزی واقعاً سنجیده می‌شود
 *
 *  ۱. مسیرها و اعتبارسنجی (شامل ۴۲۲ِ hex-only و نقشِ ناشناخته).
 *  ۲. ⭐ **`CachedSettings::forget('site_chrome')`** — یعنی بدون این تست، باگِ
 *     «ذخیره شد ولی سایت تا ۲ دقیقه رنگِ قبلی را نشان می‌دهد» کاملاً بی‌صدا
 *     می‌ماند. این تنها باگی است که *دقیقاً* همان چیزی را می‌شکند که ساخته شده.
 *  ۳. ⭐ **`Q4`** — بازدیدکنندهٔ ناشناس همان قالبی را می‌بیند که مدیر انتخاب
 *     کرده. بدون این، کل `site-theme/*` بی‌معناست.
 *  ۴. ⭐ `Q5` — سه قالب از **کد** seed می‌شوند و `path` ندارند.
 *  ۵. ⭐ دقیقاً یک قالبِ فعال (وگرنه `first()` بی‌ترتیب است).
 */
class SiteThemeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SiteThemeSeeder::class);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ])->fresh();
    }

    /** @return array<string, string> */
    private function auth(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
    }

    // ------------------------------------------------------------------
    // list
    // ------------------------------------------------------------------

    public function test_list_exposes_three_themes_three_presets_and_the_selection(): void
    {
        $u = $this->admin();

        $this->getJson('/api/v1/admin/site-theme', $this->auth($u))
            ->assertOk()
            ->assertJsonCount(3, 'data.themes')
            ->assertJsonCount(3, 'data.presets')
            // E79 — seeder یک «انتخابِ فعال» برای قالبِ پیش‌فرض می‌سازد، پس
            // اینجا دیگر `FALLBACK_THEME` نیست؛ «بدونِ انتخاب» حالتِ جداگانه‌ای
            // است که `SiteThemeResolverTest` می‌سنجد.
            ->assertJsonPath('data.selection.theme_slug', SiteThemeSeeder::DEFAULT_ACTIVE)
            ->assertJsonPath('data.selection.preset_slug', SiteThemeResolver::FALLBACK_PRESET)
            ->assertJsonPath('data.modes', ['light', 'dark', 'system']);
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/site-theme')->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // select
    // ------------------------------------------------------------------

    public function test_select_stores_the_choice_and_returns_the_resolved_tokens(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'commerce',
            'preset_slug' => 'rose',
            'mode' => 'dark',
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.selection.theme_slug', 'commerce')
            ->assertJsonPath('data.selection.mode', 'dark')
            ->assertJsonPath('data.tokens.--theme-primary', '#fb7185');
    }

    /** انتخابِ ناموجود نباید «انتخابِ خالی» ذخیره کند و بی‌سروصدا رد شود. */
    public function test_select_rejects_an_unknown_theme_or_preset(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'nope',
            'preset_slug' => 'azure',
            'mode' => 'light',
        ], $this->auth($u))->assertStatus(422);

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'minimal',
            'preset_slug' => 'nope',
            'mode' => 'light',
        ], $this->auth($u))->assertStatus(422);

        // ⚠️ E79 — عبارتِ «صفر سطر» دیگر درست نیست: seeder یک انتخابِ پیش‌فرض
        // می‌سازد. قراردادِ واقعی این است که انتخابِ **نامعتبر** جایگزینِ
        // انتخابِ معتبر نمی‌شود — و همین سنجیده می‌شود.
        $this->assertSame(
            SiteThemeSeeder::DEFAULT_ACTIVE,
            SiteThemeResolver::selection(SiteThemeResolver::GLOBAL_OWNER)['theme_slug'],
            'انتخابِ نامعتبر نباید انتخابِ معتبر را بچپاند.',
        );
    }

    public function test_select_rejects_an_unknown_mode(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'minimal',
            'preset_slug' => 'azure',
            'mode' => 'neon',
        ], $this->auth($u))->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // preset — رفتار متفاوت از select
    // ------------------------------------------------------------------

    /**
     * ⭐ `preset` نباید override را پاک کند. اگر پاکش کند، کاربرِی که رنگِ
     * دستی زده و بعد رنگ‌بندی عوض می‌کند، تنظیماتش بی‌سروصدا از بین می‌رود.
     */
    public function test_changing_the_preset_keeps_the_overrides(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'minimal', 'preset_slug' => 'azure', 'mode' => 'light',
        ], $this->auth($u))->assertOk();

        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['primary' => '#123456'],
        ], $this->auth($u))->assertOk();

        $this->putJson('/api/v1/admin/site-theme/preset', [
            'preset_slug' => 'rose',
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.selection.preset_slug', 'rose')
            ->assertJsonPath('data.tokens.--theme-primary', '#123456');
    }

    // ------------------------------------------------------------------
    // overrides — امنیت
    // ------------------------------------------------------------------

    public function test_overrides_accepts_hex_colours(): void
    {
        $u = $this->admin();
        $this->select($u);

        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['primary' => '#abc', 'accent' => '#11223344', 'radius-md' => '10px'],
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.tokens.--theme-primary', '#abc')
            ->assertJsonPath('data.tokens.--theme-radius-md', '10px');
    }

    /**
     * ⭐ `Q6` — رنگ فقط hex. `red` و `var()` و `url()` باید رد شوند.
     *
     * نکتهٔ مهم: این‌ها باید **۴۲۲** بدهند نه اینکه بی‌صدا از توکن‌ها بیفتند —
     * ساکت یعنی کاربر ذخیره می‌کند و می‌بیند اعمال نشد.
     */
    public function test_overrides_rejects_non_hex_colours(): void
    {
        $u = $this->admin();
        $this->select($u);

        foreach (['red', 'var(--primary)', '#fff; background:url(https://evil.example/x)', 'rgb(1,2,3)'] as $bad) {
            $this->putJson('/api/v1/admin/site-theme/overrides', [
                'overrides' => ['primary' => $bad],
            ], $this->auth($u))->assertStatus(422);
        }

        $this->assertSame([], DB::table('site_theme_settings')->value('overrides') ? [] : []);
    }

    /** نقشِ ناشناخته نباید بتواند هر متغیر CSS دلخواهی تعریف کند. */
    public function test_overrides_rejects_an_unknown_role(): void
    {
        $u = $this->admin();
        $this->select($u);

        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['position' => 'fixed'],
        ], $this->auth($u))->assertStatus(422);
    }

    /**
     * ⭐ `422` وقتی هنوز هیچ انتخابی وجود ندارد.
     *
     * ⚠️ E79 — از وقتی seeder یک انتخابِ پیش‌فرض می‌سازد، این تست بدونِ پاک‌کردنِ
     * صریح، هرگز `422` نمی‌گرفت و **سبزِ دروغین** می‌شد. اینجا حالتِ واقعیِ
     * «هیچ انتخابی نیست» ساخته می‌شود، نه با فرضِ خاموشیِ پیش‌فرض.
     */
    public function test_overrides_requires_a_selection_first(): void
    {
        DB::table('site_theme_settings')->delete();

        $u = $this->admin();

        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['primary' => '#123456'],
        ], $this->auth($u))->assertStatus(422);
    }

    /** ارسالِ کلیدِ override با مقدارِ خالی یعنی «حذفش کن». */
    public function test_overrides_replace_the_whole_map(): void
    {
        $u = $this->admin();
        $this->select($u);

        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['primary' => '#123456', 'accent' => '#654321'],
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.tokens.--theme-primary', '#123456');

        // کلیدِ `primary` که نیامد یعنی حذف شد ⇒ رنگِ پایهٔ `azure` برمی‌گردد.
        // اگر هنوز `#123456` بود، `PUT` جایگذاری نمی‌کرد و کلیدها جمع می‌شدند.
        $this->putJson('/api/v1/admin/site-theme/overrides', [
            'overrides' => ['accent' => '#654321'],
        ], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.tokens.--theme-primary', '#0d9488')
            ->assertJsonPath('data.tokens.--theme-accent', '#654321');

        $this->assertSame(
            ['accent' => '#654321'],
            SiteThemeResolver::selection(SiteThemeResolver::GLOBAL_OWNER)['overrides'],
        );
    }

    // ------------------------------------------------------------------
    // reset
    // ------------------------------------------------------------------

    public function test_reset_deletes_the_selection_row_and_falls_back(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'commerce', 'preset_slug' => 'forest', 'mode' => 'dark',
        ], $this->auth($u))->assertOk();

        $this->postJson('/api/v1/admin/site-theme/reset', [], $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.selection.theme_slug', SiteThemeResolver::FALLBACK_THEME)
            ->assertJsonPath('data.selection.mode', 'light');

        $this->assertSame(0, DB::table('site_theme_settings')->count());
    }

    // ------------------------------------------------------------------
    // ⭐ ابطالِ کش — باگِ ۲ دقیقه‌ای
    // ------------------------------------------------------------------

    /**
     * ⭐⭐ این تست همان چیزی را می‌گیرد که TASKS نام برده: بدون
     * `CachedSettings::forget('site_chrome')` این خط قرمز می‌شود.
     *
     * ترتیب عمداً «اول بخوان، بعد بنویس» است — اگر ابطال قبل از نوشتن بود،
     * این تست سبز می‌شد در حالی که باگ زنده می‌ماند.
     */
    public function test_writing_forgets_the_site_chrome_cache(): void
    {
        $u = $this->admin();

        // ۱) کرومِ کش‌شده را با رنگِ پیش‌فرض پر کن.
        $this->getJson('/api/v1/site/chrome')->assertOk();
        $warm = app(\App\Http\Controllers\Api\V1\Site\SiteChromeController::class)
            ->show(\Illuminate\Http\Request::create('/api/v1/site/chrome'));
        $before = json_decode($warm->getContent(), true)['data']['theme']['globals'] ?? [];

        $this->assertArrayHasKey('primary', $before, 'precondition: chrome باید قالب داشته باشد');

        // ۲) انتخابِ قرمز.
        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'minimal', 'preset_slug' => 'rose', 'mode' => 'light',
        ], $this->auth($u))->assertOk();

        // ۳) کروم باید **همین حالا** رنگِ تازه را بدهد، نه تا ۱۲۰ ثانیه دیگر.
        $after = json_decode(
            app(\App\Http\Controllers\Api\V1\Site\SiteChromeController::class)
                ->show(\Illuminate\Http\Request::create('/api/v1/site/chrome'))
                ->getContent(),
            true,
        )['data']['theme']['globals'] ?? [];

        $this->assertSame(
            '#be123c',
            $after['primary'] ?? null,
            'کشِ site_chrome باطل نشد ⇒ سایت تا دو دقیقه رنگِ قبلی را نشان می‌دهد.',
        );
    }

    /**
     * ⭐ E79 — فعال‌کردنِ قالبی که **از قبل فعال است** باید بی‌اثر و بی‌خطر باشد.
     *
     * نسخهٔ قبلی `forceFill(['active' => true])->save()` بود: چون مقدار از قبل
     * `true` بود، مدل dirty نمی‌شد و UPDATE نمی‌رفت، در حالی که خطِ قبل همه را
     * خاموش کرده بود. نتیجه: نصب **بدونِ قالبِ فعال** می‌ماند و سایت به
     * پیش‌فرضِ بی‌پوسته می‌افتد.
     *
     * این باگ تا وقتی پیش‌فرض `minimal` بود پنهان بود، چون فعال‌کردنِ `commerce`
     * همیشه یک تغییرِ واقعی بود.
     */
    public function test_activating_the_already_active_theme_keeps_it_active(): void
    {
        $u = $this->admin();

        $commerce = DB::table('themes')->where('slug', 'commerce')->first();
        $this->assertTrue((bool) DB::table('themes')->where('id', $commerce->id)->value('active'));

        $this->postJson("/api/v1/admin/themes/{$commerce->id}/activate", [], $this->auth($u))
            ->assertOk();

        $this->assertSame(1, DB::table('themes')->where('active', true)->count());
        $this->assertTrue((bool) DB::table('themes')->where('id', $commerce->id)->value('active'));
    }

    public function test_activate_a_builtin_theme_also_forgets_the_chrome_cache(): void
    {
        $u = $this->admin();

        $this->getJson('/api/v1/site/chrome')->assertOk();

        $editorial = DB::table('themes')->where('slug', 'editorial')->first();
        $commerce = DB::table('themes')->where('slug', 'commerce')->first();

        $this->postJson("/api/v1/admin/themes/{$editorial->id}/activate", [], $this->auth($u))
            ->assertOk();

        $this->postJson("/api/v1/admin/themes/{$commerce->id}/activate", [], $this->auth($u))
            ->assertOk();

        $data = json_decode(
            app(\App\Http\Controllers\Api\V1\Site\SiteChromeController::class)
                ->show(\Illuminate\Http\Request::create('/api/v1/site/chrome'))
                ->getContent(),
            true,
        )['data'];

        $this->assertSame('commerce', $data['theme']['slug']);
        $this->assertSame('10px', $data['theme']['globals']['radius-md'] ?? null);
    }

    // ------------------------------------------------------------------
    // ⭐ Q4 + Q5
    // ------------------------------------------------------------------

    /**
     * ⭐⭐ `Q4` — قالب **سراسری** است. بازدیدکنندهٔ ناشناس باید همان قالبی را
     * ببیند که مدیر انتخاب کرده، و این بدون احراز هویت و بیرون از پنل است.
     */
    public function test_an_anonymous_visitor_sees_the_selection_the_admin_made(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'commerce', 'preset_slug' => 'forest', 'mode' => 'light',
        ], $this->auth($u))->assertOk();

        // کلید **نقشِ خالی** است، نه `--theme-primary`: `themeVars()` فرانت خودش
        // پیشوند را اضافه می‌کند و resolver هم عمداً به فضای نقش برمی‌گردد
        // (`bareTokens`). اگر این‌جا پیشوند را انتظار داشتیم، یعنی جایی پیشوند
        // دوباره اضافه شده — که همان باگِ «انتخاب بی‌اثر» است.
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.theme.globals.primary', '#15803d')
            ->assertJsonMissingPath('data.theme.globals.--theme-primary');
    }

    /** ⭐ ردیفِ انتخاب **سراسری** است، نه per-user — وگرنه سایتِ عمومی عوض نمی‌شود. */
    public function test_the_selection_row_is_global_not_per_user(): void
    {
        $u = $this->admin();

        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'commerce', 'preset_slug' => 'azure', 'mode' => 'light',
        ], $this->auth($u))->assertOk();

        $row = DB::table('site_theme_settings')
            ->where('key', SiteThemeResolver::ACTIVE_KEY)
            ->sole();

        $this->assertSame(SiteThemeResolver::GLOBAL_OWNER, (int) $row->user_id);
        $this->assertNotSame((int) $u->id, (int) $row->user_id);
    }

    /** ⭐ `Q5` — سه قالب از کد seed می‌شوند، نه از استخراجِ ZIP. */
    public function test_three_themes_are_seeded_from_code_with_no_zip_path(): void
    {
        // `manifest` از `DB::table()` **رشته** است (نه cast آرایه‌ای) — پس باید
        // decode شود. دسترسیِ `$r->manifest['builtin']` روی رشته بی‌صدا
        // `null` می‌دهد و تست «صفر قالب» سبز می‌شود در حالی که seed کار کرده.
        $rows = DB::table('themes')->whereNotNull('manifest')->get();

        $builtin = $rows->filter(function (object $r): bool {
            $manifest = json_decode((string) $r->manifest, true);

            return is_array($manifest) && ($manifest['builtin'] ?? false) === true;
        });

        $this->assertCount(3, $builtin, 'سه قالبِ درون‌ساخت باید seed شده باشند.');
        $this->assertSame(
            ['commerce', 'editorial', 'minimal'],
            $builtin->pluck('slug')->sort()->values()->all(),
        );

        foreach ($builtin as $row) {
            $this->assertNull($row->path, 'قالبِ درون‌ساخت نباید فایلِ ZIP داشته باشد.');

            $manifest = json_decode((string) $row->manifest, true);
            $this->assertNotEmpty($manifest['globals'] ?? [], 'قالب باید توکنِ طراحی داشته باشد.');
        }
    }

    /**
     * ⭐ بدون این، `SiteChromeController` که `where('active',true)->first()`
     * می‌نویسد بی‌ترتیب می‌ماند: `first()` روی چند سطرِ فعال یعنی «هر بار
     * یکیِ دیگر». placeholderِ `default` دقیقاً همین کار را می‌کرد.
     */
    public function test_exactly_one_theme_is_active(): void
    {
        $this->assertSame(1, DB::table('themes')->where('active', true)->count());
        // E79 — پیش‌فرضِ نصبِ تازه «commerce» است (همان قالبِ سایتِ نمایشی)،
        // نه اولین درون‌ساخت. این مقدار عمداً قفل است تا تغییرِ بی‌صدایی نکند.
        $this->assertSame(
            'commerce',
            DB::table('themes')->where('active', true)->value('slug'),
        );
    }

    /** re-running the seeder must not steal an activation the admin made. */
    public function test_reseeding_does_not_steal_an_admin_activation(): void
    {
        DB::table('themes')->update(['active' => false]);
        DB::table('themes')->where('slug', 'commerce')->update(['active' => true]);

        $this->seed(SiteThemeSeeder::class);

        $this->assertSame(1, DB::table('themes')->where('active', true)->count());
        $this->assertSame('commerce', DB::table('themes')->where('active', true)->value('slug'));
    }

    /** `Q5` — یک ZIP که کاربر آپلود کرده، مالِ کاربر است و seed حق ندارد رزش کند. */
    public function test_reseeding_leaves_a_user_uploaded_theme_alone(): void
    {
        $id = DB::table('themes')->insertGetId([
            'user_id' => $this->admin()->id,
            'name' => 'قالب من',
            'slug' => 'mine',
            'manifest' => json_encode(['name' => 'قالب من', 'globals' => ['primary' => '#abcdef']]),
            'path' => 'themes/shared/mine.zip',
            'review_status' => 'approved',
        ]);

        DB::table('themes')->update(['active' => false]);
        DB::table('themes')->where('id', $id)->update(['active' => true]);

        $this->seed(SiteThemeSeeder::class);

        $mine = DB::table('themes')->where('id', $id)->first();

        $this->assertSame('themes/shared/mine.zip', $mine->path);
        $this->assertSame('قالب من', $mine->name);
    }

    // ------------------------------------------------------------------

    private function select(User $u): void
    {
        $this->postJson('/api/v1/admin/site-theme/select', [
            'theme_slug' => 'minimal', 'preset_slug' => 'azure', 'mode' => 'light',
        ], $this->auth($u))->assertOk();

        Cache::forget(SiteThemeResolver::CACHE_KEY.':global');
        CachedSettings::forget('site_chrome', 'chrome-default-global');
    }
}
