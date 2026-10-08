<?php

namespace Tests\Feature;

use App\Mail\OutboundEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\CatalogNotification;
use App\Services\Notifications\TelegramSender;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * E6 — فرم تماس عمومی باید «مدیر فهمید پیامی آمد» را تولید کند.
 *
 * ایمیل عمداً فرستاده نمی‌شود (SMTP در نصبِ عمومی تضمین‌شده نیست)، پس این تست
 * همان چیزی را می‌سنجد که واقعاً تحویل داده می‌شود: یک اعلانِ دیتابیسی روی
 * کاربرانی که پنل را می‌بینند.
 */
class ContactFormNoticeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * E75 — حالا از دیسپچرِ کاتالوگ: همان یک سطرِ دیتابیس (با کلید و لینکِ
     * اقدام) + تلگرامِ فوری در همان تراکنش. ایمیل از مسیرِ پایینِ کنترلر
     * می‌رود، پس در کلید نیست تا دو بار نرود.
     */
    public function test_a_new_contact_ticket_notifies_every_admin(): void
    {
        $admin = $this->user(['role' => 'admin', 'name' => 'مدیر']);
        $admin2 = $this->user(['role' => 'admin', 'name' => 'مدیر دوم']);
        $plain = $this->user(['role' => 'editor', 'name' => 'مدیر عادی']);

        $res = $this->postJson('/api/v1/contact/tickets', [
            'name' => 'زهرا',
            'email' => 'zahra@example.com',
            'subject' => 'درخواست همکاری',
            'body' => 'سلام، برای همکاری پیام می‌دهم.',
        ]);

        $res->assertCreated()->assertJsonPath('message', 'پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.');

        $this->assertDatabaseCount('tickets', 1);

        foreach ([$admin, $admin2] as $superAdmin) {
            $notification = $superAdmin->notifications()->sole();
            $this->assertSame(CatalogNotification::class, $notification->type);
            $this->assertSame('ticket.created', $notification->data['catalog_key']);
            $this->assertSame('ticket.created', $notification->catalog_key);
            $this->assertContains('زهرا', $notification->data['values']);
            $this->assertContains('zahra@example.com', $notification->data['values']);
            // E77 — قالبِ جدید خطِ موضوع ندارد (فقط نام/ایمیل/تلفن/متن).
            $this->assertNotContains('درخواست همکاری', $notification->data['values']);
        }

        // مدیر عادی نباید اعلانِ مالیِ مشتری را ببیند.
        $this->assertSame(0, $plain->notifications()->count());
    }

    /**
     * E75 — پیامِ فرم تماس همان لحظه در صفِ تلگرام می‌نشیند (نه فقط در
     * خلاصهٔ روزانه): مدیرِ دارای chat_id + رباتِ تنظیم‌شده ⇒ سطرِ pending.
     */
    public function test_a_new_contact_ticket_is_queued_to_telegram_instantly(): void
    {
        TelegramSender::setToken('1234567890:'.str_repeat('A', 30));
        $this->user(['role' => 'admin', 'email' => 'admin1@example.com', 'telegram_chat_id' => '111']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'زهرا',
            'email' => 'zahra@example.com',
            'subject' => 'درخواست همکاری',
            'body' => 'سلام.',
        ])->assertCreated();

        $row = DB::table('notification_deliveries')->where('channel', 'telegram')->sole();
        $this->assertSame('111', $row->recipient);
        $this->assertSame('pending', $row->status);
    }

    /**
     * E77 — متنِ صف‌شده دقیقاً به فرمِ خواستهٔ کاربر است (سربرگ + نام/ایمیل/
     * تلفن/متن). این فرم فیلدِ تلفن ندارد ⇒ `—`.
     */
    public function test_queued_telegram_text_matches_the_requested_format(): void
    {
        TelegramSender::setToken('1234567890:'.str_repeat('A', 30));
        $this->user(['role' => 'admin', 'email' => 'admin1@example.com', 'telegram_chat_id' => '111']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'زهرا',
            'email' => 'zahra@example.com',
            'subject' => 'درخواست همکاری',
            'body' => 'سلام.',
        ])->assertCreated();

        $row = DB::table('notification_deliveries')->where('channel', 'telegram')->sole();
        $payload = json_decode((string) $row->payload, true);
        $text = (string) ($payload['text'] ?? '');
        foreach (['از طریق وب سایت فرم تماس ارسال شده است.', 'نام فرستنده: زهرا', 'ایمیل فرستنده: zahra@example.com', 'شماره تماس فرستنده: —', 'متن پیام: سلام.'] as $needle) {
            $this->assertStringContainsString($needle, $text, "متنِ صف‌شده باید «{$needle}» را داشته باشد.");
        }
    }

    public function test_no_chat_or_no_bot_means_no_telegram_row(): void
    {
        // نه chat_id، نه توکن: هیچ سطری نباید ساخته شود (fail-soft، نه سطرِ مرده).
        $this->user(['role' => 'admin', 'email' => 'admin1@example.com']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'زهرا',
            'email' => 'zahra@example.com',
            'subject' => 'درخواست همکاری',
            'body' => 'سلام.',
        ])->assertCreated();

        $this->assertSame(0, DB::table('notification_deliveries')->where('channel', 'telegram')->count());
    }

    public function test_the_form_still_answers_201_when_the_notice_pipeline_throws(): void
    {
        $broken = Mockery::mock(Dispatcher::class);
        $broken->shouldReceive('send')->andThrow(new \RuntimeException('notifications table missing'));
        $this->app->instance(Dispatcher::class, $broken);

        $res = $this->postJson('/api/v1/contact/tickets', [
            'name' => 'رضا',
            'email' => 'reza@example.com',
            'subject' => 'خطا',
            'body' => 'متن پیام.',
        ]);

        // تیکت ثبت شده ⇒ پاسخ باید ۲۰۱ بماند، وگرنه فرستنده دوباره می‌فرستد.
        $res->assertCreated();
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_the_honeypot_reply_creates_neither_a_ticket_nor_a_notice(): void
    {
        $admin = $this->user(['is_super_admin' => true]);

        $res = $this->postJson('/api/v1/contact/tickets', [
            'name' => 'ربات',
            'email' => 'bot@example.com',
            'subject' => 'تبلیغ',
            'body' => 'spam spam spam',
            'website' => 'http://spam.example',
        ]);

        $res->assertCreated();
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_a_missing_admin_is_logged_instead_of_swallowed(): void
    {
        Log::spy();

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'مینا',
            'email' => 'mina@example.com',
            'subject' => 'پرسش',
            'body' => 'سلام.',
        ])->assertCreated();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'سوپرادمین'))
            ->once();
    }

    // ------------------------------------------------------ E6 — ایمیلِ مدیر

    /**
     * ⭐ فرم تماس باید ایمیلِ مدیر را در outbox **صف** کند، نه با SMTP مستقیم.
     * صف یعنی شکستِ SMTP پاسخِ فرم را ۵۰۰ نمی‌کند و پیام با retry از دست نمی‌رود.
     */
    public function test_a_new_contact_ticket_is_queued_as_an_email_to_every_admin(): void
    {
        Mail::fake();

        $this->user(['role' => 'admin', 'email' => 'admin1@example.com']);
        $this->user(['role' => 'admin', 'email' => 'admin2@example.com']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'زهرا',
            'email' => 'zahra@example.com',
            'subject' => 'درخواست همکاری',
            'body' => 'سلام، برای همکاری پیام می‌دهم.',
        ])->assertCreated();

        $rows = DB::table('notification_deliveries')
            ->where('channel', 'email')
            ->orderBy('recipient')
            ->get();

        $this->assertCount(2, $rows, 'هر سوپرادمین باید یک ایمیلِ صف‌شده بگیرد.');
        $this->assertSame(['admin1@example.com', 'admin2@example.com'], $rows->pluck('recipient')->all());
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all());

        // هنوز دستی به Mailer نرسیده — این خودِ ادعای «صف، نه ارسال مستقیم» است.
        Mail::assertNothingSent();

        $this->artisan('outbox:drain')->assertSuccessful();

        Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $mail): bool => $mail->hasTo('admin1@example.com'));
        Mail::assertSent(OutboundEmail::class, fn (OutboundEmail $mail): bool => $mail->hasTo('admin2@example.com'));

        $this->assertSame(
            0,
            DB::table('notification_deliveries')->where('channel', 'email')->where('status', '!=', 'sent')->count(),
            'همهٔ ایمیل‌های صف‌شده باید پس از drain تحویل شده باشند.',
        );
    }

    public function test_the_configured_admin_address_overrides_superadmin_emails(): void
    {
        Mail::fake();
        config(['mail.admin_address' => 'ops@example.com']);

        $this->user(['role' => 'admin', 'email' => 'admin@example.com']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'رضا',
            'email' => 'reza@example.com',
            'subject' => 'پرسش',
            'body' => 'سلام.',
        ])->assertCreated();

        $row = DB::table('notification_deliveries')->where('channel', 'email')->sole();
        $this->assertSame('ops@example.com', $row->recipient);
    }

    public function test_an_invalid_configured_address_falls_back_to_superadmin_emails(): void
    {
        Mail::fake();
        config(['mail.admin_address' => 'not-an-email']);

        $this->user(['role' => 'admin', 'email' => 'admin@example.com']);

        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'مینا',
            'email' => 'mina@example.com',
            'subject' => 'پرسش',
            'body' => 'سلام.',
        ])->assertCreated();

        $row = DB::table('notification_deliveries')->where('channel', 'email')->sole();
        $this->assertSame('admin@example.com', $row->recipient, 'آدرسِ نامعتبر نباید به یک صندوقِ بی‌صاحب برود.');
    }

    public function test_without_any_admin_no_email_is_queued_and_nothing_crashes(): void
    {
        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'کسی',
            'email' => 'nobody@example.com',
            'subject' => 'بی‌مخاطب',
            'body' => 'سلام.',
        ])->assertCreated();

        $this->assertSame(0, DB::table('notification_deliveries')->count());
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'کاربر '.uniqid(),
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ], $overrides));
    }
}
