# 📋 تسک‌های پیاده‌سازی — UIUX → Backend → Frontend

> **جریان کار:** هر تسک ۳ فاز دارد:
> ۱. **UIUX** — طراحی صفحه (بر اساس ADMIN-PAGES-SPEC.md + DESIGN-DIRECTIONS.md)
> ۲. **Backend** — ساخت API مطابق نیاز صفحه و طراحی
> ۳. **Frontend** — پیاده‌سازی نهایی مطابق طراحی و API
>
> ترتیب = اولویت‌بندی MVP «حلقه پول» (ADMIN-PAGES-SPEC.md بخش آخر)
>
> تاریخ: شهریور ۱۴۰۵

---

## 🎯 قوانین کلی (قبل از هر تسک)

- **کامپوننت‌های مشترک** (DataTable, SchemaForm, MediaPicker, DragDropList, ThemeCard, AppearancePanel, SaveBar...) در **مرحله ۰** طراحی و ساخته می‌شوند — همه تسک‌ها از آن‌ها استفاده می‌کنند
- **هسته UI مشترک:** دو پنل (مشتری + مرکزی) از یک هسته استفاده می‌کنند
- **هر فاز باید «تمام» تعریف شود** (Definition of Done در هر تسک)
- **RTL + فونت Vazirmatn + دارک/لایت** در همه صفحات
- **API ها باید contract-first** باشند: OpenAPI واحد + تولید تایپ TypeScript برای Next.js

---

# 🟦 مرحله ۰ — زیرساخت مشترک (پیش‌نیاز همه)

## تسک ۰.۱ — هسته UI (کامپوننت‌های مشترک)
- **UIUX:** طراحی ۴ کامپوننت قراردادی: `DataTable`, `SchemaForm`, `MediaPicker`, `DragDropList` + `ThemeCard`/`AppearancePanel` + `SaveBar`, `Modal/Drawer`, `ConfirmDialog`, `EmptyState`, `SegmentedControl`
- **Backend:** قرارداد OpenAPI هسته (auth, ui-settings) — بدون این، هیچ صفحه‌ای کار نمی‌کند
- **Frontend:** پیاده‌سازی کامپوننت‌ها + RTL + فونت + توکن‌های تم
- **DoD:** هر ۴ کامپوننت با داستانک تست شده + باندل عمومی بدون سنگینی

## تسک ۰.۲ — انتخاب جهت بصری
- **UIUX:** انتخاب از DESIGN-DIRECTIONS.md (پیشنهاد: «سپیده» مشتری + «عملیات» مرکزی)
- **Backend:** جدول `settings` گروه `ui` + `GET/PUT /api/ui-settings` (ذخیره سراسری نصب، مشترک بین مدیران)
- **Frontend:** پیاده‌سازی سیستم تم (CSS Variables + data-attributes + ضد FOUC)
- **DoD:** تغییر تم بدون رندر مجدد + ذخیره سراسری نصب + لایت/دارک

---

# 🟩 مرحله ۱ — هسته حلقه پول (اولین محصول قابل فروش)

> این ۴ صفحه «محصول + درآمد + مشتری» را تعریف می‌کنند. **گیت فروش:** یک مشتری واقعی Budget + پرداخت از درگاه + سایت لایو.

## تسک ۱.۱ — صفحه ۱.۵: مدیریت صفحات (ویرایشگر بلوکی) ⭐⭐
- **UIUX:** لیست صفحات + ویرایشگر Immersive (بوم بلوک‌ها + Inspector + نسخه‌ها + سئو) — الگوی Canvas+Inspector
- **Backend APIs:**
  - `GET /api/v1/admin/pages` (لیست + فیلتر/صفحه‌بندی)
  - `POST /api/v1/admin/pages` (ایجاد)
  - `GET /api/v1/admin/pages/{id}` (جزئیات + بلوک‌ها)
  - `PUT /api/v1/admin/pages/{id}` (ویرایش + بلوک‌ها + نسخه جدید)
  - `POST /api/v1/admin/pages/{id}/publish` (انتشار → revision + reindex)
  - `POST /api/v1/admin/pages/{id}/restore/{revision}` (بازگشت نسخه)
  - `GET /api/v1/admin/pages/{id}/revisions` (لیست نسخه‌ها)
  - `GET /api/v1/site/pages/{path}` (رندر عمومی — برای ISR)
  - `GET /api/v1/admin/blocks/schema` (فهرست بلوک‌های فعال + schema آن‌ها برای SchemaForm)
