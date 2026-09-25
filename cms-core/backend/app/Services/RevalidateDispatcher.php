<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a signed cache-purge to the Next.js revalidate route.
 *
 * Fire-and-forget by design: publish/theme-activate flows must NEVER fail
 * just because the frontend is unreachable (ISR expiry is the fallback).
 * Returns true only when the frontend answered 2xx.
 */
class RevalidateDispatcher
{
    public function __construct(
        private readonly RevalidateSigner $signer,
    ) {}

    /** @param string[] $tags */
    public function dispatch(array $tags): bool
    {
        $url = (string) config('revalidate.url', '');
        if ($url === '') {
            return false;
        }

        try {
            $response = Http::timeout(3)->connectTimeout(2)
                ->acceptJson()
                ->post($url, $this->signer->sign(array_values($tags)));

            if ($response->successful()) {
                return true;
            }

            Log::warning('revalidate.dispatch.failed', [
                'tags' => array_values($tags),
                'status' => $response->status(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('revalidate.dispatch.error', [
                'tags' => array_values($tags),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
