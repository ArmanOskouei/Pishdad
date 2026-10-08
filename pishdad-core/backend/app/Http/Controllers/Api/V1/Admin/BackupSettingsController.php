<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupRunner;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * WF-H20 — تبِ «پشتیبان» در تنظیمات: بکاپ دستی + دانلود نسخه + وضعیت زمان‌بندی.
 *
 * پیاده‌سازی دومی از پشتیبان‌گیری وجود ندارد: ساختن، فهرست‌کردن و یافتن نسخه
 * همه از `BackupRunner` می‌آید. این کنترلر فقط مرزِ HTTP و مجوز است.
 */
class BackupSettingsController extends Controller
{
    public function __construct(private BackupRunner $runner) {}

    /** فهرست نسخه‌های موجود + وضعیت پشتیبان زمان‌بندی‌شده. */
    public function index(): JsonResponse
    {
        $backups = array_map(
            static fn (array $b): array => [
                'id' => $b['id'],
                'file' => $b['file'],
                'bytes' => $b['bytes'],
                'sha256' => $b['sha256'],
                'database' => $b['database'],
                'created_at' => $b['created_at'],
            ],
            $this->runner->listBackups(),
        );

        return response()->json([
            'data' => [
                'backups' => $backups,
                'schedule' => $this->scheduleStatus($backups),
            ],
        ]);
    }

    /** اجرای بکاپ دستی. */
    public function store(): JsonResponse
    {
        try {
            $result = $this->runner->run();
        } catch (Throwable $e) {
            // fail-closed: استثنای پیش‌بینی‌نشده هم پیام فارسی می‌دهد، نه ۵۰۰ خام.
            return response()->json(['message' => 'پشتیبان‌گیری انجام نشد: '.$e->getMessage()], 422);
        }

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => [
                'file' => $result['file'] !== null ? basename((string) $result['file']) : null,
                'removed' => $result['removed'],
            ],
        ], 201);
    }

    /**
     * دانلود یک نسخه — فقط اگر همان نسخه در فهرست دیسک وجود داشته باشد.
     *
     * فایل به‌صورت جریانی (`fpassthru`) فرستاده می‌شود؛ هیچ آرشیو بزرگی در
     * حافظه بار نمی‌شود. شناسه، نامِ فایل است و تنها از روی فهرستِ خود پوشه
     * تطبیق داده می‌شود، پس `../` هرگز به فایلِ دلخواه نمی‌رسد.
     */
    public function download(string $backup): StreamedResponse|JsonResponse
    {
        $entry = $this->runner->findBackup($backup);

        if ($entry === null || ! is_file($entry['path'])) {
            return response()->json(['message' => 'نسخهٔ پشتیبان یافت نشد.'], 404);
        }

        $path = $entry['path'];
        $name = $entry['file'];

        return response()->streamDownload(function () use ($path): void {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            fpassthru($handle);
            fclose($handle);
        }, $name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $backups
     * @return array<string, mixed>
     */
    private function scheduleStatus(array $backups): array
    {
        $url = config('backup.database_url');
        $enabled = is_string($url) && trim($url) !== '';
        $latest = $backups[0] ?? null;

        return [
            'enabled' => $enabled,
            'at' => '03:15',
            'keep' => (int) config('backup.keep', 7),
            'last_run' => $latest['created_at'] ?? null,
            'last_file' => $latest['file'] ?? null,
        ];
    }
}
