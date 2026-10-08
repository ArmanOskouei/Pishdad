<?php

namespace App\Services\Media;

/** WF-C4 — موتورِ غایب: هیچ کاری نمی‌کند تا آپلود fail-soft بماند. */
final class NullImageProcessor implements ImageProcessor
{
    public function name(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function formats(): array
    {
        return [];
    }

    public function load(string $bytes): mixed
    {
        return null;
    }

    public function width(mixed $image): int
    {
        return 0;
    }

    public function encode(mixed $image, int $width, string $mime): ?string
    {
        return null;
    }

    public function release(mixed $image): void
    {
        // هیچ.
    }
}
