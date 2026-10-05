<?php

namespace App\Console\Commands;

use App\Services\Plugins\PluginPackageValidator;
use App\Services\Registry\CuratedRegistry;
use Illuminate\Console\Command;

/**
 * F1.2 — دروازهٔ CI برای `registry/curated.json`.
 *
 * ## چرا فرمان و نه یک اسکریپتِ مستقل
 *
 * «بسته‌های این فهرست معتبرند» را باید **همان** validator بگوید که نصب با آن
 * می‌سنجد. یک اسکریپتِ بی‌دسترسی به چارچوب یعنی یک parser دوم که فقط CI آن را
 * اجرا می‌کند — یعنی دقیقاً دو حقیقت، و آن‌که در مسیرِ واقعی اجرا می‌شود ضعیف‌تر
 * است. این فرمان همان کلاسِ `PluginPackageValidator` را صدا می‌زند.
 *
 * ⛔ **خروجیِ غیرصفر یعنی CI قرمز.** برعکسش (هشدار و ادامه) یعنی دروازه‌ای که
 * بعد از دو ماه کسی به آن عادت کرده و دیگر نمی‌خواند.
 *
 * ## چه چیزی سنجیده می‌شود
 *
 *  ۱. شکلِ فایل — از طریق `CuratedRegistry::shapeErrors()`، شامل قاعدهٔ
 *     `execution_status` که «دکمهٔ نصبِ دروغین» را در سطحِ داده می‌بندد.
 *  ۲. هر مدخلی که `package` دارد — تحلیلِ کامل با validator: خطای اعتبارسنجی
 *     = قرمز. هشِ `package_sha256` هم اگر اعلام شده باشد سنجیده می‌شود.
 *
 * ## چرا مدخل‌های بدون `package` خطا نمی‌دهند
 *
 * چون یک مدخلِ صرفاً-پیوندی لازم نیست بسته‌ای در مخزن داشته باشد، و فهرست خالی
 * هم — که وضعیتِ فعلیِ پروژه است — باید سبز باشد. الزامِ بسته، الزامِ
 * ناشری است که هنوز وجود ندارد.
 */
class RegistryCheck extends Command
{
    protected $signature = 'registry:check';

    protected $description = 'شکل و بسته‌های registry/curated.json را می‌سنجد (دروازهٔ CI)';

    public function handle(CuratedRegistry $registry, PluginPackageValidator $validator): int
    {
        $path = $registry->path();

        if (! is_file($path)) {
            $this->error("فهرستِ curated پیدا نشد: «{$path}».");

            return self::FAILURE;
        }

        try {
            $registry = new CuratedRegistry($path);
            $data = $registry->all();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $problems = [];
        $checked = 0;

        foreach (['plugins', 'themes'] as $kind) {
            $raw = $this->rawEntries($path, $kind);

            foreach ($raw as $index => $entry) {
                $checked++;
                $where = "{$kind}[{$index}]";

                $package = $entry['package'] ?? null;

                if (! is_string($package) || $package === '') {
                    continue;
                }

                $absolute = $this->absolutePackagePath($path, $package);

                if (! is_file($absolute)) {
                    $problems[] = "{$where}: بستهٔ «{$package}» در مخزن نیست.";

                    continue;
                }

                $problems = array_merge($problems, $this->packageProblems($where, $absolute, $entry, $validator));
            }
        }

        $total = count($data['plugins']) + count($data['themes']);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'فهرستِ curated سالم است: %d مدخل، %d بسته تحلیل شد، execution_status=%s.',
            $total,
            $checked,
            $data['execution_status']
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function packageProblems(string $where, string $absolute, array $entry, PluginPackageValidator $validator): array
    {
        $problems = [];

        $declared = $entry['package_sha256'] ?? null;

        if (is_string($declared) && $declared !== '') {
            $actual = hash_file('sha256', $absolute);

            if (! is_string($actual) || ! hash_equals(strtolower($declared), $actual)) {
                $problems[] = "{$where}: هشِ بسته با package_sha256 نمی‌خواند.";
            }
        }

        $report = $validator->analyze($absolute);

        foreach (($report['errors'] ?? []) as $error) {
            $problems[] = "{$where}: اعتبارسنجی بسته — ".($error['code'] ?? 'unknown').' '.($error['message'] ?? '');
        }

        return $problems;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawEntries(string $registryPath, string $kind): array
    {
        $data = json_decode((string) file_get_contents($registryPath), true);
        $entries = is_array($data[$kind] ?? null) ? $data[$kind] : [];

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * مسیرِ نسبیِ مدخل نسبت به **خودِ فایلِ فهرست** حساب می‌شود، نه نسبت به
     * پوشهٔ اجرا.
     *
     * یعنی `php artisan registry:check` از هر پوشه‌ای، و CI از ریشهٔ مخزن، یک
     * نتیجه می‌دهند.
     */
    private function absolutePackagePath(string $registryPath, string $package): string
    {
        return dirname($registryPath).'/'.ltrim(str_replace('\\', '/', $package), '/');
    }
}
