<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H20 — تبِ «پشتیبان»: فهرست/بکاپ دستی/دانلود + مرز مجوز.
 *
 * `pg_dump` واقعی در کانتینر تست نیست، پس مثل `BackupRunTest` یک اسکریپت شل
 * به‌عنوان `backup.binary` می‌گذاریم. یعنی مسیر واقعیِ `proc_open` و نوشتن
 * اتمیک همان است که در تولید اجرا می‌شود.
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

    public function test_manual_backup_creates_a_file_and_appears_in_the_listing(): void
    {
        $this->fakeBinary('printf PGDMP-payload > "$out"');
        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');

        $run = $auth->postJson('/api/v1/admin/settings/backups');
        $run->assertCreated();

        $file = (string) $run->json('data.file');
        $this->assertStringEndsWith('.dump', $file);
        $this->assertSame('PGDMP-payload', file_get_contents($this->dir.'/'.$file));

        $auth->getJson('/api/v1/admin/settings/backups')
            ->assertOk()
            ->assertJsonCount(1, 'data.backups')
            ->assertJsonPath('data.backups.0.file', $file)
            ->assertJsonPath('data.backups.0.database', 'cms')
            ->assertJsonPath('data.schedule.enabled', true)
            ->assertJsonPath('data.schedule.keep', 7)
            ->assertJsonPath('data.schedule.last_file', $file);
    }

    public function test_schedule_status_reports_disabled_without_database_url(): void
    {
        config(['backup.database_url' => null]);

        $this->actingAs($this->user(['settings.view']), 'sanctum')
            ->getJson('/api/v1/admin/settings/backups')
            ->assertOk()
            ->assertJsonCount(0, 'data.backups')
            ->assertJsonPath('data.schedule.enabled', false)
            ->assertJsonPath('data.schedule.last_run', null);
    }

    public function test_a_failed_manual_backup_reports_a_persian_message_and_writes_nothing(): void
    {
        config(['backup.database_url' => null]);
        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');

        $res = $auth->postJson('/api/v1/admin/settings/backups');
        $res->assertStatus(422);
        $this->assertStringContainsString('DATABASE_URL', (string) $res->json('message'));

        $auth->getJson('/api/v1/admin/settings/backups')->assertJsonCount(0, 'data.backups');
    }

    public function test_download_streams_the_exact_backup_bytes(): void
    {
        $this->fakeBinary('printf DOWNLOAD-ME-EXACTLY > "$out"');
        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');

        $file = (string) $auth->postJson('/api/v1/admin/settings/backups')->json('data.file');

        $dl = $auth->get('/api/v1/admin/settings/backups/'.$file.'/download');
        $dl->assertOk();

        $this->assertSame('DOWNLOAD-ME-EXACTLY', $dl->streamedContent());
        $this->assertStringContainsString('attachment', (string) $dl->headers->get('content-disposition'));
        $this->assertStringContainsString($file, (string) $dl->headers->get('content-disposition'));
    }

    public function test_download_requires_settings_edit_permission(): void
    {
        $this->fakeBinary('printf PGDMP > "$out"');
        $admin = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');
        $file = (string) $admin->postJson('/api/v1/admin/settings/backups')->json('data.file');

        $this->actingAs($this->user(['settings.view']), 'sanctum')
            ->get('/api/v1/admin/settings/backups/'.$file.'/download')
            ->assertForbidden();
    }

    public function test_download_never_serves_an_arbitrary_file(): void
    {
        $this->fakeBinary('printf PGDMP > "$out"');
        $auth = $this->actingAs($this->user(['settings.view', 'settings.edit']), 'sanctum');
        $auth->postJson('/api/v1/admin/settings/backups')->assertCreated();

        // فایلی روی همان دیسک که «پشتیبان» نیست.
        file_put_contents($this->dir.'/secret.txt', 'TOP-SECRET');

        foreach ([
            'secret.txt',
            '..%2F..%2F.env',
            'nonexistent.dump',
        ] as $id) {
            $auth->get('/api/v1/admin/settings/backups/'.$id.'/download')->assertNotFound();
        }
    }
}
