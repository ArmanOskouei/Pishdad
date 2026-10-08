<?php

namespace App\Services\Plugins;

use App\Models\Plugin;

/**
 * WF-H17 — «آپدیت موجود» برای افزونه‌های نصب‌شده.
 *
 * ## آخرین نسخهٔ بازار از کجا می‌آید
 *
 * دو منبعِ **واقعیِ** موجود در این نصب بررسی می‌شود و هیچ‌کدام ساخته نمی‌شود:
 *
 *  ۱. **فهرست بازار** — رکوردِ `plugins` با `source=market`، وضعیت
 *     `approved` و `yanked=false` که همان `slug` را دارد. این همان چیزی است
 *     که `routes/api.php` زیر `v1/market/*` منتشر می‌کند. چون روی `plugins`
 *     یکتاییِ `slug` هست، معمولاً همین رکورد همان افزونهٔ نصب‌شده است و
 *     نسخه‌اش با نسخهٔ نصب‌شده برابر می‌شود (⇒ آپدیت نیست).
 *  ۲. **اطلاعات انتشارِ خودِ مانیفست** — اگر بسته‌ای `latest_version` را در
 *     `manifest.json` اعلام کرده باشد. این همان منبعی است که فرانت پیش از این
 *     مستقیم از `manifest.latest_version` می‌خواند؛ اینجا فقط یک‌بار و
 *     سمتِ سرور محاسبه می‌شود تا UI مجبور نباشد درباره‌اش حدس بزند.
 *
 * اگر هیچ‌کدام نسخهٔ جدیدتری نداشته باشند، `latest_version` و `changelog`
 * `null` و `update_available` `false` است — یعنی «اطلاعات انتشار نیست»، نه
 * «آپدیت هست».
 *
 * `changelog` از مانیفستِ فهرست بازار (اگر بود) وگرنه از مانیفستِ خودِ افزونه
 * خوانده می‌شود؛ کلیدهای پذیرفته‌شده: `changelog`، `release_notes`.
 */
final class PluginUpdateResolver
{
    /** @var array<string, array{version: ?string, changelog: ?string}> */
    private array $marketCache = [];

    /**
     * @return array{latest_version: ?string, update_available: bool, changelog: ?string}
     */
    public function forPlugin(Plugin $plugin): array
    {
        $installed = (string) $plugin->version;
        $manifest = is_array($plugin->manifest) ? $plugin->manifest : [];

        $market = $this->marketRelease($plugin->slug);
        $declared = $this->stringOrNull($manifest['latest_version'] ?? null);

        $latest = null;

        foreach ([$market['version'], $declared] as $candidate) {
            if ($candidate === null || ! $this->isVersionLike($candidate)) {
                continue;
            }
            if ($latest === null || version_compare($candidate, $latest, '>')) {
                $latest = $candidate;
            }
        }

        return [
            'latest_version' => $latest,
            'update_available' => $latest !== null && version_compare($latest, $installed, '>'),
            'changelog' => $market['changelog'] ?? $this->changelogFrom($manifest),
        ];
    }

    /**
     * @return array{version: ?string, changelog: ?string}
     */
    private function marketRelease(string $slug): array
    {
        if (array_key_exists($slug, $this->marketCache)) {
            return $this->marketCache[$slug];
        }

        $listing = Plugin::query()
            ->where('slug', $slug)
            ->where('source', Plugin::SOURCE_MARKET)
            ->where('review_status', Plugin::REVIEW_APPROVED)
            ->where('yanked', false)
            ->orderByDesc('id')
            ->first();

        $manifest = $listing !== null && is_array($listing->manifest) ? $listing->manifest : [];

        return $this->marketCache[$slug] = [
            'version' => $listing?->version !== null ? (string) $listing->version : null,
            'changelog' => $this->changelogFrom($manifest),
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function changelogFrom(array $manifest): ?string
    {
        foreach (['changelog', 'release_notes'] as $key) {
            $value = $manifest[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isVersionLike(string $version): bool
    {
        return preg_match('/^\d+(\.\d+)*([-.+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }
}
