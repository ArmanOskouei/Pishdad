<?php

namespace App\Services\Subscription;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\CachedSettings;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Subscription;

/**
 * تک‌منبع وضعیت اشتراک (مرحله ۱ + تسک‌های ۲.۲ / ۴.۳).
 *
 * همه مصرف‌کننده‌ها (داشبورد مشتری، /site/status، polling قدیمی
 * subscription-status) وضعیت را از همین‌جا می‌گیرند تا دو منبع حقیقت
 * ساخته نشود. نگاشت عمومی: active|past_due → active و suspended|deleted → suspended.
 */
final class ClientSubscriptionResolver
{
    /** ردیف مشتری + اشتراک کاربر جاری (autoprovision مثل SubscriptionController). */
    public static function forUser(User $user): array
    {
        $client = Client::query()->firstOrCreate(
            ['site_id' => 'site-'.$user->id],
            [
                'name' => $user->name ?? 'سایت من',
                'tier' => 'budget',
                'status' => 'active',
                'owner_user_id' => $user->id,
            ]
        );

        $subscription = Subscription::query()->firstOrCreate(
            ['client_id' => $client->id],
            [
                'site_id' => $client->site_id,
                'status' => Subscription::ACTIVE,
                'due_at' => now()->addDays(30),
            ]
        );

        return [$client, $subscription->load('plan')];
    }

    /** اشتراک یک site_id مشخص (برای polling عمومی و /site/status). */
    public static function bySiteId(string $siteId): ?Subscription
    {
        return Subscription::query()->where('site_id', $siteId)->with('plan')->first();
    }

    /** اشتراک پیش‌فرض نصب تکی (وقتی site_id داده نشده). */
    public static function default(): ?Subscription
    {
        return Subscription::query()->with('plan')->oldest('id')->first();
    }

    /** وضعیت عمومی سبک — تنها نگاشت مجاز برای مصرف عمومی. */
    public static function publicStatus(Subscription $subscription): string
    {
        return in_array($subscription->status, [Subscription::SUSPENDED, Subscription::DELETED], true)
            ? 'suspended'
            : 'active';
    }

    /** پیام برند وضعیت: از عنوان تنظیمات سایت + fallback ثابت. */
    public static function statusMessage(Subscription $subscription, ?int $ownerUserId = null): string
    {
        $brand = 'وب‌سایت ما';

        $userId = $ownerUserId ?? $subscription->client?->owner_user_id;
        if ($userId) {
            // مشترک نصب: عنوان از تنظیمات سراسری (کلید global).
            $site = CachedSettings::remember('site', 'global', 300, fn () => Setting::get('site', 'global', []));
            if (! empty($site['title'])) {
                $brand = $site['title'];
            }
        }

        if (self::publicStatus($subscription) === 'suspended') {
            return "دسترسی به {$brand} موقتاً معلق شده است. لطفاً با پشتیبانی تماس بگیرید.";
        }

        return "{$brand} فعال است.";
    }
}
