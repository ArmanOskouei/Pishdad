<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * صفحات پیش‌فرض نصب آسان (چندنصبی خنثی): خانه / درباره ما / تماس با ما.
 *
 * - همه draft (منتشر نمی‌شوند تا مدیر بازبینی کند) و متن‌ها عمومی و خنثی‌اند.
 * - Idempotent: کلید اسلاگ سراسری یکتاست؛ اجرای دوباره با --fresh رکورد
 *   تکراری نمی‌سازد. صفحه منتشرشده (ویرایش‌شده توسط مدیر) هرگز بازنویسی
 *   نمی‌شود؛ فقط draftها نمونه‌محتوا را تازه می‌کنند.
 * - مالک: قدیمی‌ترین کاربر (معمولاً ادمین نصب)؛ اگر کاربری نیست user_id تهی.
 */
class DefaultPagesSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = User::query()->orderBy('id')->value('id');

        foreach ($this->pages() as $slug => $attrs) {
            $existing = Page::query()->where('slug', $slug)->first();

            if ($existing && $existing->status === Page::STATUS_PUBLISHED) {
                continue;
            }

            if ($existing) {
                $existing->forceFill([
                    'title' => $attrs['title'],
                    'blocks' => $attrs['blocks'],
                    'meta' => $attrs['meta'],
                ])->save();

                continue;
            }

            Page::query()->create([
                'user_id' => $ownerId,
                'title' => $attrs['title'],
                'slug' => $slug,
                'status' => Page::STATUS_DRAFT,
                'blocks' => $attrs['blocks'],
                'meta' => $attrs['meta'],
            ]);
        }

        $this->command->info('Default pages ready: home / about / contact (draft).');
    }

    /**
     * @return array<string, array{title: string, blocks: array, meta: array}>
     */
    private function pages(): array
    {
        return [
            'home' => [
                'title' => 'خانه',
                'blocks' => [
                    [
                        'type' => 'hero',
                        'data' => [
                            'title' => 'به وب‌سایت ما خوش آمدید',
                            'subtitle' => 'این یک صفحه نمونه است؛ متن‌ها را از بخش صفحات ویرایش کنید.',
                            'align' => 'center',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'data' => [
                            'body' => '<p>این وب‌سایت به‌تازگی راه‌اندازی شده است. از پنل مدیریت، برگه‌ها، نوشته‌ها و تنظیمات سایت را به‌دلخواه تغییر دهید.</p>',
                        ],
                    ],
                    [
                        'type' => 'cta',
                        'data' => ['label' => 'تماس با ما', 'href' => '/contact', 'style' => 'primary'],
                    ],
                ],
                'meta' => ['title' => 'خانه'],
            ],
            'about' => [
                'title' => 'درباره ما',
                'blocks' => [
                    [
                        'type' => 'text',
                        'data' => [
                            'body' => '<p>این یک متن نمونه درباره مجموعه است. در این بخش می‌توانید معرفی کوتاه، اهداف و راه‌های ارتباطی خود را بنویسید.</p>',
                        ],
                    ],
                ],
                'meta' => ['title' => 'درباره ما'],
            ],
            'contact' => [
                'title' => 'تماس با ما',
                'blocks' => [
                    [
                        'type' => 'text',
                        'data' => [
                            'body' => '<p>برای ارتباط با ما می‌توانید از فرم زیر استفاده کنید؛ پیام شما در بخش تیکت‌های پنل مدیریت ثبت می‌شود.</p>',
                        ],
                    ],
                    [
                        'type' => 'contact-form',
                        'data' => ['title' => 'فرم تماس'],
                    ],
                ],
                'meta' => ['title' => 'تماس با ما'],
            ],
        ];
    }
}
