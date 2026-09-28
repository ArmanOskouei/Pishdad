<?php

namespace Pishdad\Plugins\Central\Http;

use App\Http\Controllers\Controller;
use App\Services\Chabokan\ChabokanClientInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pishdad\Plugins\Central\Models\ChabokanDeployment;
use Pishdad\Plugins\Central\Models\ChabokanLog;

/**
 * تسک ۳.۳ — مدیریت چابکان (فقط operator): اسکلت + واقعیِ بی‌خطر.
 *
 * همه داده‌ها از ChabokanClientInterface می‌آیند (در MVP درایور Stub —
 * خواندن از config، بدون تماس/شارژ واقعی). همه اکشن‌ها در
 * central_chabokan_logs ثبت حسابرسی می‌شوند. عملیات مخرب
 * (stop/restart/resize/redeploy) پارامتر confirm:true اجباری دارند
 * (Confirm سطح API برای ConfirmDialog فرانت).
 */
class ChabokanController extends Controller
{
    private const DESTRUCTIVE = ['stop', 'restart', 'resize'];

    public function __construct(private readonly ChabokanClientInterface $chabokan) {}

    public function wallet(): JsonResponse
    {
        return response()->json(['data' => $this->chabokan->wallet()]);
    }

    /**
     * دسته UIUX (افزودنی): دکمه «شارژ کیف پول» — در MVP فقط ثبت درخواست
     * (درایور Stub شارژ واقعی ندارد؛ TODO درایور واقعی).
     */
    public function charge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:10000|max:1000000000',
        ], [
            'amount.required' => 'مبلغ شارژ الزامی است.',
            'amount.min' => 'حداقل مبلغ شارژ ۱۰٬۰۰۰ است.',
            'amount.max' => 'مبلغ شارژ بیش از حد مجاز است.',
        ]);

        $this->audit($request, 'wallet_charge', 'wallet', ['amount' => $validated['amount']]);

        return response()->json([
            'message' => 'درخواست شارژ کیف پول ثبت شد (در MVP شارژ واقعی انجام نمی‌شود).',
            'data' => ['amount' => $validated['amount'], 'driver' => $this->chabokan->driverName()],
        ], 201);
    }

    /**
     * دسته UIUX (افزودنی): کارت «استخر Idle + ساخت سرویس آماده» —
     * ثبت رکورد دیپلوی queued برای سرویس جدید (بدون تماس واقعی).
     */
    public function provision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:3|max:40|regex:/^[a-z0-9-]+$/',
            'size' => 'sometimes|string|in:small,medium,large',
        ], [
            'name.required' => 'نام سرویس الزامی است.',
            'name.regex' => 'نام سرویس فقط حروف کوچک انگلیسی، عدد و خط تیره (۳ تا ۴۰ نویسه).',
            'size.in' => 'اندازه مقصد معتبر نیست (small/medium/large).',
        ]);

        $deployment = ChabokanDeployment::query()->create([
            'service' => $validated['name'],
            'status' => ChabokanDeployment::QUEUED,
            'triggered_by' => $request->user()->id,
            'meta' => [
                'driver' => $this->chabokan->driverName(),
                'provision' => true,
                'size' => $validated['size'] ?? 'small',
            ],
        ]);

        $this->audit($request, 'provision', $validated['name'], ['deployment_id' => $deployment->id]);

        return response()->json([
            'message' => 'ساخت سرویس آماده ثبت شد.',
            'data' => $deployment,
        ], 201);
    }

    public function services(): JsonResponse
    {
        return response()->json(['data' => $this->chabokan->services()]);
    }

    public function start(Request $request, string $name): JsonResponse
    {
        return $this->runAction($request, $name, 'start');
    }

    public function stop(Request $request, string $name): JsonResponse
    {
        return $this->runAction($request, $name, 'stop');
    }

    public function restart(Request $request, string $name): JsonResponse
    {
        return $this->runAction($request, $name, 'restart');
    }

    public function resize(Request $request, string $name): JsonResponse
    {
        $request->validate([
            'size' => 'required|string|in:small,medium,large',
        ], [
            'size.required' => 'اندازه مقصد (size) الزامی است.',
            'size.in' => 'اندازه مقصد معتبر نیست (small/medium/large).',
        ]);

        return $this->runAction($request, $name, 'resize', ['size' => $request->input('size')]);
    }

    public function deployments(Request $request): JsonResponse
    {
        $items = ChabokanDeployment::query()
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json(['data' => $items]);
    }

    public function redeploy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service' => 'required|string',
            'confirm' => 'required|accepted',
        ], [
            'service.required' => 'نام سرویس الزامی است.',
            'confirm.required' => 'برای دیپلوی مجدد، تأییدیه (confirm:true) الزامی است.',
            'confirm.accepted' => 'برای دیپلوی مجدد، تأییدیه (confirm:true) الزامی است.',
        ]);

        $this->assertAllowedService($validated['service']);

        $deployment = ChabokanDeployment::query()->create([
            'service' => $validated['service'],
            'status' => ChabokanDeployment::QUEUED,
            'triggered_by' => $request->user()->id,
            'meta' => ['driver' => $this->chabokan->driverName()],
        ]);

        $this->audit($request, 'redeploy', $validated['service'], ['deployment_id' => $deployment->id]);

        return response()->json([
            'message' => 'دیپلوی مجدد ثبت شد.',
            'data' => $deployment,
        ], 201);
    }

    /**
     * مقایسه سرویس‌های ثبت‌شده در DB مرکزی با لیست Stub:
     * missing_in_db = سرویسی که Stub می‌شناسد ولی هیچ رکورد دیپلوی ندارد؛
     * missing_in_chabokan = سرویسی که دیپلوی شده ولی Stub نمی‌شناسد.
     */
    public function reconciliation(): JsonResponse
    {
        $stub = collect($this->chabokan->services())->pluck('name')->all();
        $recorded = ChabokanDeployment::query()->distinct()->pluck('service')->all();

        return response()->json([
            'data' => [
                'stub_services' => $stub,
                'recorded_services' => $recorded,
                'missing_in_db' => array_values(array_diff($stub, $recorded)),
                'missing_in_chabokan' => array_values(array_diff($recorded, $stub)),
                'driver' => $this->chabokan->driverName(),
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function runAction(Request $request, string $name, string $action, array $options = []): JsonResponse
    {
        $this->assertAllowedService($name);

        if (in_array($action, self::DESTRUCTIVE, true)) {
            $request->validate([
                'confirm' => 'required|accepted',
            ], [
                'confirm.required' => 'برای این عملیات، تأییدیه (confirm:true) الزامی است.',
                'confirm.accepted' => 'برای این عملیات، تأییدیه (confirm:true) الزامی است.',
            ]);
        }

        try {
            $result = $this->chabokan->serviceAction($name, $action, $options);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'نام سرویس مجاز نیست.'], 422);
        }

        $this->audit($request, $action, $name, $options);

        $messages = [
            'start' => 'سرویس روشن شد.',
            'stop' => 'سرویس خاموش شد.',
            'restart' => 'سرویس ری‌استارت شد.',
            'resize' => 'سایز سرویس تغییر کرد.',
        ];

        return response()->json([
            'message' => $messages[$action] ?? 'عملیات انجام شد.',
            'data' => $result,
        ]);
    }

    private function assertAllowedService(string $name): void
    {
        if (! in_array($name, config('chabokan.allowed_services', []), true)) {
            abort(response()->json(['message' => 'نام سرویس مجاز نیست.'], 422));
        }
    }

    private function audit(Request $request, string $action, string $service, array $payload): void
    {
        ChabokanLog::query()->create([
            'action' => $action,
            'service' => $service,
            'user_id' => $request->user()->id,
            'payload' => $payload,
        ]);
    }
}