- **Frontend:** لیست + ویرایشگر بلوکی (DragDropList + SchemaForm + Jodit + MediaPicker) + پیش‌نمایش `/preview` + revalidate
- **DoD:** ساخت/ویرایش/انتشار/بازگشت صفحه + رندر ISR + پیش‌نمایش draft با توکن + cacheHandler Redis

## تسک ۱.۲ — صفحه ۱.۱۵: اشتراک من (قلب پرداخت)
- **UIUX:** کارت وضعیت پلن + دوره + فاکتورها + تاریخچه + حالت تعلیق (فقط این صفحه) + جریان پرداخت Stepper
- **Backend APIs:**
  - `GET /api/v1/admin/subscription` (وضعیت اشتراک + پلن + سررسید)
  - `GET /api/v1/admin/subscription/invoices` (لیست فاکتورها)
  - `POST /api/v1/admin/subscription/invoices/{id}/pay` (شروع پرداخت → session درگاه مرکزی)
  - `GET /api/v1/subscription-status?site_id=` (برای polling عمومی)
- **Frontend:** بخش «اشتراک من» + دکمه پرداخت → درگاه مرکزی + حالت تعلیق (suspension_mode)
- **DoD:** دیدن فاکتور + پرداخت موفق → فاکتور paid + رفع تعلیق خودکار

## تسک ۱.۳ — صفحه ۲.۴: صورتحساب/Billing مرکزی (فقط کارفرما)
- **UIUX:** Tab پنج‌گانه: پلن‌ها / فاکتورها / پرداخت‌ها / کنترل تعلیق / تنظیمات درگاه
- **Backend APIs (central-plugin):**
  - CRUD `GET/POST/PUT/DELETE /api/central/plans`
  - CRUD `GET/POST /api/central/invoices` + `PUT /api/central/invoices/{id}/status`
  - `GET /api/central/payments`
  - `GET /api/central/subscriptions/suspended` (مشتریان در حال تعلیق + روز باقی‌مانده)
  - `POST /api/central/subscriptions/{id}/suspend|resume|delete`
  - `GET/PUT /api/central/gateway-settings` (درگاه فعال + کلید ماسک + تست)
  - Webhook درگاه: `POST /api/webhooks/payment` (HMAC امضاشده)
  - Cron مرکزی: یادآوری D-7..D-0، تعلیق D-0، حذف D+7
- **Frontend:** ۵ تب + کنترل تعلیق (مهم‌ترین) + قابل چاپ فاکتور
- **DoD:** ساخت فاکتور → پرداخت مشتری → paid → کنترل تعلیق → حذف D+7 + اعلان‌ها

## تسک ۱.۴ — صفحه ۲.۲: مدیریت مشتریان مرکزی
- **UIUX:** Master-Detail با Drawer عریض + تب‌ها (اطلاعات/سرویس‌ها/بکاپ‌ها/فاکتورها) + ارتقا تیر + توکن پشتیبانی
- **Backend APIs (central-plugin):**
  - `GET /api/central/clients` (لیست + فیلتر تیر/وضعیت/سلامت)
  - `GET /api/central/clients/{id}` (جزئیات کامل)
  - `POST /api/central/clients` (ساخت مشتری + نصب)
  - `POST /api/central/clients/{id}/upgrade-tier` (ارتقا Budget→Standard)
  - `POST /api/central/clients/{id}/support-token` (توکن یکبارمصرف سوپرادمین)
  - `POST /api/central/clients/{id}/suspend|resume|delete`
  - `GET /api/central/clients/{id}/backups` + `POST .../backup` + `POST .../restore`
- **Frontend:** لیست + Drawer جزئیات + ارتقا (ConfirmDialog دو مرحله‌ای + progress) + توکن پشتیبانی (کپی + TTL)
- **DoD:** ساخت مشتری → دیپلوی → دیدن سرویس‌ها/بکاپ‌ها/فاکتورها → ارتقا تیر → توکن پشتیبانی

---

# 🟦 مرحله ۲ — چهارچوب UI (گیت ورود + اسکلت)

> شامل: ورود (گیت همه چیز)، داشبورد، تنظیمات پایه، قالب پنل مدیریت.

## تسک ۲.۱ — صفحه ۱.۱: ورود + 2FA
- **UIUX:** کارت دو گام (رمز → 2FA) + حالت معلق → مستقیم به اشتراک
- **Backend APIs:**
  - `POST /api/v1/auth/login` (مرحله ۱)
  - `POST /api/v1/auth/2fa/verify` (مرحله ۲ — TOTP/پیامک/Authy از پلاگین)
  - `POST /api/v1/auth/logout` + `POST /api/v1/auth/refresh`
  - `POST /api/v1/auth/forgot-password` + `POST /api/v1/auth/reset-password`
