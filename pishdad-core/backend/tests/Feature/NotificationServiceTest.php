<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PluginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * K5.8 — سرویس اعلان.
 *
 * جدول `notifications` و `TicketNotification` از قبل بودند ولی **هیچ مسیر
 * خواندنی** نداشتند. یعنی اعلان‌ها نوشته می‌شدند و هرگز دیده نمی‌شدند.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ])->fresh();
    }

    private function push(User $user, array $payload): void
    {
        $user->notify(PluginNotification::fromPayload($payload + ['title' => $payload['title'] ?? 'اعلان']));
    }

    // ── خواندن ─────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/notifications')->assertUnauthorized();
    }

    public function test_index_returns_empty_for_a_user_with_no_notifications(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $res = $auth->getJson('/api/v1/admin/notifications');

        $res->assertOk();
        $this->assertSame([], $res->json('data.items'));
        $this->assertSame(0, $res->json('data.unread'));
    }

    public function test_index_returns_the_current_user_s_notifications(): void
    {
        $me = $this->user();
        $other = $this->user();

        $this->push($me, ['title' => 'برای من', 'body' => 'متن', 'severity' => 'info']);
        $this->push($other, ['title' => 'برای دیگری']);

        $auth = $this->actingAs($me, 'sanctum');
        $res = $auth->getJson('/api/v1/admin/notifications');

        $res->assertOk();
        $titles = collect($res->json('data.items'))->pluck('title');
        $this->assertContains('برای من', $titles);
        // مهم: اعلان کاربر دیگر نباید دیده شود. هیچ پارامتری برای انتخاب
        // کاربر وجود ندارد و این هم قفل می‌شود.
        $this->assertNotContains('برای دیگری', $titles);
    }

    public function test_index_counts_unread(): void
    {
        $me = $this->user();
        $this->push($me, ['title' => 'الف']);
        $this->push($me, ['title' => 'ب']);

        $auth = $this->actingAs($me, 'sanctum');
        $this->assertSame(2, $auth->getJson('/api/v1/admin/notifications')->json('data.unread'));
    }

    public function test_index_caps_per_page(): void
    {
        $me = $this->user();
        for ($i = 0; $i < 4; $i++) {
            $this->push($me, ['title' => 'ن'.$i]);
        }

        $auth = $this->actingAs($me, 'sanctum');
        // عدد بزرگ نباید خطا بدهد و نباید همه را برگرداند.
        $res = $auth->getJson('/api/v1/admin/notifications?per_page=2');
        $this->assertCount(2, $res->json('data.items'));
        // `unread` اما شمار کل است، نه شمار همین صفحه.
        $this->assertSame(4, $res->json('data.unread'));
    }

    // ── نوشتن ─────────────────────────────────────────────────────────────

    public function test_store_creates_a_notification(): void
    {
        $me = $this->user();

        $auth = $this->actingAs($me, 'sanctum');
        $auth->postJson('/api/v1/admin/notifications', [
            'title' => 'سفارش جدید',
            'body' => 'شماره ۱۰۲۴',
            'severity' => 'warning',
        ])->assertCreated();

        $this->assertSame(1, $me->notifications()->count());
    }

    public function test_store_notifies_only_the_current_user(): void
    {
        $me = $this->user();
        $other = $this->user();

        $auth = $this->actingAs($me, 'sanctum');
        // `user_id` در ورودی هست ولی نباید هیچ اثری داشته باشد — اعلان برای
        // خودِ فراخوان است. اگر روزی پارامتری اضافه شد و رعایت نشد، افزونه
        // می‌توانست برای مدیر دیگر پیام جعلی بفرستد.
        $auth->postJson('/api/v1/admin/notifications', [
            'title' => 'م جعلی',
            'user_id' => $other->id,
        ])->assertCreated();

        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame(1, $me->notifications()->count());
    }

    public function test_store_requires_a_title(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $auth->postJson('/api/v1/admin/notifications', ['body' => 'بدون عنوان'])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_store_caps_title_and_body_length(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');

        $auth->postJson('/api/v1/admin/notifications', ['title' => str_repeat('ا', 200)])
            ->assertStatus(422)->assertJsonValidationErrors('title');
        $auth->postJson('/api/v1/admin/notifications', [
            'title' => 'خوب', 'body' => str_repeat('ب', 700),
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_store_rejects_an_unknown_severity(): void
    {
        $auth = $this->actingAs($this->user(), 'sanctum');
        $auth->postJson('/api/v1/admin/notifications', [
            'title' => 'الف', 'severity' => 'apocalyptic',
        ])->assertStatus(422)->assertJsonValidationErrors('severity');
    }

    public function test_notification_id_must_be_a_positive_integer(): void
    {
        $me = $this->user();
        $auth = $this->actingAs($me, 'sanctum');

        $auth->postJson('/api/v1/admin/notifications', ['title' => 'الف', 'notification_id' => 0])
            ->assertStatus(422);
        $auth->postJson('/api/v1/admin/notifications', ['title' => 'ب', 'notification_id' => -3])
            ->assertStatus(422);

        $this->assertSame(0, $me->notifications()->count());
    }

    // ── normalization هنگام خواندن ────────────────────────────────────────

    public function test_unknown_severity_in_storage_degrades_to_info(): void
    {
        // اگر نسخهٔ قدیم‌تری شدتی ناشناخته نوشته باشد، UI نباید crash کند.
        $me = $this->user();
        $id = DB::table('notifications')->insertGetId([
            'id' => (string) Str::uuid(),
            'type' => PluginNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $me->id,
            'data' => json_encode(['title' => 'قدیمی', 'severity' => 'weird']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auth = $this->actingAs($me, 'sanctum');
        $item = collect($auth->getJson('/api/v1/admin/notifications')->json('data.items'))
            ->firstWhere('id', $id);

        $this->assertSame('info', $item['severity']);
    }

    public function test_a_string_notification_id_in_storage_degrades_to_null(): void
    {
        // دادهٔ ذخیره‌شده ممکن است رشته باشد. تبدیل به مسیر با نوع قطعی نیاز
        // دارد، پس رشته به `null` می‌افتد نه اینکه حدس بزنیم.
        $me = $this->user();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => PluginNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $me->id,
            'data' => json_encode(['title' => 'قدیمی', 'notification_id' => '12']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auth = $this->actingAs($me, 'sanctum');
        $item = collect($auth->getJson('/api/v1/admin/notifications')->json('data.items'))
            ->firstWhere('id', $id);

        $this->assertNull($item['notification_id']);
    }

    public function test_malformed_data_json_does_not_break_the_response(): void
    {
        // `data` ستون text است، پس یک ردیف خراب نباید کل endpoint را از کار
        // بیندازد — کاربر باید بقیهٔ اعلان‌هایش را ببیند.
        $me = $this->user();
        $this->push($me, ['title' => 'سالم']);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => PluginNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $me->id,
            'data' => 'not json at all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auth = $this->actingAs($me, 'sanctum');
        $res = $auth->getJson('/api/v1/admin/notifications');

        $res->assertOk();
        $titles = collect($res->json('data.items'))->pluck('title');
        $this->assertContains('سالم', $titles);
    }

    // ── خواندن‌شده و پاک‌سازی ─────────────────────────────────────────────

    public function test_read_all_marks_everything_read(): void
    {
        $me = $this->user();
        $this->push($me, ['title' => 'الف']);
        $this->push($me, ['title' => 'ب']);

        $auth = $this->actingAs($me, 'sanctum');
        $res = $auth->postJson('/api/v1/admin/notifications/read-all');

        $res->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(0, $me->unreadNotifications()->count());
    }

    public function test_read_all_does_not_touch_another_user_s_notifications(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->push($other, ['title' => 'مخصوص دیگری']);

        $this->actingAs($me, 'sanctum')->postJson('/api/v1/admin/notifications/read-all');

        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_read_old_notifications_are_pruned(): void
    {
        // تصمیم محصول: بله، حذف خودکار. بدون این جدول بی‌نهایت رشد می‌کند.
        $me = $this->user();
        $this->push($me, ['title' => 'خوانده‌شدهٔ قدیمی']);
        $this->push($me, ['title' => 'خوانده‌شدهٔ تازه']);
        $me->unreadNotifications()->update(['read_at' => now()]);

        // یکی را قدیمی می‌کنیم.
        $old = $me->notifications()->orderBy('created_at')->first();
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $auth = $this->actingAs($me, 'sanctum');
        $titles = collect($auth->getJson('/api/v1/admin/notifications')->json('data.items'))
            ->pluck('title');

        $this->assertNotContains('خوانده‌شدهٔ قدیمی', $titles);
        $this->assertContains('خوانده‌شدهٔ تازه', $titles);
    }

    public function test_unread_notifications_are_never_pruned(): void
    {
        // اعلان خوانده‌نشده یعنی کاربر هنوز ندیده. حذفش یعنی پیامی که هرگز
        // نرسید — پس سن پاک‌سازی فقط روی خوانده‌شده‌هاست.
        $me = $this->user();
        $this->push($me, ['title' => 'نخواندهٔ قدیمی']);
        $n = $me->notifications()->first();
        $n->forceFill(['created_at' => now()->subDays(400)])->save();

        $auth = $this->actingAs($me, 'sanctum');
        $titles = collect($auth->getJson('/api/v1/admin/notifications')->json('data.items'))
            ->pluck('title');

        $this->assertContains('نخواندهٔ قدیمی', $titles);
    }
}
