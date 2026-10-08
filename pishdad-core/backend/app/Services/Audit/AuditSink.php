<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * خوانندهٔ رویدادهای ممیزی — رابطِ سمتِ افزونه.
 *
 * ## چرا رابط لازم است
 *
 * هسته باید بداند **چه** اتفاقی افتاده (کاربر، اقدام، متادیتا، IP). اینکه آن رویداد
 * **کجا** بنشیند — لاگِ لاراول، جدولِ مرکز، S3 — تصمیمِ افزونه است.
 *
 * پیش‌تر این تفکیک نبود و هسته مستقیم مدلِ افزونه را صدا می‌زد. نتیجه: حذفِ
 * افزونه یعنی حذفِ لاگِ امنیتی — که دقیقاً برعکسِ چیزی بود که می‌خواستیم.
 *
 * @see AuditTrail  نقطهٔ ورودِ هسته
 */
interface AuditSink
{
    /**
 * @param  array{user_id: int|null, action: string, meta: array, ip: string|null, at: string}  $payload
 */
    public function record(array $payload): void;
}