<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WF-C2 — ریدایرکت ۳۰۱/۳۰۲ برای سایت عمومی.
 *
 * `from_path` همیشه با `/` شروع می‌شود (بدون دامنه)؛ `to_path` می‌تواند
 * مسیر داخلی (`/new`) یا نشانی مطلق (`https://…`) باشد. `$fillable` شامل
 * `hits` نیست تا از طریق توده‌ایِ فرم قابل دست‌کاری نباشد.
 */
class Redirect extends Model
{
    public const STATUS_MOVED_PERMANENTLY = 301;

    public const STATUS_FOUND = 302;

    public const STATUSES = [self::STATUS_MOVED_PERMANENTLY, self::STATUS_FOUND];

    protected $fillable = ['from_path', 'to_path', 'status_code', 'active'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'active' => 'boolean',
            'hits' => 'integer',
        ];
    }

    /** مسیر را برای مقایسه یکنواخت می‌کند: پیشوند اسلش + بدون اسلش پایانی. */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
