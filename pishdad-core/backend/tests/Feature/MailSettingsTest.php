<?php

namespace Tests\Feature;

use App\Mail\MailSettings;
use App\Mail\MailTemplates;
use App\Mail\OutboundEmail;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * WF-H11 — SMTP اختصاصی + قالب‌های ایمیل.
 *
 * ادعاهای کلیدی: رمز در حالت سکون رمزنگاری می‌شود، هرگز به کلاینت برنمی‌گردد،
 * ارسال آزمایشی از transport جعلی (بدون شبکهٔ واقعی) عبور می‌کند، و قالب‌ها
 * پایدار می‌مانند و پاک‌سازی می‌شوند.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(array $perms = ['settings.view', 'settings.edit']): User
    {
        $user = User::query()->create([
            'name' => 'مدیر ایمیل',
            'email' => 'mail'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    public function test_show_returns_defaults_without_password(): void
    {
        $res = $this->actingAs($this->user(), 'sanctum')
            ->getJson('/api/v1/admin/settings/mail');

        $res->assertOk()
            ->assertJsonPath('data.port', 587)
            ->assertJsonPath('data.encryption', 'tls')
            ->assertJsonPath('data.password_set', false);

        $this->assertArrayNotHasKey('password', $res->json('data'));
        $this->assertArrayNotHasKey('password_encrypted', $res->json('data'));
        $this->assertArrayHasKey('contact', $res->json('data.templates'));
    }

    public function test_update_persists_settings_and_encrypts_password_at_rest(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $res = $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'user@example.com',
            'password' => 'SuperSecret!123',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'آرمان',
            'encryption' => 'tls',
        ]);

        $res->assertOk()
            ->assertJsonPath('message', 'تنظیمات ایمیل ذخیره شد.')
            ->assertJsonPath('data.host', 'smtp.example.com')
            ->assertJsonPath('data.password_set', true);

        // رمز خام ذخیره نشده؛ فقط رمزگشایی‌شدنی است.
        $stored = Setting::get('mail', 'global');
        $this->assertNotSame('SuperSecret!123', $stored['password_encrypted']);
        $this->assertSame('SuperSecret!123', Crypt::decryptString($stored['password_encrypted']));

        // و هرگز در پاسخِ خواندن برنمی‌گردد.
        $show = $auth->getJson('/api/v1/admin/settings/mail')->assertOk();
        $this->assertArrayNotHasKey('password', $show->json('data'));
        $this->assertArrayNotHasKey('password_encrypted', $show->json('data'));
        $this->assertTrue($show->json('data.password_set'));
    }

    public function test_an_empty_password_keeps_the_existing_one(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'password' => 'KeepMe!123',
        ])->assertOk();

        $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp2.example.com', 'port' => 465, 'encryption' => 'ssl',
            'password' => '',
        ])->assertOk()->assertJsonPath('data.password_set', true);

        $this->assertSame('KeepMe!123', MailSettings::password());
    }

    public function test_clear_password_removes_it(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'password' => 'ThrowAway!123',
        ])->assertOk();

        $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'clear_password' => true,
        ])->assertOk()->assertJsonPath('data.password_set', false);

        $this->assertNull(MailSettings::password());
    }

    public function test_test_email_uses_the_fake_transport(): void
    {
        Mail::fake();

        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/mail', [
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'user@example.com', 'password' => 'x',
        ])->assertOk();

        $auth->postJson('/api/v1/admin/settings/mail/test', ['to' => 'dest@example.com'])
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $mail): bool => $mail->hasTo('dest@example.com'));
    }

    public function test_test_email_fails_truthfully_without_smtp_host(): void
    {
        Mail::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/v1/admin/settings/mail/test', ['to' => 'dest@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('data.sent', false);

        Mail::assertNothingSent();
    }

    public function test_test_email_requires_a_valid_recipient(): void
    {
        Mail::fake();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/v1/admin/settings/mail/test', ['to' => 'not-an-email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_templates_persist_and_are_sanitized(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->putJson('/api/v1/admin/settings/mail/templates', [
            'templates' => [
                'contact' => [
                    'subject' => 'موضوع سفارشی {{subject}}',
                    'body' => '<p>سلام {{name}}</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
                ],
            ],
        ])->assertOk()->assertJsonPath('message', 'قالب‌های ایمیل ذخیره شد.');

        $stored = Setting::get('mail_templates', 'global');
        $this->assertStringContainsString('موضوع سفارشی', $stored['contact']['subject']);
        $this->assertStringNotContainsString('<script', $stored['contact']['body']);
        $this->assertStringNotContainsString('javascript:', $stored['contact']['body']);

        // رندر: متغیرها جایگزین و مقادیر HTML escape می‌شوند.
        $rendered = MailTemplates::render('contact', ['subject' => 'تیکت', 'name' => '<b>زهرا</b>']);

        $this->assertSame('موضوع سفارشی تیکت', $rendered['subject']);
        $this->assertStringContainsString('&lt;b&gt;زهرا&lt;/b&gt;', $rendered['body']);

        // خواندن دوباره همان قالب را می‌دهد (پایداری).
        $show = $auth->getJson('/api/v1/admin/settings/mail')->assertOk();
        $this->assertSame('موضوع سفارشی {{subject}}', $show->json('data.templates.contact.subject'));
    }

    public function test_smtp_config_maps_encryption_to_scheme(): void
    {
        $this->assertSame('smtps', MailSettings::smtpConfig(['encryption' => 'ssl'])['mail.mailers.smtp.scheme']);
        $this->assertSame('smtp', MailSettings::smtpConfig(['encryption' => 'tls'])['mail.mailers.smtp.scheme']);
        $this->assertSame('smtp', MailSettings::smtpConfig(['encryption' => 'none'])['mail.mailers.smtp.scheme']);
        $this->assertSame('smtp', MailSettings::smtpConfig(['encryption' => 'ssl'])['mail.default']);
    }

    public function test_runtime_mailer_is_not_overridden_under_tests(): void
    {
        Setting::set('mail', 'global', [
            'host' => 'smtp.example.com', 'port' => 25, 'username' => '', 'from_address' => '',
            'from_name' => '', 'encryption' => 'none', 'password_encrypted' => null,
        ]);

        MailSettings::applyToConfig();

        // phpunit.xml مقدار `MAIL_MAILER=array` را force می‌کند؛ این نباید عوض شود.
        $this->assertSame('array', config('mail.default'));
    }

    public function test_routes_require_permission(): void
    {
        $viewer = $this->user(['settings.view']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/settings/mail')
            ->assertOk();

        $this->actingAs($viewer, 'sanctum')
            ->putJson('/api/v1/admin/settings/mail', ['host' => 'x', 'encryption' => 'tls'])
            ->assertStatus(403);

        $this->actingAs($this->user([]), 'sanctum')
            ->getJson('/api/v1/admin/settings/mail')
            ->assertStatus(403);
    }
}
