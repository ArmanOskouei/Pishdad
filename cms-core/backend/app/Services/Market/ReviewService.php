<?php

namespace App\Services\Market;

use App\Models\MarketLicense;
use App\Models\MarketNotice;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\User;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginTrustStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * K8.2 (upload-to-review + approve/reject, reusing Plugin.review_status),
 * K8.3 (yank), K8.4 (install-from-market, no money).
 *
 * K1.7-A: a package with >= 1 validator error is REJECTED with 422 —
 * never "install despite errors".
 */
class ReviewService
{
    public function __construct(
        private PluginPackageValidator $validator,
        private PluginTrustStore $trustStore,
    ) {}

    /**
     * Publisher submits a ZIP for review. Returns the pending Plugin row.
     *
     * @throws MarketException 422 on any validator error (K1.7-A), bad
     *                          signature, unknown publisher, or slug clash.
     */
    public function submit(UploadedFile $file, User $user): Plugin
    {
        $report = $this->validator->analyze($file->getRealPath());

        if (count($report['errors'] ?? []) > 0) {
            throw new MarketException(
                'Package rejected: validation errors must be fixed first.',
                422,
                ['errors' => $report['errors'], 'warnings' => $report['warnings'] ?? []]
            );
        }

        $manifest = $report['manifest'] ?? null;
        if (! is_array($manifest) || empty($manifest['slug']) || empty($manifest['name'])) {
            throw new MarketException('Package manifest is missing slug/name.', 422);
        }

        $check = $this->trustStore->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            Log::warning('market.submit_bad_signature', [
                'user_id' => $user->id, 'slug' => $manifest['slug'] ?? null,
            ]);

            throw new MarketException('Package signature invalid: '.$check['reason'], 422);
        }

        $keyId = $check['key_id'];
        $publisher = $keyId !== null
            ? PublisherKey::query()->where('key_fingerprint', $keyId)->first()
            : null;
        if ($publisher === null || ! $publisher->isActive()) {
            throw new MarketException('Publisher key is unknown or revoked; register it first.', 422);
        }

        if (Plugin::query()->where('slug', $manifest['slug'])->exists()) {
            throw new MarketException('A plugin with this slug already exists.', 422);
        }

        return Plugin::query()->create([
            'user_id' => $user->id,
            'name' => $manifest['name'],
            'slug' => $manifest['slug'],
            'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0',
            'source' => Plugin::SOURCE_MARKET,
            'publisher_key_id' => $keyId,
            'publisher_verified' => true,
            'signature_valid' => true,
            'review_status' => Plugin::REVIEW_PENDING,
            'submitted_at' => now(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'manifest' => $manifest,
        ]);
    }

    /**
     * WF-H19 — ناشر نسخهٔ جدیدی از پلاگینِ خودش را می‌فرستد. همان دروازه‌های
     * `submit` (اعتبارسنجی + امضای Ed25519) دوباره اجرا می‌شوند، ولی ردیف
     * موجود به‌روزرسانی و به وضعیت «در انتظار بازبینی» برگردانده می‌شود.
     *
     * @throws MarketException 403 (مالکیت/کلید) | 422 (اعتبارسنجی/اسلاگ).
     */
    public function submitVersion(Plugin $plugin, UploadedFile $file, User $user): Plugin
    {
        if ((int) $plugin->user_id !== (int) $user->id) {
            throw new MarketException('You can only publish new versions of your own plugins.', 403);
        }

        if ($plugin->source !== Plugin::SOURCE_MARKET) {
            throw new MarketException('Only market plugins can receive a new version here.', 422);
        }

        $report = $this->validator->analyze($file->getRealPath());

        if (count($report['errors'] ?? []) > 0) {
            throw new MarketException(
                'Package rejected: validation errors must be fixed first.',
                422,
                ['errors' => $report['errors'], 'warnings' => $report['warnings'] ?? []]
            );
        }

        $manifest = $report['manifest'] ?? null;
        if (! is_array($manifest) || empty($manifest['slug']) || empty($manifest['name'])) {
            throw new MarketException('Package manifest is missing slug/name.', 422);
        }

        if ((string) $manifest['slug'] !== (string) $plugin->slug) {
            throw new MarketException('The uploaded package slug does not match this plugin.', 422);
        }

        $check = $this->trustStore->verifyPublisherSignature($manifest);
        if (! $check['valid']) {
            throw new MarketException('Package signature invalid: '.$check['reason'], 422);
        }

        $keyId = $check['key_id'];
        $publisher = $keyId !== null
            ? PublisherKey::query()->where('key_fingerprint', $keyId)->first()
            : null;
        if ($publisher === null || ! $publisher->isActive()) {
            throw new MarketException('Publisher key is unknown or revoked; register it first.', 422);
        }

        if ((string) $plugin->publisher_key_id !== (string) $keyId) {
            throw new MarketException('The signing key does not match the original publisher key.', 403);
        }

        $plugin->forceFill([
            'previous_version' => $plugin->version,
            'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : $plugin->version,
            'manifest' => $manifest,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'publisher_verified' => true,
            'signature_valid' => true,
            'review_status' => Plugin::REVIEW_PENDING,
            'review_note' => null,
            'reviewed_at' => null,
            'submitted_at' => now(),
        ])->save();

        Log::info('market.version_submitted', [
            'plugin_id' => $plugin->id, 'user_id' => $user->id, 'version' => $plugin->version,
        ]);

        return $plugin->fresh();
    }

