<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\File;

/**
 * ECO4 — دفترِ اسکلت‌های محلی (قالب/افزونه).
 *
 * ## چرا لازم است
 *
 * ابزار scaffold بدون حالت توسعه‌دهنده ناقص است: توسعه‌دهنده یک قالب/افزونه
 * می‌سازد ولی برای «دیدنش» باید نصب واقعی و امضا انجام دهد. این دفتر همان
 * پلِ محلی است — `pishdad-theme:make --dev` و `pishdad-plugin:make --dev`
 * اسکلت را اینجا ثبت می‌کنند و بقیهٔ سیستم فقط وقتی حالت توسعه‌دهنده فعال
 * است آن را می‌بیند.
 *
 * ## fail-closed
 *
 * **پیش‌فرض بسته است.** نوشتن در دفتر بدون حالت توسعه‌دهندهٔ فعال
 * `RuntimeException` می‌دهد، و خواندن (`all()`) در همان حالت **تهی** برمی‌گرداند
 * — نه «فهرست را می‌خواند ولی نادیده می‌گیرد». یعنی حتی اگر فایل روی دیسک
 * بماند (کاربر dev mode را خاموش کرد)، هیچ مصرف‌کننده‌ای آن را نمی‌بیند.
 * این دقیقاً رفتار `DevMode` است: یک پرچمِ مرورگر نیست، وضعیتِ سمت سرور است.
 */
class DevScaffoldRegistry
{
    public const KIND_THEME = 'theme';

    public const KIND_PLUGIN = 'plugin';

    private const FILE = 'dev-scaffolds.json';

    public function __construct(private readonly DevMode $devMode) {}

    /** آیا حالت توسعه‌دهنده باز است؟ (دروازهٔ قبل از هر نوشتن/خواندن) */
    public function active(): bool
    {
        return $this->devMode->isActive();
    }

    /**
     * ثبتِ یک اسکلت. فقط با حالت توسعه‌دهندهٔ فعال.
     *
     * @throws \RuntimeException وقتی حالت توسعه‌دهنده باز نیست
     */
    public function register(string $kind, string $slug, string $path, array $meta = []): void
    {
        if (! $this->active()) {
            throw new \RuntimeException('حالت توسعه‌دهنده فعال نیست؛ ثبتِ اسکلتِ محلی رد شد.');
        }

        $all = $this->raw();
        $all[$kind][$slug] = [
            'path' => $path,
            'at' => now()->toIso8601String(),
        ] + $meta;

        $file = $this->path();
        File::ensureDirectoryExists(dirname($file));
        File::put($file, (string) json_encode(
            $all,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * فهرستِ اسکلت‌های ثبت‌شده — فقط وقتی حالت توسعه‌دهنده باز است.
     *
     * @return array<string, array<string, array{path: string, at: ?string}>>
     */
    public function all(): array
    {
        if (! $this->active()) {
            return [];
        }

        return $this->raw();
    }

    /** آیا این اسکلتِ مشخص ثبت شده و حالت توسعه‌دهنده باز است؟ */
    public function has(string $kind, string $slug): bool
    {
        $all = $this->all();

        return isset($all[$kind][$slug]);
    }

    /** پاک‌کردنِ کاملِ دفتر (برای تست/بازنشانی). */
    public function forget(): void
    {
        $file = $this->path();
        if (File::exists($file)) {
            File::delete($file);
        }
    }

    private function path(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.self::FILE);
    }

    /** @return array<string, array<string, array{path: string, at: ?string}>> */
    private function raw(): array
    {
        $file = $this->path();
        if (! File::exists($file)) {
            return [];
        }

        $decoded = json_decode((string) File::get($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
