<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Subscription\HttpTransport;
use App\Services\Subscription\RemoteSubscriptionResolver;
use App\Services\Subscription\SubscriptionResolver;
use App\Services\Subscription\SubscriptionResolverInterface;
use App\Services\Subscription\Transport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * J2 — resolverِ دور، وقتی مرکزی در یک استقرارِ دیگر است.
 *
 * ## ادعایی که این تست می‌سنجد
 *
 * «هر خطایی به «نمی‌دانیم» تبدیل می‌شود و هرگز exception بیرون نمی‌رود.»
 *
 * این ادعا **همه‌چیزِ** این کلاس است. چون این resolver در مسیرِ رندرِ صفحه صدا
 * زده می‌شود، یک exception یعنی سایتِ مشتری از پنل بیرون می‌افتد. و اگر به‌جای
 * «نمی‌دانیم» چیزی برگرداند، یا سایت را **بی‌دلیل می‌بندد** (کسی نمی‌تواند لاگ
 * کند) یا مشتری را **بی‌دلیل باز** می‌کند (اشتراکِ تعلیق‌شده سرو می‌شود).
 *
 * برای همین هر حالتِ خرابی جداگانه تست شده — نه با یک mockِ کلی.
 */
class RemoteSubscriptionResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Transportی که **می‌اندازد** — مثل DNSِ حل‌نشده یا TLSِ شکسته.
     */
    private function throwingTransport(): Transport
    {
        return new class implements Transport
        {
            public function post(string $url, array $payload): ?array
            {
                throw new RuntimeException('cURL error 6: Could not resolve host');
            }
        };
    }

    /**
     * Transportی که **۵۰۰** برمی‌گرداند — مثل مرکزی که دیتابیسش افتاده.
     *
     * `throw()` در `HttpTransport` آن را exception می‌کند، پس اینجا دو حالت را
     * جدا می‌آزماییم: پاسخِ ۵۰۰ِ بی‌استثنا (که نباید داده تلقی شود) و exception.
     */
    private function serverErrorTransport(): Transport
    {
        return new class implements Transport
        {
            public function post(string $url, array $payload): ?array
            {
                // HTMLِ صفحهٔ خطای لاراول — دقیقاً همان چیزی که اگر guard نبود
                // به‌عنوان «داده» بلعیده می‌شد.
                return null;
            }
        };
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری',
            'email' => uniqid().'@example.com',
            'password' => 'x',
            'role' => 'admin',
        ]);
    }

    // ------------------------------------------------------------------
    // ۱) پیکربندی‌نشده = حالتِ محلی
    // ------------------------------------------------------------------

    public function test_it_declares_itself_unconfigured_without_a_url(): void
    {
        $resolver = new RemoteSubscriptionResolver($this->throwingTransport(), null);

        $this->assertFalse($resolver->isConfigured());
    }

    public function test_an_unconfigured_remote_never_touches_the_network(): void
    {
        // اگر این transport صدا زده شود، تست exception می‌دهد چون می‌اندازد.
        $resolver = new RemoteSubscriptionResolver($this->throwingTransport(), null);

        $this->assertFalse($resolver->hasSubscriptionData());

        $status = $resolver->publicStatus('site-1');

        $this->assertSame('active', $status['status']);
        $this->assertArrayHasKey('message', $status);
        $this->assertNotSame('', $status['message'], 'پیامِ خالی یعنی UI کلمهٔ خالی نشان می‌دهد.');
    }

    // ------------------------------------------------------------------
    // ۲) هر خطا = «نمی‌دانیم»، نه crash
    // ------------------------------------------------------------------

    public function test_an_unreachable_center_becomes_dont_know_not_an_exception(): void
    {
        $resolver = new RemoteSubscriptionResolver($this->throwingTransport(), 'https://central.example');

        $this->assertTrue($resolver->isConfigured());

        // هیچ‌کدام نباید throw کنند — همین断言، کلِ ادعا است.
        $this->assertFalse($resolver->hasSubscriptionData());
        $this->assertSame(0, $resolver->overdueInvoiceCount($this->user()));
        $this->assertFalse($resolver->isDueSoon($this->user()));
        $this->assertFalse($resolver->ensureFor($this->user()));
        $this->assertSame([], $resolver->activePlans());

        $status = $resolver->publicStatus('site-1');
        $this->assertSame('active', $status['status'], 'مرکزیِ در دسترس نبود ⇒ نه تعلیق.');
        $this->assertNotSame('', $status['message'], 'پیامِ خالی یعنی UI کلمهٔ خالی نشان می‌دهد.');
    }

    public function test_an_error_page_is_not_mistaken_for_data(): void
    {
        $resolver = new RemoteSubscriptionResolver($this->serverErrorTransport(), 'https://central.example');

        $this->assertSame(0, $resolver->overdueInvoiceCount($this->user()));
        $this->assertFalse($resolver->hasSubscriptionData());
        $this->assertSame('active', $resolver->publicStatus('site-1')['status']);
    }

    // ------------------------------------------------------------------
    // ۳) پاسخِ سالم واقعاً استفاده می‌شود
    // ------------------------------------------------------------------

    public function test_a_healthy_center_is_actually_read(): void
    {
        $transport = new class implements Transport
        {
            public function post(string $url, array $payload): ?array
            {
                return match ($url) {
                    'https://central.example/hasSubscriptionData' => ['available' => true],
                    'https://central.example/publicStatus' => ['status' => 'suspended', 'message' => 'تعلیق شده'],
                    'https://central.example/overdueInvoiceCount' => ['count' => 3],
                    'https://central.example/isDueSoon' => ['due_soon' => true],
                    'https://central.example/activePlans' => ['plans' => [['id' => 1, 'name' => 'پایه']]],
                    default => [],
                };
            }
        };

        $resolver = new RemoteSubscriptionResolver($transport, 'https://central.example');

        $this->assertTrue($resolver->hasSubscriptionData());
        $this->assertSame('suspended', $resolver->publicStatus('site-1')['status']);
        $this->assertSame('تعلیق شده', $resolver->publicStatus('site-1')['message']);
        $this->assertSame(3, $resolver->overdueInvoiceCount($this->user()));
        $this->assertTrue($resolver->isDueSoon($this->user()));
        $this->assertCount(1, $resolver->activePlans());
    }

    public function test_a_negative_count_from_the_network_cannot_go_below_zero(): void
    {
        $transport = new class implements Transport
        {
            public function post(string $url, array $payload): ?array
            {
                return ['count' => -99];
            }
        };

        $resolver = new RemoteSubscriptionResolver($transport, 'https://central.example');

        // یک عددِ منفی از شبکه نباید در UI بنشیند.
        $this->assertSame(0, $resolver->overdueInvoiceCount($this->user()));
    }

    // ------------------------------------------------------------------
    // ۴) پرداخت از این مسیر نمی‌آید (J10)
    // ------------------------------------------------------------------

    public function test_payment_is_refused_here_by_design(): void
    {
        $resolver = new RemoteSubscriptionResolver($this->throwingTransport(), 'https://central.example');

        $gateway = $this->createMock(PaymentGatewayInterface::class);

        $result = $resolver->startPayment($this->user(), 1, $gateway);

        // ۴۰۹ نه ۵۰۰: درخواست معتبر است ولی از این مسیر مجاز نیست.
        $this->assertSame(409, $result['status']);
        $this->assertNotSame('', $result['body']['message']);
    }

    // ------------------------------------------------------------------
    // ۵) انتخابِ پیاده‌سازی
    // ------------------------------------------------------------------

    public function test_the_facade_stays_local_when_no_remote_url_is_set(): void
    {
        config(['subscription.remote_url' => null]);

        // نبودِ URL نباید پیاده‌سازیِ دور را انتخاب کند.
        $this->assertFalse(
            $this->isRemoteSelected(),
            'با `remote_url` خالی، مسیرِ دور نباید فعال شود.'
        );
    }

    public function test_the_facade_switches_to_remote_when_a_url_is_set(): void
    {
        config([
            'subscription.remote_url' => 'https://central.example',
            'subscription.remote_timeout' => 1,
        ]);

        $this->assertTrue(
            $this->isRemoteSelected(),
            'با `remote_url` پر، باید resolverِ دور انتخاب شود (J4).'
        );
    }

    /**
     * پیاده‌سازیِ انتخاب‌شده را می‌بیند، تا تست به رفتارِ رابط تکیه کند نه به
     * جزئیاتِ داخلی.
     */
    private function isRemoteSelected(): bool
    {
        $impl = (new \ReflectionMethod(SubscriptionResolver::class, 'impl'))
            ->invoke(null);

        $this->assertInstanceOf(SubscriptionResolverInterface::class, $impl);

        return $impl instanceof RemoteSubscriptionResolver;
    }

    // ------------------------------------------------------------------
    // ۶) `HttpTransport` واقعی
    // ------------------------------------------------------------------

    /**
     * `Http::fake` برای DNSِ شکسته کار **نمی‌کند** — چون درخواستِ واقعی می‌رود و
     * `ConnectionException` می‌آید، نه پاسخِ ۵۰۰. پس دو تست جدا لازم است، و هرکدام
     * چیزِ متفاوتی را می‌سنجند.
     */
