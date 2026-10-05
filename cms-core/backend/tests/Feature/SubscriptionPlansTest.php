<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Subscription\Models\Plan;
use Pishdad\Plugins\Subscription\Models\Subscription;
use Tests\TestCase;

/**
 * تسک ۱ (بازبینی UI/UX) — فهرست پلن‌ها + فاکتورِ مابه‌التفاوتِ ارتقا.
 *
 * ## L-B12 — این تست کجا می‌نشیند
 *
 * این تست **عمداً** فقط یک بستهٔ خصوصی را فعال می‌کند و هیچ ردیفی برای مرکز
 * نمی‌سازد، چون «پلن» و «ارتقا» دامنهٔ **اشتراک** هستند نه مرکز. از L-B12 دادهٔ
 * اشتراک در `subscription_plans` / `subscription_subscriptions` /
 * `subscription_invoices` زندگی می‌کند و خودِ افزونه آن‌ها را می‌سازد.
 *
 * نسخهٔ قبلی روی `Central\Models\{Plan,Client,Invoice}` و جدول
 * `central_invoices` نوشته شده بود — یعنی تستِ «اشتراک» عملاً داشت مسیرِ
 * دادهٔ مرکز را می‌سنجید. بعد از انتقال داده، آن تست دیگر چیزی را که فکر
 * می‌کرد نمی‌سنجید (و در واقع شکست می‌خورد، که خوب است).
 *
 * ## چرا `servePrivatePlugin` را خودش صدا می‌زند
 *
 * migrationهای ثبتِ خودکارِ بسته، در محیط تست **هیچ ردیفی نمی‌سازند** — پایهٔ
 * تست عمداً خالی است. پس هر تستی که بسته می‌خواهد باید خودش اعلامش کند.
 *
 * @see ServesPrivatePlugin
 * @see SubscriptionTest برای وضعیت/فاکتور/پرداخت
 */
class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;
    use ServesPrivatePlugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servePrivatePlugin('subscription', [
            ['method' => 'get', 'path' => 'plans', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@plans', 'middleware' => ['auth:sanctum']],
            ['method' => 'post', 'path' => 'change-plan', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@changePlan', 'middleware' => ['auth:sanctum']],
        ]);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function plan(string $slug, string $tier, int $monthly, bool $active = true): Plan
    {
        return Plan::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'tier' => $tier,
            'price_monthly' => $monthly,
            'price_yearly' => $monthly * 10,
            'currency' => 'IRT',
            'features' => ['الف', 'ب'],
            'active' => $active,
        ]);
    }

    /**
     * کاربر را روی یک پلنِ مشخص می‌نشاند.
     *
     * کلیدِ اتصال `owner_user_id` است، نه `site_id` — `site_id` فقط وقتی پر است
     * که مرکز این نصب را ثبت کرده باشد، پس نمی‌شود مبنای نگاشت باشد.
     */
    private function subscribe(User $user, Plan $plan): Subscription
    {
        return Subscription::query()->create([
            'owner_user_id' => $user->id,
            'site_id' => 'site-'.$user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'due_at' => now()->addDays(20),
        ]);
    }

    /**
     * فقط پلن‌های فعال، به ترتیب قیمت — پلن غیرفعال نباید دیده شود.
     */
    public function test_plans_lists_only_active(): void
    {
        $user = $this->user();
        $this->plan('budget', 'budget', 100000);
        $this->plan('standard', 'standard', 300000);
        $this->plan('legacy', 'budget', 50000, false);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/subscription/plans');

        $res->assertOk();
        $this->assertCount(2, $res->json('data'), 'فقط دو پلنِ فعال باید برگردد.');
        $this->assertSame('budget', $res->json('data.0.slug'));
        $this->assertSame('standard', $res->json('data.1.slug'));
    }

    /**
     * ارتقا فقط مابه‌التفاوت را فاکتور می‌کند، نه کل مبلغ را.
     */
    public function test_change_plan_creates_diff_invoice(): void
    {
        $user = $this->user();
        $budget = $this->plan('budget', 'budget', 100000);
        $standard = $this->plan('standard', 'standard', 300000);
        $this->subscribe($user, $budget);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/admin/subscription/change-plan', [
            'plan_id' => $standard->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.amount', 200000)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.meta.type', 'plan_change')
            ->assertJsonPath('data.meta.to_plan_id', $standard->id);

        $this->assertDatabaseHas('subscription_invoices', [
            'amount' => 200000,
            'status' => 'pending',
        ]);
    }

    /**
     * سه حالتِ رد: همان پلن، پلنِ ارزان‌تر، پلنِ غیرفعال.
     *
     * ⭐ پلنِ ارزان‌تر هم «تنزل» است و باید رد شود — وگرنه یک مسیرِ downgrade
     * باز می‌ماند که فاکتورِ منفی می‌سازد.
     */
    public function test_change_plan_rejects_same_or_cheaper_or_inactive(): void
    {
        $user = $this->user();
        $budget = $this->plan('budget', 'budget', 100000);
        $standard = $this->plan('standard', 'standard', 300000);
        $legacy = $this->plan('legacy', 'budget', 50000, false);
        $this->subscribe($user, $standard);

        $auth = $this->actingAs($user, 'sanctum');

        // همان پلنِ فعلی
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $standard->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'همین پلن در حال حاضر فعال است.');

        // پلن ارزان‌تر (تنزل)
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $budget->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ارتقا فقط به پلن بالاتر (گران‌تر) مجاز است.');

        // پلن غیرفعال
        $auth->postJson('/api/v1/admin/subscription/change-plan', ['plan_id' => $legacy->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'این پلن در حال حاضر فعال نیست.');
    }
}
