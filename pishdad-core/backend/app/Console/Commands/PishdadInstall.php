<?php

namespace App\Console\Commands;

use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\Install\InstallJournal;
use App\Http\Controllers\Install\Preflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * F1.1.F — نصب از خط فرمان: `php artisan pishdad:install`.
 *
 * همان گام‌های وب، با resume از ژورنال: گام‌های done رد می‌شوند.
 * سوپرادمین اینلاین ساخته می‌شود (نه SuperAdminSeeder) و ورود اولش
 * راه‌اندازی 2FA را اجبار می‌کند.
 */
class PishdadInstall extends Command
{
    protected $signature = 'pishdad:install
        {--db-host= : هاست Postgres}
        {--db-port= : پورت Postgres}
        {--db-database= : نام دیتابیس}
        {--db-username= : کاربر دیتابیس}
        {--db-password= : رمز دیتابیس}
        {--admin-name= : نام سوپرادمین}
        {--admin-email= : ایمیل سوپرادمین}
        {--admin-password= : رمز سوپرادمین}
        {--fresh : شروع دوباره از ژورنال خالی (فقط قبل از قفل)}';

    protected $description = 'نصب تعاملی هستهٔ پیشداد (preflight → database → app_key → migrate → superadmin → finalize)';

    public function handle(): int
    {
        if (InstallJournal::isInstalled()) {
            $this->error('نصب قبلاً کامل شده است (install.lock موجود است).');

            return self::FAILURE;
        }

        if ((bool) $this->option('fresh')) {
            @unlink(InstallJournal::journalPath());
            $this->line('ژورنال پاک شد؛ از اول شروع می‌کنیم.');
        }

        // گام ۱: preflight
        if (! InstallJournal::isStepDone('preflight')) {
            $this->info('گام ۱/۶ — پیش‌نیازها');
            $checks = Preflight::run();
            $this->table(
                ['چک', 'وضعیت', 'جزئیات', 'راه‌حل'],
                array_map(fn (array $c) => [$c['label'], $c['status'], $c['detail'], $c['remedy']], $checks)
            );
            if (Preflight::blockingFailures($checks)) {
                $this->error('پیش‌نیازهای حیاتی برقرار نیست؛ بعد از رفع، دوباره اجرا کنید (resume خودکار).');

                return self::FAILURE;
            }
            InstallJournal::markStepDone('preflight');
        } else {
            $this->line('گام ۱/۶ — پیش‌نیازها: قبلاً انجام شده (resume).');
        }

        // گام ۲: database
        if (! InstallJournal::isStepDone('database')) {
            $this->info('گام ۲/۶ — پایگاه داده');
            $db = $this->askDb();
            $probe = Preflight::run($db);
            $conn = $this->checkOf($probe, 'db_connection');
            $collation = $this->checkOf($probe, 'db_collation_privilege');
            if (($conn['status'] ?? '') !== 'pass' || ($collation['status'] ?? '') !== 'pass') {
                $this->error('اتصال/مجوز کافی نیست: '.($conn['detail'] ?? '').' / '.($collation['detail'] ?? ''));

                return self::FAILURE;
            }
            InstallJournal::put('db', $db);
            InstallController::writeEnv([
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => (string) $db['host'],
                'DB_PORT' => (string) $db['port'],
                'DB_DATABASE' => (string) $db['database'],
                'DB_USERNAME' => (string) $db['username'],
                'DB_PASSWORD' => (string) $db['password'],
            ]);
            InstallJournal::markStepDone('database');
        } else {
            $this->line('گام ۲/۶ — پایگاه داده: قبلاً انجام شده (resume).');
        }

        // گام ۳: app_key
        if (! InstallJournal::isStepDone('app_key')) {
            $this->info('گام ۳/۶ — کلید برنامه');
            $key = (string) (env('APP_KEY', ''));
            if (! str_starts_with($key, 'base64:')) {
                $key = 'base64:'.base64_encode(random_bytes(32));
                InstallController::writeEnv(['APP_KEY' => $key]);
                config(['app.key' => $key]);
                $this->line('کلید تازه ساخته و در .env ذخیره شد.');
            } else {
                $this->line('کلید موجود است؛ دست نمی‌زنیم.');
            }
            InstallJournal::markStepDone('app_key');
        } else {
            $this->line('گام ۳/۶ — کلید برنامه: قبلاً انجام شده (resume).');
        }

        // گام ۴: migrate
        if (! InstallJournal::isStepDone('migrate')) {
            $this->info('گام ۴/۶ — مهاجرت‌ها');
            try {
                $exit = Artisan::call('migrate', ['--force' => true]);
                $this->line((string) Artisan::output());
                if ($exit !== 0) {
                    throw new \RuntimeException('migrate exit='.$exit);
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('اجرای مهاجرت‌ها ناموفق بود.');

                return self::FAILURE;
            }
            InstallJournal::markStepDone('migrate');
        } else {
            $this->line('گام ۴/۶ — مهاجرت‌ها: قبلاً انجام شده (resume).');
        }

        // گام ۵: superadmin
        if (! InstallJournal::isStepDone('superadmin')) {
            $this->info('گام ۵/۶ — سوپرادمین');
            $name = $this->optOrAsk('admin-name', 'نام نمایشی', 'سوپرادمین');
            $email = $this->optOrAsk('admin-email', 'ایمیل', null, true);
            $password = $this->option('admin-password') ?: $this->secret('رمز عبور (حداقل ۸ نویسه)');
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('ایمیل معتبر نیست.');

                return self::FAILURE;
            }
            if (! is_string($password) || strlen($password) < 8) {
                $this->error('رمز عبور باید حداقل ۸ نویسه باشد.');

                return self::FAILURE;
            }
            $user = InstallController::createFirstAdmin((string) $name, $email, $password);
            InstallJournal::put('superadmin', ['email' => $user->email, 'id' => $user->id]);
            InstallJournal::markStepDone('superadmin');
        } else {
            $this->line('گام ۵/۶ — سوپرادمین: قبلاً انجام شده (resume).');
        }

        // گام ۶: finalize
        InstallJournal::markStepDone('finalize');
        InstallJournal::writeLock(['email' => InstallJournal::get('superadmin')['email'] ?? null]);

        $this->info('نصب کامل شد.');
        $this->warn('ورود اول سوپرادمین نیازمند فعال‌سازی تأیید دومرحله‌ای است (profile/2fa/enable).');

        return self::SUCCESS;
    }