- **Frontend:** فرم ورود + 2FA + مدیریت سشن (httpOnly) + rate limit
- **DoD:** ورود موفق + 2FA + پیام خطای یکسان + قفل بروت‌فورس

## تسک ۲.۲ — صفحه ۱.۲: داشبورد
- **UIUX:** خوش‌آمد + ۴ کارت آمار + هشدارها + گرید ویجت پلاگین (حالت خالی)
- **Backend APIs:**
  - `GET /api/v1/admin/dashboard/stats` (صفحات/فایل/تیکت/اشتراک)
  - `GET /api/v1/admin/dashboard/alerts` (هشدارها)
  - `GET /api/v1/admin/dashboard/widgets` (رجیستری ویجت پلاگین‌ها)
- **Frontend:** داشبورد + اسلات ویجت داینامیک
- **DoD:** آمار + هشدار + ویجت پلاگین + کارت اشتراک معلق قرمز

## تسک ۲.۳ — صفحات ۱.۸ + ۱.۹: تنظیمات سایت + شبکه‌های اجتماعی
- **UIUX:** فرم کارتی (هویت/لوگو/تماس) + لیست شبکه‌ها
- **Backend APIs:**
  - `GET/PUT /api/v1/admin/settings/site` (عنوان/توضیحات/لوگو/فاوآیکون/تلفن/ایمیل)
  - `GET/PUT /api/v1/admin/settings/socials` (شبکه‌ها + آیکون)
- **Frontend:** فرم + SaveBar + MediaPicker برای لوگو
- **DoD:** ذخیره تنظیمات → اعمال در سایت عمومی

## تسک ۲.۴ — صفحه ۱.۱۴: قالب پنل مدیریت ⭐
- **UIUX:** گرید ۵ کارت قالب (مینیاتور Token-Driven) + پیش‌نمایش زنده + SaveBar + تنظیمات پیشرفته
- **Backend APIs:**
  - `GET /api/v1/ui-settings` (وضعیت ظاهر فعلی، سراسری نصب)
  - `PUT /api/v1/ui-settings` (ذخیره ظاهر: dir/preset/mode/radius/density/font)
  - `GET /api/v1/themes/panel` (فهرست قالب‌های پنل + پریست‌ها)
- **Frontend:** کامپوننت ThemeCard + AppearancePanel + State Machine (saved→previewing→confirmed) + ۱۱ حالت مرزی
- **DoD:** انتخاب → پیش‌نمایش زنده → تأیید → ذخیره سراسری نصب + تغییر بدون رندر مجدد

---

# 🟩 مرحله ۳ — وابستگی‌های فنی

> فایل برای بلوک تصویر لازم است، داشبورد مرکزی نمای روزانه کارفرما.

## تسک ۳.۱ — صفحه ۱.۶: مدیریت فایل (Media Library)
- **UIUX:** گرید/لیست + آپلود DropZone + Drawer جزئیات + سطل زباله + MediaPicker
- **Backend APIs:**
  - `GET /api/v1/admin/media` (لیست + فیلتر/صفحه‌بندی)
  - `POST /api/v1/admin/media/presign` (آپلود مستقیم به MinIO)
  - `PUT /api/v1/admin/media/{id}` (ویرایش alt/نام)
  - `DELETE /api/v1/admin/media/{id}` (به سطل زباله)
  - `POST /api/v1/admin/media/{id}/restore` + `DELETE .../permanent`
  - `GET /api/v1/admin/media/{id}` (جزئیات + variations)
- **Frontend:** گالری + MediaPicker (کامپوننت مشترک) + نشانگر مصرف تیر
- **DoD:** آپلود → پیش‌نمایش → استفاده در ویرایشگر صفحات + سطل زباله

## تسک ۳.۲ — صفحه ۲.۱: داشبورد مرکزی
- **UIUX:** ۴ کارت + هشدارهای اکشن‌دار + نمودارها
- **Backend APIs (central-plugin):**
  - `GET /api/central/dashboard/stats` (مشتریان/درآمد/معلق/هشدار)
  - `GET /api/central/dashboard/charts` (روند درآمد/مشتریان جدید)
- **Frontend:** داشبورد + نمودار (Chart.js)
- **DoD:** هشدارها قابل اکشن (کیف پول/استخر/تعلیق/دیپلوی)

