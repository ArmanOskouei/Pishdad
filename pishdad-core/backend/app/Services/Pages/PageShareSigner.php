<?php

namespace App\Services\Pages;

/**
 * WF-H2 — امضای لینکِ اشتراکِ پیش‌نویس (preview بدون ورود).
 *
 * قالبِ توکن: `base64url(page_id.revision_id.expires_at.nonce).hmac_sha256`.
 * امضا روی همان بخشِ base64 محاسبه می‌شود (بدون ابهامِ جداکننده).
 *
 * - انقضا: `expires_at` (ثانیهٔ یونیکس) داخلِ خودِ توکن است و در `verify`
 *   برگردانده می‌شود تا کنترلر بتواند ۴۱۰ بدهد.
 * - یکتایی/چرخش: `nonce` تصادفی تضمین می‌کند دو لینکِ ساخته‌شده در یک ثانیه
 *   توکنِ یکسان نگیرند؛ پس ساختِ لینکِ تازه واقعاً لینکِ قبلی را می‌چرخاند.
 * - دست‌کاری: تغییرِ هر بخش، `hash_equals` را می‌شکند (۴۰۳).
 * - راز: همان `revalidate.secret` موجود؛ هیچ رازِ تازه‌ای ساخته نمی‌شود.
 */
class PageShareSigner
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 86400,
    ) {}

    /**
     * @return array{token: string, expires_at: int}
     */
    public function sign(int $pageId, int $revisionId, ?int $ttlSeconds = null): array
    {
        $expiresAt = time() + ($ttlSeconds ?? $this->ttlSeconds);
        $nonce = bin2hex(random_bytes(8));

        $payload = $pageId.'.'.$revisionId.'.'.$expiresAt.'.'.$nonce;
        $encoded = $this->base64UrlEncode($payload);
        $token = $encoded.'.'.hash_hmac('sha256', $encoded, $this->secret);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{page_id: int, revision_id: int, expires_at: int}|null
     *                                                                 null = قالب/امضا نامعتبر (دست‌کاری‌شده)
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if ($encoded === '' || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $this->secret);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($encoded);
        if ($payload === null) {
            return null;
        }

        $segments = explode('.', $payload);
        if (count($segments) !== 4) {
            return null;
        }

        foreach ([0, 1, 2] as $index) {
            if (! ctype_digit($segments[$index])) {
                return null;
            }
        }

        if (preg_match('/^[0-9a-f]{16}$/', $segments[3]) !== 1) {
            return null;
        }

        return [
            'page_id' => (int) $segments[0],
            'revision_id' => (int) $segments[1],
            'expires_at' => (int) $segments[2],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