    /** @return array{host:string,port:int|string,database:string,username:string,password:string} */
    private function askDb(): array
    {
        $current = Preflight::dbConfig();

        return [
            'host' => $this->optOrAsk('db-host', 'هاست دیتابیس', (string) ($current['host'] ?? '127.0.0.1')),
            'port' => $this->optOrAsk('db-port', 'پورت', (string) ($current['port'] ?? '5432')),
            'database' => $this->optOrAsk('db-database', 'نام دیتابیس', (string) ($current['database'] ?? ''), true),
            'username' => $this->optOrAsk('db-username', 'کاربر', (string) ($current['username'] ?? ''), true),
            'password' => $this->option('db-password') ?? $this->secret('رمز دیتابیس (خالی = بدون رمز)') ?? '',
        ];
    }

    private function optOrAsk(string $option, string $question, ?string $default, bool $required = false): string
    {
        $value = $this->option($option);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $answer = $this->ask($question, $default);
        if ($required) {
            while (! is_string($answer) || trim($answer) === '') {
                $answer = $this->ask($question.' (الزامی)', $default);
            }
        }

        return (string) $answer;
    }

    /** @param list<array{key:string,label:string,status:string,detail:string,remedy:string}> $checks */
    private function checkOf(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? '') === $key) {
                return $check;
            }
        }

        return ['status' => 'fail', 'detail' => 'چک یافت نشد.'];
    }
}
