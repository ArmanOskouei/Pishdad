<?php

namespace App\Services\Media;

/**
 * WF-C4 — موتور Imagick. اگر افزونهٔ imagick نبود، کاملاً بی‌اثر است.
 */
final class ImagickImageProcessor implements ImageProcessor
{
    public function name(): string
    {
        return 'imagick';
    }

    public function available(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function formats(): array
    {
        if (! $this->available()) {
            return [];
        }

        $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (\Imagick::queryFormats('WEBP') !== []) {
            $formats['image/webp'] = 'webp';
        }
        if (\Imagick::queryFormats('AVIF') !== []) {
            $formats['image/avif'] = 'avif';
        }
        if (\Imagick::queryFormats('GIF') !== []) {
            $formats['image/gif'] = 'gif';
        }

        return $formats;
    }

    public function load(string $bytes): mixed
    {
        if (! $this->available() || $bytes === '') {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);

            return $image;
        } catch (\Throwable) {
            return null;
        }
    }

    public function width(mixed $image): int
    {
        try {
            return (int) $image->getImageWidth();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function encode(mixed $image, int $width, string $mime): ?string
    {
        if (! $this->available() || $width <= 0 || ! array_key_exists($mime, $this->formats())) {
            return null;
        }

        try {
            $copy = clone $image;
            $copy->setIteratorIndex(0);
            $copy->setImageFormat(match ($mime) {
                'image/jpeg' => 'jpeg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                'image/gif' => 'gif',
                default => 'png',
            });
            $copy->resizeImage($width, 0, \Imagick::FILTER_LANCZOS, 1);
            $bytes = $copy->getImagesBlob();
            $copy->clear();

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function release(mixed $image): void
    {
        try {
            if ($image instanceof \Imagick) {
                $image->clear();
            }
        } catch (\Throwable) {
            // بی‌صدا — آزادسازی هرگز نباید چیزی را بیندازد.
        }
    }
}
