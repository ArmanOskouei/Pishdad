<?php

namespace Tests\Feature;

use App\Jobs\RunDatabaseBackup;
use App\Models\User;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupRunStore;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H20 / P-04 — تبِ «پشتیبان»: فهرست/بکاپ دستی/دانلود + مرز مجاز.
 *
 * `pg_dump` واقعی در کانتینر تست نیست، پس مثل `BackupRunTest` یک اسکریپت شل
 * به‌عنوان `backup.binary` می‌گذاریم. یعنی مسیر واقعیِ `proc_open` و نوشتن
 * اتمیک همان است که در تولید اجرا می‌شود.
 *
 * P-04: اجرای دستی حالا **صفی** است ⇒ پاسخ ۲۰۲ است و فایل وقتی آماده شد در
 * فهرست می‌آید. برای اینکه تست‌ها هم الزامِ صف را بسنجند و هم مسیرِ واقعیِ
 * اجرا را، Job را مستقیم صدا می‌زنیم (جای اینکه به درایورِ sync تکیه کنیم).
 */
class BackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);

        $this->dir = storage_path('app/testing/backup-settings-'.uniqid());
        File::ensureDirectoryExists($this->dir);

        config([
            'backup.database_url' => 'postgres://cms:secret@db:5432/cms',
            'backup.path' => $this->dir,
            'backup.keep' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @param list<string> $perms */
    private function user(array $perms): User
    {
        $user = User::query()->create([
            'name' => 'مدیر پشتیبان',
            'email' => 'backup'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo(...$perms);

        return $user->fresh();
    }

    private function fakeBinary(string $body): void
    {
        $path = $this->dir.'/fake-pg-dump.sh';
        file_put_contents($path, "#!/bin/sh".PHP_EOL.$this->targetArg().PHP_EOL.$body.PHP_EOL);
        chmod($path, 0755);

        config(['backup.binary' => $path]);
    }

    private function targetArg(): string
    {
        return 'out=""'.PHP_EOL
            .'for a in "$@"; do'.PHP_EOL
            .'  case "$a" in --file=*) out="${a#--file=}";; esac'.PHP_EOL
            .'done'.PHP_EOL;
    }

    public function test_listing_requires_settings_view_permission(): void
    {
        $this->actingAs($this->user(['media.view']), 'sanctum')
            ->getJson('/api/v1/admin/settings/backups')
            ->assertForbidden();
    }

    public function test_manual_backup_requires_settings_edit_permission(): void
    {
        $this->fakeBinary('printf PGDMP > "$out"');

        $this->actingAs($this->user(['settings.view']), 'sanctum')
            ->postJson('/api/v1/admin/settings/backups')
            ->assertForbidden();
    }

    public function test_manual_backup_is_queued_and_not_run_inline(): void
    {
        // P-04 — قراردادِ جدید: ۲۰۲ + Job صف می‌شود؛ هیچ pg_dump داخلِ درخواست.
        $this->fakeBinary('printf PGDMP-payload > "$out"');
        Bus::fake();

        $run = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum')
            ->postJson('/api/v1/admin/settings/backups');

        $run->assertStatus(202)->assertJsonPath('code', 'backup.queued');
        Bus::assertDispatched(RunDatabaseBackup::class);
        $this->assertSame([], glob($this->dir.'/*.dump') ?: [], 'هیچ فایلی داخل درخواست ساخته نشده باشد.');
    }

    public function test_the_job_creates_the_file_and_it_appears_in_the_listing(): void
    {
        $this->fakeBinary('printf PGDMP-payload > "$out"');

        // اجرای Job مثل کاری که ورکر انجام می‌دهد.
        (new RunDatabaseBackup(null, $this->dir))->handle(app(BackupRunner::class));

        $file = basename((string) (glob($this->dir.'/*.dump')[0] ?? ''));
        $this->assertStringEndsWith('.dump', $file);
        $this->assertSame('PGDMP-payload', file_get_contents($this->dir.'/'.$file));

        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');
        $auth->getJson('/api/v1/admin/settings/backups')
            ->assertOk()
            ->assertJsonCount(1, 'data.backups')
            ->assertJsonPath('data.backups.0.file', $file)
            ->assertJsonPath('data.backups.0.database', 'cms')
            ->assertJsonPath('data.schedule.enabled', true)
            ->assertJsonPath('data.schedule.keep', 7)
            ->assertJsonPath('data.schedule.last_file', $file)
            ->assertJsonPath('data.schedule.status', 'done');
    }

    public function test_a_failed_run_is_recorded_without_writing_a_backup_file(): void
    {
        $this->fakeBinary('exit 3');
        $this->assertSame('failed', $this->runExpectingFailure());
    }

    public function test_a_run_without_database_url_is_recorded_as_failed(): void
    {
        config(['backup.database_url' => null]);

        $this->assertSame('failed', $this->runExpectingFailure());
    }

    private function runExpectingFailure(): string
    {
        try {
            (new RunDatabaseBackup(null, $this->dir))->handle(app(BackupRunner::class));
        } catch (\Throwable $e) {
            // Job دوباره throw می‌کند تا ورکر آن را failed_jobs ثبت کند؛
            // وضعیتِ ثبتشده همان چیزی است که پnل نشان می‌دهد.
        }

        $state = BackupRunStore::read($this->dir);
        $this->assertSame('failed', $state['status'] ?? null);
        $this->assertSame([], glob($this->dir.'/*.dump') ?: []);

        return (string) ($state['status'] ?? '');
    }

    public function test_schedule_status_reports_disabled_without_database_url(): void
    {
        config(['backup.database_url' => null]);
        $this->actingAs($this->user(['settings.view']), 'sanctum')
            ->getJson('/api/v1/admin/settings/backups')
            ->assertJsonPath('data.schedule.enabled', false)
            ->assertJsonPath('data.schedule.last_run', null);
    }

    public function test_download_streams_the_exact_backup_bytes(): void
    {
        $this->fakeBinary('printf DOWNLOAD-ME-EXACTLY > "$out"');
        (new RunDatabaseBackup(null, $this->dir))->handle(app(BackupRunner::class));
        $file = basename((string) (glob($this->dir.'/*.dump')[0] ?? ''));

        $dl = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum')
            ->get('/api/v1/admin/settings/backups/'.$file.'/download');

        $dl->assertOk();
        $this->assertSame('DOWNLOAD-ME-EXACTLY', $dl->streamedContent());
        $this->assertStringContainsString('attachment', (string) $dl->headers->get('content-disposition'));
        $this->assertStringContainsString($file, (string) $dl->headers->get('content-disposition'));
    }

    public function test_download_requires_settings_edit_permission(): void
    {
        $this->fakeBinary('printf PGDMP > "$out"');
        (new RunDatabaseBackup(null, $this->dir))->handle(app(BackupRunner::class));
        $file = basename((string) (glob($this->dir.'/*.dump')[0] ?? ''));

        $this->actingAs($this->user(['settings.view']), 'sanctum')
            ->get('/api/v1/admin/settings/backups/'.$file.'/download')
            ->assertForbidden();
    }

    public function test_download_never_serves_an_arbitrary_file(): void
    {
        $this->fakeBinary('printf PGDMP > "$out"');
        $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum')
            ->postJson('/api/v1/admin/settings/backups')
            ->assertStatus(202);

        // فایلی روی همان دیسک که «پشتیبان» نیست.
        file_put_contents($this->dir.'/secret.txt', 'TOP-SECRET');

        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');
        foreach ([
            'secret.txt',
            '..%2F..%2F.env',
            'nonexistent.dump',
        ] as $id) {
            $auth->get('/api/v1/admin/settings/backups/'.$id.'/download')->assertNotFound();
        }
    }
}
