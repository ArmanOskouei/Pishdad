<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Central\Client;
use App\Models\Central\InstallHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۵.۵ — heartbeat نصب‌ها: توکن per-install + HMAC سبک.
 *
 * احراز هویت دوعاملی سبک:
 * 1) هدر X-Install-Token باید با install_token نصب (site_id) بخواند؛
 * 2) فیلد signature = hash_hmac('sha256', "{site_id}|{status}|{timestamp}", install_token)
 *    و timestamp حداکثر ۵ دقیقه اختلاف (جلوگیری از replay).
 * مقایسه‌ها با hash_equals (زمان‌ثابت).
 */
class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|string|max:100',
            'status' => 'sometimes|string|in:online,degraded,down',
            'version' => 'sometimes|nullable|string|max:30',
            'timestamp' => 'required|integer',
            'signature' => 'required|string|max:128',
        ], [
            'site_id.required' => 'شناسه نصب الزامی است.',
            'timestamp.required' => 'مهر زمانی الزامی است.',
            'signature.required' => 'امضا الزامی است.',
        ]);

        $client = Client::query()->where('site_id', $validated['site_id'])->first();
        $token = (string) $request->header('X-Install-Token', '');

        if (! $client || $client->install_token === null
            || ! hash_equals($client->install_token, $token)) {
            return response()->json(['message' => 'احراز هویت نصب نامعتبر است.'], 401);
        }

        if (abs(time() - (int) $validated['timestamp']) > 300) {
            return response()->json(['message' => 'مهر زمانی نامعتبر است.'], 401);
        }

        $expected = hash_hmac(
            'sha256',
            "{$validated['site_id']}|".($validated['status'] ?? 'online')."|{$validated['timestamp']}",
            $client->install_token
        );

        if (! hash_equals($expected, $validated['signature'])) {
            return response()->json(['message' => 'امضای heartbeat نامعتبر است.'], 401);
        }

        $status = $validated['status'] ?? 'online';

        InstallHeartbeat::query()->updateOrCreate(
            ['site_id' => $client->site_id],
            [
                'status' => $status,
                'version' => $validated['version'] ?? $client->current_version,
                'last_seen_at' => now(),
            ]
        );

        $client->forceFill([
            'health' => array_merge($client->health ?? [], ['last_status' => $status]),
            'current_version' => $validated['version'] ?? $client->current_version,
        ])->save();

        return response()->json(['message' => 'heartbeat ثبت شد.']);
    }
}
