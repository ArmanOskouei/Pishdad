<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Layouts\LinkItems;
use App\Services\Settings\CachedSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SettingsMigrateToGlobal extends Command
{
    protected $signature = 'settings:migrate-to-global {--dry-run}';

    protected $description = 'ادغام تنظیمات per-user قدیمی در کلید سراسری نصب (global)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $groups = ['site', 'socials', 'layout', 'page_blocks'];

        $rows = Setting::query()
            ->whereIn('group', $groups)
            ->where('key', 'like', 'user_%')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('هیچ تنظیم per-user برای ادغام یافت نشد.');
        }

        // گام ۱: پاک‌سازی انکدینگ روی سطرهای global موجود (اول، تا مبنای merge سالم باشد).
        foreach (Setting::query()->whereIn('group', $groups)->where('key', 'like', 'global%')->get() as $row) {
            $before = json_encode($row->value, JSON_UNESCAPED_UNICODE);
            $scrubbed = $this->scrub($row->value);
            if ($before === json_encode($scrubbed, JSON_UNESCAPED_UNICODE)) {
                continue;
            }
            if ($dry) {
                $this->line("[dry-run] scrub {$row->group}/{$row->key}");

                continue;
            }
            Setting::set($row->group, $row->key, $scrubbed);
            $this->line("پاک‌سازی انکدینگ: {$row->group}/{$row->key}");
        }

        // گام ۲: ادغام per-user قدیمی روی global پاک‌شده.
        foreach ($rows as $row) {
            $globalKey = $this->globalKey($row->key);
            $existing = Setting::get($row->group, $globalKey);
            $merged = $this->merge(is_array($existing) ? $existing : [], (array) $row->value);

            if ($dry) {
                $this->line("[dry-run] {$row->group}/{$row->key} → {$row->group}/{$globalKey}");

                continue;
            }

            DB::transaction(function () use ($row, $globalKey, $merged) {
                Setting::set($row->group, $globalKey, $merged);
            });

            $this->line("مهاجرت: {$row->group}/{$row->key} → {$row->group}/{$globalKey}");
        }

        if (! $dry) {
            CachedSettings::flushTag();
            $this->info('کش تنظیمات پاک شد. سطرهای قدیمی user_* دست‌نخورده باقی ماندند.');
        }

        return self::SUCCESS;
    }

    private function globalKey(string $key): string
    {
        $suffix = substr($key, strlen('user_'));
        $suffix = explode(':', $suffix, 2)[1] ?? '';

        return $suffix === '' ? 'global' : 'global:'.$suffix;
    }

    /**
     * پاک‌سازی رشته‌های دوبار-انکدشده در یک درخت ساختاریافته
     * (layout widgets: links labels, placeholder, heading, text …).
     */
    private function scrub(mixed $node): mixed
    {
        if (is_string($node)) {
            return LinkItems::cleanText($node) ?? $node;
        }
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = $this->scrub($v);
            }

            return $out;
        }

        return $node;
    }

    private function merge(array $base, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && $base[$k] !== [] && $v !== []) {
                $base[$k] = $this->merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
