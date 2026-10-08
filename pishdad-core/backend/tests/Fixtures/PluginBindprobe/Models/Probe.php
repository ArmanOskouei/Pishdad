<?php

namespace Pishdad\Plugins\Bindprobe\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * K7.8 — مدلِ آزمایشی، فقط برای نگهبانِ route model binding.
 *
 * در `tests/Fixtures` است نه در `app/` و نه در بستهٔ واقعی: اگر داخل هسته بود،
 * یک کلاسِ `App\` عملاً ثابت می‌کرد guardِ namespace بی‌اثر است — همان دلیلی که
 * نمونهٔ K7.13 را در fixture گذاشتیم.
 *
 * جدولش `plugin_bindprobe_models` است که هیچ‌وقت ساخته نمی‌شود؛ تست فقط
 * `coerce()` را می‌سنجد و به کوئری نمی‌رسد.
 */
class Probe extends Model
{
    protected $table = 'plugin_bindprobe_models';
}
