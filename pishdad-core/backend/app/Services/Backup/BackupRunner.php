<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * E5 — گرفتن dump با `pg_dump` + چرخش + مانیفست.
 *
 * ## چهار قاعده‌ای که این کلاس نگه می‌دارد
 *
 *  ۱) **fail-closed.** هر شرطی که نتواند تضمین کند «این فایل یک dump کامل و
 *     قابل‌بازگردانی است»، یعنی *پیام فارسی + `ok=false`*. هیچ مسیری به
 *     `Storage::fake` یا «بی‌خیال، فردا می‌بینیم» ندارد.
 *  ۲) **نوشتن اتمیک.** `pg_dump` اول در `<file>.tmp` می‌نویسد و بعد `rename`
 *     می‌شود. اگر وسط کار قطع شود، فایل نیم‌کاره اصلاً به اسم `.dump` دیده
 *     نمی‌شود — پس چیزی که «بکاپ» به نظر می‌رسد واقعاً بکاپ است.
 *  ۳) **رمز در آرگومان روی خط فرمان.** URL داخل `escapeshellarg` می‌رود و
 *     stderr (که می‌تواند شامل بخشی از URL باشد) فقط در پیام خطا می‌آید، نه در
 *     لاگ با سطح info.
 *  ۴) **تست‌پذیری بدون pg_dump.** `dump()` یک متدِ `protected` است؛ تست با یک
 *     زیرکلاس آن را جعل می‌کند و نیازی به نصب PostgreSQL در کانتینر تست نیست.
 */
class BackupRunner
{
    /** @var list<string> پیام‌های فارسیِ خطا؛ آخرین پیام همان چیزی است که به کاربر می‌رسد. */
    private array $errors = [];

    public function __construct(
        private ?string $binary = null,
        private ?string $databaseUrl = null,
    ) {}

    /**
     * @param  int|null  $keep  override کانفیگ (۰ یا منفی = فقط حذف اضافه‌ها)
     * @return array{ok: bool, message: string, file: ?string, removed: list<string>, error: ?string}
     */
    public function run(?int $keep = null, ?string $directory = null): array
    {
        $this->errors = [];

        $url = $this->databaseUrl();
        if ($url === null) {
            return $this->fail('متغیر DATABASE_URL تنظیم نشده است؛ بدون رشتهٔ اتصال نمی‌توان از دیتابیس پشتیبان گرفت.');
        }

        $binary = $this->binary();
        $missing = $this->missingBinary($binary);
        if ($missing !== null) {
            return $this->fail($missing);
        }

        $dir = $this->directory($directory);
        if (! $this->ensureDirectory($dir)) {
            return $this->fail('پوشهٔ پشتیبان قابل ساخت نیست: «'.$dir.'». دسترسی نوشتن روی دیسک را بررسی کنید.');
        }

        $database = $this->databaseName($url);
        $name = $database.'-'.now()->format('Ymd-His').'.dump';
        $target = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
        $tmp = $target.'.tmp';

        // بازماندهٔ یک اجرای قبلیِ قطع‌شده: نباید به‌جای dump تازه rename شود.
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        $started = now();
        $error = $this->dump($binary, $url, $tmp);
        if ($error !== null) {
            @unlink($tmp);

            return $this->fail($error);
        }

        $bytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($bytes <= 0) {
            @unlink($tmp);

            return $this->fail('pg_dump فایل خالی ساخت؛ این dump قابل‌بازگردانی نیست و کنار گذاشته شد.');
        }

        if (! @rename($tmp, $target)) {
            @unlink($tmp);

            return $this->fail('نوشتن نهایی فایل پشتیبان ممکن نشد؛ پوشهٔ «'.$dir.'» را بررسی کنید.');
        }

        $entry = [
            'file' => $name,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $target) ?: null,
            'database' => $database,
            'created_at' => $started->toIso8601String(),
        ];

        $keepCount = $keep ?? (int) config('backup.keep', 7);

        // چرخش **قبل** از مانیفست: مانیفست باید آینهٔ دیسک باشد. اگر بعد از
        // مانیفست بچرخانیم، مانیفست فایل‌هایی را فهرست می‌کند که دیگر وجود
        // ندارند و بازگردانیِ یک نسخهٔ حذف‌شده شکست می‌خورد.
        $removed = $keepCount > 0 ? $this->rotate($dir, $keepCount) : [];

        if ($this->writeManifest($dir, $entry) === null) {
            // فایل هست ولی فهرستش نیست: بازگردانی ممکن، مدیریت نسخه‌ها نیست.
            // باز هم FAILURE — چون «بکاپ مدیریت‌شده» قول داده بودیم.
            return $this->fail('فایل پشتیبان ساخته شد («'.$name.'») ولی نوشتن مانیفست شکست خورد؛ مدیریت نسخه‌ها نامطمئن است.', [
                'file' => $target,
            ]);
        }

        Log::info('backup.run', [
            'file' => $name,
            'bytes' => $bytes,
            'database' => $database,
            'removed' => $removed,
        ]);

