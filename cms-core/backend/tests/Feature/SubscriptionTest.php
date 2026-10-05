<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Subscription\Models\Invoice;
use Pishdad\Plugins\Subscription\Models\Plan;
use Pishdad\Plugins\Subscription\Models\Subscription;
use Tests\TestCase;

/**
 * تسک ۱.۲ — DoD: دیدن اشتراک/فاکتور + پرداخت Stub + polling عمومی.
 *
 * ## L-B12 — این تست دیگر به مرکز وابسته نیست
 *
 * نسخهٔ قبلی روی مدل‌های `Central\Models\{Client,Subscription,Invoice}` و جدول
 * `central_payments` نوشته شده بود — یعنی تستِ «اشتراک» عملاً تستِ «مرکز» بود.
 * حالا داده در `subscription_*` ساخته می‌شود و `serveCentralPlugin()` هم لازم
 * نیست، که دقیقاً همان استقلالی است که L-B12 خواسته بود.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use ServesPrivatePlugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servePrivatePlugin('subscription', [
            ['method' => 'get', 'path' => 'show', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@show', 'middleware' => ['auth:sanctum']],
            ['method' => 'get', 'path' => 'invoices', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@invoices', 'middleware' => ['auth:sanctum']],
            ['method' => 'post', 'path' => 'invoices/{invoice}/pay', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@pay', 'middleware' => ['auth:sanctum']],
            ['method' => 'get', 'path' => 'plans', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@plans', 'middleware' => ['auth:sanctum']],
            ['method' => 'post', 'path' => 'change-plan', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@changePlan', 'middleware' => ['auth:sanctum']],
            ['method' => 'get', 'path' => 'status', 'handler' => 'Pishdad\\Plugins\\Subscription\\Http\\SubscriptionController@status', 'middleware' => []],
        ]);
    }

    private function user(string $role = 'admin'): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => $role,
        ]);
    }

    private function plan(string $tier = 'budget', int $monthly = 100000): Plan
    {
        return Plan::query()->create([
            'name' => 'پلن '.$tier,
            'slug' => 'plan-'.uniqid(),
            'tier' => $tier,
            'price_monthly' => $monthly,
            'price_yearly' => $monthly * 10,
            'active' => true,
        ]);
    }

    private function subscribe(User $user, ?Plan $plan = null, string $status = 'active'): Subscription
    {
        return Subscription::query()->create([
            'owner_user_id' => $user->id,
            'site_id' => 'site-'.$user->id,
            'plan_id' => ($plan ?? $this->plan())->id,
            'status' => $status,
            'due_at' => now()->addMonth(),
        ]);
    }

    /**
     * DoD ۱: اولین بازدید نباید ۴۰۴ بدهد و باید پلنِ پایه را بسازد.
     */
    public function test_show_autoprovides_own_subscription(): void
    {
        $user = $this->user();
        $this->plan('budget', 100000);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/subscription');

        $res->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tier', 'budget');

        $this->assertNotEmpty($res->json('data.due_at'));
        $this->assertDatabaseHas('subscription_subscriptions', [
            'owner_user_id' => $user->id, 'status' => 'active',
        ]);
    }

    /**
     * DoD ۲: فهرست فاکتورها فقط مالِ خودِ کاربر — و مالِ کسِ دیگر نیست.
     *
     * این تستِ مرزِ امنیتی است (IDOR): اگر کوئری به `owner_user_id` قید نخورد،
     * کاربر می‌تواند با یک id حدس‌زدنی فاکتورِ غریبه را ببیند.
     */
    public function test_invoices_lists_only_own(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        $mySub = $this->subscribe($mine);
        $theirSub = $this->subscribe($theirs);

        $mySub->invoices()->create(['amount' => 100000, 'status' => 'pending', 'currency' => 'IRT']);
        $mySub->invoices()->create(['amount' => 90000, 'status' => 'paid', 'currency' => 'IRT']);
        $theirSub->invoices()->create(['amount' => 200000, 'status' => 'pending', 'currency' => 'IRT']);

        $res = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/admin/subscription/invoices');

        $res->assertOk()->assertJsonPath('total', 2);

        $amounts = collect($res->json('data'))->pluck('amount');
        $this->assertTrue($amounts->contains(100000), 'فاکتور خودی باید دیده شود.');
        $this->assertFalse($amounts->contains(200000), 'فاکتورِ دیگری نباید دیده شود.');
    }

    /**
     * J10 — `pay` دیگر دروغ نمی‌گوید.
     *
     * نسخهٔ قبلی این تست `STUB-` و `sandbox.zarinpal.com` را می‌خواست: یعنی
     * تست، دروغِ Stub را **قفل می‌کرد**. این بدترین نوع تست بود — نه فقط اینکه
     * چیزی را نمی‌سنجید، بلکه راهِ اصلاحش را هم می‌بستد.
     *
     * نیتِ اصلیِ تست («بدون شارژ واقعی») سرِ جایش مانده و حالا **قوی‌تر** است:
     * نه‌تنها شارژی انجام نمی‌شود، بلکه هیچ نشانهٔ پرداختِ ساختگی هم تولید
     * نمی‌شود — نه `authority`، نه `pay_url`، نه `gateway_ref` روی فاکتور.
     */
    public function test_pay_says_billing_is_manual_and_invents_nothing(): void
    {
        $user = $this->user();
        $subscription = $this->subscribe($user);
        $invoice = $subscription->invoices()->create([
            'amount' => 500000, 'status' => 'pending', 'currency' => 'IRT',
        ]);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$invoice->id}/pay");

        $res->assertOk()
            ->assertJsonPath('message', 'صورتحساب دستی است')
            ->assertJsonPath('data.manual_billing', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 500000);

        // نه لینک ساختگی، نه مرجع ساختگی. وجودشان یعنی دوباره به کاربر دروغ گفته شده.
        $this->assertNull($res->json('data.pay_url'), 'نباید لینک پرداخت ساختگی برگردد.');
        $this->assertNull($res->json('data.authority'), 'نباید authority ساختگی برگردد.');

        // فاکتور دست‌نخورده می‌ماند: نه `paid` می‌شود، نه `gateway_ref` می‌گیرد.
        $fresh = $invoice->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->gateway_ref, 'مرجع درگاهِ ساختگی نباید روی فاکتور بنشیند.');
    }

    /**
     * ⭐ مرزِ امنیتی: فاکتورِ کس دیگر و فاکتورِ پرداخت‌شده هر دو رد می‌شوند.
     *
     * J10 — این تست عمداً **زنده نگه داشته شد**. حذفِ Stub نباید به قیمتِ حذفِ
     * مرزِ IDOR تمام شود: حتی وقتی پرداختِ آنلاین وجود ندارد، endpoint نباید
     * وجودِ فاکتورِ غریبه را لو بدهد (۴۰۴) یا فاکتورِ تمام‌شده را دوباره بپذیرد (۴۲۲).
     */
    public function test_pay_rejects_foreign_or_paid_invoice(): void
    {
        $mine = $this->user();
        $theirs = $this->user();

        $theirInvoice = $this->subscribe($theirs)->invoices()->create([
            'amount' => 700000, 'status' => 'pending', 'currency' => 'IRT',
        ]);
        $paidInvoice = $this->subscribe($mine)->invoices()->create([
            'amount' => 800000, 'status' => 'paid', 'currency' => 'IRT',
        ]);

        // فاکتورِ غریبه ⇒ ۴۰۴ (نه ۴۰۳: وجودش را هم لو نمی‌دهیم).
        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$theirInvoice->id}/pay")
            ->assertNotFound();

        // فاکتورِ پرداخت‌شده ⇒ ۴۲۲.
        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/v1/admin/subscription/invoices/{$paidInvoice->id}/pay")
            ->assertStatus(422);
    }

    /**
     * DoD ۴: polling عمومی و سبک.
     *
     * ⭐ سه‌گانهٔ `active` / `suspended` / `missing` — این تفکیک قبلاً یکی بود و
     * دو باگ می‌ساخت: سایتِ خودِ کاربر ۴۰۴ می‌گرفت، و سایتِ ناشناس فعال
     * گزارش می‌شد.
     */
    public function test_public_status_polling_is_lightweight(): void
    {
        $user = $this->user();
        $subscription = $this->subscribe($user);
        $siteId = $subscription->site_id;

        // ۱) سایتِ شناخته‌شده و فعال ⇒ active
        $this->getJson("/api/v1/p/subscription/status?site_id={$siteId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // ۲) بدون site_id ⇒ نه ۴۰۴، نه تعلیق. «نمی‌دانیم کدام سایت» است.
        $this->getJson('/api/v1/p/subscription/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // ۳) site_id ناشناس ⇒ missing
        $this->getJson('/api/v1/p/subscription/status?site_id=nope')
            ->assertOk()
            ->assertJsonPath('data.status', 'missing');

        // ۴) تعلیق ⇒ suspended
        $subscription->update(['status' => 'suspended']);
        $this->getJson("/api/v1/p/subscription/status?site_id={$siteId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    /**
     * L-B12 — مسیرِ قدیمی نباید بشکند، ولی باید deprecated علامت بخورد.
     */
    public function test_legacy_alias_still_works_and_marks_deprecation(): void
    {
        $user = $this->user();
        $this->plan();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/subscription');

        $res->assertOk();
        $this->assertSame('true', $res->headers->get('Deprecation'), 'مسیر قدیمی باید deprecated علامت بخورد.');
    }

    /**
     * ⭐ مرزِ امنیتی: مسیرِ قدیمی نباید بدون توکن جواب بدهد.
     */
    public function test_legacy_alias_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/subscription')->assertUnauthorized();
    }

    /**
     * L-B12 — افزونه باید بتواند بدون مرکز بالا بیاید.
     *
     * این مهم‌ترین تستِ استقلال: نه `serveCentralPlugin()` صدا زده می‌شود، نه
     * جدول `central_*` ساخته می‌شود. اگر روزی کدی دوباره به مرکز وابسته شود،
     * همین تست می‌میرد.
     */
    public function test_works_without_central_plugin_installed(): void
    {
        $user = $this->user();
        $this->plan();

        // ⭐ آزمونِ واقعیِ استقلال: مسیرِ اشتراک باید **هیچ ردیفی** از مرکز
        // لازم نداشته باشد. جدول‌های `central_*` ممکن است در DB باشند (core
        // آن‌ها را می‌سازد)، ولی داده‌ای در آن‌ها نیست و مسیر نباید بخواند.
        Plugin::query()->where('slug', 'central')->delete();
        DB::table('central_clients')->delete();
        DB::table('central_subscriptions')->delete();

        $user = $this->user();
        $this->plan();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/p/subscription/show');

        $res->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.status', 'active');
    }
}
