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
    public static function for(string $disk, string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        if ($disk === 's3') {
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
