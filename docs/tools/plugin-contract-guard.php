<?php

declare(strict_types=1);

/**
 * K1.6.2 — نگهبانِ هم‌خوانیِ راهنما با قرارداد.
 *
 * ## مسئله‌ای که این نگهبان برای آن است
 *
 * `docs/PLUGIN-GUIDE.md` و `docs/PLUGIN-MANIFEST-CONTRACT.md` ساختار بسته،
 * فهرست نقاطِ اتصال و درجهٔ باز بودنشان را **دوباره می‌نویسند**. این یعنی دو
 * منبعِ حقیقت. و چون یکی از آن‌ها داده است و دیگری کد، هیچ‌کدام نمی‌تواند
 * دیگری را تولید کند ⇒ هر بار که قرارداد عوض می‌شود، راهنما بی‌سروصدا دروغ
 * می‌گوید و هیچ تستی قرمز نمی‌شود.
 *
 * شش ماه این اتفاق افتاد و معلوم نشد. این اسکریپت **مقادیرِ واقعی را از خودِ
 * `PluginPackageContract` استخراج می‌کند** و بعد سند را می‌سنجد — یعنی سند
 * حقیقت را تکرار نمی‌کند، بلکه در برابرش **آزموده** می‌شود.
 *
 * ## چرا این یک تستِ PHPUnit نیست
 *
 * `cms-core/backend/docker-compose.yml` فقط همان پوشه را mount می‌کند، پس
 * کانتینرِ اپ `docs/` ریشهٔ مخزن را **نمی‌بیند**. یک تستِ بک‌اند که سند را
 * بخواند یا همیشه «پیدا نشد» می‌خورد یا باید mount اضافه کند که کلِ تست‌های
 * هم‌زمان را restart می‌کند. الگوی خودِ مخزن برای guard سطحِ مخزن،
 * `docs/tools/` + یک استپِ CI است (`tasks-guard.mjs` دقیقاً همین است).
 *
 * اجرا:
 *   php docs/tools/plugin-contract-guard.php
 *
 * خروجی: فهرستِ drift به‌ازای هر مورد، و کدِ خروج ۱ اگر چیزی نخواند.
 */

$root = dirname(__DIR__, 2);
$autoload = $root.'/cms-core/backend/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, " autoload پیدا نشد: {$autoload}\ncomposer install را در cms-core/backend اجرا کن.\n");

    exit(2);
}

require $autoload;

use App\Services\Plugins\PluginPackageContract as Contract;

/** @var list<string> $problems */
$problems = [];

$guidePath = $root.'/docs/PLUGIN-GUIDE.md';
$contractDocPath = $root.'/docs/PLUGIN-MANIFEST-CONTRACT.md';

foreach ([$guidePath, $contractDocPath] as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, " سند پیدا نشد: {$path}\n");

        exit(2);
    }
}

$guide = (string) file_get_contents($guidePath);
$contractDoc = (string) file_get_contents($contractDocPath);

// ── مقادیرِ واقعی، از کد ─────────────────────────────────────────────────────

/** @var list<string> $allowedPaths */
$allowedPaths = Contract::ALLOWED_PATHS;

/** @var array<string, string> $openness */
$openness = array_map(
    static fn (array $meta): string => (string) $meta['openness'],
    Contract::EXTENSION_POINTS
);

/** @var array<string, string> $status */
$status = array_map(
    static fn (array $meta): string => (string) $meta['status'],
    Contract::EXTENSION_POINTS
);

/**
 * ریشه‌هایِ پوشه‌ای که درخت باید نشان دهد: `Laravel` و `Next.js`.
 *
 * فقط **segmentِ اول** — نه هر پیشوند. دلیل این محدودیت شکلِ درختِ سند است: آن
 * مسیرِ چندقطعه‌ای را در یک سطر می‌نویسد (`routes/api.php`) و پوشهٔ میانی را
 * اصلاً گره نمی‌کند. اگر `Laravel/routes` را هم انتظار داشته باشیم، نگهبان سندِ
 * درست را «مقصدِ کم» گزارش می‌کند — و یک نگهبانی که همیشه خطای الکی بدهد بعد از
 * دو هفته خاموش می‌شود.
 *
 * @param  list<string>  $allowed
 * @return list<string>
 */
