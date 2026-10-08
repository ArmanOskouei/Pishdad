<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * F4.2.B — شکلِ استانداردِ `DatabaseChannel`.
 *
 * ## چرا این کلاس این‌قدر خالی است
 *
 * لاراول برای `DatabaseChannel` فقط `database()` و `id`/`data` می‌خواهد. عنوان و
 * بدنه هم **ستونِ جدا** دارند (`F4.2.B` migration دوم) و لینک از allowlist عبور
 * کرده. پس این کلاس هیچ کاری نمی‌کند جز اینکه بگوید «کدام کلیدِ کاتالوگ» — و
 * همین **کلید** است که در DB می‌نشیند.
 *
 * ⭐ و همین تفاوتِ کوچک، رفتارِ درست را می‌سازد: `PluginNotification` (K5.8)
 * عنوان/بدنه را در `data` می‌نویسد و رندرِ امروزِ کاتالوگ را نمی‌بیند. این یکی
 * عنوان/بدنه را از `data` **نمی‌خواند** — پس وقتی متنِ یک قالب بهتر شد، اعلانِ
 * ۶ ماه پیش هم با متنِ جدید نمایش داده می‌شود و **یک** منبعِ حقیقت داریم.
 *
 * `getKey()` لازم است چون `Notification::create()` برای گروه‌بندی (`markAsRead`
 * روی چند سطر) از آن استفاده می‌کند.
 */
class CatalogNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, scalar|null>  $values  دادهٔ رویداد، فقط مقدار.
     */
    public function __construct(
        public readonly string $catalogKey,
        public readonly array $values = [],
    ) {}

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function via(object $notifiable): array
    {
        // فقط کانالِ داخلی. ایمیل/SMS/push از مسیرِ outbox می‌روند
        // (`NotificationDispatcher`) چون تحویلِ بیرونی retry و ادعا دارد، و
        // `Notification::via` هیچ‌کدام را ندارد.
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'catalog_key' => $this->catalogKey,
            'values' => $this->values,
        ];
    }

    public function getKey(): string
    {
        return $this->catalogKey;
    }
}
