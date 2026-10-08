<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** تسک ۵.۳ — DoD: تیکت از فرم تماس + پاسخ + اعلان + شمارش واقعی داشبورد. */
class TicketsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    public function test_create_reply_close_ticket(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $ticketId = $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'خطا در سایت', 'body' => 'صفحه اصلی باز نمی‌شود.',
        ])->assertCreated()->json('data.id');

        $auth->getJson('/api/v1/admin/tickets')->assertOk()->assertJsonPath('data.total', 1);
        $auth->postJson("/api/v1/admin/tickets/{$ticketId}/messages", ['body' => 'پیگیری می‌کنم.'])
            ->assertCreated();

        $auth->getJson('/api/v1/admin/dashboard/stats')->assertOk()
            ->assertJsonPath('data.open_tickets', 1);

        $auth->putJson("/api/v1/admin/tickets/{$ticketId}", ['status' => 'closed'])->assertOk();

        $auth->getJson('/api/v1/admin/dashboard/stats')->assertOk()
            ->assertJsonPath('data.open_tickets', 0);

        $auth->postJson("/api/v1/admin/tickets/{$ticketId}/messages", ['body' => 'دوباره؟'])
            ->assertStatus(422);
    }

    public function test_tickets_are_shared_across_managers(): void
    {
        $owner = $this->owner();
        $other = User::query()->create([
            'name' => 'مدیر دوم', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Other!1234'), 'role' => 'admin',
        ]);
        $other->assignRole('owner');

        $ticketId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/admin/tickets', [
            'subject' => 'تیکت مشترک',
            'body' => 'پیام اولیه',
        ])->assertCreated()->json('data.id');

        $otherAuth = $this->actingAs($other->fresh(), 'sanctum');
        $otherAuth->getJson('/api/v1/admin/tickets')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.0.id', $ticketId);
        $otherAuth->getJson("/api/v1/admin/tickets/{$ticketId}")
            ->assertOk()
            ->assertJsonPath('data.subject', 'تیکت مشترک');
        $otherAuth->putJson("/api/v1/admin/tickets/{$ticketId}", ['priority' => 'high'])
            ->assertOk();
        $otherAuth->postJson("/api/v1/admin/tickets/{$ticketId}/messages", ['body' => 'پاسخ مدیر دوم'])
            ->assertCreated();
    }

    public function test_missing_ticket_attachment_returns_not_found(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'ضمیمه ناموجود',
            'body' => 'متن',
            'attachment_media_id' => 999999,
        ])->assertNotFound()->assertJsonPath('message', 'فایل ضمیمه یافت نشد.');
    }

    /** مشترک نصب: ضمیمه هر رسانه موجود نصب پذیرفته است. */
    public function test_attachment_accepts_shared_media(): void
    {
        $me = $this->owner();
        $other = User::query()->create([
            'name' => 'مدیر دوم', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Other!1234'), 'role' => 'admin',
        ]);

        $mine = Media::query()->create([
            'user_id' => $me->id, 'disk' => 'local', 'path' => 'a.png',
            'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $shared = Media::query()->create([
            'user_id' => $other->id, 'disk' => 'local', 'path' => 'b.png',
            'original_name' => 'b.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $auth = $this->actingAs($me, 'sanctum');
        $ticketId = $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'ضمیمه', 'body' => 'با فایل خودم.',
            'attachment_media_id' => $mine->id,
        ])->assertCreated()->json('data.id');

        $auth->postJson("/api/v1/admin/tickets/{$ticketId}/messages", [
            'body' => 'فایل مشترک.', 'attachment_media_id' => $shared->id,
        ])->assertCreated();
    }

    public function test_operator_reply_creates_internal_notification(): void
    {
        $admin = $this->owner();
        $ticket = Ticket::query()->create([
            'user_id' => $admin->id, 'subject' => 'کمک', 'source' => 'panel',
        ]);

        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
        // مسیرِ هسته گیتِ صریح دارد: اپراتور هم باید `tickets.edit` را داشته باشد.
        // این تست **قبلاً** از مسیرِ افزونه رد می‌شد که همان گیت را نداشت.
        $operator->givePermissionTo('tickets.edit');

        $this->actingAs($operator->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/tickets/{$ticket->id}/messages", ['body' => 'بررسی شد.'])
            ->assertCreated()->assertJsonPath('message', 'پاسخ ثبت شد.');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_contact_form_creates_ticket_and_honeypot_fakes_success(): void
    {
        $before = Ticket::query()->count();

        $ticketId = $this->postJson('/api/v1/contact/tickets', [
            'name' => 'بازدیدکننده', 'email' => 'visitor@example.com',
            'subject' => 'سؤال', 'body' => 'قیمت‌ها؟',
        ])->assertCreated()->json('data.ticket_id');
        $this->assertNotEmpty($ticketId);

        // بات: honeypot پر → موفقیت جعلی، بدون رکورد.
        $this->postJson('/api/v1/contact/tickets', [
            'name' => 'بات', 'email' => 'bot@example.com',
            'subject' => 'spam', 'body' => 'spam', 'website' => 'http://spam.example',
        ])->assertCreated();

        $this->assertEquals($before + 1, Ticket::query()->count());
    }

    /** WF-M10 — اولویت جدید + برچسب‌ها: ذخیره، نرمال‌سازی و فیلتر. */
    public function test_priority_and_labels_persist_and_filter(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'کندی سایت', 'body' => 'صفحات دیر باز می‌شوند.',
            'priority' => 'urgent', 'labels' => ['پرداخت', 'پرداخت', '  ', 'فوری'],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('tickets', ['id' => $id, 'priority' => 'urgent']);

        $auth->getJson("/api/v1/admin/tickets/{$id}")->assertOk()
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.labels', ['پرداخت', 'فوری']);

        $auth->getJson('/api/v1/admin/tickets?priority=urgent')->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/admin/tickets?priority=low')->assertOk()->assertJsonPath('data.total', 0);

        $auth->getJson('/api/v1/admin/tickets?label='.urlencode('فوری'))->assertOk()->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/admin/tickets?label='.urlencode('ناموجود'))->assertOk()->assertJsonPath('data.total', 0);

        $auth->putJson("/api/v1/admin/tickets/{$id}", ['priority' => 'low', 'labels' => ['بازگشت‌پول']])
            ->assertOk()
            ->assertJsonPath('data.priority', 'low')
            ->assertJsonPath('data.labels', ['بازگشت‌پول']);

        $auth->putJson("/api/v1/admin/tickets/{$id}", ['priority' => 'super'])->assertStatus(422);
    }

    /** WF-M10 — خروجی CSV فقط ردیف‌های فیلترشده را برمی‌گرداند. */
    public function test_tickets_csv_export_respects_filters(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'درخواست بازگشت', 'body' => 'می‌خواهم لغو کنم.',
            'priority' => 'high', 'labels' => ['بازگشت'],
        ])->assertCreated();
        $auth->postJson('/api/v1/admin/tickets', [
            'subject' => 'سؤال عمومی', 'body' => 'چطور؟', 'priority' => 'low',
        ])->assertCreated();

        $res = $auth->get('/api/v1/admin/tickets/export?priority=high');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('subject', $csv);
        $this->assertStringContainsString('درخواست بازگشت', $csv);
        $this->assertStringContainsString('بازگشت', $csv);
        $this->assertStringNotContainsString('سؤال عمومی', $csv);
    }

    /** WF-M10 — خواندن/خروجی نیازمند tickets.view و نوشتن نیازمند tickets.edit. */
    public function test_ticket_writes_and_export_require_permission(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $nobody = User::query()->create([
            'name' => 'بدون نقش', 'email' => uniqid().'@example.com',
            'password' => Hash::make('None!1234'), 'role' => 'admin',
        ]);
        $none = $this->actingAs($nobody, 'sanctum');
        $none->getJson('/api/v1/admin/tickets')->assertForbidden();
        $none->get('/api/v1/admin/tickets/export')->assertForbidden();
        $none->postJson('/api/v1/admin/tickets', ['subject' => 'x', 'body' => 'y'])->assertForbidden();

        $viewer = User::query()->create([
            'name' => 'بیننده', 'email' => uniqid().'@example.com',
            'password' => Hash::make('View!1234'), 'role' => 'admin',
        ]);
        $viewer->assignRole('viewer');

        $vauth = $this->actingAs($viewer->fresh(), 'sanctum');
        $vauth->getJson('/api/v1/admin/tickets')->assertOk();
        $vauth->get('/api/v1/admin/tickets/export')->assertOk();
        $vauth->postJson('/api/v1/admin/tickets', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
    }
}
