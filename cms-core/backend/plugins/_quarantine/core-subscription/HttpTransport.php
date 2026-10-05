<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * `Transport` روی HTTPِ لاراول.
 *
 * ## چرا `throw()` را روشن می‌کنیم
 *
 * پیش‌فرضِ لاراول **exception نمی‌دهد** مگر برای خطای اتصال؛ ۴۰۴ و ۵۰۰ را در
 * `successful()` برمی‌گرداند و برنامه فکر می‌کند موفق است — و آن‌وقت
 * `RemoteSubscriptionResolver` یک HTMLِ خطای ۵۰۰ را به‌عنوان «داده» می‌بلعد.
 *
 * اینجا عمداً **همه** را exception می‌کنیم تا لایهٔ بالاتر همه را یکسان ببیند و
 * به «نمی‌دانیم» تبدیل کند. یک مسیرِ خطا، یک رفتار.
 */
final class HttpTransport implements Transport
{
    /**
     * جای window پیش‌فرض (`timeout`/`connectTimeout`) عمداً کم است.
     *
     * دلیل: این resolver در مسیرِ **رندرِ صفحه** صدا زده می‌شود. سه ثانیه انتظار
     * برای یک مرکزی که پایین است یعنی سایتِ مشتری هم کند می‌شود. بهتر است زود
     * بگوییم «نمی‌دانیم» و صفحه با حالتِ عمومی رندر شود.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly int $timeoutSeconds = 3,
        private readonly ?string $token = null,
    ) {}

    public function post(string $url, array $payload): ?array
    {
        $request = $this->http
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();

        if (is_string($this->token) && $this->token !== '') {
            $request = $request->withToken($this->token);
        }

        // ⛔ `throw()` — دلیلش در بالای همین کلاس توضیح داده شده.
        $response = $request->post($url, $payload)->throw();

        $decoded = $response->json();

        // `data` پاسخِ استانداردِ APIِ همین پروژه است؛ اگر نبود، خودِ بدنه.
        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            $inner = $decoded['data'];
            if (is_array($inner)) {
                return $inner;
            }
        }

        return is_array($decoded) ? $decoded : null;
    }
}