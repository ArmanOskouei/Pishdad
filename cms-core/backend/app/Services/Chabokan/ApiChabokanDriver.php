<?php

namespace App\Services\Chabokan;

use App\Services\External\MissingCredentialException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * درایورِ واقعیِ چابکان (E4).
 *
 * ## وضعیتِ این کلاس
 *
 * ⚠️ **نوشته شده ولی هرگز اجرا نشده** — `CHABOKAN_API_TOKEN` در اختیار نداریم
 * و آدرسِ پیش‌فرضِ کانفیگ (`api.chabakan.example`) یک placeholder است، نه آدرسِ
 * واقعیِ چابکان. یعنی شکلِ endpointها یک حدسِ مستند است، نه راستی‌آزمایی‌شده.
 *
 * دو دلیل که با این حال نوشته شد:
 *  ۱. سازنده‌اش **fail-closed** است، پس `CHABOKAN_DRIVER=api` بدون توکن از همین
 *     الان نمی‌تواند بی‌سروصدا رد شود — همان چیزی که خواسته شده.
 *  ۲. مرزِ قرارداد مشخص است، پس روزی که توکن آمد کار، افزودنِ چند خط است نه
 *     نوشتنِ دوباره از صفر.
 *
 * ⭐ نکتهٔ ایمنی: این درایور **زیرساختِ واقعی را کنترل می‌کند** (start/stop/
 * restart/resize). یعنی یک توکنِ اشتباه یا یک endpointِ غلط، یک سایتِ در حالِ
 * اجرا را می‌خواباند. به همین دلیل `serviceAction` قبل از هر چیز نامِ سرویس را
 * در برابر `config('chabokan.allowed_services')` چک می‌کند و هیچ وقت
 * `Host`/`Ip` را از ورودیِ کاربر نمی‌گیرد.
 */
class ApiChabokanDriver implements ChabokanClientInterface
{
    public const DEPENDENCY = 'چابکان';

    /**
     * کلیدهای محیطیِ لازم، به همان اسمی که در `.env.example` آمده.
     *
     * `CHABOKAN_API_BASE` هم لازم است چون پیش‌فرضِ فعلی یک `*.example` است و
     * اگر رهایش کنیم یعنی DNS به یک جای ناموجود می‌رود و خطای گمراه‌کننده
     * می‌گیریم، نه «کلید را ندادی».
     *
     * @var list<string>
     */
    public const REQUIRED_KEYS = ['CHABOKAN_API_TOKEN', 'CHABOKAN_API_BASE'];

    private const PLACEHOLDER = 'example';

    private ?string $lastError = null;

    public function driverName(): string
    {
        return 'api';
    }

    /**
     * @throws MissingCredentialException
     */
    public function __construct()
    {
        $missing = self::missingKeys();
        if ($missing !== []) {
            throw new MissingCredentialException(self::DEPENDENCY, $this->driverName(), $missing);
        }
    }

    /**
     * کلید(های) جاافتاده — بدون ساختنِ درایور، برای `pishdad:doctor`.
     *
     * علاوه بر خالی بودن، مقدارِ `*.example` را هم «جاافتاده» حساب می‌کنیم چون
     * عملاً هیچ سرویسی پشتش نیست و باز گذاشتنش فقط دروغِ مرتب است.
     *
     * ⚠️ از `config()` می‌خواند نه `env()` — با `config:cache`، `env()` بیرون از
     * فایل‌های کانفیگ null می‌دهد و `doctor` روی نصبِ کش‌شده دروغ می‌گوید.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        $map = [
            'CHABOKAN_API_TOKEN' => (string) config('chabokan.api_token', ''),
            'CHABOKAN_API_BASE' => (string) config('chabokan.api_base', ''),
        ];

        $missing = [];
        foreach ($map as $key => $value) {
            if (trim($value) === '' || str_contains($value, self::PLACEHOLDER)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function wallet(): array
    {
        $data = $this->call('GET', '/account/balance');

        $balance = (int) ($data['balance'] ?? 0);
        $threshold = (int) config('chabokan.low_balance_threshold', 0);

        return [
            'balance' => $balance,
            'currency' => (string) ($data['currency'] ?? config('chabokan.currency', 'IRT')),
            'low_balance' => $balance < $threshold,
            'driver' => $this->driverName(),
        ];
    }

    public function services(): array
    {
        $data = $this->call('GET', '/services');

        return collect($data['services'] ?? [])->map(fn (array $s): array => [
            'name' => (string) ($s['name'] ?? ''),
            'status' => (string) ($s['status'] ?? 'unknown'),
            'driver' => $this->driverName(),
        ])->values()->all();
    }

    /**
     * ⚠️ این متد **زیرساختِ واقعی** را تغییر می‌دهد. نامِ سرویس حتماً باید در
     * `config/chabokan.allowed_services` باشد و اکشن هم جزو فهرستِ بستهٔ زیر.
     *
     * @throws \InvalidArgumentException اگر سرویس یا اکشن مجاز نباشد.
     */
    public function serviceAction(string $name, string $action, array $options = []): array
    {
        $allowed = (array) config('chabokan.allowed_services', []);

        if (! in_array($name, $allowed, true)) {
            throw new \InvalidArgumentException('نام سرویس مجاز نیست.');
        }

        if (! in_array($action, ['start', 'stop', 'restart', 'resize'], true)) {
            throw new \InvalidArgumentException('اکشن مجاز نیست.');
        }

        $result = $this->call('POST', '/services/'.$name.'/'.$action, $options);

        Log::warning('chabokan.api_action', [
            'service' => $name,
            'action' => $action,
            'options' => $options,
        ]);

        return [
            'service' => $name,
            'action' => $action,
            'status' => (string) ($result['status'] ?? $action),
            'options' => $options,
            'driver' => $this->driverName(),
        ];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RuntimeException روی پاسخِ ناموفق.
     */
    private function call(string $method, string $path, array $payload = []): array
    {
        $base = rtrim((string) config('chabokan.api_base'), '/');
        $url = $base.$path;

        try {
            $request = Http::timeout(15)->acceptJson()->withToken((string) config('chabokan.api_token'));
            $res = $method === 'GET' ? $request->get($url) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            $this->lastError = 'اتصال به چابکان برقرار نشد: '.$e->getMessage();
            throw new RuntimeException($this->lastError, 0, $e);
        }

        if (! $res->successful()) {
            $this->lastError = 'چابکان خطا داد (HTTP '.$res->status().'): '.mb_substr((string) $res->body(), 0, 300);
            throw new RuntimeException($this->lastError);
        }

        $this->lastError = null;
        $json = $res->json();

        return is_array($json) ? $json : [];
    }
}