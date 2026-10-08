<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** تسک ۲.۳ — DoD: خواندن/ذخیره تنظیمات سایت + شبکه‌ها (کش‌دار، اعتبارسنجی فارسی، ایزولاسیون). */
class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    public function test_site_returns_defaults(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings/site');

        $res->assertOk()->assertJsonPath('data.title', 'وب‌سایت من');
    }

    public function test_site_update_persists_and_reads_back(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'فروشگاه آرمان',
            'description' => 'فروشگاه اینترنتی',
            'phone' => '09123456789',
            'email' => 'info@example.com',
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        // خواندن دوم باید مقدار ذخیره‌شده را بدهد (ابطال کش هنگام ذخیره).
        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.title', 'فروشگاه آرمان')
            ->assertJsonPath('data.phone', '09123456789');
    }

    public function test_site_rejects_invalid_phone_and_email_with_persian_errors(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'phone' => '12345',
            'email' => 'not-an-email',
        ]);

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('errors.phone'));
        $this->assertNotEmpty($res->json('errors.email'));
    }

    /** مشترک نصب: رسانه هر مدیر در تنظیمات سایت همه مدیران پذیرفته است. */
    public function test_site_accepts_shared_media_id(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $media = Media::query()->create([
            'user_id' => $theirs->id, 'disk' => 'local', 'path' => 'x',
            'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 10,
        ]);

        $this->actingAs($mine, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست رسانه',
            'logo_media_id' => $media->id,
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');
    }

    public function test_shared_settings_references_return_not_found_when_missing(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'logo_media_id' => 999999,
        ])->assertNotFound()->assertJsonPath('message', 'رسانه انتخاب‌شده یافت نشد.');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/socials', [
            'socials' => [[
                'key' => 'telegram',
                'url' => 'https://t.me/test',
                'icon_media_id' => 999999,
            ]],
        ])->assertNotFound()->assertJsonPath('message', 'رسانه انتخاب‌شده یافت نشد.');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'homepage_page_id' => 999999,
        ])->assertNotFound()->assertJsonPath('message', 'صفحه خانه انتخاب‌شده یافت نشد.');
    }

    public function test_socials_roundtrip(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/socials', [
            'socials' => [
                ['key' => 'instagram', 'url' => 'https://instagram.com/mypage', 'active' => true],
                ['key' => 'telegram', 'url' => 'https://t.me/mypage', 'active' => false],
            ],
        ])->assertOk()->assertJsonPath('message', 'شبکه‌های اجتماعی ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/socials')
            ->assertOk()
            ->assertJsonCount(2, 'data.socials')
            ->assertJsonPath('data.socials.0.key', 'instagram');
    }

    public function test_socials_rejects_bad_key_and_url(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/socials', [
            'socials' => [
                ['key' => 'Bad Key!', 'url' => 'notaurl'],
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['socials.0.key', 'socials.0.url']);
    }

    public function test_socials_accepts_custom_key_with_label(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/socials', [
            'socials' => [
                ['key' => 'my-podcast', 'url' => 'https://example.com/cast', 'label' => 'پادکست من'],
            ],
        ])->assertOk()->assertJsonPath('message', 'شبکه‌های اجتماعی ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/socials')
            ->assertOk()
            ->assertJsonPath('data.socials.0.key', 'my-podcast')
            ->assertJsonPath('data.socials.0.label', 'پادکست من')
            ->assertJsonPath('data.socials.0.icon_url', null);
    }

    public function test_socials_icon_url_resolves_shared_media(): void
    {
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/1/logo.png',
            'original_name' => 'logo.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/socials', [
            'socials' => [
                ['key' => 'my-brand', 'url' => 'https://example.com', 'icon_media_id' => $media->id],
            ],
        ])->assertOk()->assertJsonPath(
            'data.socials.0.icon_url',
            rtrim((string) config('filesystems.disks.s3.url'), '/').'/media/1/logo.png'
        );

        $auth->getJson('/api/v1/admin/settings/socials')
            ->assertOk()
            ->assertJsonPath(
                'data.socials.0.icon_url',
                rtrim((string) config('filesystems.disks.s3.url'), '/').'/media/1/logo.png'
            );
    }

    /** مشترک نصب: آیکون رسانه هر مدیر در شبکه‌های همه مدیران پذیرفته است. */
    public function test_socials_accepts_shared_icon_media(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $media = Media::query()->create([
            'user_id' => $theirs->id, 'disk' => 's3', 'path' => 'media/x.png',
            'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $this->actingAs($mine, 'sanctum')->putJson('/api/v1/admin/settings/socials', [
            'socials' => [
                ['key' => 'my-brand', 'url' => 'https://example.com', 'icon_media_id' => $media->id],
            ],
        ])->assertOk()->assertJsonPath('message', 'شبکه‌های اجتماعی ذخیره شد.');
    }

    public function test_site_includes_logo_and_favicon_urls(): void
    {
        $user = $this->user();
        $media = Media::query()->create([
            'user_id' => $user->id, 'disk' => 's3', 'path' => 'media/1/site-logo.png',
            'original_name' => 'site-logo.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت لوگو',
            'logo_media_id' => $media->id,
        ])->assertOk();

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.logo_url', $base.'/media/1/site-logo.png')
            // برندِ پیش‌فرضِ نصب وقتی رسانه‌ای انتخاب نشده (کلون از گیت‌هاب).
            ->assertJsonPath('data.favicon_url', '/favicon.ico');
    }

    /** برندِ پیش‌فرضِ نصب: بدون هیچ رسانه‌ای، لوگو/فاوآیکون باید پیش‌فرض باشند. */
    public function test_default_brand_urls_when_nothing_is_selected(): void
    {
        $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.logo_url', '/pishdad-logo.png')
            ->assertJsonPath('data.favicon_url', '/favicon.ico');
    }

    /** سایت عمومی هم در نبودِ رسانه، برندِ پیش‌فرض را می‌دهد. */
    public function test_public_chrome_exposes_default_brand_urls(): void
    {
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.logo_url', '/pishdad-logo.png')
            ->assertJsonPath('data.favicon_url', '/favicon.ico');
    }

    /** مشترک نصب: تنظیم ذخیره‌شده توسط یک مدیر برای مدیر دیگر خوانده می‌شود. */
    public function test_settings_are_shared_across_managers(): void
    {
        $a = $this->user();
        $b = $this->user();

        $this->actingAs($a, 'sanctum')->putJson('/api/v1/admin/settings/site', ['title' => 'سایت الف']);

        $this->actingAs($b, 'sanctum')->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.title', 'سایت الف');
        $this->actingAs($b, 'sanctum')->putJson('/api/v1/admin/settings/socials', [
            'socials' => [['key' => 'telegram', 'url' => 'https://t.me/shared', 'active' => true]],
        ])->assertOk();
        $this->actingAs($a, 'sanctum')->getJson('/api/v1/admin/settings/socials')
            ->assertOk()
            ->assertJsonPath('data.socials.0.key', 'telegram');
    }

    /**
     * سناریوی واقعی مسئله ۱: تنظیم ذخیره‌شده توسط admin@example.com برای
     * سوپرادمین هم قابل خواندن است (سوپرادمین همه داده مدیریتی را می‌بیند).
     */
    public function test_setting_saved_by_admin_is_readable_by_other_admin(): void
    {
        $admin = $this->user(['email' => 'admin@example.com']);
        $super = User::query()->create([
            'name' => 'مدیر دوم',
            'email' => 'super'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت مدیر',
        ])->assertOk();

        $this->actingAs($super, 'sanctum')->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.title', 'سایت مدیر');

        // و برعکس: سوپرادمین می‌بیند، مدیر هم می‌بیند.
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.title', 'سایت مدیر');
    }

    public function test_site_language_mode_single_by_default(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.language_mode', 'single')
            ->assertJsonPath('data.locales', ['fa'])
            ->assertJsonPath('data.primary_locale', 'fa');
    }

    public function test_site_language_mode_dual_exposes_primary_then_secondary(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت دوزبانه',
            'locale' => 'fa',
            'language_mode' => 'dual',
            'secondary_locale' => 'en',
        ])->assertOk();

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.language_mode', 'dual')
            ->assertJsonPath('data.locales', ['fa', 'en'])
            ->assertJsonPath('data.primary_locale', 'fa');
    }

    public function test_site_language_primary_can_be_en_with_fa_secondary(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'English primary',
            'locale' => 'en',
            'language_mode' => 'dual',
            'secondary_locale' => 'fa',
        ])->assertOk();

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.locales', ['en', 'fa'])
            ->assertJsonPath('data.primary_locale', 'en');
    }

    public function test_site_rejects_secondary_locale_equal_to_primary(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'locale' => 'fa',
            'language_mode' => 'dual',
            'secondary_locale' => 'fa',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['secondary_locale']);
    }

    public function test_site_rejects_invalid_language_mode(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'language_mode' => 'triple',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['language_mode']);
    }

    /** ECO2 — کروم عمومی باید زبان‌ها را بدهد تا سایت/میدل‌ور مسیر را بسازد. */
    public function test_chrome_exposes_available_locales_and_primary(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت دوزبانه',
            'locale' => 'fa',
            'language_mode' => 'dual',
            'secondary_locale' => 'en',
        ])->assertOk();

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.locales', ['fa', 'en'])
            ->assertJsonPath('data.primary_locale', 'fa');
    }

    public function test_site_seo_geo_fields_roundtrip(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت سئو',
            'site_url' => 'https://example.ir/',
            'ai_summary' => 'فروشگاه اینترنتی کالای ایرانی.',
            'robots_index' => false,
            'address' => 'تهران، خیابان مثال',
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.site_url', 'https://example.ir')
            ->assertJsonPath('data.ai_summary', 'فروشگاه اینترنتی کالای ایرانی.')
            ->assertJsonPath('data.robots_index', false)
            ->assertJsonPath('data.address', 'تهران، خیابان مثال');
    }

    public function test_site_rejects_invalid_site_url_and_long_ai_summary(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'site_url' => 'notaurl',
            'ai_summary' => str_repeat('ا', 1001),
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['site_url', 'ai_summary']);
    }

    public function test_site_og_image_url_resolves_shared_media(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $own = Media::query()->create([
            'user_id' => $mine->id, 'disk' => 's3', 'path' => 'media/1/og.png',
            'original_name' => 'og.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        $shared = Media::query()->create([
            'user_id' => $theirs->id, 'disk' => 's3', 'path' => 'media/x.png',
            'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $auth = $this->actingAs($mine, 'sanctum');
        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت OG',
            'og_image_media_id' => $own->id,
        ])->assertOk();

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.og_image_url', $base.'/media/1/og.png');

        // مشترک نصب: رسانه مدیر دیگر هم پذیرفته است.
        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت OG',
            'og_image_media_id' => $shared->id,
        ])->assertOk();
    }

    public function test_update_site_dispatches_site_chrome_revalidation(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);
        config(['revalidate.url' => 'http://front.local/api/revalidate']);

        $user = User::query()->create([
            'name' => 'ت', 'email' => 'rev'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت تست',
        ])->assertOk();

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/revalidate')
            && in_array('site-chrome', $req['tags'], true));
    }

    public function test_chrome_reflects_site_save_immediately(): void
    {
        $user = User::query()->create([
            'name' => 'ت', 'email' => 'chrome'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        $auth = $this->actingAs($user, 'sanctum');

        // پرایم کش کروم با مقدار قدیمی (کلید settings:site_chrome:chrome-default-{id}).
        $auth->getJson('/api/v1/admin/settings/site')->assertOk();
        $this->getJson('/api/v1/site/chrome')->assertOk();

        $auth->putJson('/api/v1/admin/settings/site', ['title' => 'عنوان تازه'])->assertOk();

        // بدون هیچ صبری: هم کش بک‌اند باطل شده هم مقدار تازه است.
        $this->getJson('/api/v1/site/chrome')->assertJsonPath('data.title', 'عنوان تازه');
    }

    /** WF-M20 — متن سیاست حریم خصوصی ذخیره، خوانده و در کروم عمومی افشا می‌شود. */
    public function test_site_privacy_policy_roundtrip_and_chrome_exposes_it(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->putJson('/api/v1/admin/settings/site', [
            'title' => 'سایت حریم خصوصی',
            'privacy_policy' => "بند اول.\n\nبند دوم.",
        ])->assertOk()->assertJsonPath('message', 'تنظیمات سایت ذخیره شد.');

        $auth->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy', "بند اول.\n\nبند دوم.");

        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy', "بند اول.\n\nبند دوم.");
    }

    /** WF-M20 — متن سیاست نباید HTML بپذیرد (صفحهٔ عمومی امن بماند). */
    public function test_site_rejects_privacy_policy_with_markup(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/site', [
            'title' => 'تست',
            'privacy_policy' => '<script>alert(1)</script>',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['privacy_policy']);
    }

    /** WF-M20 — نبودِ مقدار ⇒ پیش‌فرضِ غیرخالی افشا می‌شود. */
    public function test_site_privacy_policy_has_default(): void
    {
        $this->getJson('/api/v1/site/chrome')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy', SiteSettingsController::DEFAULT_PRIVACY_POLICY);
    }
}
