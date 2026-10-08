<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * WF-M21 — محتوای نمونهٔ یک‌کلیکی برای نصبِ تازه.
 *
 * این seeder عمداً منطقِ موجود را **دوباره به کار می‌گیرد**، نه اینکه کپی کند:
 * `DefaultPagesSeeder` (خانه/درباره ما/تماس با ما، draft و idempotent) و
 * `FormSeeder` (فرم تماس) را صدا می‌زند و روی صفحهٔ خانه یک گالریِ
 * placeholder می‌گذارد تا «صفحهٔ دمو + تصاویر نمونه» واقعاً دیده شود.
 *
 * قراردادها با بقیهٔ seederها یکی است:
 * - idempotent: اجرای دوباره نه صفحه/فرم تکراری می‌سازد نه فایل/media.
 * - محتوای منتشرشدهٔ مدیر هرگز بازنویسی نمی‌شود.
 * - مسیرِ تصویر، هشِ محتوایش است تا کشِ مرورگر نسخهٔ کهنه را نگه ندارد.
 */
class SampleContentSeeder extends Seeder
{
    /**
     * تصاویر placeholder: نام فایل ⇒ رنگ پس‌زمینه.
     *
     * @var array<string, string>
     */
    private const PLACEHOLDERS = [
        'sample-welcome.svg' => '#4f46e5',
        'sample-features.svg' => '#0ea5e9',
    ];

    public function run(): void
    {
        // بازاستفادهٔ مستقیم از منطق صفحات و فرم — بدون تکرار.
        $this->call([DefaultPagesSeeder::class, FormSeeder::class]);

        $ownerId = User::query()->orderBy('id')->value('id');

        $this->attachSampleGallery(Page::query()->where('slug', 'home')->first(), $ownerId);

        $this->command?->info('Sample content ready: home/about/contact + placeholder gallery.');
    }

    /** گالریِ نمونه را فقط روی صفحهٔ خانهٔ **پیش‌نویس** می‌گذارد (idempotent). */
    private function attachSampleGallery(?Page $home, ?int $ownerId): void
    {
        if ($home === null || $home->isPublished()) {
            return;
        }

        $ids = $this->seedPlaceholders($ownerId);
        if ($ids === []) {
            return;
        }

        $blocks = $home->blocks ?? [];

        // اجرای پیشین گالری گذاشته ⇒ دوباره اضافه نکن.
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'gallery') {
                return;
            }
        }

        $blocks[] = [
            'type' => 'gallery',
            'data' => [
                'media_ids' => $ids,
                'columns' => 2,
                'caption' => 'تصاویر نمونه',
            ],
        ];

        $home->forceFill(['blocks' => $blocks])->save();
    }

    /**
     * @return list<int>
     */
    private function seedPlaceholders(?int $ownerId): array
    {
        $ids = [];

        foreach (self::PLACEHOLDERS as $filename => $background) {
            $bytes = $this->placeholderSvg($background);
            $path = 'media/sample/'.md5($bytes).'.svg';

            $media = Media::query()
                ->where('disk', 'public')
                ->where('path', $path)
                ->first();

            if ($media === null) {
                Storage::disk('public')->put($path, $bytes);

                $media = new Media([
                    'user_id' => $ownerId,
                    'disk' => 'public',
                    'path' => $path,
                    'original_name' => $filename,
                    'mime' => 'image/svg+xml',
                    'size' => strlen($bytes),
                    'alt' => 'تصویر نمونه',
                ]);
                $media->save();
            }

            $ids[] = (int) $media->getKey();
        }

        return $ids;
    }

    /** placeholder خنثی — بدون متن (متنِ SVG فارسی شکل‌دهیِ درست نمی‌گیرد). */
    private function placeholderSvg(string $background): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500">'
            .'<rect width="800" height="500" fill="'.$background.'"/>'
            .'<rect x="40" y="40" width="720" height="420" fill="none" stroke="#ffffff" stroke-opacity="0.4" stroke-width="3" stroke-dasharray="12 10"/>'
            .'<circle cx="400" cy="250" r="64" fill="#ffffff" fill-opacity="0.18"/>'
            .'</svg>';
    }
}
