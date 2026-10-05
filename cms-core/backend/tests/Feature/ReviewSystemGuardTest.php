<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\SystemPlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * J7 — حلقهٔ وابستگی: «تأییدکنندهٔ افزونه در افزونه است».
 *
 * ## حلقه دقیقاً کجاست
 *
 * `ReviewController` (بستهٔ مرکزی) روی مدلِ `Plugin` هسته نوشته است. یعنی برای
 * اینکه یک افزونهٔ هسته‌ای تأیید شود، باید **افزونهٔ مرکزی نصب باشد**. اگر فردا
 * مرکزی به استقرارِ دوم برود (J4) و خاموش شود، صفِ بازبینی هم با او می‌رود —
 * و افزونه‌های سیستمیِ هسته دیگر قابلِ فعال‌سازی نیستند.
 *
 * این فقط مشکلِ استقرار نیست؛ یعنی **افزونهٔ هسته به افزونه وابسته است**، برعکسِ
 * همان قاعده‌ای که J5 برای بقیه برقرار کرد.
 *
 * ## اما این تست، یک رگرسیونِ واقعی را می‌گیرد که ربطی به استقرار ندارد
 *
 * `Plugin::isSystem()` می‌گوید افزونه‌های سیستمی با slugِ اجازه‌شده **محافظت**
 * می‌شوند — یعنی کاربر نباید بتواند حذفشان کند یا دوباره نصبشان کند. ولی
 * `ReviewController::approve()` این قاعده را دور می‌زند: فقط `review_status` را
 * عوض می‌کند و هیچ guard‌ای ندارد. یعنی یک اپراتور می‌تواند افزونهٔ سیستمی را
 * تأیید کند — و همان‌جا بود که `PluginDdlProvisioningFlowTest` انتظار `422` دارد.
 *
 * این تست هر دو را قفل می‌کند تا وقتی J4 تصمیم معماری‌اش را می‌گیرد، رفتارِ امنیتی
 * وسطِ کار عوض نشود.
 */
class ReviewSystemGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'),
            'role' => 'operator',
        ]);
    }

    /**
 * افزونهٔ سیستمی = یکی از slugهای `SystemPlugin::allows()`.
 *
 * ⚠️ رکورد را **نمی‌سازیم** بلکه همان که نصب‌کننده ساخته برمی‌داریم.
 * `plugins_slug_unique` یکتا است و مهاجرتِ نصب بسته‌های همراه (از جمله `central`)
 * را قبلاً ثبت کرده — ساختنِ دوباره‌اش خطای ۲۳۵۰۵ می‌دهد، نه رکوردِ تست.
 *
 * و این دقیقاً همان چیزی است که می‌خواهیم بسنجیم: افزونهٔ سیستمیِ **واقعی**.
 */
    private function systemPlugin(): Plugin
    {
        // در پروسهٔ تست جدولِ `system_plugins` خالی است (مهاجرتِ نصب در
        // `RefreshDatabase` اجرا نمی‌شود). پس slug را خودمان تعریف می‌کنیم —
        // که برای این تست **بهتر** هم هست: قاعدهٔ واقعی این است که هر slugِ
        // ثبت‌شده در `system_plugins` محافظت می‌شود، نه یک slugِ خاص.
        $slug = 'central';

        SystemPlugin::query()->create(['slug' => $slug, 'notes' => null]);
        SystemPlugin::flush();

        $this->assertTrue(
            SystemPlugin::allows($slug),
            'پیش‌شرط: slug باید واقعاً سیستمی شناخته شود، وگرنه تست هیچ‌چیز را نمی‌سنجد.'
        );

        $plugin = Plugin::query()->where('slug', $slug)->first();

        if ($plugin === null) {
            $plugin = Plugin::query()->create([
                'user_id' => null,
                'name' => 'افزونهٔ سیستمی برای بازبینی',
                'slug' => $slug,
                'version' => '1.0.0',
                'active' => true,
                'source' => Plugin::SOURCE_CORE,
                'review_status' => Plugin::REVIEW_PENDING,
            ]);
        }

        $plugin->forceFill([
            'review_status' => Plugin::REVIEW_PENDING,
            'submitted_at' => now(),
        ])->save();

        $this->assertTrue($plugin->fresh()->isSystem(), 'پیش‌شرط: این افزونه باید سیستمی باشد.');

        return $plugin->fresh();
    }

    public function test_a_system_plugin_cannot_be_approved_through_the_review_queue(): void
    {
        $plugin = $this->systemPlugin();

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson("/api/v1/p/central/review/plugin/{$plugin->id}/approve")
            ->assertStatus(422);

        // مهم‌تر از کدِ پاسخ: رکورد **نباید** عوض شده باشد.
        $this->assertSame(
            Plugin::REVIEW_PENDING,
            $plugin->fresh()->review_status,
            'افزونهٔ سیستمی نباید تأیید شود — حتی اگر endpoint پاسخِ دیگری بدهد.',
        );
    }

    public function test_a_system_plugin_cannot_be_rejected_through_the_review_queue(): void
    {
        $plugin = $this->systemPlugin();

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson("/api/v1/p/central/review/plugin/{$plugin->id}/reject", ['note' => 'دلیلِ کافی برای رد'])
            ->assertStatus(422);

        $this->assertSame(
            Plugin::REVIEW_PENDING,
            $plugin->fresh()->review_status,
        );
    }

    public function test_the_review_queue_does_not_list_system_plugins(): void
    {
        $this->systemPlugin();

        $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/p/central/review/queue')
            ->assertOk();

        $slugs = collect($this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/p/central/review/queue')->json('data'))
            ->pluck('slug')
            ->all();

        $this->assertNotContains('central', $slugs, 'افزونهٔ سیستمی نباید در صفِ بازبینی بیاید.');
    }

    /**
     * افزونهٔ عادیِ کاربر باید همچنان بازبینی شود — این نگهبان نباید راهِ اصلی را
     * ببندد.
     *
     * بدون این تست، یک راه‌حلِ غلطِ ساده («هر چیزی که `source=core` دارد را رد کن»)
     * هم سبز می‌شد و بازار را از کار می‌انداخت.
     */
    public function test_an_ordinary_submitted_plugin_is_still_reviewable(): void
    {
        $owner = User::query()->create([
            'name' => 'ناشر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Publisher!1234'),
            'role' => 'admin',
        ]);

        $plugin = Plugin::query()->create([
            'user_id' => $owner->id,
            'name' => 'افزونهٔ بازارِ کاربر',
            'slug' => 'demo-market-plugin',
            'version' => '0.1.0',
            'active' => false,
            'source' => Plugin::SOURCE_MARKET,
            'review_status' => Plugin::REVIEW_PENDING,
        ]);

        $this->assertFalse($plugin->isSystem(), 'پیش‌شرط: این افزونه نباید سیستمی باشد.');

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson("/api/v1/p/central/review/plugin/{$plugin->id}/approve")
            ->assertOk();

        $this->assertSame(Plugin::REVIEW_APPROVED, $plugin->fresh()->review_status);
    }
}