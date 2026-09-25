<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Backup;
use App\Models\Central\BillingEvent;
use App\Models\Central\Client;
use App\Models\Central\Subscription;
use App\Models\Central\SupportToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * تسک ۱.۴ — مدیریت مشتریان مرکزی (فقط operator).
 * ارتقا تیر فقط یک پله رو به جلو؛ توکن پشتیبانی یکبارمصرف (هش + TTL ۱۵ دقیقه)؛
 * بکاپ/ریستور در MVP رکورد Stub است (pg_dump واقعی TODO مرحله ۵).
 */
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->with('subscription.plan:id,name,slug');

        if ($tier = $request->query('tier')) {
            $query->where('tier', $tier);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        // دسته ۱ (برابری UI/UX): جستجوی متنی نام/دامنه/site_id/ایمیل مالک.
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('domain', 'ilike', "%{$search}%")
                ->orWhere('site_id', 'ilike', "%{$search}%")
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'ilike', "%{$search}%")));
        }

        $clients = $query->latest()->paginate((int) $request->query('per_page', 20));

        if ($health = $request->query('health')) {
            $clients->setCollection(
                $clients->getCollection()->filter(fn (Client $c) => $c->healthStatus() === $health)->values()
            );
        }

        $clients->getCollection()->transform(fn (Client $c) => array_merge($c->toArray(), [
            'health_status' => $c->healthStatus(),
        ]));

        return response()->json(['data' => $clients]);
    }

    public function show(Client $client): JsonResponse
    {
        $client->load(['subscription.plan', 'invoices' => fn ($q) => $q->latest()->limit(10), 'backups' => fn ($q) => $q->latest()->limit(5)]);

        return response()->json([
            'data' => array_merge($client->toArray(), ['health_status' => $client->healthStatus()]),
        ]);
    }

    /** ساخت مشتری + نصب اولیه (اشتراک ۳۰ روزه). Provisioning واقعی زیرساخت TODO مرحله بعد. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'domain' => 'sometimes|nullable|string|max:200',
            'tier' => 'sometimes|string|in:budget,standard,enterprise',
            'owner_user_id' => 'sometimes|nullable|exists:users,id',
            'site_id' => 'sometimes|string|max:100|unique:central_clients,site_id',
        ], [
            'name.required' => 'نام مشتری الزامی است.',
            'tier.in' => 'تیر معتبر نیست.',
        ]);

        $client = Client::query()->create(array_merge(
            ['tier' => 'budget', 'status' => 'active', 'site_id' => 'site-'.Str::random(8)],
            $validated
        ));

        Subscription::query()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'status' => Subscription::ACTIVE,
            'due_at' => now()->addDays(30),
        ]);

        return response()->json([
            'message' => 'مشتری ساخته شد.',
            'data' => $client->fresh()->load('subscription'),
        ], 201);
    }

    /** ارتقا تیر: فقط Budget→Standard→Enterprise (یک پله، بدون بازگشت). */
    public function upgradeTier(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'tier' => 'required|string|in:budget,standard,enterprise',
        ], [
            'tier.required' => 'تیر مقصد الزامی است.',
            'tier.in' => 'تیر مقصد معتبر نیست.',
        ]);

        $order = config('billing.tiers', ['budget', 'standard', 'enterprise']);
        $current = array_search($client->tier, $order, true);
        $target = array_search($validated['tier'], $order, true);

        if ($target !== $current + 1) {
            return response()->json([
                'message' => 'ارتقا فقط یک پله رو به جلو مجاز است (Budget→Standard→Enterprise).',
            ], 422);
        }

        $client->forceFill(['tier' => $validated['tier']])->save();

        BillingEvent::query()->create([
            'client_id' => $client->id,
            'subscription_id' => $client->subscription?->id,
            'type' => 'tier_upgrade',
            'message' => "ارتقا تیر به {$validated['tier']}.",
        ]);

        return response()->json(['message' => 'تیر مشتری ارتقا یافت.', 'data' => $client->fresh()]);
    }

    /**
     * صدور توکن یکبارمصرف سوپرادمین: متن خام فقط همین‌بار برمی‌گردد؛
     * ذخیره فقط هش است، ۱۵ دقیقه اعتبار، با اولین مصرف می‌سوزد.
     */
    public function supportToken(Client $client): JsonResponse
    {
        $plain = 'sup_'.Str::random(32);

        $token = SupportToken::query()->create([
            'client_id' => $client->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes((int) config('billing.support_token_ttl_minutes', 15)),
        ]);

        return response()->json([
            'message' => 'توکن پشتیبانی ساخته شد؛ فقط یک‌بار نمایش داده می‌شود.',
            'data' => [
                'token' => $plain,
                'expires_at' => $token->expires_at,
            ],
        ], 201);
    }

    /** مصرف توکن پشتیبانی (ورود سوپرادمین به نصب مشتری): دومین مصرف ناموفق است. */
    public function consumeSupportToken(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ], [
            'token.required' => 'توکن الزامی است.',
        ]);

        $token = SupportToken::query()
            ->where('client_id', $client->id)
            ->where('token_hash', hash('sha256', $validated['token']))
            ->first();

        if (! $token || ! $token->isConsumable()) {
            return response()->json(['message' => 'توکن نامعتبر، منقضی یا قبلاً مصرف شده است.'], 410);
        }

        $token->forceFill(['used_at' => now()])->save();

        return response()->json([
            'message' => 'توکن پذیرفته شد.',
            'data' => ['client_id' => $client->id, 'site_id' => $client->site_id],
        ]);
    }

    public function suspend(Client $client): JsonResponse
    {
        $client->forceFill(['status' => 'suspended'])->save();
        $client->subscription?->forceFill([
            'status' => Subscription::SUSPENDED, 'suspended_at' => now(),
        ])->save();

        BillingEvent::query()->create([
            'client_id' => $client->id,
            'subscription_id' => $client->subscription?->id,
            'type' => 'suspend',
            'message' => 'تعلیق دستی مشتری توسط اپراتور.',
        ]);

        return response()->json(['message' => 'مشتری معلق شد.', 'data' => $client->fresh()]);
    }

    public function resume(Client $client): JsonResponse
    {
        $client->forceFill(['status' => 'active'])->save();
        $client->subscription?->forceFill([
            'status' => Subscription::ACTIVE, 'suspended_at' => null,
        ])->save();

        BillingEvent::query()->create([
            'client_id' => $client->id,
            'subscription_id' => $client->subscription?->id,
            'type' => 'resume',
            'message' => 'بازگشایی دستی مشتری توسط اپراتور.',
        ]);

        return response()->json(['message' => 'مشتری بازگشایی شد.', 'data' => $client->fresh()]);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->forceFill(['status' => 'deleted'])->save();
        $client->subscription?->forceFill([
            'status' => Subscription::DELETED, 'deleted_at' => now(),
        ])->save();

        BillingEvent::query()->create([
            'client_id' => $client->id,
            'subscription_id' => $client->subscription?->id,
            'type' => 'purge',
            'message' => 'حذف دستی مشتری توسط اپراتور.',
        ]);

        return response()->json(['message' => 'مشتری حذف شد.']);
    }

    public function backups(Client $client): JsonResponse
    {
        return response()->json([
            'data' => $client->backups()->latest()->paginate(20),
        ]);
    }

    /** Stub: ثبت رکورد بکاپ. TODO: pg_dump واقعی + آپلود MinIO رمزنگاری‌شده (مرحله ۵). */
    public function backup(Client $client): JsonResponse
    {
        $backup = Backup::query()->create([
            'client_id' => $client->id,
            'type' => 'manual',
            'status' => 'done',
            'path' => "stub://backups/{$client->site_id}/".now()->format('Ymd-His').".dump",
            'meta' => ['stub' => true, 'todo' => 'pg_dump واقعی در مرحله ۵'],
        ]);

        return response()->json(['message' => 'بکاپ ثبت شد (Stub).', 'data' => $backup], 201);
    }

    /** Stub: علامت‌گذاری ریستور. TODO: بازیابی واقعی از روی بکاپ (مرحله ۵). */
    public function restore(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'backup_id' => 'required|exists:central_backups,id',
        ], [
            'backup_id.required' => 'شناسه بکاپ الزامی است.',
        ]);

        $backup = Backup::query()->where('id', $validated['backup_id'])
            ->where('client_id', $client->id)->firstOrFail();

        $backup->forceFill(['status' => 'restoring', 'meta' => array_merge($backup->meta ?? [], [
            'stub' => true, 'todo' => 'ریستور واقعی در مرحله ۵',
        ])])->save();

        return response()->json(['message' => 'ریستور آغاز شد (Stub).', 'data' => $backup->fresh()]);
    }
}