        $message = 'پشتیبان‌گیری انجام شد: '.$name.' ('.$this->humanBytes($bytes).').'
            .' بازگردانی: pg_restore --dbname=postgresql://... --clean --if-exists "'.$name.'"';

        if ($removed !== []) {
            $message .= ' — '.count($removed).' نسخهٔ قدیمی حذف شد.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'file' => $target,
            'removed' => $removed,
            'error' => null,
        ];
    }

    /** پوشهٔ مؤثر پشتیبان‌ها (override تست/پنل مقدم بر کانفیگ). */
    public function directory(?string $directory = null): string
    {
        return $directory ?: (string) (config('backup.path') ?: storage_path('app/backups'));
    }

    /**
     * فهرست نسخه‌های موجود برای پنل مشتری.
     *
     * دیسک منبع حقیقت است (مانیفست ممکن است کهنه باشد)، ولی فرادادهٔ سنگین
     * (`sha256`) از مانیفست خوانده می‌شود تا هر بار بازشدن صفحه، گیگابایت‌ها
     * دوباره هش نشوند. هر ورودی مسیر مطلق را هم دارد تا کنترلر دوباره منطق
     * مسیر را نسازد.
     *
     * @return list<array{id: string, file: string, path: string, bytes: int, sha256: ?string, database: ?string, created_at: ?string}>
     */
    public function listBackups(?string $directory = null): array
    {
        $dir = $this->directory($directory);
        $known = $this->manifestEntries($dir);

        $entries = [];
        foreach ($this->dumps($dir) as $path) {
            $name = basename($path);
            $meta = $known[$name] ?? [];

            $entries[] = [
                'id' => $name,
                'file' => $name,
                'path' => $path,
                'bytes' => (int) ($meta['bytes'] ?? filesize($path)),
                'sha256' => is_string($meta['sha256'] ?? null) ? $meta['sha256'] : null,
                'database' => is_string($meta['database'] ?? null) ? $meta['database'] : $this->databaseOf($name),
                'created_at' => is_string($meta['created_at'] ?? null) ? $meta['created_at'] : $this->createdAtOf($name),
            ];
        }

        // نام فایل زمان‌دار است، پس مرتب‌سازی نزولیِ رشته = تازه‌به‌کهنه.
        usort($entries, static fn (array $a, array $b) => strcmp((string) $b['file'], (string) $a['file']));

        return $entries;
    }

    /**
     * یک نسخه را با شناسه (نام فایل) پیدا می‌کند — فقط اگر واقعاً روی دیسک باشد.
     *
     * @return array{id: string, file: string, path: string, bytes: int, sha256: ?string, database: ?string, created_at: ?string}|null
     */
    public function findBackup(string $id): ?array
    {
        // «id» فقط نام فایل است. هر مسیری (`..`، جداکننده، درایو) رد می‌شود و
        // حتی اگر بگذرد، در فهرستِ دیسکِ همان پوشه هیچ تطابقی پیدا نمی‌کند.
        if ($id === '' || $id !== basename($id)) {
            return null;
        }

        foreach ($this->listBackups() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * اجرای واقعی `pg_dump`.
     *
     * @return string|null پیام خطا، یا `null` یعنی موفق.
     */
    protected function dump(string $binary, string $url, string $target): ?string
    {
        $command = escapeshellarg($binary)
            .' --dbname='.escapeshellarg($url)
            .' --format=custom --no-owner --no-privileges --file='.escapeshellarg($target);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return 'اجرای pg_dump ممکن نشد؛ اجرای این فرمان روی این سرور مجاز نیست.';
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $deadline = time() + max(1, (int) config('backup.timeout', 600));
        $exit = -1;
        $timedOut = false;

        while (true) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (! $status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (time() > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(200_000);
        }

        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closed = proc_close($process);
        if ($exit === -1) {
            $exit = (int) $closed;
        }

        if ($timedOut) {
            return 'pg_dump از سقف زمانی ('.((int) config('backup.timeout', 600)).' ثانیه) عبور نکرد و متوقف شد.';
        }
        if ($exit !== 0) {
            $detail = trim($this->redact($stderr, $url));

            return 'pg_dump با کد خطای '.$exit.' متوقف شد'.($detail !== '' ? ': '.Str::limit($detail, 300) : '.');
        }

        return null;
    }

    /**
     * نوشتن مانیفست به‌صورت اتمیک.
     *
     * @param  array<string, mixed>  $entry
     */
    private function writeManifest(string $dir, array $entry): ?string
    {
        $name = (string) config('backup.manifest', 'manifest.json');
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
        $tmp = $path.'.tmp';

        // موجودی روی دیست، منبع حقیقت است: نسخهٔ تازه را هم با فرادادهٔ
        // کاملش می‌گذاریم و بقیه را از خودِ فایل بازسازی می‌کنیم (sha256 و
        // حجم). تاریخِ نسخه‌های قدیمی از نام فایل خوانده می‌شود چون نام
        // زمان‌دار است — پس مانیفست بعد از ری‌استارت هم کامل می‌ماند.
        $entries = [];
        foreach ($this->dumps($dir) as $existing) {
            $existingName = basename($existing);

            $entries[] = $existingName === $entry['file'] ? $entry : [
                'file' => $existingName,
                'bytes' => (int) filesize($existing),
                'sha256' => hash_file('sha256', $existing) ?: null,
                'database' => $this->databaseOf($existingName),
                'created_at' => $this->createdAtOf($existingName),
            ];
        }

        // جدیدترین اول؛ چون نام فایل با زمان مرتب می‌شود، همین ترتیب درست است.
        usort($entries, fn (array $a, array $b) => strcmp((string) $b['file'], (string) $a['file']));

        $payload = json_encode([
            'generated_at' => now()->toIso8601String(),
            'app_version' => (string) config('app.version', 'unknown'),
            'keep' => (int) config('backup.keep', 7),
            'backups' => $entries,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false || @file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);

            return null;
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return null;
        }

        return $path;
    }

    /**
     * چرخش: نگه‌داشتن `keep` تازه‌ترین فایل و حذف بقیه.
     *
     * @return list<string> نام فایل‌های حذف‌شده
     */
    private function rotate(string $dir, int $keep): array
    {
        $files = $this->dumps($dir);
        if (count($files) <= $keep) {
            return [];
        }

        $removed = [];
        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $removed[] = basename($old);
            }
        }

        return $removed;
    }

    /** @return array<string, array<string, mixed>> نگاشت «نام فایل ⇒ ورودی مانیفست» */
    private function manifestEntries(string $dir): array
    {
        $name = (string) config('backup.manifest', 'manifest.json');
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['backups']) || ! is_array($decoded['backups'])) {
            return [];
        }

        $map = [];
        foreach ($decoded['backups'] as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $map[$entry['file']] = $entry;
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function dumps(string $dir): array
    {
        $found = glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.dump') ?: [];
        rsort($found, SORT_STRING);

        return array_values($found);
    }

    private function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return @mkdir($dir, 0750, true) || is_dir($dir);
    }

    private function missingBinary(string $binary): ?string
    {
        if ($binary === '') {
            return 'مسیر pg_dump (کانفیگ backup.binary) خالی است.';
        }
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            if (! is_file($binary) || ! is_executable($binary)) {
                return 'فایل اجرایی pg_dump در «'.$binary.'» پیدا نشد یا قابل اجرا نیست.';
            }

            return null;
        }

        return null; // نام ساده ⇒ از PATH می‌آید؛ اگر نبود، خودِ pg_dump کد خطا می‌دهد.
    }

    private function databaseUrl(): ?string
    {
        $url = $this->databaseUrl ?? config('backup.database_url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return trim($url);
    }

    private function binary(): string
    {
        return (string) ($this->binary ?? config('backup.binary', 'pg_dump'));
    }

    /** نام دیتابیس فقط برای نام فایل و مانیفست است — نه برای اتصال. */
    private function databaseName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = ltrim($path, '/');

        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) {
            return 'database';
        }

        return $name;
    }

    /** نام دیتابیس از روی نام فایلِ یک نسخهٔ قدیمی. */
    private function databaseOf(string $fileName): ?string
    {
        if (preg_match('/^(.+)-\d{8}-\d{6}\.dump$/', $fileName, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * زمان ساخت از روی نام فایل (`Ymd-His`).
     *
     * اگر نام قابل‌خواندن نبود `null` برمی‌گردد به‌جای اینکه تاریخِ دروغین
     * بسازد — یک `created_at` غلط در مانیفست از نبودش بدتر است.
     */
    private function createdAtOf(string $fileName): ?string
    {
        if (preg_match('/-\d{8}-\d{6}\.dump$/', $fileName, $m) !== 1) {
            return null;
        }

        $stamp = substr($fileName, -15, 15);
        $parsed = \DateTimeImmutable::createFromFormat('Ymd-His', $stamp, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->format(DATE_ATOM);
    }

    /** URL (که رمز دارد) نباید در پیام خطا یا لاگ نشت کند. */
    private function redact(string $text, string $url): string
    {
        $text = str_replace($url, '[DATABASE_URL]', $text);

        if (preg_match('#//[^/@:]+:([^@/]+)@#', $url, $m) === 1) {
            $text = str_replace($m[1], '***', $text);
        }

        return $text;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }

    /**
     * @param  array{file?: ?string}  $extra
     * @return array{ok: bool, message: string, file: ?string, removed: list<string>, error: ?string}
     */
    private function fail(string $message, array $extra = []): array
    {
        $this->errors[] = $message;
        Log::error('backup.run failed', ['message' => $message]);

        return [
            'ok' => false,
            'message' => $message,
            'file' => $extra['file'] ?? null,
            'removed' => [],
            'error' => $message,
        ];
    }
}