## تسک ۳.۳ — صفحه ۲.۳: مدیریت چابکان (اسکلت)
- **UIUX:** Ops Console: موجودی کیف پول + استخر سرویس + سرویس‌های مشتریان + دیپلوی‌ها + reconciliation
- **Backend APIs (central-plugin):**
  - `GET /api/central/chabokan/wallet` (موجودی + هشدار)
  - `GET /api/central/chabokan/services` (لیست سرویس‌ها + وضعیت)
  - `POST /api/central/chabokan/services/{name}/start|stop|restart|resize`
  - `GET /api/central/chabokan/deployments` (تاریخچه) + `POST .../redeploy`
  - `GET /api/central/chabokan/reconciliation` (ناسازگاری)
- **Frontend:** StatusBadge + ActionMatrix + ConfirmDialog
- **DoD:** مدیریت سرویس‌ها + دیپلوی مجدد + گزارش reconciliation

---

# 🟦 مرحله ۴ — تکمیل محصول

> در MVP با یک قالب می‌شود شروع کرد؛ تعلیق عمومی بخش الزامی حلقه است.

## تسک ۴.۱ — صفحات ۱.۱۱ + ۱.۱۲: هدر/فوتر + بلوک‌های سایت (درگ‌اندروپ)
- **UIUX:** Widget Area (پالت ویجت + بوم) + Tab به‌ازای نوع صفحه
- **Backend APIs:**
  - `GET/PUT /api/v1/admin/layouts/header` + `/footer` (ویجت‌ها + چیدمان)
  - `GET /api/v1/admin/layouts/page-types` (انواع صفحه + بلوک‌های پیش‌فرض)
  - `GET/PUT /api/v1/admin/layouts/page-type/{type}/blocks`
- **Frontend:** DragDropList + WidgetSettingsForm (SchemaForm)
- **DoD:** چیدمان هدر/فوتر با درگ‌اندروپ + پیش‌نمایش زنده

## تسک ۴.۲ — صفحه ۱.۱۳: قالب‌های سایت
- **UIUX:** Theme Gallery (کارت + فعال‌سازی + پیش‌نمایش + آپلود)
- **Backend APIs:**
  - `GET /api/v1/admin/themes` (لیست)
  - `POST /api/v1/admin/themes/upload` (آپلود ZIP + امضا)
  - `POST /api/v1/admin/themes/{id}/activate` + `/preview`
  - `DELETE /api/v1/admin/themes/{id}`
- **Frontend:** گالری + پیش‌نمایش + revalidate
- **DoD:** فعال‌سازی قالب → revalidate + purge CDN + تعویض ظاهر

## تسک ۴.۳ — صفحه ۱.۱۶: تعلیق عمومی (سمت سایت)
- **UIUX:** صفحه تمام‌صفحه با برند + پیام + دکمه تماس (بدون لینک پنل)
- **Backend APIs:**
  - `GET /api/v1/site/status` (بررسی وضعیت اشتراک برای رندر عمومی)
  - polling: `GET /api/subscription-status?site_id=` (۵-۱۰ دقیقه)
- **Frontend:** صفحه تعلیق + suspension_mode (پنل فقط-پرداخت)
- **DoD:** سایت مسدود + پیام + پنل فقط پرداخت

---

# 🟩 مرحله ۵ — عملیاتی (بعد از درآمد)

> این صفحات برای مشتری اول ضروری نیستند — بعد از تأیید مدل درآمد.

## تسک ۵.۱ — صفحه ۱.۳: مدیران + نقش‌ها
- **UIUX:** Tab دوگانه (مدیران / نقش‌ها و دسترسی‌ها) — سوپرادمین مخفی
- **Backend:** CRUD users + roles/permissions + RBAC middleware
- **Frontend:** جدول + مودال + پنل چک‌باکس دسترسی
- **DoD:** ساخت مدیر + تخصیص نقش + مخفی بودن سوپرادمین

## تسک ۵.۲ — صفحه ۱.۴: پروفایل مدیر (+ AppearancePanel مشترک)
- **UIUX:** دو ستونه + بخش‌های 2FA داینامیک (Section Slot)
- **Backend:** update profile + change password + 2FA methods + recovery codes
- **Frontend:** پروفایل + اسلات پلاگین + AppearancePanel
- **DoD:** تغییر رمز + فعال‌سازی 2FA + کد بازیابی

