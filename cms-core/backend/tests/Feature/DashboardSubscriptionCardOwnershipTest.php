<?php

namespace Tests\Feature;

use App\Services\Subscription\ClientSubscriptionResolver;
use App\Services\Subscription\SubscriptionResolver;
use App\Services\Subscription\SubscriptionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * تست مالکیتِ کارتِ اشتراک در داشبورد (تسک B1.2، بازنویسی‌شده در J5).
 *
 * ## مسئله‌ای که کاربر دید (B1.2)
 *
 * با غیرفعال کردن پلاگین `subscription`، آیتم منوی «اشتراک من» از منو حذف شد —
 * ولی کارت اشتراک در داشبورد **همچنان دیده می‌شد**.
 *
 * ## آنچه J5 تغییر داد
 *
 * نسخهٔ قبلِ این تست یک ادعای نادرست را قفل می‌کرد: اینکه «کارت از `central`
 * می‌آید، پس با خاموش‌شدنش می‌رود». آن زمان درست بود چون
 * `ClientSubscriptionResolver` مستقیم `Central\Models\Subscription` را import
 * می‌کرد.
 *
 * J5 همان import را برداشت. حالا:
 *
 *  - هیچ فایلِ هسته‌ای کلاسی از افزونهٔ مرکزی را نمی‌شناسد
 *    (`CentralBoundaryTest` این را تضمین می‌کند)،
 *  - و بنابراین ادعای «مالکیت از راه کلاس» دیگر قابلِ بیان نیست.
 *
 * مالکیتِ واقعی حالا از راه **رابط** است: `SubscriptionResolver`. پس تست هم
 * باید از همان راه بپرسد.
 *
 * @see \Tests\Feature\CentralBoundaryTest  تضمینِ نبودِ ارجاعِ مستقیم
 */
class DashboardSubscriptionCardOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_always_returns_a_source_chain(): void
    {
        $sources = (new \ReflectionMethod(ClientSubscriptionResolver::class, 'sources'))
            ->invoke(null);

        $this->assertNotEmpty($sources);

        foreach ($sources as $source) {
            $this->assertInstanceOf(SubscriptionSource::class, $source);
        }

        // منبع پیش‌فرضِ همیشه‌حاضر باید در انتهای زنجیره باشد.
        $this->assertInstanceOf(
            \App\Services\Subscription\NullSubscriptionSource::class,
            $sources[count($sources) - 1],
        );
    }

    /**
     * وقتی هیچ جدول مرکزی وجود ندارد (نصبِ تازهٔ مشتری، یا `central` خاموش)،
     * resolver باید `null` بدهد تا UI کارت را پنهان کند — و **500 ندهد**.
     *
     * ⚠️ این دقیقاً همان اشتباهی است که K7.2 قبلاً سایت عمومی را می‌شکست.
     */
    public function test_missing_central_tables_yield_null_not_an_exception(): void
    {
        $centralTables = ['central_clients', 'central_subscriptions'];

        $dropped = [];
        $existed = [];

        foreach ($centralTables as $table) {
            if (Schema::hasTable($table)) {
                $existed[] = $table;
            }
        }

        // در دیتابیس تست معمولاً جدول‌ها نیستند؛ اگر بودند، rename موقت.
        foreach ($existed as $table) {
            Schema::rename($table, $table.'_b12tmp');
            $dropped[] = $table;
        }

        try {
            [$client, $subscription] = ClientSubscriptionResolver::forUser(
                \App\Models\User::factory()->create(),
            );

            $this->assertNull($client);
            $this->assertNull($subscription);
        } finally {
            foreach ($dropped as $table) {
                Schema::rename($table.'_b12tmp', $table);
            }
        }
    }

    /**
     * J5 — کارتِ اشتراک دیگر از هسته داده نمی‌گیرد، و این تست همان را می‌سنجد.
     *
     * ## چرا این تست عوض شد و نه فقط کوتاه
     *
     * نسخهٔ قبل دو چیز را هم‌زمان ادعا می‌کرد که دیگر ممکن نیست:
     *
     *  ۱. `forUser()` یک آرایهٔ دوتایی می‌دهد،
     *  ۲. و عضوِ دومش (`daysUntilDue()`) یک متدِ **مدلِ افزونه** است.
     *
     * دومی یعنی هسته هنوز متدِ افزونه را صدا می‌زند — همان مرزی که J5 برداشت.
     * تست داشت قاعدهٔ مرز را خلافِ خودش اجباری می‌کرد، و بدتر: با هر اجرای
     * `migrate:fresh` می‌شد `skip` و عملاً هیچ محافظتی نمی‌داد.
     *
     * تقسیمِ کارِ امروز:
     *
     *  - `ClientSubscriptionResolver::forUser()` → فقط `tier`/`status`/`due_at`
     *    روی شیءِ ساده. مصرف‌کنندهٔ واقعی‌اش `MediaController::quotaState()`
     *    فقط `tier` را می‌خواند.
     *  - `days_remaining` → از `SubscriptionResolver::forUser()[]` می‌آید؛ همان
     *    چیزی که `DashboardController` مصرف می‌کند.
     */
    public function test_the_card_payload_no_longer_comes_from_a_plugin_model(): void
    {
        $user = \App\Models\User::factory()->create();

        $resolved = ClientSubscriptionResolver::forUser($user);

        // `null` یعنی «قابلیتِ اشتراک برای این کاربر نیست» — جوابِ درستِ نصبِ
        // بدون افزونه است و `MediaController` برایش fallback دارد.
        if ($resolved === null) {
            $this->assertFalse(
                SubscriptionResolver::isAvailable(),
                'forUser() نباید null بدهد وقتی resolver خودش می‌گوید داده دارد.'
            );

            return;
        }

        $this->assertCount(2, $resolved);

        $client = $resolved[0] ?? null;
        $subscription = $resolved[1] ?? null;

        // ساختارِ داده، همان چیزی که `MediaController` می‌خواند.
        $this->assertIsString($client->tier ?? null);
        $this->assertArrayHasKey('status', (array) $subscription);

        // ⚠️ این assert **خودِ مرز** است: اگر روزی کسی دوباره مدلِ Eloquent برگرداند،
        // اینجا می‌شکند — و باید بشکند.
        $this->assertNotInstanceOf(
            Model::class,
            $subscription,
            'forUser() نباید مدلِ افزونه را برگرداند؛ داده باید شیءِ ساده باشد.'
        );
        $this->assertFalse(
            method_exists($subscription, 'daysUntilDue'),
            'محاسبهٔ روزِ باقی‌مانده دیگر کارِ هسته نیست — از resolver بخوانید.'
        );
    }

    /**
     * J5 — `days_remaining` حالا در آرایهٔ resolver زندگی می‌کند، نه در مدل.
     *
     * این تست را از سمتِ **مصرف‌کننده** می‌سنجد: کلیدی که `DashboardController`
     * می‌خواند باید وجود داشته باشد و نوعش درست باشد — چون یک روز کسی کلید را
     * جابه‌جا می‌کند و کارتِ داشبورد بی‌صدا خالی می‌شود.
     *
     * و تعریفِ درست همین‌جاست: `daysUntilDue()` (تا سررسید) و نه
     * `purgeDaysRemaining()` (تا حذف) — چون دومی برای اشتراکِ فعال همیشه صفر
     * بود و کارت «۰ روز مانده» نشان می‌داد. آن انتخاب حالا در
     * `SubscriptionData::forUser()` زندگی می‌کند.
     */
    public function test_days_remaining_is_owned_by_the_resolver(): void
    {
        $user = \App\Models\User::factory()->create();

        $payload = SubscriptionResolver::forUser($user);

        $this->assertArrayHasKey('days_remaining', $payload);
        $this->assertTrue(
            $payload['days_remaining'] === null || is_int($payload['days_remaining']),
            'days_remaining یا null است یا عددِ روز — نه رشته و نه مدل.'
        );
    }
}