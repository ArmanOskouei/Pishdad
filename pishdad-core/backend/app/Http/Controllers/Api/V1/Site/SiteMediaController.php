<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E90 — سروِ عمومیِ فایل‌های رسانه‌ایِ دیسکِ s3 از خودِ بک‌اند.
 *
 * ## چرا این روت وجود دارد
 *
 * نشانی‌های s3 تا امروز مستقیم MinIO بودند (`{AWS_URL}/{path}`). دو مرگ:
 * ۱) سطل با CP-3 خصوصی شد (درست — آپلودهای کاربران نباید جهان‌خوانا باشند)
 *    ولی لوگو/فاوآیکون/OG هم از همان‌جا می‌آمدند ⇒ ۴۰۳ و سایتِ بی‌لوگو؛
 * ۲) `AWS_URL` یک IP/هاستِ ثابت است که با تغییرِ DHCP می‌پوسد (دقیقاً همان
 *    چیزی که روی نصبِ زنده افتاد: `192.168.8.100` دیگر وجود نداشت).
 *
 * حالا `MediaUrl::for('s3', …)` به همین روت اشاره می‌کند: سطل خصوصی
 * می‌ماند و هیچ URL مستقیمِ MinIO دیگر در HTML/JSON عمومی نیست.
 *
 * ## مرز امنیتی
 *
 * - شناسه عددی است (lookup دیتابیسی) — هیچ path traversal ممکن نیست.
 * - فقط رکوردِ موجودِ همان دیسک (`disk = s3`) سرو می‌شود؛ `path` از ورودی
 *   خوانده نمی‌شود.
 * - فقط تصویر/PDF (همان allowlist آپلود S-06): `evil.html`ای که به هر راه
 *   رسیده باشد اینجا ۴۰۴ می‌گیرد، نه `text/html`.
 * - SVG با `Content-Security-Policy: sandbox` سرو می‌شود: به‌عنوان `<img>`
 *   رندر می‌شود ولی اسکریپتِ درونش هرگز اجرا نمی‌شود (XSS ذخیره‌شده).
 * - `X-Content-Type-Options: nosniff` تا مرورگر حدسِ نوع نزند.
 * - کشِ عمومیِ یک‌روزه (replace همان path را نگه می‌دارد، پس immutable نه).
 */
class SiteMediaController extends Controller
{
    /** @var array<string, string> پسوند → mime (آینهٔ MediaController::MEDIA_MIMES). */
    private const ALLOWED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'ico' => 'image/x-icon',
    ];

    public function show(Request $request, int $id): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $media = Media::query()->where('id', $id)->where('disk', 's3')->first();

        if ($media === null) {
            return response()->json(['message' => 'یافت نشد.'], 404);
        }

        // E90 — نسخه‌های واکنش‌گرا (`photo-640.webp`…) فایل‌های جدایند ولی
        // شناسهٔ خودشان را ندارند. `?file=` فقط وقتی پذیرفته می‌شود که عیناً
        // در همین رکورد باشد (مسیرِ اصلی یا یکی از `variants.items.*.sources`):
        // هیچ traversal، هیچ فایلِ غریبه.
        $path = (string) $media->path;
        $requested = $request->query('file');
        if (is_string($requested) && $requested !== '') {
            if (! in_array($requested, $this->servablePaths($media), true)) {
                return response()->json(['message' => 'یافت نشد.'], 404);
            }
            $path = $requested;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::ALLOWED[$extension] ?? null;

        if ($mime === null || ! Storage::disk('s3')->exists($path)) {
            return response()->json(['message' => 'یافت نشد.'], 404);
        }

        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ];

        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        return Storage::disk('s3')->response($path, null, $headers);
    }

    /** @return list<string> مسیرهای قابل‌سروِ همین رکورد (اصلی + نسخه‌ها). */
    private function servablePaths(Media $media): array
    {
        $paths = [(string) $media->path];

        $variants = $media->variants;
        if (is_array($variants) && is_array($variants['items'] ?? null)) {
            foreach ($variants['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];
                foreach ($sources as $variantPath) {
                    if (is_string($variantPath) && $variantPath !== '') {
                        $paths[] = $variantPath;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