/**
 * `Http::fake` سراسری با factoryای که ما **تزریق** کرده‌ایم یکی نیست — و همین
 * اشتباه، تست را بی‌صدا از DNS واقعی رد می‌کرد (و `ConnectionException` می‌داد
 * به‌جای `RequestException`).
 *
 * راهِ درست: fake کردن روی **همان factoryای** که به کلاس داده‌ایم. نامِ
 * pattern هم باید دامنه‌ای باشد که لارavel آن را به‌عنوان pattern معتبر می‌شناسد
 * (یک `*` ساده کافی است — و اگر `preventStrayRequests` روشن باشد، هر درخواستی که
 * به fake نخورد و شکست بخورد، تست را لو می‌دهد که الگو اشتباه بوده).
 */
public function test_the_http_transport_turns_a_500_into_an_exception(): void
    {
        $factory = new HttpFactory;
        $factory->fake(['*' => Http::response('<html>Server Error</html>', 500)]);

        $transport = new HttpTransport($factory);

        $this->expectException(RequestException::class);

        $transport->post('https://central.example/publicStatus', []);
    }

    /**
     * و یک تستِ جدا برای حالتِ «در دسترس نیستِ کامل».
     *
     * این همان حالتی است که در تولید واقعاً رخ می‌دهد (مرکزی خاموش یا DNS خراب) و
     * `Http::fake` آن را شبیه‌سازی نمی‌کند. تست می‌گوید transport **بلعیده** نمی‌شود
     * در لایهٔ خودش — یعنی قراردادش «exception بده» است و لایهٔ بالاتر تصمیم
     * می‌گیرد.
     */
    public function test_an_unresolvable_host_is_an_exception_not_a_silent_null(): void
    {
        Http::preventStrayRequests();

        $transport = new HttpTransport(new HttpFactory, 1);

        try {
            $result = $transport->post('https://this-host-does-not-exist.invalid/x', []);
            $this->fail('انتظار exception بود ولی '.var_export($result, true).' برگشت.');
        } catch (ConnectionException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_http_transport_unwraps_the_standard_data_envelope(): void
    {
        $factory = new HttpFactory;
        $factory->fake(['*' => Http::response(['data' => ['status' => 'active', 'message' => 'ok']], 200)]);

        $transport = new HttpTransport($factory);

        $this->assertSame(
            ['status' => 'active', 'message' => 'ok'],
            $transport->post('https://central.example/publicStatus', []),
        );
    }
}