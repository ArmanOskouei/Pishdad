import { NotificationInbox, NotificationsTabs } from "./NotificationInbox";
import { PushSettingsCard } from "@/components/admin/PushSettingsCard";
import { WebhookSettingsCard } from "@/components/admin/WebhookSettingsCard";

/**
 * F4.2.F - صندوقچهٔ اعلان‌ها (`/admin/notifications`).
 *
 * چهار تب دارد:
 *
 * • **اعلان مرورگر** (P1.13) — تنظیم روشن/خاموش کردن push روی این دستگاه
 * • **وب‌هوک خروجی** (WF-L2) — ارسال رویدادهای نصب به یک URL امضاشده (n8n/Zapier)
 * • صندوقچه — اعلان‌های داخلی که از API می‌آیند
 * • ترجیحات — تنظیمات کانال و موضوع
 *
 * # چرا `serverOne`
 *
 * این صفحه `serverOne` است. یعنی `use server` ندارد و داده‌ها را با
 * `serverApi()` می‌گیرد. اگر کد را به `serverOne` تبدیل کنیم، دستگاه
 * اول به فرانت می‌رود و به همین دلیل کل صفحه می‌افتد.
 *
 * # احراز هویت
 *
 * همهٔ endpointهای این صفحه زیر `auth:sanctum` هستند و `user_id` از خودِ
 * نشست خوانده می‌شود — نه از ورودی کاربر. پس کاربر نمی‌تواند صندوقچهٔ
 * کس دیگری را بخواند.
 */
export default function NotificationsPage() {
  return (
    <div>
      <div className="page-head">
        <div>
          <h1>اعلان‌ها</h1>
          <p>تنظیمات اعلان و صندوقچهٔ پیام‌های داخلی پنل.</p>
        </div>
      </div>

      <PushSettingsCard />

      <WebhookSettingsCard />

      <NotificationsTabs active="inbox" />
      <NotificationInbox />
    </div>
  );
}