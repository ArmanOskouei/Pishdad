<?php

namespace App\Services\Market;

use App\Models\MarketLedgerEntry;
use App\Models\MarketLicense;
use App\Models\MarketOrder;
use App\Models\MarketPayout;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * K8.5 — orders + licenses + settlement ledger + MANUAL payouts.
 * No gateway anywhere (K8.6 deferred): the buyer/operator confirms payment
 * manually, and the operator executes payouts manually.
 */
class BillingService
{
    /** Platform cut in basis points (10%). */
    public const PLATFORM_FEE_BPS = 1000;

    public function __construct(private ReviewService $review) {}

    public function createOrder(Plugin $plugin, User $user): MarketOrder
    {
        if ((bool) ($plugin->yanked ?? false)) {
            throw new MarketException('Plugin yanked: fresh orders are blocked.', 410);
        }

        if ($plugin->review_status !== Plugin::REVIEW_APPROVED) {
            throw new MarketException('Only approved market plugins can be ordered.', 422);
        }

        return MarketOrder::query()->create([
            'user_id' => $user->id,
            'plugin_id' => $plugin->id,
            'amount' => $this->review->priceOf($plugin),
            'currency' => $this->review->currencyOf($plugin),
            'status' => MarketOrder::STATUS_PENDING,
        ]);
    }

    /**
     * Manual payment confirmation (no gateway). The buyer or an operator
     * confirms; writes sale/fee/share ledger rows + ensures license.
     */
    public function payOrder(MarketOrder $order, User $actor): MarketOrder
    {
        if (! $order->isPaid() && $order->status !== MarketOrder::STATUS_PENDING) {
            throw new MarketException('Only pending orders can be paid.', 422);
        }

        if ($order->isPaid()) {
            return $order;
        }

        $isOperator = (($actor->role ?? null) === 'operator');
        if ((int) $order->user_id !== (int) $actor->id && ! $isOperator) {
            throw new MarketException('Only the buyer or an operator can confirm payment.', 403);
        }

        return DB::transaction(function () use ($order) {
            $order->forceFill(['status' => MarketOrder::STATUS_PAID, 'paid_at' => now()])->save();

            $plugin = $order->plugin;
            $publisherKeyId = $this->publisherRowId($plugin);
            $fee = intdiv((int) $order->amount * self::PLATFORM_FEE_BPS, 10000);

            MarketLedgerEntry::query()->create([
                'order_id' => $order->id, 'plugin_id' => $order->plugin_id,
                'publisher_key_id' => $publisherKeyId, 'kind' => MarketLedgerEntry::KIND_SALE,
                'amount' => $order->amount, 'currency' => $order->currency,
                'meta' => ['by' => 'manual-confirm'],
            ]);
            MarketLedgerEntry::query()->create([
                'order_id' => $order->id, 'plugin_id' => $order->plugin_id,
                'publisher_key_id' => $publisherKeyId, 'kind' => MarketLedgerEntry::KIND_PLATFORM_FEE,
                'amount' => $fee, 'currency' => $order->currency, 'meta' => ['bps' => self::PLATFORM_FEE_BPS],
            ]);
            MarketLedgerEntry::query()->create([
                'order_id' => $order->id, 'plugin_id' => $order->plugin_id,
                'publisher_key_id' => $publisherKeyId, 'kind' => MarketLedgerEntry::KIND_PUBLISHER_SHARE,
                'amount' => (int) $order->amount - $fee, 'currency' => $order->currency, 'meta' => null,
            ]);

            MarketLicense::query()->updateOrCreate(
                ['user_id' => $order->user_id, 'plugin_id' => $order->plugin_id],
                ['order_id' => $order->id, 'valid_until_major' => CoreMajor::current(), 'active' => true]
            );

            Log::info('market.order_paid', ['order_id' => $order->id]);

            return $order->fresh();
        });
    }

    public function createPayout(int $publisherKeyId, int $amount, ?string $note, string $currency = 'IRT'): MarketPayout
    {
        $key = PublisherKey::query()->find($publisherKeyId);
        if ($key === null) {
            throw new MarketException('Publisher key not found.', 404);
        }

        if ($amount <= 0) {
            throw new MarketException('Payout amount must be positive.', 422);
        }

        // Manual settlement: operator must not pay out more than the earned share.
        $earned = (int) MarketLedgerEntry::query()
            ->where('publisher_key_id', $publisherKeyId)
            ->where('kind', MarketLedgerEntry::KIND_PUBLISHER_SHARE)
            ->sum('amount');
        $alreadyPaid = (int) MarketLedgerEntry::query()
            ->where('publisher_key_id', $publisherKeyId)
            ->where('kind', MarketLedgerEntry::KIND_PAYOUT)
            ->sum('amount');

        if ($amount > $earned - $alreadyPaid) {
            throw new MarketException('Payout exceeds the unsettled publisher share.', 422);
        }

        return MarketPayout::query()->create([
            'publisher_key_id' => $publisherKeyId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => MarketPayout::STATUS_PENDING,
            'note' => $note,
        ]);
    }

    /** Manual payout execution (bank transfer etc. happens off-system). */
    public function payPayout(MarketPayout $payout): MarketPayout
    {
        if ($payout->isPaid()) {
            return $payout;
        }

        return DB::transaction(function () use ($payout) {
            $payout->forceFill(['status' => MarketPayout::STATUS_PAID, 'paid_at' => now()])->save();

            MarketLedgerEntry::query()->create([
                'order_id' => null, 'plugin_id' => null,
                'publisher_key_id' => $payout->publisher_key_id,
                'kind' => MarketLedgerEntry::KIND_PAYOUT,
                'amount' => $payout->amount, 'currency' => $payout->currency,
                'meta' => ['payout_id' => $payout->id, 'by' => 'manual'],
            ]);

            Log::info('market.payout_paid', ['payout_id' => $payout->id]);

            return $payout->fresh();
        });
    }

    private function publisherRowId(Plugin $plugin): ?int
    {
        $fingerprint = is_string($plugin->publisher_key_id) ? $plugin->publisher_key_id : null;
        if ($fingerprint === null) {
            return null;
        }

        return PublisherKey::query()->where('key_fingerprint', $fingerprint)->value('id');
    }
}
