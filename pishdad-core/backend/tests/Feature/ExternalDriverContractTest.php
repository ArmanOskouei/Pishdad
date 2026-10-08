<?php

namespace Tests\Feature;

use App\Mail\OutboundEmail;
use App\Services\External\DependencyChecker;
use App\Services\External\DependencyStatus;
use App\Services\External\MissingCredentialException;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\NullSmsDriver;
use App\Services\Sms\PanelSmsDriver;
use App\Services\Sms\SmsDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E2/E3/E4 — قراردادِ «درایور انتخاب‌شده ولی کلید نیست».
 *
 * ## چرا این تست‌ها هستند و چرا این‌قدر سخت‌گیرانه
 *
 * سه وابستگیِ این پروژه **بدونِ کلید** کار نمی‌کنند و هیچ‌کدام هم به‌طور پیش‌فرض
 * exception نمی‌دادند. نتیجه: اپراتور `MAIL_MAILER=smtp` یا `SMS_DRIVER=panel`
 * می‌گذاشت، فکر می‌کرد وصل است، و سایت یا بی‌صدا ایمیل نمی‌فرستاد یا هر درخواست
 * را ۱۰ ثانیه معطل می‌کرد.
 *
 * این تست‌ها همان چیزی را قفل می‌کنند که بدست آمد:
 *
 *  ۱. درایورِ واقعی + کلیدِ ناقص ⇒ **exception** با نامِ دقیقِ کلید.
 *  ۲. درایورِ بی‌خطر (`null`/`log`/`stub`) ⇒ **هیچ exception‌ای**، و `delivers()`
 *     صادقانه `false` است.
 *  ۳. درایورِ ناشناخته ⇒ به درایورِ بی‌خطر برمی‌گردد، **ولی** `doctor` آن را
 *     گزارش می‌کند (تایپِ اشتباه پنهان نماند).
 */
class ExternalDriverContractTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ پیامک (E3)

    public function test_sms_defaults_to_the_null_driver_and_never_claims_delivery(): void
    {
        config(['sms.driver' => 'null']);

        $sender = SmsDriverFactory::make();

        $this->assertInstanceOf(NullSmsDriver::class, $sender);
        $this->assertFalse($sender->delivers(), 'درایور null هرگز نباید ادعای تحویل کند.');
        $this->assertFalse($sender->send('09120000000', 'سلام'), 'درایور null نباید موفق برگردد.');
        $this->assertNotNull($sender->lastError());
    }

    public function test_sms_log_driver_records_but_does_not_claim_delivery(): void
    {
        config(['sms.driver' => 'log']);

        $sender = SmsDriverFactory::make();

        $this->assertInstanceOf(LogSmsDriver::class, $sender);
        $this->assertFalse($sender->delivers());
        $this->assertFalse($sender->send('09120000000', 'کد ۱۲۳۴'));
    }

    public function test_sms_panel_without_credentials_fails_closed_naming_the_exact_keys(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => null,
            'sms.panel.api_key' => null,
            'sms.panel.sender' => null,
        ]);

        try {
            SmsDriverFactory::make();
            $this->fail('درایور panel بدون کلید باید exception بدهد.');
        } catch (MissingCredentialException $e) {
            $this->assertSame('panel', $e->driver);
            $this->assertSame(
                ['SMS_PANEL_URL', 'SMS_PANEL_API_KEY', 'SMS_PANEL_SENDER'],
                $e->keys,
                'پیام خطا باید هر سه کلیدِ جاافتاده را نام ببرد.',
            );

            // پیام باید **فارسی** باشد و نام کلید را داخل خودش داشته باشد،
            // وگرنه اپراتور نمی‌داند کجای `.env` را باز کند.
            foreach (['SMS_PANEL_URL', 'SMS_PANEL_API_KEY', 'SMS_PANEL_SENDER'] as $key) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $e->getMessage());
        }
    }

    public function test_sms_panel_partially_configured_reports_only_the_missing_key(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => 'https://panel.example.test',
            'sms.panel.api_key' => 'secret-key',
            'sms.panel.sender' => '', // تنها این یکی جا افتاده
        ]);

        $this->assertSame(
            ['SMS_PANEL_SENDER'],
            SmsDriverFactory::missingKeys(),
            'نباید کلیدهای پرشده را هم گزارش کرد — اپراتور فقط همین یکی را باید پر کند.',
        );
    }

    public function test_sms_panel_with_all_credentials_resolves_and_claims_delivery(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => 'https://panel.example.test',
            'sms.panel.api_key' => 'secret-key',
            'sms.panel.sender' => '10004346',
        ]);

        $sender = SmsDriverFactory::make();

        $this->assertInstanceOf(PanelSmsDriver::class, $sender);
        $this->assertTrue($sender->delivers());
    }

    public function test_an_unknown_sms_driver_falls_back_to_null_instead_of_throwing(): void
    {
        config(['sms.driver' => 'kavenegar']); // تایپِ اشتباهِ محتمل

        $sender = SmsDriverFactory::make();

        $this->assertInstanceOf(NullSmsDriver::class, $sender, 'درایور ناشناخته نباید سایت را بیندازد.');
        $this->assertFalse($sender->delivers());
    }

    // ----------------------------------------------- پیامکِ واقعی (E3) — ارسال

    /** @return array<string, mixed> */
    private function configuredPanel(): array
    {
        return [
            'sms.driver' => 'panel',
            'sms.panel.url' => 'https://panel.example.test',
            'sms.panel.api_key' => 'secret-key',
            'sms.panel.sender' => '10004346',
        ];
    }

    public function test_panel_send_returns_true_only_when_the_gateway_accepts_the_message(): void
    {
        config($this->configuredPanel());
        Http::fake(['panel.example.test/*' => Http::response(['status' => 200], 200)]);

        $sender = SmsDriverFactory::make();

        $this->assertTrue($sender->send('09120000000', 'کد تأیید شما: ۱۲۳۴۵۶'));
        $this->assertNull($sender->lastError());

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/sms/send')
            && $req['to'] === '09120000000'
            && $req['sender'] === '10004346');
    }

    public function test_panel_send_is_honest_when_the_gateway_rejects_in_its_body(): void
    {
        config($this->configuredPanel());
        // HTTP 200 است ولی درگاه در بدنه گفته پیام را نپذیرفته — همان تله‌ای
        // که اگر فقط به کدِ HTTP نگاه کنیم با «ارسال شد» دروغ می‌شود.
        Http::fake(['panel.example.test/*' => Http::response(['status' => 401], 200)]);

        $sender = SmsDriverFactory::make();

        $this->assertFalse($sender->send('09120000000', 'متن'), 'ردشدنِ درگاه نباید موفق گزارش شود.');
        $this->assertNotNull($sender->lastError());
        $this->assertStringContainsString('نپذیرفت', (string) $sender->lastError());
    }

    public function test_panel_send_reports_a_transport_error_without_claiming_delivery(): void
    {
        config($this->configuredPanel());
        Http::fake(fn () => throw new ConnectionException('gateway is down'));

        $sender = SmsDriverFactory::make();

        $this->assertFalse($sender->send('09120000000', 'متن'));
        $this->assertStringContainsString('اتصال', (string) $sender->lastError());
    }

    /**
     * ⭐ ضعیف‌ترین حالت: درگاه HTTP 200 می‌دهد و بدنه هیچ `status` ندارد.
     * اینجا فقط HTTP ملاک است، ولی باید **در لاگ هشدار** بنشیند تا کسی نداند
     * واقعاً چه شد — سکوت یعنی ادعای تحویلِ بدونِ شاهد.
     */
    public function test_panel_send_warns_when_the_gateway_body_has_no_status_key(): void
    {
        config($this->configuredPanel());
        Http::fake(['panel.example.test/*' => Http::response('OK', 200)]);
        Log::spy();

        $sender = SmsDriverFactory::make();

        $this->assertTrue($sender->send('09120000000', 'متن'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $ctx): bool => $event === 'sms.panel_response_without_status'
                && ($ctx['to'] ?? null) === '09120000000')
            ->once();
    }

    // -------------------------------------------------------------- SMTP (E2)

    public function test_the_smtp_mailer_is_fully_config_driven_and_hardcodes_no_credentials(): void
    {
        $smtp = config('mail.mailers.smtp');

        $this->assertSame('smtp', $smtp['transport']);
        $this->assertIsInt($smtp['timeout'], 'MAIL_TIMEOUT باید عدد باشد تا کلیدِ رشته‌ای اشتباهی قفل نکند.');

        // ⭐ هیچ اعتبارنامه‌ای در مخزن نیست: در محیط تست فقط از env می‌آید و
        // اینجا خالی است. اگر روزی کسی یک مقدارِ واقعی را هاردکد کند، این
        // تست قرمز می‌شود.
        $this->assertTrue(
            in_array($smtp['username'], [null, ''], true),
            'MAIL_USERNAME نباید هیچ مقدارِ هاردکدی داشته باشد.',
        );
        $this->assertTrue(
            in_array($smtp['password'], [null, ''], true),
            'MAIL_PASSWORD نباید هیچ مقدارِ هاردکدی داشته باشد.',
        );

        // زنجیرهٔ failover موجود است: smtp → log. یعنی SMTP مرده پیام را گم نمی‌کند.
        $this->assertSame(['smtp', 'log'], config('mail.mailers.failover.mailers'));
        $this->assertArrayHasKey('admin_address', config('mail'));
    }

    /**
     * ⭐ «واقعاً ارسال می‌شود» — نه فقط `Mail::fake()`.
     *
     * MailFake قبل از transport می‌ایستد و هیچ‌وقت ثابت نمی‌کند Mailer پیام را
     * به transport می‌رساند. اینجا Mailerِ واقعیِ `array` را می‌گیریم و بعد از
     * `send()` به **همان** ArrayTransport نگاه می‌کنیم: اگر ساختِ Mailable یا
     * مسیر Mailable→Symfony بشکند، این پیام اینجا نیست.
     */
    public function test_a_message_flows_through_the_real_mailer_into_the_transport(): void
    {
        $mailer = Mail::mailer('array');
        $transport = $mailer->getSymfonyTransport();

        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $transport);

        $mailer->to('admin@example.com')->send(new OutboundEmail('موضوع آزمایش', 'بدنهٔ آزمایش'));

        $messages = $transport->messages();
        $this->assertCount(1, $messages, 'پیام باید به transport رسیده باشد، نه فقط به MailFake.');

        $sent = $messages->first()->getOriginalMessage();
        $this->assertSame('admin@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('موضوع آزمایش', $sent->getSubject());
        $this->assertStringContainsString('بدنهٔ آزمایش', $sent->getTextBody() ?? '');
    }

    /**
     * E2 — تحویلِ واقعی روی SMTP (Mailhog محلی).
     *
     * Mailhog در این پروژه روی شبکهٔ دیگری است؛ اگر از این کانتینر دیده شود
     * (مثلاً با `host.docker.internal:1025`) پیام واقعاً فرستاده و در صندوق
     * Mailhog دیده می‌شود. اگر هیچ‌کدام قابلِ‌دسترس نبود، `skipped` می‌شود تا
     * تستِ واحدِ بالا تنها منبعِ حقیقت بماند.
     */
    public function test_the_smtp_transport_really_delivers_to_the_local_mailhog(): void
    {
        $host = $this->firstReachableMailhogHost();

        if ($host === null) {
            $this->markTestSkipped('Mailhog محلی از این کانتینر در دسترس نیست (شبکهٔ جدا).');
        }

        // صندوق را خالی کن تا تطبیقِ پیام با موضوعِ یکتا قطعی باشد.
        try {
            Http::timeout(3)->delete("http://{$host}:8025/api/v1/messages");
        } catch (\Throwable) {
            // پاک‌سازی اختیاری است.
        }

        config([
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => 1025,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.timeout' => 5,
        ]);
        Mail::purge('smtp');

        $subject = 'mailhog-e2-'.bin2hex(random_bytes(6));

        // اگر SMTP تحویل نشود، Symfony استثنا می‌اندازد و تست همین‌جا قرمز می‌شود.
        Mail::mailer('smtp')->to('probe@example.test')->send(new OutboundEmail($subject, 'بدنهٔ آزمایشی'));

        $this->assertTrue(
            $this->mailhogReceived($host, $subject),
            'پیام از SMTP واقعی رفت ولی در صندوق Mailhog پیدا نشد.',
        );
    }

    public function test_doctor_flags_smtp_without_credentials_as_missing(): void
    {
        config(['mail.default' => 'smtp']);

        $mail = collect(app(DependencyChecker::class)->all())->firstWhere('key', 'mail');

        $this->assertNotNull($mail);
        $this->assertSame(DependencyStatus::MISSING, $mail->state);
        $this->assertTrue($mail->isBlocking());
        $this->assertContains('MAIL_HOST', $mail->missingKeys);
    }

    public function test_doctor_treats_the_failover_chain_without_credentials_as_warn(): void
    {
        config(['mail.default' => 'failover']);

        $mail = collect(app(DependencyChecker::class)->all())->firstWhere('key', 'mail');

        $this->assertNotNull($mail);
        $this->assertSame(DependencyStatus::WARN, $mail->state, 'failover باید کاهش‌یافته ولی غیرمسدودکننده باشد.');
        $this->assertFalse($mail->isBlocking());
    }

    /** @return string|null اولین میزبانِ Mailhogِ قابلِ‌دسترس */
    private function firstReachableMailhogHost(): ?string
    {
        foreach (['mailhog', 'host.docker.internal', '127.0.0.1'] as $host) {
            $socket = @fsockopen($host, 1025, $errno, $errstr, 1);

            if (is_resource($socket)) {
                fclose($socket);

                return $host;
            }
        }

        return null;
    }

    private function mailhogReceived(string $host, string $subject): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $res = Http::timeout(3)->get("http://{$host}:8025/api/v2/messages");

                if ($res->successful()) {
                    $items = (array) ($res->json('items') ?? []);

                    foreach ($items as $item) {
                        $headers = $item['Content']['Headers'] ?? [];
                        $subjects = (array) ($headers['Subject'] ?? []);

                        if (in_array($subject, $subjects, true)) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable) {
                // Mailhog ممکن است یک لحظه دیر بالا بیاید؛ دوباره تلاش می‌کنیم.
            }

            usleep(300_000);
        }

        return false;
    }


    // ------------------------------------------------------------- گزارشِ doctor

    public function test_doctor_reports_every_required_dependency(): void
    {
        $keys = array_map(
            static fn (DependencyStatus $s): string => $s->key,
            app(DependencyChecker::class)->all(),
        );

        // هر هشت موردی که چک‌لیستِ E2/E3/E4 + زیرساخت خواسته بود.
        foreach (['mail', 'sms', 'storage', 'redis', 'plugin-ddl', 'seal-keys', 'outbox'] as $expected) {
            $this->assertContains($expected, $keys, "گزارشِ doctor باید «{$expected}» را شامل شود.");
        }
    }

    public function test_doctor_flags_a_real_driver_without_credentials_as_missing(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => null,
            'sms.panel.api_key' => null,
            'sms.panel.sender' => null,
        ]);

        $statuses = app(DependencyChecker::class)->all();
        $sms = collect($statuses)->firstWhere('key', 'sms');

        $this->assertNotNull($sms);
        $this->assertSame(DependencyStatus::MISSING, $sms->state);
        $this->assertTrue($sms->isBlocking());
        $this->assertContains('SMS_PANEL_API_KEY', $sms->missingKeys);
        $this->assertNotSame('', $sms->remedy, 'وضعیت MISSING باید راه‌حل داشته باشد.');
    }

    public function test_doctor_treats_offline_drivers_as_warn_not_missing(): void
    {
        config(['sms.driver' => 'null']);

        $statuses = app(DependencyChecker::class)->all();

        foreach (['sms'] as $key) {
            $status = collect($statuses)->firstWhere('key', $key);
            $this->assertSame(DependencyStatus::WARN, $status->state, "«{$key}» باید warn باشد نه MISSING.");
            $this->assertFalse($status->isBlocking(), 'خاموشیِ آگاهانه نباید دیپلوی را متوقف کند.');
        }
    }

    public function test_doctor_command_exits_zero_when_only_offline_drivers_are_configured(): void
    {
        config(['sms.driver' => 'null']);

        $this->artisan('pishdad:doctor')->assertSuccessful();
    }

    public function test_doctor_command_exits_non_zero_on_missing_credentials(): void
    {
        config([
            'sms.driver' => 'panel',
            'sms.panel.url' => null,
            'sms.panel.api_key' => null,
            'sms.panel.sender' => null,
        ]);

        $this->artisan('pishdad:doctor')->assertFailed();
    }

    public function test_doctor_json_output_is_machine_readable(): void
    {
        // `Artisan::call()` و نه `$this->artisan()`: نسخهٔ PendingCommand خروجی را
        // خودش می‌بندد و `Artisan::output()` تا وقتی اجرا نشده `null` می‌ماند.
        Artisan::call('pishdad:doctor', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'خروجیِ --json باید JSON معتبر باشد تا پایشِ خودکار بتواند آن را بخواند.');

        $keys = array_column($payload, 'key');
        foreach (['mail', 'sms', 'outbox'] as $expected) {
            $this->assertContains($expected, $keys);
        }

        // هر ردیف باید سه چیز داشته باشد که پایش لازم دارد: کلید، حالت، و متن.
        foreach ($payload as $row) {
            $this->assertArrayHasKey('state', $row);
            $this->assertArrayHasKey('detail', $row);
            $this->assertContains($row['state'], [DependencyStatus::OK, DependencyStatus::WARN, DependencyStatus::MISSING]);
        }
    }
}
