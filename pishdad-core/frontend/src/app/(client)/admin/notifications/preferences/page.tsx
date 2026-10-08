import { NotificationsTabs } from "../NotificationInbox";
import { NotificationPreferences } from "../NotificationPreferences";

/**
 * F4.2.F — ترجیحاتِ اعلان (`/admin/notifications/preferences`).
 *
 * مسیرِ جدا و نه تبِ کلاینتی: چهار کارتِ ۵گانه با `PUT`، آدرسِ مستقلِ قابلِ
 * اشتراک و قابلِ بازگشت با دکمهٔ «قبل» لازم دارد. ضمناً یک صفحهٔ موتور-جستجو
 * (`AdminSearch`) این مسیر را به‌عنوان مقصد می‌شناسد.
 */
export default function NotificationPreferencesPage() {
  return (
    <div>
      <div className="page-head">
        <div>
          <h1>ترجیحات اعلان</h1>
          <p>هر گروه، هر کانال. خاموشی همیشه انتخابِ شماست — نه نبودِ سطر.</p>
        </div>
      </div>
      <NotificationsTabs active="preferences" />
      <NotificationPreferences />
    </div>
  );
}
