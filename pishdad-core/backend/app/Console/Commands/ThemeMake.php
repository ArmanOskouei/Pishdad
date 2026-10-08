<?php

namespace App\Console\Commands;

use App\Services\Plugins\DevScaffoldRegistry;
use App\Services\Plugins\PluginPackageContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * ECO4 — `php artisan pishdad-theme:make {slug}` → اسکلتِ قالبِ کد‌محور.
 *
 * ## چرا generator و نه «کپی از روی نمونه»
 *
 * قراردادِ قالب (`ThemeManifest` در `frontend/src/themes/theme-manifest.ts`)
 * را نمی‌توان خواند؛ باید اجرا شود. این فرمان کمینه‌ای می‌سازد که **همین حالا**
 * با همان قرارداد می‌خواند: `theme.json` با کلیدهای لازم و یک `index.tsx` که
 * علیه `ThemeProps` کامپایل می‌شود. اگر روزی قرارداد عوض شود، اینجا قرمز
 * می‌شود، نه اینکه قالبِ ناسازگار بی‌صدا ساخته شود.
 *
 * ## قالب = کد، نه ZIP
 *
 * برخلاف افزونه، قالب به‌صورت **سورس** در `frontend/src/themes/{slug}/` می‌نشیند
 * (تصمیم Q5). نصب/امضای ZIP ندارد؛ صاحب سایت آن را کامپایل می‌کند. این فرمان
 * فقط اسکلت را می‌کارد و ثبتِ رجیستری را به توسعه‌دهنده (و `--dev`) می‌سپارد.
 *
 * ## fail-closed
 *
 * اگر پوشه از قبل باشد، بدون `--force` بازنویسی نمی‌شود. `--dev` هم بدون
 * حالت توسعه‌دهندهٔ فعال رد می‌شود و **قبل** از نوشتن بررسی می‌شود، تا اسکلتِ
 * نیمه‌ثبت‌شده روی دیسک نماند.
 */
class ThemeMake extends Command
{
    protected $signature = 'pishdad-theme:make
        {slug : اسلاگ قالب (a-z0-9 و نقطه/خط‌تیره، ۲ تا ۴۰ نویسه)}
        {--name= : نام نمایشی فارسی}
        {--description= : توضیح کوتاه}
        {--path= : مسیر خروجی (پیش‌فرض: pishdad-core/frontend/src/themes)}
        {--force : اگر پوشه هست، بازنویسی شود}
        {--dev : ثبت در دفترِ توسعهٔ محلی (نیاز به حالت توسعه‌دهندهٔ فعال)}';

    protected $description = 'ساخت اسکلتِ قالبِ کد‌محور (theme.json + index.tsx) هم‌خوان با قراردادِ ThemeManifest';

    /** کلیدهای الزامیِ `theme.json` طبق قرارداد فرانت. */
    private const REQUIRED_KEYS = ['slug', 'name', 'description', 'version', 'modes', 'slots', 'tokens'];

    private const MODES = ['light', 'dark', 'system'];

    private const SLOTS = ['header', 'footer', 'main', 'left', 'right', 'banner'];