$containers = static function (array $allowed): array {
    $out = [];

    foreach ($allowed as $target) {
        $root = explode('/', $target)[0];

        if (! in_array($root, $allowed, true)) {
            $out[] = $root;
        }
    }

    return array_values(array_unique($out));
};

// ── ۱) درختِ بسته ────────────────────────────────────────────────────────────

/**
 * مسیرهای درختِ بسته را از بلوکِ کدِ «درخت بستهٔ مجاز» بیرون می‌کشد.
 *
 * عمق از تعدادِ `│` های پیش از شاخه خوانده می‌شود، نه از تعدادِ فاصله — چون
 * درخت با `├──` و `│   ` کشیده شده و فاصله‌ها نامعتبرند.
 *
 * @return list<string>
 */
$treePaths = static function (string $doc): array {
    $lines = preg_split('/\R/u', $doc) ?: [];

    $inside = false;
    $inFence = false;
    $depth = -1;
    $stack = [];
    $out = [];

    foreach ($lines as $line) {
        if (str_starts_with($line, '## درخت بستهٔ مجاز')) {
            $inside = true;

            continue;
        }

        if (! $inside) {
            continue;
        }

        // پایانِ بخش: سرِ بعدی.
        if (str_starts_with($line, '## ') && ! str_starts_with($line, '## درخت بستهٔ مجاز')) {
            break;
        }

        if (trim($line) === '```') {
            $inFence = ! $inFence;

            continue;
        }

        if (! $inFence) {
            continue;
        }

        // پیشوندِ تورفتگی می‌تواند `│   ` یا صرفاً چهار فاصله باشد: بلوکِ `Laravel/` با
// `│` کشیده شده و بلوکِ `Next.js/` با فاصله. اگر فقط `│` را بپذیریم، شاخه‌های
// `Next.js` بی‌صدا نادیده گرفته می‌شوند و نگهبان دو مقصد را «گم‌شده» گزارش
// می‌کند — یعنی یک خطای کاذب روی سندی که درست است.
if (! preg_match('/^([\s│]*)(?:├──|└──)\s+(.+)$/u', $line, $m)) {
            continue;
        }

        // عمق = پهنای تورفتگی تقسیم بر ۴ (چه `│   ` باشد چه چهار فاصله).
        $level = intdiv(mb_strlen($m[1], 'UTF-8'), 4);

        // اسمِ گره، بدون توضیحِ `← …`.
        $name = trim(preg_replace('/\s*←.*$/u', '', $m[2]) ?? '');
        $name = rtrim($name, '/');

        $stack = array_slice($stack, 0, max(0, $level));
        $stack[$level] = $name;

        $path = implode('/', array_filter($stack, static fn ($s): bool => $s !== ''));

        if ($path !== '' && $path !== 'بسته.zip') {
            $out[$path] = true;
        }
    }

    return array_keys($out);
};

$tree = $treePaths($guide);

if ($tree === []) {
    $problems[] = 'بلوکِ «درخت بستهٔ مجاز» در PLUGIN-GUIDE.md پیدا نشد — نگهبان عملاً بی‌اثر است.';
} else {
    // ── (a) هر مقصدِ مجاز باید در درخت باشد ──
    //
    // این بند همان چیزی را می‌گیرد که K1.6.2 می‌خواهد: اگر قرارداد مقصدی
    // اضافه یا حذف کرد و سند عوض نشد، همین‌جا قرمز می‌شود.
    $missing = array_values(array_diff($allowedPaths, $tree));

    if ($missing !== []) {
        $problems[] = 'مقصدِ مجازی در قرارداد هست ولی درختِ PLUGIN-GUIDE.md نیست: '.implode('، ', $missing);
    }

    // ── (b) دو سطحِ اولِ درخت باید *دقیقاً* قرارداد باشد ──
    //
    // درختِ سند دو چیز را با هم قاطی می‌کند: **مقصدها** (قرارداد) و **نمونه‌هایی
    // که داخلشان می‌شود گذاشت** (`Laravel/src/Models/`, `Services/`, …). جدا
    // کردنشان با عمق انجام می‌شود: دو سطحِ اول قرارداد است، هر چیزی عمیق‌تر
    // نمونه است.
    //
    // بدون این برش، نگهبان یا باید `ServiceProvider.php` را «مقصدِ اضافه»
    // گزارش کند (یعنی همیشه قرمز ⇒ بعد از دو هفته خاموش می‌شود) یا نمونه‌ها را
    // مجاز بداند (یعنی یک پوشهٔ دلخواه بی‌سروصدا اضافه می‌شود).
    $top = static fn (array $paths): array => array_values(array_filter(
        $paths,
        static fn (string $p): bool => count(explode('/', $p)) <= 2
    ));

    $containers = $containers($allowedPaths);

    $expectedTop = $top([...$allowedPaths, ...$containers]);
    $actualTop = $top($tree);

    sort($expectedTop);
    sort($actualTop);

    if ($expectedTop !== $actualTop) {
        $problems[] = 'ساختارِ دو سطحِ اولِ بسته در PLUGIN-GUIDE.md با `PluginPackageContract::ALLOWED_PATHS` نمی‌خواند.'
            ."\n    در قرارداد ولی در سند نیست: ".(implode('، ', array_values(array_diff($expectedTop, $actualTop))) ?: '—')
            ."\n    در سند ولی در قرارداد نیست: ".(implode('، ', array_values(array_diff($actualTop, $expectedTop))) ?: '—');
    }
}

