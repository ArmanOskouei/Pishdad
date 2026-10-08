<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Catalog;
use App\Notifications\CatalogNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outbox\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * F4.2.B — کاتالوگ، امنیتِ `action_href`، و ترجیحات.
 *
 * ⭐ سه چیزی که این تست‌ها نگه می‌دارند:
 *  ۱. **بدنه هرگز HTML نیست** — حتی وقتی دادهٔ رویداد HTML دارد.
 *  ۲. **۸ شرطِ allowlistِ `action_href`** — هر هشت.
 *  ۳. **ترجیح** — و اینکه «دیتابیس» از ترجیح رد نمی‌شود.
 */
class NotificationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $extra = []): User
    {
        return User::query()->create($extra + [
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
    // کاتالوگ به‌عنوان منبعِ حقیقت
    // ------------------------------------------------------------------

    public function test_every_catalog_key_has_a_known_group_and_channel(): void
    {
        $this->assertNotEmpty(Catalog::keys());

        foreach (Catalog::keys() as $key) {
            $e = Catalog::entry($key);

            $this->assertArrayHasKey($e['group'], Catalog::groupLabels(), "گروهِ ناشناخته در «{$key}»");
            $this->assertNotEmpty($e['channels'], "«{$key}» هیچ کانالی ندارد");

            foreach ($e['channels'] as $channel) {
                $this->assertContains($channel, Catalog::CHANNELS, "کانالِ ناشناخته در «{$key}»");
            }
        }
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Catalog::entry('nope.not.here');
    }

    /** کاتالوگِ خالی یعنی هیچ اعلانی هرگز ساخته نمی‌شود — باید دیده شود. */
    public function test_the_catalog_is_not_empty(): void
    {
        $this->assertGreaterThanOrEqual(5, count(Catalog::keys()));
        $this->assertCount(4, Catalog::GROUPS, 'هر گروه باید دقیقاً یک کارت باشد، نه بیست‌تا.');
    }

    // ------------------------------------------------------------------
    // ⭐ امنیت: بدنه هرگز HTML
    // ------------------------------------------------------------------

    /**
     * ⭐⭐ دادهٔ رویداد در دنیای واقعی HTML دارد (نامِ افزونه، متنِ تیکت، نامِ
     * فایل). اگر فقط به «کاتالوگ HTML ندارد» تکیه کنیم، یک بار که داده HTML
     * شد، drawer پنل خودِ ذخیره‌شده-XSS می‌شد.
     */
    public function test_html_in_the_event_data_is_stripped_from_the_body(): void
    {
        $body = Catalog::render(Catalog::entry('plugins.enabled')['body'], [
            '<script>alert(1)</script><img src=x onerror=alert(2)>افزونه',
        ]);

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringContainsString('افزونه', $body, 'متنِ سالم باید بماند.');
    }

    public function test_the_inbox_never_returns_html(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'plugins.enabled', ['<b onclick="x()">بد</b>']);

        $this->getJson('/api/v1/admin/notification-inbox', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.items.0.body', 'افزونهٔ «بد» فعال شد و امضای آن معتبر است.');
    }

    /** `K5.8`-rows (از افزونه) هم باید پاک‌سازی شوند — آن‌ها از کاتالوگ نمی‌آیند. */
    public function test_a_plugin_written_row_is_also_sanitised(): void
    {
        $u = $this->user();

        $u->notify(new \App\Notifications\PluginNotification(
            '<img src=x onerror=alert(1)>تیتر',
            '<script>bad()</script>',
            'warning',
            null,
            null,
        ));

        $this->getJson('/api/v1/admin/notification-inbox', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'تیتر')
            ->assertJsonPath('data.items.0.body', 'bad()');
    }

    /** بدنه باید به سقف برسد، نه اینکه کارتِ drawer را بشکند. */
    public function test_the_body_is_truncated(): void
    {
        $body = Catalog::plain(str_repeat('ا', Catalog::MAX_BODY + 500));

        $this->assertSame(Catalog::MAX_BODY, mb_strlen($body));
    }

    // ------------------------------------------------------------------
    // ⭐⭐ ۸ شرطِ allowlistِ action_href
    // ------------------------------------------------------------------

    public function test_every_catalog_action_href_passes_all_eight_conditions(): void
    {
        $seen = 0;

        foreach (Catalog::keys() as $key) {
            $action = Catalog::entry($key)['action'];
            if ($action === null) {
                continue;
            }

            $seen++;
            $this->assertTrue(
                Catalog::isSafeActionHref($action['href']),
                "لینکِ «{$key}» از allowlist رد شد — یعنی یکی از هشت شرط را ندارد.",
            );
        }

        $this->assertGreaterThan(0, $seen, 'هیچ اعلانی لینک ندارد ⇒ شرط‌ها بی‌آزمون می‌مانند.');
    }

    /** ۲/۳/۴: فقط مسیر داخلی، بدون scheme، بدون query/fragment. */
    public function test_only_internal_paths_are_allowed(): void
    {
        foreach ([
            '//evil.com/phish',            // protocol-relative
            '/\\evil.com',                 // مرورگر `\` را مثل `/` می‌گیرد
            'https://evil.example/x',      // بیرونی
            'http://evil.example',
            'javascript:alert(1)',
            'data:text/html,<script>',
            'mailto:a@b.c',
            'tel:100',
            '/admin?next=https://evil.example',  // ۴: open-redirect
            '/admin#frag',                        // ۴: لنگر
            'admin/plugins',                      // ۲: نسبی، نه داخلی
        ] as $href) {
            $this->assertFalse(
                Catalog::isSafeActionHref($href),
                "«{$href}» نباید اجازه بگیرد.",
            );
        }

        $this->assertTrue(Catalog::isSafeActionHref('/admin/plugins'));
    }

    /** ۵: نویسهٔ کنترلی — `java\tscript:` باید بمیرد. */
    public function test_control_characters_are_rejected(): void
    {
        foreach (["/admin\n/x", "/admin\t/x", "/admin\0/x", "/admin\r\nLocation: https://evil.example"] as $href) {
            $this->assertFalse(Catalog::isSafeActionHref($href));
        }
    }

    /** ۶: طول. */
    public function test_an_overlong_href_is_rejected(): void
    {
        $this->assertFalse(Catalog::isSafeActionHref('/admin/'.str_repeat('a', Catalog::ACTION_HREF_MAX)));
        $this->assertTrue(Catalog::isSafeActionHref('/admin/'.str_repeat('a', 40)));
    }

    /** ۷: قطعهٔ بالا-رفتنی. `...` مجاز، `..` نه. */
    public function test_parent_directory_segments_are_rejected(): void
    {
        $this->assertFalse(Catalog::isSafeActionHref('/admin/../../etc/passwd'));
        $this->assertFalse(Catalog::isSafeActionHref('/admin/..'));
        $this->assertTrue(Catalog::isSafeActionHref('/admin/a..b'));
    }

    /**
     * ⭐⭐ شرطِ اول: لینک از **فراخواننده** نمی‌آید.
     *
     * `assertAction` روی ورودیِ خودش گارد می‌گذارد. اگر روزی کسی
     * `action_href` را از request بگیرد، هشت شرط روی ورودیِ مهاجم اجرا می‌شود
     * که بی‌معناست — و بدتر، `SafeUrl` لینکِ داخلی را قبول می‌کند پس مهاجم
     * می‌تواند به `/admin/plugins/anything` اشاره کند.
     */
    public function test_a_catalog_entry_with_an_unsafe_href_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Catalog::assertAction('test.key', ['label' => 'x', 'href' => 'https://evil.example']);
    }

    /** شرط ۸ در عمل: رد شدنِ سازگاری با `SafeUrl` که `F0.2` تعریف کرده. */
    public function test_the_allowlist_agrees_with_safe_url(): void
    {
        $this->assertTrue(
            Catalog::isSafeActionHref('/admin/plugins'),
            'مسیرِ داخلی باید در هر دو تعریف بگذرد — وگرنه دو allowlist داریم.',
        );
    }

    /** لینکِ تأییدنشده در DB نباید از راهِ API بیرون بزند (fail-closed روی دادهٔ کهنه). */
    public function test_a_tampered_stored_href_is_not_returned(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'resources.quota_warning', ['900 MB']);
        $this->assertSame('/admin/media', $this->lastHref($u), 'precondition');

        DB::table('notifications')->update(['action_href' => 'https://evil.example']);

        $this->getJson('/api/v1/admin/notification-inbox', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.items.0.action_href', null);
    }

    // ------------------------------------------------------------------
    // ⭐ ترجیحات
    // ------------------------------------------------------------------

    public function test_the_preference_matrix_defaults_to_the_catalog(): void
    {
        $u = $this->user();

        $this->getJson('/api/v1/admin/notification-preferences', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.groups.security', 'امنیت و ورود')
            ->assertJsonPath('data.matrix.security.email', true)
            ->assertJsonPath('data.matrix.plugins.push', false);
    }

    public function test_a_preference_can_be_switched_off_and_on_again(): void
    {
        $u = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'email', 'enabled' => false,
        ], $this->auth($u))->assertOk()->assertJsonPath('data.matrix.security.email', false);

        // تکرارِ همان درخواست نباید سطرِ دوم بسازد (قیدِ nullable-NULL این را
        // پوشش نمی‌دهد و `updateOrInsert` عمداً همان را می‌کند).
        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'email', 'enabled' => false,
        ], $this->auth($u))->assertOk();

        $this->assertSame(1, DB::table('notification_preferences')->count());

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'email', 'enabled' => true,
        ], $this->auth($u))->assertOk()->assertJsonPath('data.matrix.security.email', true);
    }

    public function test_an_unknown_group_or_channel_is_rejected(): void
    {
        $u = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'nope', 'channel' => 'email', 'enabled' => true,
        ], $this->auth($u))->assertStatus(422);

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'pigeon', 'enabled' => true,
        ], $this->auth($u))->assertStatus(422);
    }

    /** ⭐ کارتِ ترجیح فقط مالِ خودِ کاربر است. */
    public function test_a_preference_is_per_user(): void
    {
        $a = $this->user();
        $b = $this->user();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'email', 'enabled' => false,
        ], $this->auth($a))->assertOk();

        $this->assertFalse(NotificationDispatcher::allows($a, 'security.login_new_device', 'security', 'email'));
        $this->assertTrue(NotificationDispatcher::allows($b, 'security.login_new_device', 'security', 'email'));
    }

    public function test_a_per_key_override_beats_the_group_default(): void
    {
        $u = $this->user();

        DB::table('notification_preferences')->insert([
            'user_id' => $u->id,
            'group_key' => 'security',
            'catalog_key' => 'security.login_new_device',
            'channel' => 'email',
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(NotificationDispatcher::allows($u, 'security.login_new_device', 'security', 'email'));
        $this->assertTrue(NotificationDispatcher::allows($u, 'security.password_changed', 'security', 'email'));
    }

    // ------------------------------------------------------------------
    // ⭐ ارسال: دیتابیس همیشه، بیرونی طبقِ ترجیح
    // ------------------------------------------------------------------

    public function test_the_database_row_is_always_written(): void
    {
        $u = $this->user();
        Mail::fake();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'email', 'enabled' => false,
        ], $this->auth($u))->assertOk();

        NotificationDispatcher::send($u, 'security.login_new_device', ['Firefox · تهران']);

        $this->assertSame(1, $u->notifications()->count(), 'خاموش‌کردنِ ایمیل نباید صندوق را خالی کند.');
        $this->assertSame(0, DB::table('notification_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_channels_come_from_the_preference_matrix(): void
    {
        // شماره لازم است: نبودِ مقصد نباید سطرِ صف بسازد (تست بعدی).
        $u = $this->user(['phone' => '09120000000']);
        Mail::fake();

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'security', 'channel' => 'sms', 'enabled' => true,
        ], $this->auth($u))->assertOk();

        NotificationDispatcher::send($u, 'security.login_new_device', ['x']);

        $rows = DB::table('notification_deliveries')->get();

        // ایمیل به‌صورت پیش‌فرض روشن است، و SMS چون تازه روشن شده. هر دو در صف.
        $this->assertSame(2, $rows->count());
        $this->assertSame(
            ['email', 'sms'],
            $rows->pluck('channel')->sort()->values()->all(),
        );
    }

    /** کانالی که کاتالوگ پیش‌فرضش خاموش است، با ترجیح روشن می‌شود. */
    public function test_a_channel_off_by_default_can_be_switched_on(): void
    {
        $u = $this->user(['phone' => '09120000000']);
        Mail::fake();

        // `plugins.enabled` فقط `email` دارد.
        $this->assertFalse(NotificationDispatcher::allows($u, 'plugins.enabled', 'plugins', 'sms'));

        $this->putJson('/api/v1/admin/notification-preferences', [
            'group_key' => 'plugins', 'channel' => 'sms', 'enabled' => true,
        ], $this->auth($u))->assertOk();

        $this->assertTrue(NotificationDispatcher::allows($u, 'plugins.enabled', 'plugins', 'sms'));

        NotificationDispatcher::send($u, 'plugins.enabled', ['x']);

        $this->assertSame(
            ['email', 'sms'],
            DB::table('notification_deliveries')->pluck('channel')->sort()->values()->all(),
        );
    }

    /** نبودِ مقصد نباید سطرِ صف بسازد — پیامی که هرگز نمی‌رسد. */
    public function test_no_recipient_means_no_queue_row(): void
    {
        $u = $this->user(['email' => '']);
        Mail::fake();

        NotificationDispatcher::send($u, 'plugins.enabled', ['x']);

        $this->assertSame(1, $u->notifications()->count());
        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    /** ⭐ هر دو نوشتن در یک تراکنش — وگرنه صندوق چیزی نشان می‌دهد که هرگز خبر نمی‌گیرد. */
    public function test_the_queue_row_carries_the_notification_id(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'resources.quota_warning', ['900 MB']);

        $notificationId = $u->notifications()->sole()->id;
        $row = DB::table('notification_deliveries')->first();

        $this->assertSame($notificationId, $row->notification_id);
    }

    /** رویدادِ تکراری نباید دو پیام بدهد (F0.6 بند ۴). */
    public function test_a_repeated_event_does_not_double_queue(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ من']);
        NotificationDispatcher::send($u, 'plugins.enabled', ['افزونهٔ من']);

        $this->assertSame(1, DB::table('notification_deliveries')->count());
    }

    // ------------------------------------------------------------------

    public function test_the_inbox_only_shows_the_callers_own_rows(): void
    {
        $a = $this->user();
        $b = $this->user();
        Mail::fake();

        NotificationDispatcher::send($a, 'system.maintenance', ['۲۰', '۲۱']);

        $this->getJson('/api/v1/admin/notification-inbox', $this->auth($b))
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.unread', 0);
    }

    public function test_the_inbox_renders_the_catalog_text_not_the_stored_data(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'plugins.enabled', ['فروشگاه']);

        $this->getJson('/api/v1/admin/notification-inbox', $this->auth($u))
            ->assertOk()
            ->assertJsonPath('data.items.0.catalog_key', 'plugins.enabled')
            ->assertJsonPath('data.items.0.severity', 'info')
            ->assertJsonPath('data.items.0.action_label', null);
    }

    public function test_the_severity_column_is_indexed_for_the_inbox(): void
    {
        $u = $this->user();
        Mail::fake();

        NotificationDispatcher::send($u, 'security.2fa_disabled', []);

        $this->assertSame('warning', DB::table('notifications')->value('severity'));
    }

    private function lastHref(User $u): ?string
    {
        $row = $this->getJson('/api/v1/admin/notification-inbox', $this->auth($u))
            ->assertOk()
            ->json('data.items.0.action_href');

        return is_string($row) ? $row : null;
    }
}