    public function approve(Plugin $plugin, ?string $note = null): Plugin
    {
        if ($plugin->review_status !== Plugin::REVIEW_PENDING) {
            throw new MarketException('Only pending submissions can be approved.', 422);
        }

        $plugin->forceFill([
            'review_status' => Plugin::REVIEW_APPROVED,
            'review_note' => $note,
            'reviewed_at' => now(),
        ])->save();

        return $plugin->fresh();
    }

    public function reject(Plugin $plugin, ?string $note = null): Plugin
    {
        if ($plugin->review_status !== Plugin::REVIEW_PENDING) {
            throw new MarketException('Only pending submissions can be rejected.', 422);
        }

        $plugin->forceFill([
            'review_status' => Plugin::REVIEW_REJECTED,
            'review_note' => $note,
            'reviewed_at' => now(),
        ])->save();

        return $plugin->fresh();
    }

    /**
     * K8.3 — yank: record stays, fresh installs blocked, existing installs
     * (licenses, active flags) keep working. Emits a security notice.
     */
    public function yank(Plugin $plugin, string $reason): Plugin
    {
        if (trim($reason) === '') {
            throw new MarketException('A yank reason is required.', 422);
        }

        return DB::transaction(function () use ($plugin, $reason) {
            $plugin->forceFill([
                'yanked' => true,
                'yanked_at' => now(),
                'yank_reason' => $reason,
            ])->save();

            MarketNotice::query()->create([
                'plugin_id' => $plugin->id,
                'type' => MarketNotice::TYPE_YANK,
                'message' => "SECURITY: plugin [{$plugin->slug}] v{$plugin->version} yanked: {$reason}. "
                    .'Existing installs keep working; fresh installs are blocked. Update or remove at will.',
            ]);

            Log::warning('market.plugin_yanked', ['slug' => $plugin->slug, 'reason' => $reason]);

            return $plugin->fresh();
        });
    }

    public function unyank(Plugin $plugin): Plugin
    {
        return DB::transaction(function () use ($plugin) {
            $plugin->forceFill(['yanked' => false, 'yanked_at' => null, 'yank_reason' => null])->save();

            MarketNotice::query()->create([
                'plugin_id' => $plugin->id,
                'type' => MarketNotice::TYPE_UNYANK,
                'message' => "Plugin [{$plugin->slug}] restored to the market.",
            ]);

            return $plugin->fresh();
        });
    }

    /**
     * K0.7 yanked-non-deletable guard for the market layer.
     *
     * TODO: PluginController::uninstall is frozen for K8 (file boundary) and
     * does NOT call this guard, so a yanked plugin can still be deleted via
     * DELETE /api/v1/admin/plugins/{plugin}/uninstall. To close the gap, K8.6
     * (or a follow-up) should either route uninstalls through this guard or
     * move the check into Plugin::deleting().
     *
     * @throws MarketException 409 when the plugin is yanked.
     */
    public function ensureDeletable(Plugin $plugin): void
    {
        if ((bool) ($plugin->yanked ?? false)) {
            throw new MarketException(
                'Yanked plugins cannot be deleted (K0.7 yanked-non-deletable). Unyank first.',
                409
            );
        }
    }

    /**
     * K8.4 — install from market without money. Free plugins get a license on
     * the spot; priced plugins require a paid order (see BillingService).
     *
     * @throws MarketException 422 (not approved) | 410 (yanked) | 402 (payment required).
     */
    public function install(Plugin $plugin, User $user): MarketLicense
    {
        if ((bool) ($plugin->yanked ?? false)) {
            throw new MarketException('Plugin yanked: fresh installs are blocked.', 410);
        }

        if ($plugin->review_status !== Plugin::REVIEW_APPROVED) {
            throw new MarketException('Only approved market plugins can be installed.', 422);
        }

        $price = $this->priceOf($plugin);
        $license = MarketLicense::query()
            ->where('user_id', $user->id)->where('plugin_id', $plugin->id)->first();

        if ($license !== null && $license->coversCore()) {
            return $license;
        }

        if ($price > 0 && $license === null) {
            throw new MarketException('This plugin requires a paid order first.', 402);
        }

        return MarketLicense::query()->updateOrCreate(
            ['user_id' => $user->id, 'plugin_id' => $plugin->id],
            ['valid_until_major' => CoreMajor::current(), 'active' => true, 'order_id' => $license?->order_id]
        );
    }

    public function priceOf(Plugin $plugin): int
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];
        $price = $manifest['price'] ?? 0;

        return max(0, (int) $price);
    }

    public function currencyOf(Plugin $plugin): string
    {
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];

        return is_string($manifest['currency'] ?? null) ? $manifest['currency'] : 'IRT';
    }
}
