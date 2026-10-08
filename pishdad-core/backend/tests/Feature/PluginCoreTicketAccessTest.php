<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\PluginNotification;
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginDbContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * K7.13 — آیا یک افزونه می‌تواند `operatorReply` را بگیرد؟
 *
 * محصول تصمیم گرفت `operatorReply` به افزونه برود. این تست‌ها **پیش‌شرط‌های**
 * آن تصمیم را می‌سنجند، نه خودِ انتقال را.
 *
 * ## چرا مهم است
 *
 * «از نظر معماری مجاز است» جملهٔ بی‌ارزشی است اگر کسی تستش نکند. سه چیز باید
 * همزمان درست باشند و هر کدام می‌تواند بی‌صدا خراب شود:
 *  ۱) افزونه بتواند کلاس‌های `App\Models` هسته را **بخواند**.
 *  ۲) قرارداد DB جلوی **ساختن** جدول هسته را بگیرد (ولی نه خواندنش).
 *  ۳) `PluginAutoloader` namespace هسته را مسدود کند.
 */
class PluginCoreTicketAccessTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(): Plugin
    {
        // `firstOrCreate` نه `create`: ممکن است تستی پیش از این، همین slug را
        // ساخته باشد. `create` روی آن به `plugins_slug_unique` می‌خورد و کل تست
        // با `UniqueConstraintViolationException` می‌مرد.
        //
        // این تست فقط به «یک افزونهٔ فعال با namespace ثبت‌شده» نیاز دارد، نه به
        // مانیفست خودش — پس رکورد موجود دقیقاً همان چیزی است که لازم دارد.
        $p = Plugin::query()->firstOrCreate(
            ['slug' => 'sample'],
            [
                'name' => 'افزونه نمونه',
                'version' => '1.0.0',
                'manifest' => [],
                'active' => true,
            ],
        );

        // همان الگوی `registerFromRelease`: prefix از slug مشتق می‌شود، پس
        // افزونه نمی‌تواند namespace دلخواهش را انتخاب کند.
        //
        // ⚠️ ریشه اینجا عمداً یک پوشهٔ **خالی** در temp است، نه
        // `plugins/sample/Laravel/src`. تست می‌خواهد ثابت کند بارگذار
        // namespace را ثبت می‌کند و کلاسِ `App\Models` هنوز در دسترس است —
        // پس به کدِ افزونه نیازی ندارد، و مسیرِ واقعی فقط آن را به تست
        // وابسته می‌کرد.
        //
        // J11 — ولی اگر از قبل همین slug ثبت شده باشد، ثبت با پوشهٔ خالی
        // قبلی آن را **بازنویسی** می‌کرد و ریشهٔ واقعی از دست می‌رفت. پس فقط
        // وقتی چیزی ثبت نشده.
        $loader = app(PluginAutoloader::class);

        if (! $loader->isRegistered('sample')) {
            $root = sys_get_temp_dir().'/plugin-sample';
            if (! is_dir($root)) {
                mkdir($root, 0o777, true);
            }

            $loader->register(
                'sample',
                $root,
                PluginAutoloader::NAMESPACE_ROOT.Str::studly('sample').'\\',
            );
        }

        return $p;
    }

    // ── ۱) افزونه می‌تواند مدل هسته را بخواند ────────────────────────────

    public function test_a_plugin_class_can_resolve_a_core_model(): void
    {
        $this->plugin();

        $this->assertTrue(
            class_exists(Ticket::class),
            'افزونه باید بتواند Ticket هسته را resolve کند.',
        );
        $this->assertTrue(class_exists(TicketMessage::class));
        $this->assertTrue(class_exists(PluginNotification::class));
    }

    public function test_reading_a_core_ticket_works_from_a_plugin_namespace(): void
    {
        $this->plugin();

        $user = User::query()->create([
            'name' => 'مشتری',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ]);

        $ticket = Ticket::query()->create([
            'user_id' => $user->id,
            'subject' => 'مشکل ورود',
            'status' => Ticket::OPEN,
        ]);

        $message = $ticket->messages()->create([
            'user_id' => $user->id,
            'author_type' => 'customer',
            'body' => 'نمی‌توانم وارد شوم.',
        ]);

        // همان چیزی که `operatorReply` انجام می‌دهد، از داخل namespace افزونه.
        $reply = $ticket->messages()->create([
            'user_id' => $user->id,
            'author_type' => 'operator',
            'body' => 'پاسخ اپراتور.',
        ]);

        $this->assertSame('operator', $reply->author_type);
        $this->assertCount(2, $ticket->messages()->get());
        $this->assertSame($message->id, $ticket->messages()->orderBy('id')->first()->id);
    }

    public function test_a_plugin_can_notify_a_user_through_the_core_channel(): void
    {
        $this->plugin();

        $user = User::query()->create([
            'name' => 'مشتری',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ]);

        $user->notify(PluginNotification::fromPayload(['title' => 'پاسخ اپراتور']));

        $this->assertSame(1, $user->notifications()->count());
    }

    // ── ۲) قرارداد DB جلوی ساختن جدول هسته را می‌گیرد ─────────────────────

    public function test_a_plugin_may_not_declare_a_core_ticket_table(): void
    {
        // اگر افزونه بتواند `tickets` را اعلام کند، عملاً یک نسخهٔ دوم از
        // جدول هسته می‌سازد و دادهٔ واقعی جایی می‌ماند که UI آن را نمی‌بیند.
        foreach (['tickets', 'ticket_messages', 'notifications'] as $table) {
            $this->assertContains(
                $table,
                PluginDbContract::CORE_TABLES,
                "«{$table}» باید در فهرست ممنوع باشد.",
            );
        }
    }

    public function test_the_deny_list_matches_the_real_schema(): void
    {
        // تستِ خودِ قرارداد این را چک می‌کند؛ اینجا فقط یادآوری است که
        // `CORE_TABLES` دستی نگه داشته می‌شود و جدول تازه باید اضافه شود.
        foreach (['tickets', 'ticket_messages'] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "جدول «{$table}» باید در اسکیما وجود داشته باشد.",
            );
        }
    }

    // ── ۳) namespace هسته مسدود است ──────────────────────────────────────

    public function test_the_autoloader_refuses_a_core_namespace_prefix(): void
    {
        // افزونه نمی‌تواند خودش را در `App\` جا بزند. این تضمین می‌کند که
        // «افزونه می‌تواند هسته را بخواند» به «افزونه می‌تواند هسته را
        // بازنویسی کند» تبدیل نشود.
        //
        // ⚠️ **دلیلِ خطا** عمداً بررسی می‌شود، نه فقط خودِ استثنا.
        //
        // نسخهٔ اول این تست فقط `expectException` داشت و **کاذب** بود: با
        // برداشتن guard، ثبتِ `App\Services\` باز هم خطا می‌داد چون
        // `normalizeSlug` و بررسی `SEGMENT_PATTERN` همان ورودی را رد
        // می‌کردند — ولی با دلیل اشتباه. یعنی تست سبز می‌شد در حالی که guardِ
        // موردِ نظر اصلاً وجود نداشت. همان شکلی از نگهبانی که K100 داشت.
        //
        // حالا پیام خطا را می‌سنجیم تا معلوم شود **همان** guard زنجیره را متوقف
        // کرده، نه یک چکِ تصادفیِ دیگر.
        $loader = app(PluginAutoloader::class);

        try {
            $loader->register('evil', sys_get_temp_dir(), 'App\\Services\\');
            $this->fail('ثبت پیشوند هسته باید رد می‌شد.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                self::NAMESPACE_GUARD_HINT,
                $e->getMessage(),
                'خطا باید از guardِ ریشهٔ namespace بیاید، نه از چک تصادفیِ دیگر.',
            );
        }

        // و مهم‌تر: اصلاً ثبت نشده باشد.
        $this->assertArrayNotHasKey(
            'evil',
            $loader->registeredSlugs(),
            'بستهٔ ردشده نباید در فهرست ثبت‌شده‌ها باشد.',
        );
    }

    /** متنِ مشترکِ پیامِ guardِ ریشهٔ namespace. */
    private const NAMESPACE_GUARD_HINT = 'ریشهٔ اجباری';

    public function test_the_autoloader_refuses_to_reject_a_root_level_prefix(): void
    {
        // ثبت بدون پیشوند باید خطا بدهد، نه بی‌صدا همه‌چیز را بار کند.
        //
        // دوباره **دلیل** خطا بررسی می‌شود: پیشوند خالی هم از guard ریشه رد
        // می‌شود و هم از `SEGMENT_PATTERN` (چون `rest` خالی می‌ماند و
        // `preg_match` روی رشتهٔ خالی شکست می‌خورد). بدون سنجش پیام، این تست
        // هم کاذب بود.
        $loader = app(PluginAutoloader::class);

        try {
            $loader->register('evil2', sys_get_temp_dir(), '');
            $this->fail('پیشوند خالی باید رد می‌شد.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                self::NAMESPACE_GUARD_HINT,
                $e->getMessage(),
            );
        }

        $this->assertArrayNotHasKey('evil2', $loader->registeredSlugs());
    }

    // ── مرزی که باید بدانیم باز است ──────────────────────────────────────

    public function test_core_tables_are_not_prefixed_for_plugins_on_read(): void
    {
        // ⚠️ این تست **قانون** را ثبت می‌کند، نه اینکه آن را تأیید کند.
        //
        // `CORE_TABLES` فقط جلوی *اعلام* جدول با همان نام را می‌گیرد. خواندن
        // جدول هسته از داخل افزونه آزاد است — چون `PluginAutoloader` فقط
        // namespace افزونه را ثبت می‌کند و دسترسی به `App\Models` را مسدود
        // نمی‌کند.
        //
        // این همان چیزی است که تصمیم K7.13 رویش ایستاده. اگر روزی کسی
        // خواست افزونه به جدول هسته **نرسد**، باید این تست عمداً قرمز شود تا
        // معلوم شود آن محدودیت تازه اضافه شده — نه اینکه بی‌صدا برسد.
        $this->assertTrue(
            class_exists(Ticket::class),
            'خواندن مدل هسته از افزونه آزاد است؛ اگر روزی بسته شد، این تست قرمز می‌شود.',
        );

        // و جدول واقعاً همان نام بدون پیشوند است — یعنی افزونه باید
        // `tickets` را بخواند، نه `plugin_tickets`.
        $this->assertSame('tickets', (new Ticket)->getTable());
    }

    /**
     * مرزِ باقی‌مانده: نامِ خامِ جدولی که افزونه
     * اعلام می‌کند **هیچ‌وقت** در دیتابیس نمی‌نشیند.
     *
     * پیشوند از slug خودِ افزونه می‌آید، پس افزونه نه می‌تواند نامِ خامِ یک
     * جدولِ هسته را اعلام کند و نه با نامِ اعلامی به جای داده‌های هسته می‌نشیند.
     */
    public function test_a_plugin_table_can_never_land_on_a_core_name(): void
    {
        // جدولِ هسته‌ای که هنوز وجود دارد: اعلامش باید از همان دروازه رد شود،
        // نه فقط اینکه اسمش در فهرست باشد.
        $issues = PluginDbContract::check(['tables' => [['name' => 'tickets']]], 'demo');
        $this->assertSame('db.table_collides_core', $issues[0]['code'] ?? null);

        // و یک نامِ آزاد پذیرفته می‌شود، ولی فقط با پیشوندِ slug خودش.
        $this->assertSame([], PluginDbContract::check(['tables' => [['name' => 'hardware_keys']]], 'demo'));
        $this->assertSame('demo_hardware_keys', PluginDbContract::prefixed('demo', 'hardware_keys'));

        // جدولِ هسته سرِ جایش است و جدولِ افزونه — تا اعلام نشود — وجود ندارد.
        $this->assertTrue(Schema::hasTable('tickets'));
        $this->assertFalse(Schema::hasTable('demo_hardware_keys'));
    }
}
