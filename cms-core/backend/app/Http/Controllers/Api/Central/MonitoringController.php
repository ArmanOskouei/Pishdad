<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLog;
use App\Models\Central\Client;
use App\Models\Central\InstallHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تسک ۵.۵ — گرید سلامت نصب‌ها + لاگ متمرکز (فقط operator).
 * نصب ساکت = بدون heartbeat تازه بیش از N دقیقه (پیش‌فرض ۱۰).
 * لاگ فقط ۳۰ روز گذشته (سقف نگهداری) + صفحه‌بندی.
 */
class MonitoringController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        $silentMinutes = max(1, (int) $request->query('silent_minutes', 10));

        $heartbeats = InstallHeartbeat::query()->get()->keyBy('site_id');

        $grid = Client::query()->latest()->get()->map(function (Client $client) use ($heartbeats, $silentMinutes) {
            $hb = $heartbeats->get($client->site_id);
            $state = 'never';
            if ($hb) {
                $state = $hb->isSilent($silentMinutes) ? 'silent' : 'online';
            }

            return [
                'client_id' => $client->id,
                'name' => $client->name,
                'site_id' => $client->site_id,
                'status' => $hb?->status,
                'version' => $hb?->version ?? $client->current_version,
                'last_seen_at' => $hb?->last_seen_at?->toIso8601String(),
                'state' => $state,
            ];
        });

        return response()->json(['data' => [
            'silent_minutes' => $silentMinutes,
            'grid' => $grid,
            'silent' => $grid->whereIn('state', ['silent', 'never'])->values(),
        ]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => 'sometimes|string|in:debug,info,warning,error,critical',
            'site_id' => 'sometimes|string|max:100',
            // دسته UIUX (افزودنی): جستجوی متنی در message/site_id.
            'q' => 'sometimes|nullable|string|max:200',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = CentralLog::query()
            ->where('created_at', '>=', CentralLog::retentionCutoff())
            ->latest();

        if (! empty($validated['level'])) {
            $query->where('level', $validated['level']);
        }
        if (! empty($validated['site_id'])) {
            $query->where('site_id', $validated['site_id']);
        }
        if (! empty($validated['q'])) {
            $q = trim((string) $validated['q']);
            $query->where(fn ($w) => $w
                ->where('message', 'ilike', "%{$q}%")
                ->orWhere('site_id', 'ilike', "%{$q}%"));
        }

        return response()->json(['data' => $query->paginate(
            (int) ($validated['per_page'] ?? 20)
        )]);
    }

    /**
     * دسته UIUX (افزودنی): آپتایم روزانه هر نصب (پیش‌فرض ۳۰ روز).
     * صادقانه: فقط روز جاری از روی heartbeat زنده محاسبه می‌شود،
     * روزهای گذشته `unknown` (تاریخچه روزانه ذخیره نمی‌شود — TODO).
     */
    public function uptime(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $silentMinutes = max(1, (int) $request->query('silent_minutes', 10));

        $heartbeats = InstallHeartbeat::query()->get()->keyBy('site_id');
        $today = now()->startOfDay();

        $clients = Client::query()->latest()->get()->map(function (Client $client) use ($heartbeats, $silentMinutes, $days, $today) {
            $hb = $heartbeats->get($client->site_id);
            $todayStatus = 'unknown';
            if ($hb) {
                $todayStatus = $hb->isSilent($silentMinutes) ? 'silent' : 'online';
            }

            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i)->toDateString();
                $series[] = [
                    'date' => $date,
                    // فقط امروز واقعی است؛ بقیه تاریخچه ندارند.
                    'status' => $i === 0 ? $todayStatus : 'unknown',
                ];
            }

            return [
                'client_id' => $client->id,
                'name' => $client->name,
                'site_id' => $client->site_id,
                'today' => $todayStatus,
                'days' => $series,
            ];
        });

        return response()->json(['data' => [
            'days' => $days,
            'clients' => $clients,
        ]]);
    }
}