## تسک ۵.۳ — صفحه ۱.۷: تیکت‌ها
- **UIUX:** Master-Detail (لیست + گفتگو)
- **Backend:** CRUD tickets + ticket_messages + notify
- **Frontend:** چت + پاسخ + اعلان
- **DoD:** ایجاد تیکت از فرم تماس + پاسخ + اعلان به فرستنده

## تسک ۵.۴ — صفحه ۱.۱۰: پلاگین‌ها
- **UIUX:** لیست + آپلود + فعال/غیرفعال + رندر شرطی نقش
- **Backend:** install/activate/deactivate/upgrade/uninstall + امضا + checksum
- **Frontend:** لیست + سوییچ + Badge آپدیت + `can(action)`
- **DoD:** نصب پلاگین امضاشده → فعال → hook ها ثبت → rollback

## تسک ۵.۵ — صفحه ۲.۵: مانیتورینگ
- **UIUX:** گرید سلامت + لاگ متمرکز + آپتایم
- **Backend:** `/health` هر نصب + heartbeat مرکزی + لاگ aggregator
- **Frontend:** داشبورد سلامت + لاگ + نمودار آپتایم
- **DoD:** تشخیص نصب پایین + هشدار + لاگ جستجو

## تسک ۵.۶ — صفحه ۲.۶: آپدیت‌ها
- **UIUX:** جدول نصب‌ها + رول‌اوت پلکانی (بدون canary)
- **Backend:** version registry + پچ فلیت + rollback
- **Frontend:** Badge آپدیت + ConfirmDialog + پیشرفت
- **DoD:** آپدیت پلکانی با rollback

## تسک ۵.۷ — صفحه ۲.۷: پروفایل اپراتور
- **UIUX:** 2FA سخت‌افزاری + توکن راه‌اندازی + Kill-Switch + لاگ
- **Backend:** 2FA + setup tokens + step-up auth + kill-switch
- **Frontend:** QR + مدیریت توکن + Kill-Switch ترسناک
- **DoD:** بازتولید توکن با 2FA + لاگ فعالیت

---

# 📊 خلاصه ترتیب تسک‌ها

| مرحله | تسک | نوع |
|---|---|---|
| **۰** | ۰.۱ هسته UI + ۰.۲ جهت بصری | زیرساخت |
| **۱** | ۱.۱ صفحات ⭐⭐ / ۱.۲ اشتراک / ۱.۳ Billing / ۱.۴ مشتریان | هسته حلقه پول |
| **۲** | ۲.۱ ورود / ۲.۲ داشبورد / ۲.۳ تنظیمات / ۲.۴ قالب پنل ⭐ | چهارچوب UI |
| **۳** | ۳.۱ فایل / ۳.۲ داشبورد مرکزی / ۳.۳ چابکان | وابستگی فنی |
| **۴** | ۴.۱ هدر/فوتر / ۴.۲ قالب‌ها / ۴.۳ تعلیق | تکمیل محصول |
| **۵** | ۵.۱ تا ۵.۷ | عملیاتی (بعد از درآمد) |

## ⚡ وابستگی‌ها (چه تسکی چه تسکی را مسدود می‌کند)
- **۱.۵ (صفحات)** ← به **۳.۱ (فایل/MediaPicker)** وابسته است → هر دو در مرحله ۱/۳ همزمان شروع شوند
- **۱.۱۵ (اشتراک)** ← به **۲.۴ (درگاه در مرکزی)** وابسته است
- **۲.۲ (مشتریان)** ← به **۳.۳ (چابکان اسکلت)** وابسته است (دکمه ارتقا/دیپلوی)
- **۱.۱۴ (قالب پنل)** ← به **۰.۲ (جهت بصری)** وابسته است
- همه ← به **۰.۱ (هسته UI)** وابسته هستند

---

## 🔄 گردش کار هر تسک (که به ایجنت‌ها گفته می‌شود)

```
[شروع تسک]
  ↓
[UIUX]  طراحی صفحه طبق ADMIN-PAGES-SPEC + DESIGN-DIRECTIONS
  ↓ (تحویل طراحی)
[Backend]  ساخت API ها مطابق نیاز صفحه و طراحی (contract-first + OpenAPI)
  ↓ (تحویل API)
[Frontend]  پیاده‌سازی نهایی مطابق طراحی UIUX و API ها
  ↓
[DoD]  بررسی Definition of Done تسک → [تمام] یا [بازگشت به اصلاح]
```

_این سند مرجع ترتیب اجرای تسک‌هاست. هر تسک بعد از تکمیل قبلی (با رعایت وابستگی‌ها) شروع می‌شود._