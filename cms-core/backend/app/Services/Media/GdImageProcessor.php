<?php

namespace App\Services\Media;

/**
 * WF-C4 — موتور GD. کدگذاری WebP/AVIF فقط اگر تابعِ مربوطه کامپایل شده باشد.
 */
final class GdImageProcessor implements ImageProcessor
{
    public function name(): string
    {
        return 'gd';
    }

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public function formats(): array
    {
        if (! $this->available()) {
            return [];
        }

        $formats = [];
        if (function_exists('imagejpeg')) {
            $formats['image/jpeg'] = 'jpg';
        }
        if (function_exists('imagepng')) {
            $formats['image/png'] = 'png';
        }
        if (function_exists('imagewebp')) {
            $formats['image/webp'] = 'webp';
        }
        if (function_exists('imageavif')) {
            $formats['image/avif'] = 'avif';
        }
        if (function_exists('imagegif')) {
            $formats['image/gif'] = 'gif';
        }

        return $formats;
    }

    public function load(string $bytes): mixed
    {
        if (! $this->available() || $bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        if (function_exists('imagealphablending')) {
            @imagealphablending($image, true);
        }
        if (function_exists('imagesavealpha')) {
            @imagesavealpha($image, true);
        }

        return $image;
    }

    public function width(mixed $image): int
    {
        return (int) @imagesx($image);
    }

    public function encode(mixed $image, int $width, string $mime): ?string
    {
        if (! $this->available() || $width <= 0) {
            return null;
        }
        if (! array_key_exists($mime, $this->formats())) {
            return null;
        }

        $scaled = @imagescale($image, $width);
        if ($scaled === false) {
            return null;
        }

        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => @imagejpeg($scaled, null, 82),
            'image/png' => @imagepng($scaled, null, 8),
            'image/webp' => @imagewebp($scaled, null, 82),
            'image/avif' => @imageavif($scaled, null, 60),
            'image/gif' => @imagegif($scaled),
            default => false,
        };
        $bytes = (string) ob_get_clean();

        @imagedestroy($scaled);

        return $ok && $bytes !== '' ? $bytes : null;
    }

    public function release(mixed $image): void
    {
        if (is_object($image) || is_resource($image)) {
            @imagedestroy($image);
        }
    }
}
