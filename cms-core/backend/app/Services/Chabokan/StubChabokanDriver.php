<?php

namespace App\Services\Chabokan;

use Illuminate\Support\Facades\Log;

/**
 * درایور Stub چابکان (تسک ۳.۳): همه داده‌ها از config/chabokan.php.
 * هیچ تماس HTTP، هیچ شارژ، هیچ تغییر واقعی زیرساخت — کاملاً بی‌خطر.
 */
class StubChabokanDriver implements ChabokanClientInterface
{
    public function driverName(): string
    {
        return 'stub';
    }

    public function wallet(): array
    {
        $balance = (int) config('chabokan.wallet_balance', 0);
        $threshold = (int) config('chabokan.low_balance_threshold', 0);

        return [
            'balance' => $balance,
            'currency' => (string) config('chabokan.currency', 'IRT'),
            'low_balance' => $balance < $threshold,
            'driver' => $this->driverName(),
        ];
    }

    public function services(): array
    {
        $names = config('chabokan.allowed_services', []);
        $statuses = config('chabokan.stub_statuses', []);

        return collect($names)->map(fn (string $name) => [
            'name' => $name,
            'status' => $statuses[$name] ?? 'running',
            'driver' => $this->driverName(),
        ])->values()->all();
    }

    public function serviceAction(string $name, string $action, array $options = []): array
    {
        $allowed = config('chabokan.allowed_services', []);

        if (! in_array($name, $allowed, true)) {
            throw new \InvalidArgumentException('نام سرویس مجاز نیست.');
        }

        Log::info('chabokan.stub_action', ['service' => $name, 'action' => $action, 'options' => $options]);

        // TODO مرحله ۵ (ApiChabokanDriver): فراخوانی واقعی API چابکان با CHABOKAN_API_TOKEN.

        return [
            'service' => $name,
            'action' => $action,
            'status' => match ($action) {
                'stop' => 'stopped',
                'resize' => 'resized',
                default => 'running',
            },
            'options' => $options,
            'driver' => $this->driverName(),
        ];
    }
}
