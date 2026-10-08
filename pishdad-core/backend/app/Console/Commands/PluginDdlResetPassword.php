<?php

namespace App\Console\Commands;

use App\Services\Plugins\PluginDdlConnection;
use App\Services\Plugins\PluginDdlProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * K5.12 — بازنشانیِ عمدیِ رمز نقش DDL افزونه.
 *
 * ## چرا این فرمان وجود دارد
 *
 * `ensureRole()` **عمداً** رمز را خودسرانه بازنشانی نمی‌کند. اگر `PLUGIN_DDL_PASSWORD`
 * در `.env` با رمز دیتابیس نخواند، خطای صریح می‌دهد و همین‌جا می‌ایستد.
 *
 * دلیلش این است که بازنشانیِ خودکار یعنی هر بار که `.env` بازتولید شود (که در
 * نصب تازه و در بعضی ابزارهای استقرار اتفاق می‌افتد) رمز دیتابیس هم عوض شود. تغییر
 * پنهانِ دیتابیس از راه یک استقرار، بدتر از خطای صریحی است که آدم بخواند.
 *
 * پس این یک عملیاتِ جدا و **خواسته** است.
 */
class PluginDdlResetPassword extends Command
{
    protected $signature = 'plugin-ddl:reset-password {--check : فقط بررسی کن، چیزی را تغییر نده}';

    protected $description = 'بازنشانی رمز نقش '.PluginDdlConnection::ROLE.' (DDL افزونه) از روی PLUGIN_DDL_PASSWORD';

    public function handle(PluginDdlProvisioner $provisioner): int
    {
        $role = PluginDdlConnection::ROLE;
        $check = (bool) $this->option('check');

        if ($check) {
            if (! $provisioner->roleExists()) {
                $this->error("نقش «{$role}» وجود ندارد.");

                return self::FAILURE;
            }

            if (app(PluginDdlConnection::class)->isAvailable()) {
                $this->info("نقش «{$role}» با رمز پیکربندی سالم است.");

                return self::SUCCESS;
            }

            $this->error("نقش «{$role}» هست ولی رمز پیکربندی به آن وصل نمی‌شود.");
            $this->line('  برای بازنشانی، همین فرمان را بدون --check اجرا کن.');

            return self::FAILURE;
        }

        try {
            $provisioner->resetPassword();
        } catch (Throwable $e) {
            $this->error('بازنشانی نشد: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("رمز نقش «{$role}» از روی پیکربندی بازنشانی شد.");
        $this->line('  یادآوری: دیتابیس تغییر کرد. اگر نسخه‌ای از این تغییر باید در کنترل نسخه باشد، الان وقت ثبت آن است.');

        return self::SUCCESS;
    }
}
