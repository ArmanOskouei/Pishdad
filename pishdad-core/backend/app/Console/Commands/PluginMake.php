<?php

namespace App\Console\Commands;

use App\Services\Plugins\DevScaffoldRegistry;
use App\Services\Plugins\PluginPackageContract;
use App\Services\Plugins\PluginPackageValidator;
use App\Services\Plugins\PluginSignatureVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * K1.4 — `php artisan pishdad-plugin:make {slug}` → اسکلتِ افزونه.
 *
 * ## چرا یک generator و نه «کپی از روی یک نمونه»؟
 *
 * راهنمای `docs/PLUGIN-GUIDE.md` یک **قرارداد** را توضیح می‌دهد، ولی قرارداد را
 * نمی‌توان خواند؛ باید اجرا شود. این فرمان کمینه‌ای می‌سازد که **همین حالا** از
 * `PluginPackageValidator` رد می‌شود — نه نسخه‌ای که «به‌نظر می‌رسد درست است».
 * اگر روزی قرارداد عوض شود، این فرمان همان لحظه قرمز می‌شود و آن‌وقت راهنما و
 * اسکلت با هم اصلاح می‌شوند، نه یکی بدون دیگری.
 *
 * ## fail-closed
 *
 * اگر اعتبارسنجی رد کند، ZIP ساخته‌شده **حذف** می‌شود و فرمان `FAILURE` می‌دهد.
 * یک ZIP نامعتبر که روی دیسک مانده باشد، دقیقاً همان چیزی است که بعداً در
 * `PluginController` با پیامی گمراه‌کننده رد می‌شود. درختِ سورس می‌ماند تا
 * توسعه‌دهنده اصلاحش کند.
 *
 * ## امضا
 *
 * اگر `PLUGIN_PUBLISHER_SECRET_KEY` تنظیم باشد، مانیفست امضا و بسته به‌صورت
 * **سخت‌گیرانه** اعتبارسنجی می‌شود. اگر نباشد (توسعه)، اعتبارسنجی با
 * `allowUnsigned` انجام و یک هشدار فارسی چاپ می‌شود — چون بستهٔ امضا‌نشده روی
 * سرور واقعی نصب نمی‌شود.
 */
class PluginMake extends Command
{
    protected $signature = 'pishdad-plugin:make
        {slug : اسلاگ افزونه (a-z0-9 و نقطه/خط‌تیره، ۲ تا ۴۰ نویسه)}
        {--name= : نام نمایشی فارسی}
        {--description= : توضیح کوتاه}
        {--author= : نام ناشر}
        {--path= : مسیر خروجی (پیش‌فرض: storage/app/plugin-skeletons)}
        {--force : اگر پوشه/فایل هست، بازنویسی شود}
        {--no-validate : اعتبارسنجی بسته انجام نشود (پیش‌فرض: انجام می‌شود)}
        {--dev : ثبت در دفترِ توسعهٔ محلی (نیاز به حالت توسعه‌دهندهٔ فعال)}';

    protected $description = 'ساخت اسکلتِ حداقلیِ افزونه (ZIP + سورس) که از اعتبارسنج بستهٔ هسته رد می‌شود';

