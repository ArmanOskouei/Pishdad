<?php

namespace App\Console\Commands;

use App\Services\Plugins\PluginIntegritySeal;
use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * K5.2-W — بازبینی و مهرِ دوبارهٔ نسخه‌های افزونه.
 *
 * ## چرا این فرمان وجود دارد
 *
 * نصب‌هایی که پیش از این تسک نصب شده‌اند مهر ندارند. وضعیتِ آن‌ها `unsealed`
 * است، یعنی «هنوز تأییدنشده» — و عمداً **خودکار** درست نمی‌شوند، چون «خودکار
 * مهر زدن» یعنی «هر چیزی که روی دیسک است، معتبر اعلام می‌شود» و آن دقیقاً
 * همان چیزی است که این سرویس برای جلوگیری از آن ساخته شده.
 *
 * پس ترمیم یک **تصمیمِ صریحِ مالکِ نصب** است: `--reseal` فقط روی نسخه‌هایی که
 * خودت نام می‌بری، و بعدش بازبینی دوباره انجام می‌شود تا اگر مهر درست نشد،
 * همان‌جا معلوم شود.
 *
 * خروجیِ بدون `--reseal` فقط **گزارش** است: هیچ چیزی را تغییر نمی‌دهد.
 */
class PluginSeal extends Command
{
    protected $signature = 'plugin-seal
        {slug? : فقط همین افزونه؛ بدون آن همهٔ افزونه‌های نصب‌شده}
        {--reseal : مهر را از نو بزن (پیش از آن بازبینی کن و نتیجه را بگو)}
        {--json : خروجی ماشین‌خوان}';

    protected $description = 'بازبینی مهر یکپارچگی نسخه‌های افزونه (و مهر دوباره با --reseal)';

    public function handle(PluginIntegritySeal $seal, PluginReleaseManager $releases): int
    {
        $slug = $this->argument('slug');

        try {
            $slugs = $slug === null ? $releases->installedSlugs() : [$releases->normalizeSlug((string) $slug)];
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($slugs === []) {
            $this->warn('هیچ افزونه‌ای روی دیسک نصب نیست.');

            return self::SUCCESS;
        }

        $reseal = (bool) $this->option('reseal');
        $rows = [];
        $worst = 'verified';

        foreach ($slugs as $each) {
            foreach ($releases->releases($each) as $release) {
                $verdict = $seal->verify($each, $release['name']);

                if ($reseal && ! $verdict['ok'] && $verdict['status'] === 'unsealed') {
                    // فقط «نبودِ مهر» ترمیم می‌شود. `mismatch` یعنی فایل‌ها
                    // عوض شده‌اند و امضای دوباره یعنی پذیرفتنِ آن دستکاری.
                    $verdict = $seal->reseal($each, $release['name']);
                    $verdict = $verdict['ok']
                        ? $seal->verify($each, $release['name'])
                        : [
                            'ok' => false, 'status' => 'unsealed', 'code' => $verdict['code'],
                            'reason' => $verdict['message'], 'files' => 0, 'digest' => null,
                        ];
                }

                $rows[] = [
                    'slug' => $each,
                    'release' => $release['name'],
                    'active' => $release['active'],
                    'status' => $verdict['status'],
                    'ok' => $verdict['ok'],
                    'code' => $verdict['code'],
                    'reason' => $verdict['reason'],
                    'files' => $verdict['files'],
                ];

                if (! $verdict['ok']) {
                    $worst = $verdict['status'];
                }
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $worst === 'verified' ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['افزونه', 'نسخه', 'فعال', 'وضعیت', 'فایل', 'توضیح'],
            array_map(static fn (array $r): array => [
                $r['slug'],
                $r['release'],
                $r['active'] ? '✓' : '—',
                $r['status'],
                (string) $r['files'],
                $r['reason'],
            ], $rows)
        );

        if ($worst === 'verified') {
            $this->info('همهٔ نسخه‌ها با مهر یکپارچگی می‌خوانند.');

            return self::SUCCESS;
        }

        if ($worst === 'unsealed') {
            $this->warn('چند نسخه هنوز مهر ندارند (تأییدنشده، نه نامعتبر). با --reseal مهرشان را بزنید.');

            return self::FAILURE;
        }

        $this->error('یک یا چند نسخه با مهرشان نمی‌خوانند. --reseal نزنید تا علت دستکاری روشن شود.');

        return self::FAILURE;
    }
}
