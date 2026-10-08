<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageVital;
use App\Models\PageView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WF-H12 — ثبت بازدیدِ سایت عمومی.
 *
 * عمومی و بدون احراز هویت است (throttle روی route)، چون بازدیدکننده حساب
 * ندارد. خودمیزبان و **بدون کوکی**: نه IP ذخیره می‌شود نه user-agent خام.
 *
 * ## کشور و دستگاه — صادقانه
 *
 * کشور فقط از هدرِ لبه خوانده می‌شود (`CF-IPCountry` یا `X-Country`)؛ اگر
 * لبه آن را ندهد یا مقدار ماشینیِ نامعتبر باشد، `null` می‌ماند. هیچ جدولِ
 * GeoIP محلی وجود ندارد و کشور **حدس زده نمی‌شود**. «دستگاه» هم هنگام ثبت از
 * user-agent مشتق و سپس دور ریخته می‌شود.
 */
class AnalyticsController extends Controller
{
    /** زبان‌های عمومیِ شناخته‌شده — هم‌قرارداد با `lib/i18n/public`. */
    private const PUBLIC_LOCALES = ['fa', 'en'];

    /** مقدارهای «نمی‌دانم»ِ Cloudflare/Tor که نباید به‌عنوان کشور ثبت شوند. */
    private const COUNTRY_UNKNOWN = ['XX', 'T1', ''];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500',
            'referrer' => 'nullable|string|max:1000',
        ]);

        $path = $this->normalizePath((string) $validated['path']);
        if ($path === '') {
            return response()->json(['ok' => true]);
        }

        [$locale, $slug] = $this->deriveLocaleAndSlug($path);

        PageView::query()->create([
            'page_id' => $this->resolvePageId($slug, $locale),
            'path' => $path,
            'slug' => $slug === '' ? null : $slug,
            'referrer' => $this->referrerHost($validated['referrer'] ?? null),
            'country' => $this->country($request),
            'device' => $this->device($request),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * WF-M14 — ثبت Web Vitals (LCP/INP/CLS) برای یک مسیر.
     *
     * هر معیار اختیاری است؛ فقط معیارهای ارسال‌شده ثبت می‌شوند. مقادیر از
     * مرورگرِ بازدیدکننده می‌آیند و **خام** ذخیره می‌شوند (صدک در گزارش
     * ساخته می‌شود). بازدیدهای ربات‌ها ثبت نمی‌شوند چون معیارِ تجربهٔ کاربر
     * ندارند و صدک را آلوده می‌کنند.
     */
    public function vitals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500',
            'lcp' => 'nullable|numeric|min:0|max:600000',
            'inp' => 'nullable|numeric|min:0|max:600000',
            'cls' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($this->device($request) === 'bot') {
            return response()->json(['ok' => true]);
        }

        $metrics = [
            'lcp' => $validated['lcp'] ?? null,
            'inp' => $validated['inp'] ?? null,
            'cls' => $validated['cls'] ?? null,
        ];
        if (array_filter($metrics, static fn ($v): bool => $v !== null) === []) {
            return response()->json(['ok' => true]);
        }

        $path = $this->normalizePath((string) $validated['path']);
        if ($path === '') {
            return response()->json(['ok' => true]);
        }

        [$locale, $slug] = $this->deriveLocaleAndSlug($path);
        $pageId = $this->resolvePageId($slug, $locale);

        foreach ($metrics as $metric => $value) {
            if ($value === null) {
                continue;
            }

            PageVital::query()->create([
                'page_id' => $pageId,
                'path' => $path,
                'slug' => $slug === '' ? null : $slug,
                'metric' => $metric,
                'value' => (float) $value,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** مسیر را نرمال می‌کند: query/hash حذف، اسلش‌های تکراری جمع، بدون اسلشِ انتها. */
    private function normalizePath(string $raw): string
    {
        $path = (string) parse_url(trim($raw), PHP_URL_PATH);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    /**
     * زبانِ مسیر را جدا می‌کند: `/en/about` ⇒ (en, about)، `/about` ⇒ (null, about).
     *
     * @return array{0: string|null, 1: string}
     */
    private function deriveLocaleAndSlug(string $path): array
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return [null, ''];
        }

        $locale = in_array($segments[0], self::PUBLIC_LOCALES, true) ? array_shift($segments) : null;

        return [$locale, implode('/', $segments)];
    }

    /**
     * `page_id` را فقط با تطبیقِ دقیق پیدا می‌کند وگرنه `null`.
     *
     * وقتی زبانِ مسیر معلوم است همان را می‌خواند، وگرنه اولین صفحهٔ هم‌اسلاگ
     * (اسلاگ per-locale یکتاست، نه سراسری). هیچ پیوندِ حدسی ساخته نمی‌شود.
     */
    private function resolvePageId(string $slug, ?string $locale): ?int
    {
        if ($slug === '') {
            return null;
        }

        $query = Page::query()->where('slug', $slug);
        if ($locale !== null) {
            $query->where('locale', $locale);
        }

        return $query->value('id');
    }

    /** فقط میزبانِ ارجاع‌دهنده؛ هرگز URL کامل (حریمِ خصوصیِ مسیرِ قبلی). */
    private function referrerHost(?string $referrer): ?string
    {
        $referrer = trim((string) $referrer);
        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_substr($host, 0, 255) : null;
    }

    /** کشور از هدرِ لبه؛ نبودش یعنی `null` — بدون حدس. */
    private function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Country', 'X-Country-Code'] as $header) {
            $value = strtoupper(trim((string) $request->header($header, '')));
            if (preg_match('/^[A-Z]{2}$/', $value) === 1 && ! in_array($value, self::COUNTRY_UNKNOWN, true)) {
                return $value;
            }
        }

        return null;
    }

    /** دستگاه از user-agent مشتق می‌شود؛ خودِ رشته ذخیره نمی‌شود. */
    private function device(Request $request): string
    {
        $ua = strtolower((string) $request->userAgent());

        if ($ua === '') {
            return 'unknown';
        }

        if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless/', $ua) === 1) {
            return 'bot';
        }

        if (preg_match('/ipad|tablet|kindle|silk|playbook/', $ua) === 1) {
            return 'tablet';
        }

        if (preg_match('/mobi|android|iphone|ipod|windows phone|opera mini/', $ua) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }
}