    public function handle(
        PluginPackageValidator $validator,
        PluginSignatureVerifier $verifier,
        DevScaffoldRegistry $registry,
    ): int {
        $slug = (string) $this->argument('slug');
        $version = '1.0.0';

        if (preg_match(PluginPackageContract::SLUG_PATTERN, $slug) !== 1) {
            $this->error('اسلاگ «'.$slug.'» معتبر نیست. الگو: ^[a-z0-9][a-z0-9._-]{1,39}$ — با عدد/حرف کوچک انگلیسی شروع شود و بین ۲ تا ۴۰ نویسه باشد.');

            return self::FAILURE;
        }
        if (preg_match(PluginPackageContract::TABLE_SAFE_SLUG_PATTERN, $slug) !== 1) {
            $this->error('اسلاگ «'.$slug.'» برای افزونه‌ای که جدول دارد مجاز نیست: نامِ نهایی جدول از همین اسلاگ ساخته می‌شود و فقط a-z، 0-9 و زیرخل را می‌پذیرد. نقطه و خط‌تیره را حذف کنید.');

            return self::FAILURE;
        }

        // ECO4 — fail-closed: `--dev` بدون حالت توسعه‌دهندهٔ فعال رد می‌شود،
        // **قبل** از نوشتن هر فایلی، تا اسکلتِ نیمه‌ثبت‌شده روی دیسک نماند.
        if ($this->option('dev') && ! $registry->active()) {
            $this->error('حالت توسعه‌دهنده فعال نیست؛ --dev رد شد. اول از پنل حالت توسعه را باز کنید.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('path') ?: storage_path('app/plugin-skeletons')), '/\\');
        $tree = $root.DIRECTORY_SEPARATOR.$slug;

        if (File::exists($tree) && ! $this->option('force')) {
            $this->error('«'.$tree.'» از قبل وجود دارد. اگر مطمئنید، --force بدهید.');

            return self::FAILURE;
        }

        try {
            File::deleteDirectory($tree);
            $this->writeSkeleton($tree, $slug, $version, $verifier);
        } catch (Throwable $e) {
            $this->error('ساخت اسکلت ناموفق بود: '.$e->getMessage());

            return self::FAILURE;
        }

        $zipPath = $root.DIRECTORY_SEPARATOR.$slug.'-'.$version.'.zip';
        if (File::exists($zipPath) && ! $this->option('force')) {
            $this->error('«'.$zipPath.'» از قبل وجود دارد. اگر مطمئنید، --force بدهید.');

            return self::FAILURE;
        }

        try {
            $this->zip($tree, $zipPath);
        } catch (Throwable $e) {
            @unlink($zipPath);
            $this->error('ساخت ZIP ناموفق بود: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('اسکلت ساخته شد:');
        $this->line('  سورس: '.$tree);
        $this->line('  بسته: '.$zipPath);

        if ($this->option('no-validate')) {
            $this->warn('اعتبارسنجی انجام نشد (--no-validate). قبل از انتشار، بسته را با اعتبارسنج هسته بسنجید.');
            $this->maybeRegisterDev($registry, $slug, $tree, $zipPath);

            return self::SUCCESS;
        }

        $secret = (string) env('PLUGIN_PUBLISHER_SECRET_KEY', '');
        $signed = $secret !== '';

        $result = $validator->analyze($zipPath, $signed ? [] : ['allowUnsigned' => true]);

        if (! $result['ok']) {
            // fail-closed: بستهٔ ردشده نباید روی دیسک بماند.
            @unlink($zipPath);
            $this->error('بسته اعتبارسنجی نشد و حذف شد. خطاها:');
            foreach ($result['errors'] as $issue) {
                $this->line('  ['.($issue['code'] ?? '?').'] '.($issue['message'] ?? ''));
            }

            return self::FAILURE;
        }

        $this->info('اعتبارسنجی بسته: OK (slug: '.($result['summary']['slug'] ?? $slug).')');

        foreach ($result['warnings'] as $issue) {
            $this->warn('['.($issue['code'] ?? '?').'] '.($issue['message'] ?? ''));
        }

        if (! $signed) {
            $this->warn('بسته امضا نشد (PLUGIN_PUBLISHER_SECRET_KEY تنظیم نیست). این اسکلت فقط برای توسعه است؛ نصب روی سرور واقعی بستهٔ امضاشده می‌خواهد.');
        }

        $this->maybeRegisterDev($registry, $slug, $tree, $zipPath);

        return self::SUCCESS;
    }

    /**
     * ECO4 — ثبتِ اسکلت در دفترِ توسعهٔ محلی.
     *
     * بدون `--dev` هیچ‌کاری نمی‌کند. `register()` خودش هم fail-closed است، پس
     * حتی اگر اینجا صدا زده شود و حالت توسعه خاموش باشد، چیزی نوشته نمی‌شود.
     */
    private function maybeRegisterDev(DevScaffoldRegistry $registry, string $slug, string $tree, string $zipPath): void
    {
        if (! $this->option('dev')) {
            return;
        }

        $registry->register(DevScaffoldRegistry::KIND_PLUGIN, $slug, $tree, ['zip' => $zipPath]);
        $this->warn('در دفترِ توسعهٔ محلی ثبت شد (فقط محلی؛ روی نصب واقعی بی‌اثر است).');
    }

    /**
     * نوشتن درختِ کمینه.
     *
     * فقط مسیرهایی که `PluginPackageContract::ALLOWED_PATHS` اجازه می‌دهد
     * ساخته می‌شوند؛ اگر روزی این فهرست عوض شود، اعتبارسنجی همین‌جا قرمز می‌شود
     * و سازنده مجبور می‌شود هم‌زمان تصمیم بگیرد، نه اینکه بستهٔ ناسازگار بسازد.
     */
    private function writeSkeleton(string $tree, string $slug, string $version, PluginSignatureVerifier $verifier): void
    {
        $class = Str::studly($slug);
        $namespace = PluginPackageContract::PLUGIN_NAMESPACE_PREFIX.$class;
        $table = $slug.'_items';

        $manifest = [
            'name' => (string) ($this->option('name') ?: ('افزونهٔ '.$class)),
            'slug' => $slug,
            'version' => $version,
            'description' => (string) ($this->option('description') ?: 'اسکلت خالی که با pishdad-plugin:make ساخته شده.'),
            'author' => (string) ($this->option('author') ?: 'ناشناس'),
            'api' => [
                'prefix' => $slug,
                'routes' => [
                    [
                        'path' => 'items',
                        'method' => 'get',
                        'handler' => $namespace.'\\Http\\ItemController@index',
                        'middleware' => ['auth:sanctum'],
                    ],
                ],
            ],
            'db' => [
                // نامِ خام: هسته خودش «slug_» را اضافه می‌کند.
                'tables' => [['name' => 'items', 'indexes' => ['created_at']]],
            ],
        ];

        $secret = (string) env('PLUGIN_PUBLISHER_SECRET_KEY', '');
        if ($secret !== '') {
            $manifest['signature'] = $verifier->sign($manifest, $secret);
            $manifest['publisher'] = ['key_id' => 'cli'];
        }

        $this->put($tree, PluginPackageContract::MANIFEST, (string) json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        $this->put($tree, 'Laravel/src/ServiceProvider.php', $this->serviceProvider($namespace, $class));
        $this->put($tree, 'Laravel/src/Models/Item.php', $this->model($namespace, $table));
        $this->put($tree, 'Laravel/src/Http/ItemController.php', $this->controller($namespace));
        $this->put($tree, 'Laravel/routes/api.php', $this->routes($namespace));
        $this->put(
            $tree,
            'Laravel/database/migrations/'.now()->format('Y_m_d_His').'_create_'.$table.'.php',
            $this->migration($table)
        );
        $this->put($tree, 'Laravel/config/plugin.php', $this->config($slug, $class));
    }

    private function put(string $tree, string $relative, string $contents): void
    {
        $path = $tree.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    private function zip(string $tree, string $zipPath): void
    {
        File::ensureDirectoryExists(dirname($zipPath));
        @unlink($zipPath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('فایل ZIP باز نشد: '.$zipPath);
        }

        // مسیرهای داخل ZIP همیشه `/` می‌خواهند، و ترتیب ورودی‌ها ثابت است تا
        // خروجیِ یک اسکلتِ یکسان همیشه یکسان ساخته شود.
        $files = File::allFiles($tree);
        usort($files, fn ($a, $b) => strcmp(
            str_replace('\\', '/', $a->getRelativePathname()),
            str_replace('\\', '/', $b->getRelativePathname())
        ));

        foreach ($files as $file) {
            $name = str_replace('\\', '/', $file->getRelativePathname());
            $zip->addFile($file->getPathname(), $name);
        }

        $zip->close();

        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            throw new \RuntimeException('ZIP خالی یا ناقص ساخته شد: '.$zipPath);
        }
    }

    private function serviceProvider(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\\Support\\ServiceProvider as BaseProvider;

        /**
         * نقطهٔ ورود بک‌اند افزونه. هستهٔ پیشداد این کلاس را کشف و صدا می‌زند؛
         * هر چیزی که اینجا ثبت نشود، در درخواست‌ها در دسترس نیست.
         */
        class ServiceProvider extends BaseProvider
        {
            public function register(): void
            {
                // تنظیمات افزونه از Laravel/config/plugin.php خوانده می‌شود.
                \$this->mergeConfigFrom(__DIR__.'/../config/plugin.php', '{$namespace}');
            }

            public function boot(): void
            {
                // مسیرها از manifest.json می‌آیند، نه از اینجا.
            }
        }

        PHP;
    }

    private function model(string $namespace, string $table): string
    {
        return <<<PHP
        <?php

        namespace {$namespace}\\Models;

        use Illuminate\\Database\\Eloquent\\Model;

        /**
         * نام جدول **نهایی** است (با پیشوند افزونه)، چون Eloquent پیشوند
         * نمی‌سازد و فقط پیکربندی می‌کند.
         */
        class Item extends Model
        {
            protected \$table = '{$table}';

            protected \$fillable = ['title', 'body'];
        }

        PHP;
    }

    private function controller(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace}\\Http;

        use {$namespace}\\Models\\Item;
        use Illuminate\\Http\\JsonResponse;

        class ItemController
        {
            public function index(): JsonResponse
            {
                return response()->json([
                    'message' => 'فهرست آیتم‌ها.',
                    'data' => Item::query()->latest()->limit(50)->get(),
                ]);
            }
        }

        PHP;
    }

    private function routes(string $namespace): string
    {
        return <<<PHP
        <?php

        use {$namespace}\\Http\\ItemController;

        /**
         * نقشهٔ مسیرها.
         *
         * هستهٔ پیشداد فراخوانی را از `manifest.json` می‌خواند
         * (`api.routes[].handler`)، نه از این فایل — این فایل قراردادِ
         * نوشتن/خواندنِ همان دو چیز را یک‌جا نگه می‌دارد تا از هم جدا نشوند.
         * هر مسیری که اینجا اضافه کنید باید در مانیفست هم باشد و برعکس.
         */
        return [
            'items' => [ItemController::class, 'index'],
        ];

        PHP;
    }

    private function migration(string $table): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                if (Schema::hasTable('{$table}')) {
                    return;
                }

                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->id();
                    \$table->string('title');
                    \$table->text('body')->nullable();
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };

        PHP;
    }

    private function config(string $slug, string $class): string
    {
        return <<<PHP
        <?php

        /**
         * تنظیمات افزونه. کلیدش namespace کلاس‌هاست، پس با `config('{$slug}')`
         * خوانده می‌شود و در `php artisan config:cache` هم گم نمی‌شود.
         */
        return [
            'enabled' => true,
            'label' => '{$class}',
        ];

        PHP;
    }
}