// تصمیم C2-b — `panel.json` حذف شد و نباید جایی به‌عنوان مقصد جا بزند.
foreach ($tree as $path) {
    if (str_ends_with($path, 'panel.json')) {
        $problems[] = "«{$path}» در درختِ بسته آمده ولی تصمیم C2-b آن را حذف کرد؛ مانیفست تنها منبعِ حقیقت است.";
    }
}

// ── ۲) جدولِ نقاطِ اتصال ─────────────────────────────────────────────────────

/**
 * کلیدِ نقطه‌ها را از جدولِ «جدول هر نقطه» بیرون می‌کشد.
 *
 * فقط ستونِ اول خوانده می‌شود، و فقط سلول‌هایی که **دقیقاً** یک شناسهٔ نقطه‌اند
 * (`site.page_type`) — نه هر چیزی که نقطه‌گونه به نظر می‌رسد. وگرنه ارجاع‌های
 * متنی مثل «مثل `admin.menu`» در توضیحِ ستون‌ها به‌عنوان ردیف شمرده می‌شدند.
 *
 * @return list<string>
 */
$documentedPoints = static function (string $doc): array {
    $lines = preg_split('/\R/u', $doc) ?: [];

    $inside = false;
    $out = [];

    foreach ($lines as $line) {
        if (str_starts_with($line, '## جدول هر نقطه')) {
            $inside = true;

            continue;
        }

        if (! $inside) {
            continue;
        }

        if (str_starts_with($line, '## ')) {
            break;
        }

        if (! str_starts_with(trim($line), '|')) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, " \t|")));
        $first = $cells[0] ?? '';

        if (preg_match('/^`([a-z]+\.[a-z_]+)`$/', $first, $m) === 1) {
            $out[$m[1]] = true;
        }
    }

    return array_keys($out);
};

$documented = $documentedPoints($guide);
$contractPoints = array_keys(Contract::EXTENSION_POINTS);

sort($documented);
sort($contractPoints);

if ($documented !== $contractPoints) {
    $problems[] = 'فهرستِ نقاطِ اتصال در جدولِ PLUGIN-GUIDE.md با `PluginPackageContract::EXTENSION_POINTS` نمی‌خواند.'
        ."\n    در قرارداد ولی در سند نیست: ".(implode('، ', array_values(array_diff($contractPoints, $documented))) ?: '—')
        ."\n    در سند ولی در قرارداد نیست: ".(implode('، ', array_values(array_diff($documented, $contractPoints))) ?: '—');
}

// ── ۳) درجهٔ باز بودن ────────────────────────────────────────────────────────

/**
 * نگاشتِ نقطه ⇒ درجه‌ای که سند ادعا می‌کند.
 *
 * `deferred` عمداً سنجیده نمی‌شود: سند برای نقطهٔ deferred در ستونِ «درجه»
 * کلمهٔ `deferred` می‌نویسد نه `openness` واقعیِ آن نقطه. آن ادعا را بندِ
 * `status` می‌سنجد، نه این.
 *
 * @return array<string, string>
 */
