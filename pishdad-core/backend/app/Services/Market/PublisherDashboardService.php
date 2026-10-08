<?php

namespace App\Services\Market;

use App\Models\MarketLedgerEntry;
use App\Models\MarketPayout;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\User;

/**
 * WF-H19 — داشبورد ناشر.
 *
 * ناشر در این مدل، کاربری است که پلاگین‌های `source=market` با `user_id` او
 * ثبت شده‌اند؛ کلید ناشر از روی `plugins.publisher_key_id` (fingerprint) به
 * `publisher_keys` وصل می‌شود. هیچ داده‌ای ساخته نمی‌شود: فقط ردیف‌های واقعی
 * بازار تجمیع می‌شوند و همه‌چیز به خودِ کاربر محدود است.
 */
class PublisherDashboardService
{
    public function __construct(private BillingService $billing) {}

    public function forUser(User $user): array
    {
        $plugins = Plugin::query()
            ->where('user_id', $user->id)
            ->where('source', Plugin::SOURCE_MARKET)
            ->orderByDesc('id')
            ->get();

        $fingerprints = $plugins->pluck('publisher_key_id')
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->unique()
            ->values();

        $publishers = $fingerprints->isEmpty()
            ? collect()
            : PublisherKey::query()->whereIn('key_fingerprint', $fingerprints->all())->get();

        $keyIds = $publishers->pluck('id')->map(fn ($id) => (int) $id)->all();

        [$totals, $salesCount, $currency] = $this->ledgerTotals($keyIds);

        $payouts = $keyIds === []
            ? collect()
            : MarketPayout::query()->whereIn('publisher_key_id', $keyIds)->latest()->get();

        return [
            'publishers' => $publishers->map(fn (PublisherKey $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'key_fingerprint' => $p->key_fingerprint,
                'status' => $p->status,
                'verified_at' => $p->verified_at?->toIso8601String(),
            ])->values()->all(),
            'plugins' => $plugins->map(fn (Plugin $p) => $this->presentPlugin($p))->values()->all(),
            'sales' => [
                'currency' => $currency,
                'gross' => $totals[MarketLedgerEntry::KIND_SALE],
                'platform_fee' => $totals[MarketLedgerEntry::KIND_PLATFORM_FEE],
                'publisher_share' => $totals[MarketLedgerEntry::KIND_PUBLISHER_SHARE],
                'payout' => $totals[MarketLedgerEntry::KIND_PAYOUT],
                'unsettled' => $totals[MarketLedgerEntry::KIND_PUBLISHER_SHARE]
                    - $totals[MarketLedgerEntry::KIND_PAYOUT],
                'sales_count' => $salesCount,
            ],
            'payouts' => $payouts->map(fn (MarketPayout $p) => [
                'id' => $p->id,
                'publisher_key_id' => (int) $p->publisher_key_id,
                'amount' => (int) $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'note' => $p->note,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * درخواست تسویهٔ ناشر — یک ردیف pending می‌سازد؛ اجرا همچنان دستی است.
     * سقف: سهم تسویه‌نشده منهای درخواست‌های pending (تا درخواست چندباره نشود).
     */
    public function requestPayout(
        User $user,
        int $amount,
        ?string $note,
        string $currency,
        ?int $publisherKeyId,
    ): MarketPayout {
        if ($amount <= 0) {
            throw new MarketException('Payout amount must be positive.', 422);
        }

        $keyIds = $this->publisherKeyIds($user);

        if ($publisherKeyId !== null) {
            if (! in_array($publisherKeyId, $keyIds, true)) {
                throw new MarketException('This publisher key does not belong to you.', 403);
            }
            $keyId = $publisherKeyId;
        } elseif ($keyIds === []) {
            throw new MarketException('You are not a registered publisher yet.', 422);
        } elseif (count($keyIds) === 1) {
            $keyId = $keyIds[0];
        } else {
            throw new MarketException('Multiple publisher keys: choose one to settle.', 422);
        }

        $earned = (int) MarketLedgerEntry::query()
            ->where('publisher_key_id', $keyId)
            ->where('kind', MarketLedgerEntry::KIND_PUBLISHER_SHARE)
            ->sum('amount');
        $paid = (int) MarketLedgerEntry::query()
            ->where('publisher_key_id', $keyId)
            ->where('kind', MarketLedgerEntry::KIND_PAYOUT)
            ->sum('amount');
        $pending = (int) MarketPayout::query()
            ->where('publisher_key_id', $keyId)
            ->where('status', MarketPayout::STATUS_PENDING)
            ->sum('amount');

        if ($amount > $earned - $paid - $pending) {
            throw new MarketException('Payout exceeds the unsettled publisher share.', 422);
        }

        return $this->billing->createPayout($keyId, $amount, $note, $currency);
    }

    /** @return list<int> */
    public function publisherKeyIds(User $user): array
    {
        $fingerprints = Plugin::query()
            ->where('user_id', $user->id)
            ->where('source', Plugin::SOURCE_MARKET)
            ->whereNotNull('publisher_key_id')
            ->distinct()
            ->pluck('publisher_key_id')
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->values();

        if ($fingerprints->isEmpty()) {
            return [];
        }

        return PublisherKey::query()
            ->whereIn('key_fingerprint', $fingerprints->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $keyIds
     * @return array{0: array<string, int>, 1: int, 2: string}
     */
    private function ledgerTotals(array $keyIds): array
    {
        $totals = [
            MarketLedgerEntry::KIND_SALE => 0,
            MarketLedgerEntry::KIND_PLATFORM_FEE => 0,
            MarketLedgerEntry::KIND_PUBLISHER_SHARE => 0,
            MarketLedgerEntry::KIND_PAYOUT => 0,
        ];
        $salesCount = 0;
        $currency = 'IRT';

        if ($keyIds === []) {
            return [$totals, $salesCount, $currency];
        }

        $rows = MarketLedgerEntry::query()
            ->whereIn('publisher_key_id', $keyIds)
            ->get(['kind', 'amount', 'currency']);

        foreach ($rows as $row) {
            $kind = (string) $row->kind;
            if (array_key_exists($kind, $totals)) {
                $totals[$kind] += (int) $row->amount;
            }
            if ($kind === MarketLedgerEntry::KIND_SALE) {
                $salesCount++;
            }
            if (is_string($row->currency) && $row->currency !== '') {
                $currency = $row->currency;
            }
        }

        return [$totals, $salesCount, $currency];
    }

    private function presentPlugin(Plugin $p): array
    {
        $manifest = is_array($p->manifest) ? $p->manifest : [];

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'version' => $p->version,
            'previous_version' => $p->previous_version,
            'price' => max(0, (int) ($manifest['price'] ?? 0)),
            'currency' => is_string($manifest['currency'] ?? null) ? $manifest['currency'] : 'IRT',
            'review_status' => $p->review_status,
            'review_note' => $p->review_note,
            'yanked' => (bool) $p->yanked,
            'yank_reason' => $p->yank_reason,
            'active' => (bool) $p->active,
            'submitted_at' => $p->submitted_at?->toIso8601String(),
            'reviewed_at' => $p->reviewed_at?->toIso8601String(),
        ];
    }
}
