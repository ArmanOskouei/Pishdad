<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * E5 — `backup:run`.
 *
 * `pg_dump` واقعی در کانتینر تست نیست، پس این تست یک **اسکریپت شل** به‌عنوان
 * `backup.binary` می‌سازد. یعنی مسیرِ واقعیِ `proc_open`، `escapeshellarg`،
 * خواندنِ stderr و نوشتنِ اتمیک همه واقعاً اجرا می‌شوند — و سه حالتِ ممکنِ یک
 * dump (موفق، خالی، نیم‌کاره‌و‌مرده) قابل بازتولید می‌شوند.
 */
class BackupRunTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing/backup-'.uniqid());
        File::ensureDirectoryExists($this->dir);

        config([
            'backup.database_url' => 'postgres://cms:secret@db:5432/cms',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_fails_closed_when_no_database_url_is_configured(): void
    {
        config(['backup.database_url' => null]);
        $this->fakeBinary('printf PGDMP > "$out"');   // اصلاً نباید صدا زده شود

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(1, $code, 'بدون DATABASE_URL نباید موفق گزارش شود.');
        $this->assertStringContainsString('DATABASE_URL', Artisan::output());
        $this->assertSame([], $this->dumps(), 'هیچ فایلی نباید ساخته شده باشد.');
    }

    public function test_it_fails_closed_when_the_configured_binary_is_missing(): void
    {
        config(['backup.binary' => '/nonexistent/path/pg_dump']);

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('pg_dump', Artisan::output());
        $this->assertSame([], $this->dumps());
    }

    public function test_it_writes_a_dump_and_a_manifest_with_a_checksum(): void
    {
        $this->fakeBinary('printf PGDMP-fake-payload > "$out"');

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(0, $code, Artisan::output());

        $dumps = $this->dumps();
        $this->assertCount(1, $dumps);
        $this->assertStringStartsWith('cms-', basename($dumps[0]));
        $this->assertSame('PGDMP-fake-payload', file_get_contents($dumps[0]));

        $manifest = $this->manifest();
        $this->assertSame(basename($dumps[0]), $manifest['backups'][0]['file']);
        $this->assertSame('cms', $manifest['backups'][0]['database']);
        $this->assertSame(hash('sha256', 'PGDMP-fake-payload'), $manifest['backups'][0]['sha256']);
        $this->assertNotEmpty($manifest['backups'][0]['created_at']);
    }

    /**
     * ⭐ `pg_dump` واقعاً با همان URL و قالبِ درست اجرا می‌شود.
     *
     * اینجا باینریِ واقعی در `PATH` نیست، پس یک اسکریپت به‌جایش می‌گذاریم که
     * آرگومان‌های واقعیِ `proc_open` را روی دیسک می‌نویسد. اگر کسی `--dbname`
     * یا `--format=custom` را از `BackupRunner::dump()` بردارد، این تست می‌شکند.
     */
    public function test_it_invokes_pg_dump_with_the_database_url_and_custom_format(): void
    {
        $argsFile = $this->dir.'/argv.txt';

        $this->fakeBinary(
            'printf "%s\n" "$@" > '.escapeshellarg($argsFile).PHP_EOL
            .'printf PGDMP > "$out"'
        );

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(0, $code, Artisan::output());

        $argv = (string) file_get_contents($argsFile);

        $this->assertStringContainsString('--dbname=postgres://cms:secret@db:5432/cms', $argv);
        $this->assertStringContainsString('--format=custom', $argv);
        $this->assertStringContainsString('--no-owner', $argv);
        $this->assertStringContainsString('--no-privileges', $argv);
        $this->assertStringContainsString('--file='.$this->dir, $argv);
    }

    public function test_a_failed_dump_never_leaves_a_file_that_looks_like_a_backup(): void
    {
        // سناریوی واقعی: pg_dump نیم‌کاره می‌نویسد و بعد می‌میرد.
        $this->fakeBinary('printf half-written > "$out"'.PHP_EOL.'echo "FATAL: cannot connect" >&2'.PHP_EOL.'exit 1');

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('کد خطای 1', Artisan::output());
        $this->assertSame([], $this->dumps(), 'فایل نیم‌کاره نباید به اسم .dump دیده شود.');
        $this->assertSame([], $this->leftovers(), 'فایل .tmp هم باید پاک شود.');
        $this->assertFileDoesNotExist($this->dir.'/manifest.json', 'مانیفستِ بی‌دیتابیس یعنی وعدهٔ دروغین.');
    }

    public function test_an_empty_dump_is_refused(): void
    {
        $this->fakeBinary('exit 0');

        $code = Artisan::call('backup:run', ['--path' => $this->dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('خالی', Artisan::output());
        $this->assertSame([], $this->dumps());
    }

    public function test_rotation_keeps_only_the_requested_number_of_copies(): void
    {
        foreach (['20260101', '20260102', '20260103'] as $day) {
            file_put_contents($this->dir.'/cms-'.$day.'-030000.dump', 'old');
        }
        $this->fakeBinary('printf fresh > "$out"');

        $code = Artisan::call('backup:run', ['--path' => $this->dir, '--keep' => '2']);
        $output = Artisan::output(); // یک‌بار می‌خوانیم: `fetch()` بافر را خالی می‌کند.

        $this->assertSame(0, $code, $output);
        $this->assertCount(2, $this->dumps(), 'سقف --keep باید رعایت شود.');
        $this->assertStringContainsString('نسخهٔ قدیمی حذف شد', $output);

        // مانیفست آینهٔ دیسک است: همان دو نسخهٔ بازمانده، نه نسخهٔ حذف‌شده — و
        // به ترتیب تازه‌به‌کهنه.
        $listed = array_column($this->manifest()['backups'], 'file');
        $this->assertCount(2, $listed, 'مانیفست نباید نسخه‌ای را فهرست کند که حذف شده است.');
        $this->assertSame(
            array_map('basename', $this->dumps()),
            $this->sorted($listed),
            'مانیفست باید دقیقاً همان دو نسخهٔ بازمانده را فهرست کند.'
        );
        $this->assertStringContainsString('cms-20260103', $listed[1] ?? '', 'نسخهٔ قدیمی‌تر باید ته فهرست باشد.');
    }

    public function test_the_password_never_reaches_the_error_message(): void
    {
        config(['backup.database_url' => 'postgres://cms:sup3rs3cret@db:5432/cms']);
        $this->fakeBinary('echo "FATAL: could not connect to $1" >&2'.PHP_EOL.'exit 1');

        Artisan::call('backup:run', ['--path' => $this->dir]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('sup3rs3cret', $output);
        $this->assertStringContainsString('DATABASE_URL', $output);
    }

    /**
     * یک اسکریپت شل به‌عنوان `pg_dump` می‌سازد.
     *
     * بدنهٔ اسکریپت آرگومان‌های واقعیِ فرمان را می‌بیند، پس ثابت می‌شود که
     * `--dbname` و `--file` درست و یک‌بار به ابزار می‌رسند.
     */
    private function fakeBinary(string $body): void
    {
        $path = $this->dir.'/fake-pg-dump.sh';
        file_put_contents($path, "#!/bin/sh".PHP_EOL.$this->targetArg().PHP_EOL.$body.PHP_EOL);
        chmod($path, 0755);

        config(['backup.binary' => $path]);
    }

    /** مقدارِ `--file=` را در متغیر `$out` می‌گذارد. */
    private function targetArg(): string
    {
        return 'out=""'.PHP_EOL
            .'for a in "$@"; do'.PHP_EOL
            .'  case "$a" in --file=*) out="${a#--file=}";; esac'.PHP_EOL
            .'done'.PHP_EOL;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return (array) json_decode((string) file_get_contents($this->dir.'/manifest.json'), true);
    }

    /** @return list<string> */
    private function dumps(): array
    {
        return glob($this->dir.'/*.dump') ?: [];
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return glob($this->dir.'/*.tmp') ?: [];
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