$documentedOpenness = static function (string $doc): array {
    $lines = preg_split('/\R/u', $doc) ?: [];

    $inside = false;
    $out = [];

    foreach ($lines as $line) {
        if (str_starts_with($line, '## جدول هر نقطه')) {
            $inside = true;

            continue;
        }

        if (! $inside) {
            continue;
        }

        if (str_starts_with($line, '## ')) {
            break;
        }

        if (! str_starts_with(trim($line), '|')) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, " \t|")));
        $first = $cells[0] ?? '';
        $second = $cells[1] ?? '';

        if (preg_match('/^`([a-z]+\.[a-z_]+)`$/', $first, $m) !== 1) {
            continue;
        }

        if (str_contains($second, 'deferred')) {
            continue;
        }

        if (preg_match('/\*\*([a-z_]+)\*\*/', $second, $g) === 1) {
            $out[$m[1]] = $g[1];
        }
    }

    return $out;
};

$opennessByPoint = $documentedOpenness($guide);

foreach ($opennessByPoint as $point => $claimed) {
    $real = $openness[$point] ?? null;

    if ($real === null) {
        continue;
    }

    if ($claimed !== $real) {
        $problems[] = "درجهٔ باز بودنِ «{$point}» در سند «{$claimed}» است ولی قرارداد «{$real}» می‌گوید.";
    }
}

// نقطه‌ای که قرارداد `deferred` است باید در سند هم deferred علامت خورده باشد.
//
// جدولِ سند برای نقطهٔ deferred در ستونِ «درجه» کلمهٔ `deferred` می‌نویسد نه
// `openness` واقعیِ آن نقطه — پس بندِ بالا عمداً آن‌ها را رد می‌کند و این بند
// ادعای `status` را می‌سنجد.
$guideLines = preg_split('/\R/u', $guide) ?: [];

foreach ($contractPoints as $point) {
    if (($status[$point] ?? null) !== 'deferred') {
        continue;
    }

    if (! in_array($point, $documented, true)) {
        continue;   // بندِ ۲ همین را گزارش کرده.
    }

    $marked = false;

    foreach ($guideLines as $line) {
        if (str_contains($line, '`'.$point.'`') && str_contains($line, 'deferred')) {
            $marked = true;

            break;
        }
    }

    if (! $marked) {
        $problems[] = "«{$point}» در قرارداد deferred است ولی سند آن را deferred نشان نمی‌دهد.";
    }
}

// ── ۴) سندِ دوم نباید نقطهٔ ناشناخته بسازد ───────────────────────────────────

preg_match_all('/`((?:admin|site|core)\.[a-z_]+)`/u', $contractDoc, $found);

foreach (array_unique($found[1]) as $mention) {
    if (! in_array($mention, $contractPoints, true)) {
        $problems[] = "PLUGIN-MANIFEST-CONTRACT.md به نقطهٔ «{$mention}» اشاره می‌کند که در قرارداد نیست.";
    }
}

// ── گزارش ───────────────────────────────────────────────────────────────────

$checked = [
    'ALLOWED_PATHS' => count($allowedPaths),
    'EXTENSION_POINTS' => count($contractPoints),
];

if ($problems === []) {
    fwrite(
        STDOUT,
        "plugin-contract-guard: سبز — ساختار بسته و جدولِ نقاطِ اتصال با قرارداد می‌خوانند"
        ." (".implode('، ', array_map(
            static fn (string $k, int $n): string => "{$k}={$n}",
            array_keys($checked),
            array_values($checked)
        )).").\n"
    );

    exit(0);
}

fwrite(STDERR, "plugin-contract-guard: ".count($problems)." ناهماهنگی\n");

foreach ($problems as $i => $problem) {
    fwrite(STDERR, '  '.($i + 1).') '.$problem."\n");
}

fwrite(
    STDERR,
    "\nراهنما باید از قرارداد ساخته شود، نه اینکه آن را تکرار کند. یا سند را با\n"
    ."PluginPackageContract::ALLOWED_PATHS و ::EXTENSION_POINTS هماهنگ کن، یا اگر قرارداد\n"
    ."اشتباه است اول آن را عوض کن. نگهبان عمداً هر دو طرف را می‌سنجد.\n"
);

exit(1);