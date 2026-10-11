<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * نشانیِ عمومیِ یک فایلِ مدیا، بر پایهٔ **دیسکِ خودِ فایل**.
 *
 * ## باگی که این کلاس می‌بندد
 *
 * همهٔ مصرف‌کننده‌ها (کتابخانهٔ مدیا، تنظیمات، کروم، پروفایل، مدیران) نشانی را
 * با پایهٔ **S3/MinIO** می‌ساختند، حتی برای فایل‌هایی که روی دیسکِ `public`
 * (تصاویرِ محتوای سایت) ذخیره شده‌اند ⇒ ۴۰۴ و در مرورگر `ERR_BLOCKED_BY_ORB`
 * برای SVG. `PageController` از قبل درست بود؛ این کلاس همان منطق را یک‌جا و
 * قابل‌استفاده در همه‌جا می‌کند.
 */
final class MediaUrl
{
    /** @return string|null نشانیِ عمومی، یا null اگر دیسک ناشناخته/مسیر خالی باشد. */
    /**
     * @return string|null نشانی عمومی فایل، یا null اگر قابل‌سرو نیست/نامعتبر.
     *
     * @param int|null $id شناسهٔ ردیفِ media. برای دیسکِ s3 لازم است: نشانی
     * به روتِ عمومیِ بک‌اند (`/api/v1/site/media/{id}`) اشاره می‌کند تا سطل
     * خصوصی بماند (E90). بدونِ id به نشانیِ مستقیمِ قدیمیِ MinIO برمی‌گردد
     * (سازگاری عقب‌رو؛ همهٔ فراخوان‌های هسته id می‌دهند).
     */
    public static function for(string $disk, string $path, ?int $id = null): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if ($disk === 's3') {
            if ($id !== null && $id > 0) {
                $base = rtrim((string) config('app.url', ''), '/');

                return $base === '' ? null : $base.'/api/v1/site/media/'.$id;
            }

            $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

            return $base === '' ? null : $base.'/'.$path;
        }

        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }

        return $url === '' ? null : $url;
    }
}
