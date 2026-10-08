<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Media\ImageOptimizer;
use Illuminate\Console\Command;

/**
 * WF-C4 — تولید نسخه‌های واکنش‌گرا برای محتوای موجود/seed.
 *
 * روی نصبِ بدون پردازندهٔ تصویر (GD/Imagick) این command خطا نمی‌دهد؛ فقط
 * هشدار می‌دهد و هیچ تغییری نمی‌زند — چون مسیر آپلود هم fail-soft است.
 */
class MediaOptimize extends Command
{
    protected $signature = 'media:optimize
        {--id= : فقط یک شناسهٔ مدیا}
        {--limit=200 : حداکثر تعداد ردیف}
        {--force : بازتولید حتی اگر `variants` وجود دارد}';

    protected $description = 'ساخت نسخه‌های WebP/AVIF و چند-عرض برای تصاویرِ کتابخانه';

    public function handle(ImageOptimizer $optimizer): int
    {
        if (! $optimizer->isSupported()) {
            $this->warn('پردازندهٔ تصویر (GD/Imagick) در دسترس نیست — چیزی تغییر نکرد.');

            return self::SUCCESS;
        }

        $query = Media::query()->whereNotNull('path')->orderBy('id');

        $id = $this->option('id');
        if ($id !== null && $id !== '') {
            $query->where('id', (int) $id);
        } elseif (! $this->option('force')) {
            $query->whereNull('variants');
        }

        $limit = max(1, (int) $this->option('limit'));
        $rows = $query->limit($limit)->get();

        $done = 0;
        $skipped = 0;

        foreach ($rows as $media) {
            $optimizer->optimize($media) !== null ? $done++ : $skipped++;
        }

        $this->info(sprintf(
            'پردازش‌شده: %d · بدون تغییر (fail-soft): %d · موتور: %s',
            $done,
            $skipped,
            $optimizer->processorName(),
        ));

        return self::SUCCESS;
    }
}
