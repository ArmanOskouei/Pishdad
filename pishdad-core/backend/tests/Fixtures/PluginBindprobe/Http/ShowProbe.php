<?php

namespace Pishdad\Plugins\Bindprobe\Http;

use App\Models\Plugin as CorePlugin;
use Pishdad\Plugins\Bindprobe\Models\Probe;

/**
 * K7.8 — هندلر آزمایشی، فقط برای نگهبانِ route model binding.
 *
 * typehint واقعی دارد (`Probe $probe`) چون یک `ReflectionNamedType` مصنوعی
 * در PHP 8.5 خطای «Failed to retrieve the reflection object» می‌دهد — یعنی
 * نوع قابل استفاده نیست و تست عملاً چیزی نمی‌سنجید.
 *
 * `core()` مدلِ **هسته** را typehint می‌کند تا نگهبانِ مرز هم typehint واقعی
 * داشته باشد، نه یک نوعِ ساختگی.
 */
class ShowProbe
{
    public function show(Probe $probe): int
    {
        return (int) $probe->getKey();
    }

    public function core(CorePlugin $plugin): int
    {
        return (int) $plugin->getKey();
    }

    public function raw(string $slug): string
    {
        return $slug;
    }
}
