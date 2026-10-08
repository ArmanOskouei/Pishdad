<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته ۱ (برابری UI/UX) — جستجوی متنی تیکت‌ها (موضوع + متن پیام). */
class TicketSearchTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    private function ticket(User $user, string $subject, string $body): Ticket
    {
        $ticket = Ticket::query()->create([
            'user_id' => $user->id, 'subject' => $subject,
            'status' => 'open', 'priority' => 'normal', 'source' => 'panel',
        ]);
        $ticket->messages()->create([
            'user_id' => $user->id, 'author_type' => 'admin', 'body' => $body,
        ]);

        return $ticket;
    }

    public function test_search_matches_subject_and_message_body(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->ticket($user, 'خطا در درگاه پرداخت', 'سلام، پرداخت انجام نمی‌شود.');
        $this->ticket($user, 'سؤال درباره پلن', 'زرین‌پال قطع است؟');
        $this->ticket($other, 'خطا در درگاه پرداخت', 'مشکل دیگری دارم.');
        $auth = $this->actingAs($user, 'sanctum');

        // موضوع خود کاربر.
        $auth->getJson('/api/v1/admin/tickets?search='.urlencode('پلن'))
            ->assertOk()->assertJsonPath('data.total', 1);
        // متن پیام (زرین‌پال فقط در پیام دوم است).
        $auth->getJson('/api/v1/admin/tickets?search='.urlencode('زرین‌پال'))
            ->assertOk()->assertJsonPath('data.total', 1);
        // تیکت مدیر دیگر هم دیده می‌شود (مشترک نصب).
        $auth->getJson('/api/v1/admin/tickets?search='.urlencode('درگاه'))
            ->assertOk()->assertJsonPath('data.total', 2);
    }
}
