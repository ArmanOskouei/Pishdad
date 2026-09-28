<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * K7.8 — ابزارهای مشترک تست‌های افزونهٔ مرکزی.
 *
 * ## چرا trait
 *
 * هر تستِ مرکزی به سه چیز نیاز دارد: افزونه در جدول باشد، جدول route پاک
 * شود، و مسیر درست از مانیفست خوانده شود. این‌ها قبلاً در هر فایل تکرار
 * می‌شدند — ۲۲ نسخهٔ یکسان که هیچ‌کدام دیده نمی‌شوند.
 *
 * ## چرا trait هیچ `setUp` ندارد
 *
 * قلاب در `Tests\TestCase::setUp()` است و **شرطی** اجرا می‌شود: فقط اگر متنِ
 * فایل تست رشتهٔ `/api/v1/p/central` را داشته باشد. اگر اینجا `setUp` می‌بود،
 * trait با `setUp` خودِ تست تداخل می‌کرد (`Cannot redeclare setUp`) — همان
 * خطایی که `PluginsTest` گرفت.
 *
 * @see TestCase::setUp()
 */
trait ServesCentralPlugin
{
    private ?Plugin $centralPluginRow = null;

    protected function serveCentralPlugin(): Plugin
    {
        $plugin = Plugin::query()->firstOrCreate(
            ['slug' => 'central'],
            [
                'name' => 'مرکز',
                'version' => '1.0.0',
                'active' => true,
                'system' => true,
                'review_status' => Plugin::REVIEW_APPROVED,
                'source' => Plugin::SOURCE_CORE,
                'path' => 'plugins/central',
                'manifest' => $this->centralManifest(),
            ],
        );

        $this->centralPluginRow = $plugin;

        PluginRouteTable::flush();

        return $plugin;
    }

    /**
     * مانیفست از **بستهٔ واقعی روی دیسک** خوانده می‌شود، نه از آرایهٔ دستی.
     *
     * آرایهٔ دستی یعنی تست‌ها به یک نسخهٔ کهنهٔ ۶۳ مسیر می‌چسبند و تغییرِ
     * manifest هیچ تستی را نمی‌شکند — همان «نگهبانی که چیزی را تضمین نمی‌کند»
     * که K7.8 در `CentralBoundaryTest` پیدا کرد.
     */
    private function centralManifest(): array
    {
        $path = base_path('plugins/central/manifest.json');

        if (! is_file($path)) {
            throw new \RuntimeException("بستهٔ مرکزی روی دیسک نیست: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('مانیفست بستهٔ مرکزی JSON معتبر نیست.');
        }

        return $decoded;
    }

    /**
     * مسیر API مرکزی، از **مانیفست**.
     *
     * اگر prefix عوض شود ولی تست‌ها رشتهٔ ثابت داشته باشند، همه سبز می‌مانند و
     * هیچ‌کدام مسیر واقعی را نمی‌زنند.
     */
    protected function centralUrl(string $path = ''): string
    {
        $prefix = Str::slug($this->centralManifest()['api']['prefix'] ?? 'central');
        $path = ltrim($path, '/');

        return '/api/v1/p/'.$prefix.($path === '' ? '' : '/'.$path);
    }
}
