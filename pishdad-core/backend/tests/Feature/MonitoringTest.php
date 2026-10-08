<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * J5 — مسیرِ قدیمیِ heartbeat در هسته **گم شده** و نباید برگردد.
 *
 * ⚠️ این تنها تستِ باقی‌ماندهٔ این فایل است. بقیهٔ تست‌های مانیتورینگ (ثبتِ
 * heartbeat، شبکهٔ سلامت، لاگِ متمرکز) روی مسیرها و مدل‌های **افزونهٔ «پنل
 * مرکزی»** بودند؛ آن افزونه از مسیر رفت و آن تست‌ها هم با آن رفتند — نه یک
 * core assertion که اینجا جا مانده باشد.
 *
 * آنچه **هسته** مالکش است و هنوز باید قفل شود، همین یکی است: نبودنِ مسیر.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * انتظار `404` است نه `401`: `401` یعنی مسیر هست و فقط احراز هویت خورده.
     */
    public function test_the_old_core_heartbeat_path_is_gone(): void
    {
        foreach (['/api/v1/internal/heartbeat', '/api/internal/heartbeat'] as $uri) {
            $this->postJson($uri, [])->assertNotFound(
                "«{$uri}» در هسته نباید وجود داشته باشد. heartbeat یک قابلیتِ افزونه"
                .' است، نه مسیرِ هسته — وگرنه یک نصبِ عمومیِ بدونِ افزونه هم از راه'
                .' هسته پاسخ می‌داد.'
            );
        }
    }
}
