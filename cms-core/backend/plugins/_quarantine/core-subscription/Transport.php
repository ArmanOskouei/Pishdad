<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * لایهٔ HTTPِ resolverِ دور — یک نامِ مستقل، تا تست بتواند واقعی باشد.
 *
 * ## چرا رابطِ جدا و نه `Http::post` مستقیم
 *
 * چون ادعای اصلیِ `RemoteSubscriptionResolver` این است که **هر** خطایی به
 * «نمی‌دانیم» تبدیل می‌شود. این ادعا با یک فراخوانیِ مستقیم قابلِ آزمایش نیست:
 * یا باید سرور بالا بیاوری، یا باید mock کنی — و mock یعنی «رفتاری که فکر می‌کنیم
 * داریم»، نه رفتاری که داریم.
 *
 * با این رابط، تست یک `Transport` می‌دهد که **می‌اندازد**، یکی که **۵۰۰** برمی‌گرداند،
 * و یکی که **JSON خراب** می‌دهد. هر سه واقعاً به همان کد می‌رسند.
 *
 * @see RemoteSubscriptionResolver
 */
interface Transport
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  `null` یعنی «پاسخِ قابل‌استفاده نبود».
     *
     * @throws \Throwable  هر خطایی مجاز است — لایهٔ بالاتر آن را می‌بلعد.
     */
    public function post(string $url, array $payload): ?array;
}