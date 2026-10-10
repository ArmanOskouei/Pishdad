<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\Media\ImageOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * P-05 — تولیدِ نسخه‌های واکنش‌گرای تصویر، بیرون از مسیرِ آپلود.
 *
 * پیش از این `MediaController` مستقیماً `ImageOptimizer::optimize()` را
 * می‌زد؛ GD/Imagick روی یک JPEG بزرگ ثانیه‌های CPU+Ramo داخل درخواست.
 *
 * قراردادِ fail-soft حفظ می‌شود: مدیا از قبل ذخیره شده و فایلِ اصلی سالم
 * است؛ این Job فقط `variants` را می‌سازد. اگر شکست بخورد، فرانت به فایلِ
 * اصلی برمی‌گردد و چیزی برای نمایش در UI نیست — فقط `failed_jobs`.
 */
class OptimizeMediaVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $mediaId,
        /** مسیرهای variants قدیمی — دیگر در DB ارجاع نمی‌شوند. */
        public array $stalePaths = [],
        public ?string $disk = null,
    ) {}

    public function handle(ImageOptimizer $optimizer): void
    {
        // مدیا تازه خوانده می‌شود، نه سریالایزه: نسخهٔ زمانِ dispatch ممکن است
        // کهنه باشد (مثلاً حذف شده).
        $media = Media::query()->find($this->mediaId);
        if ($media === null) {
            return;
        }

        $optimizer->optimize($media);

        // مسیرهای کهنه **بی‌تزلر** حذف می‌شوند: dispatchکننده آن‌ها را دقیقاً
        // چون دیگر در DB ارجاع نمی‌شوند فرستاده. موفقیتِ موتور به این مربوط
        // نیست — واریانتِ یتیم روی دیسک فضای تلف‌شده است.
        foreach ($this->stalePaths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            try {
                Storage::disk($this->disk ?: $media->disk)->delete($path);
            } catch (Throwable) {
                // حذفِ یتیم هرگز ضرر ندارد؛ گذر.
            }
        }
    }
}
