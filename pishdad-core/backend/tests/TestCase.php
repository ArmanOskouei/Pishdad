<?php

namespace Tests;

use App\Models\Plugin;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // هر تست از **جدول خالی** شروع کند.
        //
        // چرا این پاک‌سازی لازم است: کشِ `plugin.routes` و `plugin.manifests`
        // بین تست‌ها زنده می‌ماند (خواندن از cache ارزان‌تر از DB است و در
        // محیط تست هم همین منطق اجرا می‌شود). پس تستی که بسته‌ای را فعال می‌کند،
        // ردیفش را در تستِ بعدی هم می‌بیند — و تستی که انتظار دارد جدول خالی
        // باشد، شکست می‌خورد.
        //
        // این یک نشتِ **بین تستی** است، نه نشتِ کد. دقیقاً همان چیزی که
        // `RefreshDatabase` برای جدول‌ها حل می‌کند و اینجا برای کش.
        PluginRouteTable::flush();
        ManifestRegistry::flushCache();

        // S-08 — نشتِ بین‌تستیِ نرخ‌گیر: کشِ `array` در کلِ پروسهٔ phpunit زنده
        // می‌ماند، پس شمارندهٔ throttle بین تست‌ها جمع می‌شود و تست‌های بعدیِ
        // یک مسیرِ محدودشده ۵۰۳ می‌گیرند (بدون اینکه درخواستی بیش از سقف زده
        // باشند). رفتارِ production دست‌نخورده می‌ماند: آنجا کشِ واقعی است و
        // `RateLimiter` عمداً فقط با کلیدِ صریح پاک می‌شود.
        Cache::flush();
    }

    /**
     * تست‌هایی که **فقط** کلید داشته باشد.
     *
     * با ده‌ها ردیف در جدول، «همه چیز پاک شد» و «فقط `demo` باقی ماند» دو
     * assertion متفاوت‌اند و این دومی قبلاً شکست می‌خورد.
     *
     * @return list<string>
     */
    protected function pluginSlugs(): array
    {
        return Plugin::query()
            ->orderBy('slug')
            ->pluck('slug')
            ->map(static fn (mixed $s): string => (string) $s)
            ->all();
    }
}
