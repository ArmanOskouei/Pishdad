<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * K5.8 — اعلانی که افزونه‌ها می‌سازند.
 *
 * ## چرا یک کلاس و نه ذخیرهٔ مستقیم در جدول
 *
 * جدول `notifications` استاندارد Laravel است: `uuid`, `type`, `morphs`, `data`,
 * `read_at`. نوشتن مستقیم در `data` یعنی ساختن ردیف با `type` دلخواه، و آن‌وقت
 * هر کدی می‌تواند رکوردی بنویسد که `toArray()` آن را نمی‌فهمد یا بدتر، رکوردی
 * بنویسد که UI به آن اعتماد می‌کند ولی ساختارش غلط است.
 *
 * با یک کلاس، `type` همیشه همین است و `data` همیشه همین شکل. افزونه فقط
 * **ورودی** می‌دهد، نه ساختار ذخیره‌شده.
 *
 * ## چرا `notification_id` و نه `href`
 *
 * همان قاعدهٔ K6.2: افزونه مسیر نمی‌دهد. یک `notification_id` می‌دهد و هسته
 * آن را به مسیرِ یک ابزار/صفحهٔ ثبت‌شده نگاشت می‌کند. مسیر دلخواه یعنی لینک
 * به هر جا، از جمله مسیرهایی که افزونه مالکشان نیست.
 */
class PluginNotification extends Notification
{
    use Queueable;

    /**
     * شدت‌های مجاز. سه‌تا کافی است و عمداً کم: هر شدت بیشتر یعنی یک رنگ و
     * یک تصمیم در UI که باید پیاده شود.
     */
    public const SEVERITIES = ['info', 'warning', 'critical'];

    public function __construct(
        private string $title,
        private ?string $body,
        private string $severity,
        private ?int $notificationId,
        private ?string $actionLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'severity' => $this->severity,
            // `null` یعنی «بدون مقصد» و UI کارت غیرقابل‌کلیک می‌کشد.
            'notification_id' => $this->notificationId,
            'action_label' => $this->actionLabel,
        ];
    }

    /** `@param array<string, mixed> $payload` */
    public static function fromPayload(array $payload): self
    {
        $severity = is_string($payload['severity'] ?? null) ? $payload['severity'] : 'info';
        if (! in_array($severity, self::SEVERITIES, true)) {
            $severity = 'info';
        }

        $id = $payload['notification_id'] ?? null;

        return new self(
            title: is_string($payload['title'] ?? null) ? $payload['title'] : '',
            body: is_string($payload['body'] ?? null) ? $payload['body'] : null,
            severity: $severity,
            // فقط `int` مثبت. رشتهٔ عددی رد می‌شود چون بعداً به مسیر تبدیل
            // می‌شود و نوعش باید قطعی باشد — همان قاعدهٔ K6.2.
            notificationId: is_int($id) && $id > 0 ? $id : null,
            actionLabel: is_string($payload['action_label'] ?? null) ? $payload['action_label'] : null,
        );
    }
}
