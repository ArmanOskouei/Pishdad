<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\TelegramSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * E73 — توکنِ رباتِ تلگرام از پنل (نه فقط `.env`).
 *
 * پیش‌تر توکن فقط در `.env` بود و در پنل هیچ راهی برای دیدن یا عوض‌کردنش
 * نبود؛ کاربر chat_id می‌داد ولی پیامی نمی‌رفت. حالا سه مسیر هست (خواندنِ
 * ماسک‌شده، ذخیره/پاک‌سازی، پیامِ آزمایشی) و هر سه پشت `perm:settings.edit`
 * اند — رازِ نصب است، نه تنظیمِ شخصی.
 *
 * سه قاعده قفل می‌شود: ① توکنِ کامل هرگز به مرورگر نمی‌رود ② `.env` اگر پر
 * باشد همیشه برنده است ③ خطای provider (توکن غلط، بلاک، قطعی) ۴۲۲ِ تمیز
 * می‌دهد نه ۵۰۰. ارسالِ واقعی با `Http::fake` سنجیده می‌شود تا تست به
 * اینترنت و رباتِ واقعی نیاز نداشته باشد.
 */
class TelegramBotSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/notification-preferences/telegram-bot';

    private function manager(array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'editor',
        ]);

        if ($permissions !== []) {
            $role = Role::query()->firstOrCreate(['name' => 'r'.uniqid(), 'guard_name' => 'web']);
            foreach ($permissions as $p) {
                Permission::query()->firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            }
            $role->givePermissionTo($permissions);
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function asEditor(): User
    {
        return $this->manager(['settings.view', 'settings.edit']);
    }

    private function auth(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    public function test_bot_endpoints_need_settings_edit(): void
    {
        $auth = $this->auth($this->manager());

        $auth->getJson(self::URL)->assertForbidden();
        $auth->putJson(self::URL, ['token' => '1234567890:'.str_repeat('A', 30)])->assertForbidden();
        $auth->postJson(self::URL.'/test', [])->assertForbidden();
    }

    public function test_unconfigured_bot_reports_cleanly(): void
    {
        // E74 — نسخهٔ منتشرشده یک رباتِ پیش‌فرض دارد، پس «بدون توکن» یعنی
        // اپراتور خودش کانال را خاموش کرده: خطِ خالیِ صریح در `.env`
        // (`TELEGRAM_BOT_TOKEN=`). اینجا همان حالت را می‌سازیم.
        config()->set('telegram.bot_token', '');

        $auth = $this->auth($this->asEditor());

        $auth->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.masked', null);
    }

    /**
     * ⭐ E74 — نصبِ تازه بدون هیچ تنظیمی باید تلگرامِ فعال داشته باشد.
     *
     * رباتِ عمومیِ پیشداد عمدی است (مثل کلیدِ عمومی، نه راز): کاربر فقط
     * chat_id می‌دهد و یک‌بار Start می‌زند. این تست همان ادعا را قفل می‌کند —
     * اگر کسی پیش‌فرض را از `config/telegram.php` بردارد، همین قرمز می‌شود.
     */
    public function test_a_zero_setup_install_already_has_the_pishdad_bot(): void
    {
        // نه `.env` تصمیمی گرفته، نه پنل چیزی ذخیره کرده ⇒ باید رباتِ
        // عمومی بنشیند. `null` یعنی «خط در `.env` اصلاً نیست».
        config()->set('telegram.bot_token', null);

        $this->assertFalse(
            Setting::query()->where('group', TelegramSender::SETTINGS_GROUP)
                ->where('key', TelegramSender::SETTINGS_KEY)->exists(),
            'این تست باید بدون توکنِ پنل اجرا شود.',
        );

        $res = $this->auth($this->asEditor())->getJson(self::URL)->assertOk();

        $res->assertJsonPath('data.configured', true)->assertJsonPath('data.is_default', true);
        $this->assertSame([], TelegramSender::missingKeys(), 'رباتِ پیش‌فرض باید `doctor` را ساکت کند.');

        $masked = $res->json('data.masked');
        $this->assertIsString($masked);
        $this->assertStringStartsWith('8970988910:AA***', $masked);
        $this->assertStringNotContainsString(TelegramSender::DEFAULT_TOKEN, $res->getContent());

        // الگوی توکن باید همان چیزی باشد که پنل می‌پذیرد، تا نسخهٔ خودِ
        // کاربر با همان اعتبارسنجی سنجیده شود.
        $this->assertSame(1, preg_match(TelegramSender::TOKEN_PATTERN, TelegramSender::DEFAULT_TOKEN));
    }

    /**
     * ⭐ E74 — پاک‌کردن از پنل برگشت به رباتِ عمومی است، نه خاموشی.
     *
     * پاک‌کردن یعنی «دیگر رباتِ خودم را نمی‌خواهم»، نه «کانال را ببندم»؛
     * برای خاموشیِ واقعی `TELEGRAM_BOT_TOKEN=` (خالیِ صریح) در `.env` لازم
     * است. این تست همان برگشت را قفل می‌کند تا کسی فکر نکند پاک‌کردن یعنی
     * خاموشی و بعد گیت را به خرابیِ خاموش تغییر ندهد.
     */
    public function test_clearing_the_panel_token_falls_back_to_the_default_bot(): void
    {
        config()->set('telegram.bot_token', null);
        $auth = $this->auth($this->asEditor());

        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();
        $this->assertStringStartsWith('1234567890:AA', (string) TelegramSender::masked());

        $auth->putJson(self::URL, ['token' => null])->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.is_default', true);

        $this->assertSame(TelegramSender::DEFAULT_TOKEN, TelegramSender::token());
        $this->assertStringStartsWith('8970988910:AA***', (string) TelegramSender::masked());
    }

    /**
     * سیم‌کشیِ خودِ بسته: پیش‌فرضِ `config/telegram.php` باید همان رباتِ
     * پیشداد باشد. اگر کسی رشته را در فایل عوض کند ولی ثابت را نه، همین قرمز
     * می‌شود — وگرنه کاربر توکنِ یک رباتِ دیگر را می‌بیند ولی پنل می‌گوید
     * «رباتِ پیش‌فرض».
     */
    public function test_the_shipped_config_default_is_the_pishdad_bot(): void
    {
        $this->assertSame(TelegramSender::DEFAULT_TOKEN, config('telegram.default_token'));

        config()->set('telegram.bot_token', null);
        Setting::query()->where('group', TelegramSender::SETTINGS_GROUP)
            ->where('key', TelegramSender::SETTINGS_KEY)->delete();

        $this->assertTrue(TelegramSender::usesDefaultBot());
    }

    public function test_garbage_token_is_rejected(): void
    {
        config()->set('telegram.bot_token', '');
        $auth = $this->auth($this->asEditor());

        // رشتهٔ خالی/فاصله یعنی پاک‌سازی است، نه توکن — آن را اینجا نمی‌سنجیم.
        foreach (['nope', 'abc:short', '123:'.str_repeat('x', 100)] as $bad) {
            $auth->putJson(self::URL, ['token' => $bad])->assertStatus(422);
        }

        // هیچ‌کدام نباید چیزی ذخیره کرده باشد.
        $this->assertFalse(TelegramSender::configured());
    }

    public function test_token_roundtrips_masked_and_never_leaks(): void
    {
        config()->set('telegram.bot_token', null);
        $auth = $this->auth($this->asEditor());
        $token = '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn';

        $res = $auth->putJson(self::URL, ['token' => $token])->assertOk();
        $res->assertJsonPath('data.configured', true);

        $masked = $res->json('data.masked');
        $this->assertIsString($masked);
        $this->assertStringStartsWith('1234567890:AA***', $masked);
        // توکنِ کامل نباید هیچ‌جای پاسخ باشد.
        $this->assertStringNotContainsString($token, $res->getContent());

        $auth->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.masked', $masked);
    }

    /**
     * ⭐ E74 — خطِ خالیِ `TELEGRAM_BOT_TOKEN=` یعنی «خاموش»، و پنل باید
     * **بگوید** نه اینکه توکن را بپذیرد و بی‌صدا بی‌اثر رها کند.
     *
     * بدون این ردِ صریح، مدیر توکن خودش را ذخیره می‌کرد، پیام «ذخیره شد» می‌دید
     * و هیچ اعلانی نمی‌رسید — بدترین حالتِ ممکن برای کاربری که دارد تلاش
     * می‌کند.
     */
    public function test_a_blank_env_line_refuses_the_panel_token_with_a_clear_message(): void
    {
        config()->set('telegram.bot_token', '');
        $auth = $this->auth($this->asEditor());

        $res = $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])
            ->assertStatus(422);

        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN', (string) $res->json('errors.token.0'));

        // و چیزی هم ذخیره نشده باشد.
        $this->assertFalse(
            Setting::query()->where('group', TelegramSender::SETTINGS_GROUP)
                ->where('key', TelegramSender::SETTINGS_KEY)->exists(),
        );
    }

    public function test_env_token_always_wins_over_the_panel(): void
    {
        config()->set('telegram.bot_token', '9999999999:ENVenvENVenvENVenvENVenv00');

        $auth = $this->auth($this->asEditor());

        // حتی با توکنِ پنل، ماسک باید مالِ env باشد.
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();
        $masked = TelegramSender::masked();
        $this->assertIsString($masked);
        $this->assertStringStartsWith('9999999999:EN***', $masked);
    }

    public function test_test_message_needs_a_destination(): void
    {
        config()->set('telegram.bot_token', null);
        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        // نه chat داده شده، نه کاربر chat_id دارد.
        $auth->postJson(self::URL.'/test', [])->assertStatus(422);
    }

    public function test_test_message_reaches_the_provider(): void
    {
        config()->set('telegram.bot_token', null);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertOk()
            ->assertJson(['message' => 'پیام آزمایشی فرستاده شد؛ تلگرام را ببینید.']);

        Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/sendMessage'));
    }

    public function test_provider_rejection_is_a_clean_422(): void
    {
        config()->set('telegram.bot_token', null);
        // توکنِ غلط: تلگرام 401 می‌دهد (نه exception شبکه).
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn'])->assertOk();

        $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertStatus(422);
    }

    public function test_connection_errors_never_leak_the_token(): void
    {
        config()->set('telegram.bot_token', null);
        // cURL نشانیِ کامل (شاملِ توکن) را در پیامِ خطا می‌گذارد؛ پاسخِ API
        // نباید آن را برگرداند — نه در متن، نه در لاگِ کاربر.
        $token = '1234567890:AAbbCCddEEffGGhhIIjjKKllMMnn';
        Http::fake(['api.telegram.org/*' => function () use ($token): never {
            throw new \Illuminate\Http\Client\ConnectionException(
                "cURL error 7: Failed to connect to api.telegram.org:443 for https://api.telegram.org/bot{$token}/sendMessage"
            );
        }]);

        $auth = $this->auth($this->asEditor());
        $auth->putJson(self::URL, ['token' => $token])->assertOk();

        $res = $auth->postJson(self::URL.'/test', ['chat_id' => '12345'])->assertStatus(422);
        $this->assertStringNotContainsString($token, $res->getContent());
        $this->assertStringContainsString('/bot***', $res->getContent());
    }
}