    public function handle(DevScaffoldRegistry $registry): int
    {
        $slug = (string) $this->argument('slug');
        $version = '1.0.0';

        if (preg_match(PluginPackageContract::SLUG_PATTERN, $slug) !== 1) {
            $this->error('اسلاگ «'.$slug.'» معتبر نیست. الگو: ^[a-z0-9][a-z0-9._-]{1,39}$ — با عدد/حرف کوچک انگلیسی شروع شود و بین ۲ تا ۴۰ نویسه باشد.');

            return self::FAILURE;
        }

        // ECO4 — fail-closed: `--dev` بدون حالت توسعه‌دهندهٔ فعال رد می‌شود،
        // **قبل** از نوشتن هر فایلی.
        if ($this->option('dev') && ! $registry->active()) {
            $this->error('حالت توسعه‌دهنده فعال نیست؛ --dev رد شد. اول از پنل حالت توسعه را باز کنید.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('path') ?: $this->defaultRoot()), '/\\');
        $tree = $root.DIRECTORY_SEPARATOR.$slug;

        if (File::exists($tree) && ! $this->option('force')) {
            $this->error('«'.$tree.'» از قبل وجود دارد. اگر مطمئنید، --force بدهید.');

            return self::FAILURE;
        }

        $manifest = $this->manifest($slug, $version);

        $missing = array_values(array_diff(self::REQUIRED_KEYS, array_keys($manifest)));
        if ($missing !== []) {
            $this->error('مانیفستِ تولیدشده کلیدهای الزامی را ندارد: '.implode(', ', $missing));

            return self::FAILURE;
        }

        try {
            File::deleteDirectory($tree);
            $this->put($tree, 'theme.json', (string) json_encode(
                $manifest,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
            $this->put($tree, 'index.tsx', $this->component($slug));
        } catch (Throwable $e) {
            File::deleteDirectory($tree);
            $this->error('ساخت اسکلت ناموفق بود: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('اسکلتِ قالب ساخته شد:');
        $this->line('  سورس: '.$tree);
        $this->line('  مانیفست: '.$tree.DIRECTORY_SEPARATOR.'theme.json');

        if ($this->option('dev')) {
            $registry->register(DevScaffoldRegistry::KIND_THEME, $slug, $tree);
            $this->warn('در دفترِ توسعهٔ محلی ثبت شد (فقط محلی؛ روی نصب واقعی بی‌اثر است).');
        }

        $this->line('یادآوری: برای فعال‌شدن، قالب را در frontend/src/themes/manifests.ts و registry.tsx ثبت کنید.');

        return self::SUCCESS;
    }

    /**
     * ریشهٔ پیش‌فرض = پوشهٔ قالب‌های فرانت، کنارِ همین مخزن.
     *
     * `base_path()` روی `pishdad-core/backend` است، پس یک پله بالا و بعد `frontend`.
     * اگر فرانت همسایه نیست (کانتینرِ فقط-بک‌اند)، `config('theme.scaffold_path')`
     * صریح برنده است تا مسیرِ اشتباه ساخته نشود.
     */
    private function defaultRoot(): string
    {
        $configured = trim((string) config('theme.scaffold_path', ''));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        return dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'
            .DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'themes';
    }

    /** @return array<string, mixed> */
    private function manifest(string $slug, string $version): array
    {
        $class = Str::studly($slug);

        return [
            'slug' => $slug,
            'name' => (string) ($this->option('name') ?: ('قالب '.$class)),
            'description' => (string) ($this->option('description') ?: 'اسکلت خالی که با pishdad-theme:make ساخته شده.'),
            'version' => $version,
            'modes' => self::MODES,
            'slots' => self::SLOTS,
            'tokens' => [
                'radius-sm' => '2px',
                'radius-md' => '4px',
                'radius-lg' => '6px',
                'radius-xl' => '8px',
                'fs-body' => '15px',
                'fs-small' => '13px',
                'fs-h' => '19px',
                'density' => '1',
            ],
        ];
    }

    /**
     * کامپوننتِ شروع. علیه `ThemeProps` و `ThemeShell`ی هسته کامپایل می‌شود،
     * پس توسعه‌دهنده از لحظهٔ صفر یک تایپ‌چکِ سبز دارد، نه اسکلتی که اول باید
     * درستش کند.
     */
    private function component(string $slug): string
    {
        $class = Str::studly($slug);
        $variant = trim((string) preg_replace('/[^a-z0-9-]+/', '-', $slug), '-') ?: 'theme';

        return <<<TSX
        import { ThemeShell } from "../shared";
        import type { ThemeProps } from "../types";

        export function {$class}Theme(props: ThemeProps) {
          return <ThemeShell {...props} variant="{$variant}" layout={{ sidebarPlacement: "stacked" }} />;
        }

        export default {$class}Theme;

        TSX;
    }

    private function put(string $tree, string $relative, string $contents): void
    {
        $path = $tree.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}
