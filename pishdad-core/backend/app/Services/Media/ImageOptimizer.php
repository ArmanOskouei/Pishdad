<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * WF-C4 — تولید نسخه‌های واکنش‌گرا (WebP/AVIF + چند عرض) کنار فایل اصلی،
 * روی **همان دیسکِ رکورد** (`s3` یا `public`/`local`).
 *
 * ## فیلسوفِ طراحی: هرگز استثنا پرت نکن
 *
 * این سرویس بعد از آپلود اجرا می‌شود. اگر پردازندهٔ تصویر نبود، فایل خراب
 * بود، یا نوشتن روی دیسک شکست، باید **سکوت کند** و آپلود اصلی دست‌نخورده
 * بماند. پس هیچ متدی استثنا پرت نمی‌کند و خروجیِ `null` یعنی «نسخه‌ای ساخته
 * نشد» — که فرانت دقیقاً مثل امروز به فایل اصلی fallback می‌کند.
 *
 * ## چیدمان ذخیره‌سازی
 *
 * برای مسیر `dir/name.ext` نسخه‌ها با الگوی `dir/name-{width}.{ext}` نوشته
 * می‌شوند؛ مثلاً `media/shared/x.jpg` ⇒ `media/shared/x-640.webp`. فراداده در
 * ستون JSONِ `media.variants` ذخیره می‌شود تا `PageController` بتواند نشانی
 * عمومی هر نسخه را با همان دیسک بسازد.
 */
final class ImageOptimizer
{
    /** عرض‌های واکنش‌گرا (پیکسل) — قرارداد ثابتِ تسک. */
    public const WIDTHS = [320, 640, 960, 1280];

    /** قالبی که هرگز درست rastar نمی‌شود: SVG برداری است و GIF می‌تواند متحرک باشد. */
    private const SKIP_MIMES = ['image/svg+xml', 'image/gif'];

    private const EXT_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];

    private ImageProcessor $processor;

    public function __construct(?ImageProcessor $processor = null)
    {
        $this->processor = $processor ?? self::detectProcessor();
    }

    /** بهترین موتورِ موجود؛ نبودشان ⇒ موتورِ بی‌اثر (fail-soft). */
    public static function detectProcessor(): ImageProcessor
    {
        foreach ([new GdImageProcessor(), new ImagickImageProcessor()] as $candidate) {
            if ($candidate->available()) {
                return $candidate;
            }
        }

        return new NullImageProcessor();
    }

    public function processorName(): string
    {
        return $this->processor->name();
    }

    public function isSupported(): bool
    {
        return $this->processor->available();
    }

    /**
     * ساخت و ذخیرهٔ نسخه‌ها برای یک ردیف مدیا.
     *
     * @return array<string, mixed>|null فرادادهٔ ذخیره‌شده، یا `null` در حالت fail-soft.
     */
    public function optimize(Media $media): ?array
    {
        try {
            if (! $this->processor->available()) {
                return null;
            }

            $path = (string) $media->path;
            $mime = $this->mimeFor($media, $path);

            if ($path === '' || $mime === null || in_array($mime, self::SKIP_MIMES, true)) {
                return null;
            }

            $disk = $this->diskFor($media);

            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = (string) $disk->get($path);
            $image = $this->processor->load($bytes);

            if ($image === null) {
                return null;
            }

            $originalWidth = $this->processor->width($image);
            $items = $this->build($disk, $path, $mime, $image, $originalWidth);
            $this->processor->release($image);

            if ($items === []) {
                return null;
            }

            $meta = [
                'processor' => $this->processor->name(),
                'widths' => array_map(static fn (array $i): int => $i['width'], $items),
                'items' => $items,
            ];

            $media->forceFill(['variants' => $meta])->save();

            return $meta;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{width: int, sources: array<string, string>}>
     */
    private function build(Filesystem $disk, string $path, string $mime, mixed $image, int $originalWidth): array
    {
        if ($originalWidth <= 0) {
            return [];
        }

        $widths = array_values(array_filter(
            self::WIDTHS,
            static fn (int $w): bool => $w <= $originalWidth,
        ));

        if ($widths === []) {
            $widths = [$originalWidth];
        }

        $targets = $this->targets($mime);
        if ($targets === []) {
            return [];
        }

        $dir = dirname($path);
        $dir = $dir === '.' || $dir === '' ? '' : rtrim(str_replace('\\', '/', $dir), '/').'/';
        $base = pathinfo($path, PATHINFO_FILENAME);

        $items = [];

        foreach ($widths as $width) {
            $sources = [];

            foreach ($targets as $targetMime => $ext) {
                $encoded = $this->processor->encode($image, $width, $targetMime);

                if ($encoded === null || $encoded === '') {
                    continue;
                }

                $variantPath = $dir.$base.'-'.$width.'.'.$ext;

                try {
                    $disk->put($variantPath, $encoded);
                    $sources[$targetMime] = $variantPath;
                } catch (\Throwable) {
                    // یک قالبِ شکست‌خورده نباید بقیه را بیندازد.
                }
            }

            if ($sources !== []) {
                $items[] = ['width' => $width, 'sources' => $sources];
            }
        }

        return $items;
    }

    /**
     * قالب‌های مقصد: قالبِ اصلی (اگر موتور پشتیبانی کند) + WebP + AVIF.
     *
     * @return array<string, string>
     */
    private function targets(string $sourceMime): array
    {
        $available = $this->processor->formats();
        $targets = [];

        if (isset($available[$sourceMime])) {
            $targets[$sourceMime] = $available[$sourceMime];
        }

        foreach (['image/webp', 'image/avif'] as $modern) {
            if (isset($available[$modern]) && ! isset($targets[$modern])) {
                $targets[$modern] = $available[$modern];
            }
        }

        return $targets;
    }

    private function diskFor(Media $media): Filesystem
    {
        $disk = (string) ($media->disk ?: config('filesystems.default', 'local'));

        return \Illuminate\Support\Facades\Storage::disk($disk);
    }

    private function mimeFor(Media $media, string $path): ?string
    {
        $mime = is_string($media->mime) && $media->mime !== '' ? $media->mime : null;

        if ($mime !== null && str_starts_with($mime, 'image/')) {
            return $mime;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return self::EXT_MIME[$ext] ?? null;
    }
}
