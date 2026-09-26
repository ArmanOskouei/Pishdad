<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Client;
use App\Models\Central\CoreVersion;
use App\Models\Central\OperatorActivity;
use App\Models\Central\UpdateRollout;
use App\Models\Central\UpdateRolloutItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تسک ۵.۶ — رجیستری نسخه‌ها + رول‌اوت پلکانی (بدون canary طبق SPEC) + rollback.
 *
 * رول‌اوت دومرحله‌ای است: بدون `confirm:true` هیچ رکوردی ساخته نمی‌شود.
 * هر آیتم نسخه قبلی (previous_version) را نگه می‌دارد تا rollback ممکن باشد.
 */
class UpdateController extends Controller
{
    public function index(): JsonResponse
    {
        $latest = CoreVersion::query()->where('is_latest', true)->first();

        // دسته UIUX (افزودنی): آخرین وضعیت رول‌اوت هر نصب (برای بج شکست‌خورده).
        $lastStatus = UpdateRolloutItem::query()
            ->orderByDesc('id')
            ->get(['client_id', 'status'])
            ->keyBy('client_id');

        $installs = Client::query()->orderBy('id')->get()->map(fn (Client $c) => [
            'client_id' => $c->id,
            'name' => $c->name,
            'site_id' => $c->site_id,
            'current_version' => $c->current_version,
            'update_available' => $latest && $c->current_version !== null
                && version_compare($c->current_version, $latest->version, '<'),
            'last_rollout_status' => $lastStatus->get($c->id)?->status,
        ]);

        return response()->json(['data' => [
            'latest' => $latest,
            'versions' => CoreVersion::query()->latest()->get(),
            'installs' => $installs,
        ]]);
    }

    public function rollout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => 'required|string|max:30|exists:central_core_versions,version',
            'install_ids' => 'sometimes|array',
            'install_ids.*' => 'integer|exists:central_clients,id',
            'batch_size' => 'sometimes|integer|min:1|max:100',
            'confirm' => 'sometimes|boolean',
        ], [
            'version.required' => 'نسخه هدف الزامی است.',
            'version.exists' => 'این نسخه در رجیستری ثبت نشده است.',
        ]);

        if (($validated['confirm'] ?? false) !== true) {
            return response()->json(['message' => 'برای اجرای رول‌اوت، تأیید صریح (confirm:true) لازم است.'], 422);
        }

        $clients = isset($validated['install_ids'])
            ? Client::query()->whereIn('id', $validated['install_ids'])->orderBy('id')->get()
            : Client::query()->orderBy('id')->get();

        if ($clients->isEmpty()) {
            return response()->json(['message' => 'نصبی برای رول‌اوت یافت نشد.'], 422);
        }

        $batchSize = (int) ($validated['batch_size'] ?? 10);

        $rollout = DB::transaction(function () use ($validated, $clients, $batchSize, $request) {
            $rollout = UpdateRollout::query()->create([
                'version' => $validated['version'],
                'status' => 'in_progress',
                'batch_size' => $batchSize,
                'created_by' => $request->user()->id,
            ]);

            foreach ($clients->values() as $index => $client) {
                UpdateRolloutItem::query()->create([
                    'rollout_id' => $rollout->id,
                    'client_id' => $client->id,
                    'from_version' => $client->current_version,
                    'to_version' => $validated['version'],
                    'previous_version' => $client->current_version,
                    'status' => 'pending',
                    'batch_index' => intdiv($index, $batchSize),
                ]);
            }

            return $rollout;
        });

        OperatorActivity::record($request->user(), 'update.rollout', [
            'rollout_id' => $rollout->id, 'version' => $rollout->version,
        ]);

        $batches = $rollout->items()->orderBy('batch_index')->orderBy('client_id')->get()
            ->groupBy('batch_index')->values();

        return response()->json([
            'message' => 'رول‌اوت پلکانی ثبت شد.',
            'data' => [
                'rollout' => $rollout,
                'batches' => $batches,
            ],
        ], 201);
    }

    /**
     * دسته UIUX (افزودنی): کارت «رول‌اوت جاری» — آخرین رول‌اوت ناتمام
     * (in_progress/paused) با پیشرفت شمارشی آیتم‌ها.
     */
    public function current(): JsonResponse
    {
        $rollout = UpdateRollout::query()
            ->whereIn('status', ['in_progress', 'paused'])
            ->latest()
            ->first();

        if (! $rollout) {
            return response()->json(['data' => ['rollout' => null]]);
        }

        $counts = $rollout->items()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) $counts->sum();
        $done = (int) ($counts['applied'] ?? 0) + (int) ($counts['rolled_back'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);

        return response()->json(['data' => [
            'rollout' => $rollout,
            'total' => $total,
            'done' => $done,
            'failed' => $failed,
            'pending' => max(0, $total - $done - $failed),
            'progress' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ]]);
    }

    /** دسته UIUX (افزودنی): توقف موقت رول‌اوت جاری. */
    public function pause(Request $request, UpdateRollout $rollout): JsonResponse
    {
        if ($rollout->status !== 'in_progress') {
            return response()->json(['message' => 'فقط رول‌اوت در حال اجرا قابل توقف است.'], 422);
        }
        $rollout->forceFill(['status' => 'paused'])->save();
        OperatorActivity::record($request->user(), 'update.pause', ['rollout_id' => $rollout->id]);

        return response()->json(['message' => 'رول‌اوت متوقف شد.', 'data' => $rollout->fresh()]);
    }

    /** دسته UIUX (افزودنی): ادامه رول‌اوت متوقف‌شده. */
    public function resume(Request $request, UpdateRollout $rollout): JsonResponse
    {
        if ($rollout->status !== 'paused') {
            return response()->json(['message' => 'فقط رول‌اوت متوقف‌شده قابل ادامه است.'], 422);
        }
        $rollout->forceFill(['status' => 'in_progress'])->save();
        OperatorActivity::record($request->user(), 'update.resume', ['rollout_id' => $rollout->id]);

        return response()->json(['message' => 'رول‌اوت ادامه یافت.', 'data' => $rollout->fresh()]);
    }

    public function rollback(Request $request, Client $install): JsonResponse
    {
        $item = UpdateRolloutItem::query()
            ->where('client_id', $install->id)
            ->whereIn('status', ['pending', 'applied', 'failed'])
            ->latest()
            ->first();

        if (! $item || $item->previous_version === null) {
            return response()->json(['message' => 'نسخه قبلی برای بازگشت ثبت نشده است.'], 422);
        }

        DB::transaction(function () use ($item, $install) {
            $install->forceFill(['current_version' => $item->previous_version])->save();
            $item->forceFill(['status' => 'rolled_back'])->save();
            $item->rollout()->update(['status' => 'rolled_back']);
        });

        OperatorActivity::record($request->user(), 'update.rollback', [
            'client_id' => $install->id, 'restored_version' => $install->fresh()->current_version,
        ]);

        return response()->json([
            'message' => 'نصب به نسخه قبلی برگردانده شد.',
            'data' => ['current_version' => $install->fresh()->current_version],
        ]);
    }
}
