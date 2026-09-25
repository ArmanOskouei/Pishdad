# تسک‌ها — منبع حقیقت کارهای باقی‌مانده

> **قانون کاری (الزامی):** هر درخواست جدید کاربر **اول** اینجا ثبت می‌شود، **بعد** اجرا می‌شود.
> هنگام کار، وضعیت هر ردیف به‌روز می‌شود. **هرگز** وسط کار متوقف نشو؛ تا وقتی ردیف `pending` یا `in_progress` باقی مانده، ادامه بده.
> ستون `Blocked` فقط وقتی پر می‌شود که کاربر صریحاً گفته فعلاً رها شود.

## قانون ضد توقف (Stop-Avoidance Rule)

**علت باگ:** هر بار بعد از اجرای یک دستور shell، نوبت پاسخ تمام می‌شد و کار نیمه‌کاره می‌ماند.

**قاعده:** بعد از هر دستور shell / build / deploy، **بلافاصله مرحله بعدیِ همین جدول را شروع کن**. فقط وقتی پاسخ را متوقف کن که:
- همه ردیف‌ها `done` شده باشند، **یا**
- کاربر درخواست جدیدی داده باشد، **یا**
- یک ردیف واقعاً `Blocked` شده و برای ادامه نیاز به تصمیم کاربر است.

پیام «ادامه بده» از طرف کاربر نباید لازم شود.

## وضعیت فعلی — ۲۰۲۶-۰۹-۲۵

### گروه A — باگ‌های گزارش‌شده (اولویت ۱)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| A1 | تغییرات هدر/فوتر روی سایت عمومی اعمال نمی‌شود | done | `header-footer/page.tsx` | ریشه: `fetchSiteChrome` با تگ cache می‌شد و `router.refresh()` آن را تازه نمی‌کرد → `serverOne` (no-store) |
| A2 | درگ‌انددراپ در تنظیمات هدر/فوتر فعال نیست | done | `LayoutsClient.tsx` | شناسهٔ پایدار، onMove واقعی، دکمه‌های بالا/پایین و DragDropList در هدر/ستون‌های فوتر؛ drag واقعی در مرورگر و `tsc --noEmit` سبز |
| A3 | نمایش واضح اینکه هر ویجت در کدام ستون فوتر است | done | `LayoutsClient.tsx` | گرید ۱ تا ۴ ستونهٔ RTL با جعبه و برچسب ستون، و انتقال بین ستون‌ها؛ drag/انتقال و `tsc --noEmit` سبز |
| A4 | پروفایل: امکان تغییر ایمیل | done | `ProfileController`, `ProfileClient` | تغییر با رمز فعلی، یکتایی ایمیل و غیرفعال‌سازی 2FA؛ تست‌ها سبز |

### گروه B — هدر پنل (اولویت ۲)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| B1 | دکمه «مشاهده سایت» بزرگ‌تر + متن کامل فارسی | done | `Topbar.tsx`, `globals.css` | متن + آیکون SVG در دسکتاپ؛ آیکون ۴۴px با aria-label در موبایل؛ تست ۳۲۰/۱۴۴۰ |
| B2 | دکمه خروج با آیکون/متن واضح | done | `Topbar.tsx`, `globals.css` | آیکون logout، متن «خروج»، title/aria-label فارسی؛ تست ۳۲۰/۱۴۴۰ |

### گروه C — موبایل (اولویت ۳)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| C1 | نوار پایین موبایل قابل تنظیم توسط مدیر | done | `BottomNav.tsx` + settings | تنظیم سراسری نصب با GET/PUT در `api/v1/ui-settings`، اعتبارسنجی مسیرهای مجاز و تست Feature؛ ۲۳۶ تست/۱۱۸۳ assertion سبز |
| C2 | پیش‌فرض نوار پایین = صفحات روزانه | done | همان | داشبورد، صفحات، فایل‌ها، تیکت‌ها، هدر/فوتر، تنظیمات سایت؛ بدون مدیران، مرکزی، قالب پنل، شبکه‌ها و پلاگین‌ها |

### گروه D — کارهای قبلی که اجرا نشدند (اولویت ۴)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| D1 | نشست‌های فعال: OS/مرورگر/آیکون/تاریخ ورود+آخرین فعالیت/حذف تکی | done | `ProfileController`, `ProfileClient` | ثبت user-agent/IP، parse با jenssegers/agent، حذف تکی و UI شمسی؛ ۲۴۶ تست/۱۲۵۶ assertion سبز |
| D2 | تست کامل رفتار اشتراک داده بین مدیران | done | `tests/Feature/*` | داده‌های صفحات، رسانه، تنظیمات، چیدمان، پریست، تم، پلاگین و تیکت بین مدیران مشترک؛ استثناهای شخصی حفظ؛ تست‌های per-user معکوس؛ ۲۴۶ تست/۱۲۵۶ assertion سبز |

### گروه F — تسک‌های جدید کاربر (اولویت ۱)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| F1 | دکمه حذف صفحه در جدول لیست صفحات (کنار ویرایش/پیش‌نمایش) | done | `admin/pages`, `PageController.php`, `PageDeleteTest.php` | `DELETE /api/v1/admin/pages/{page}` با `perm:pages.delete`، تأیید فارسی، سطل زباله، toast و refresh؛ ۲ تست Feature اضافه شد؛ اجرای suite در این سیستم به‌دلیل خرابی Docker ممکن نبود |
| F2 | دکمه انتشار: خودکار اول پیش‌نویس ذخیره کند بعد منتشر کند | done | `PageEditor.tsx` | در حالت dirty ابتدا PUT پیش‌نویس و سپس POST انتشار؛ **تأیید زنده**: یک کلیک → نسخه ۱۰ «ویرایش» + نسخه ۱۱ «انتشار»، toast «صفحه با موفقیت منتشر شد»، بدون خطای «اول پیش‌نویس ذخیره کن» |
| F3 | QR کد در فعال‌سازی TOTP پروفایل | done | `ProfileClient.tsx`, `page.tsx`, `globals.css`, `package.json` | `qrcode@1.5.4`؛ QR سمت کلاینت از `otpauth://` با issuer عنوان سایت، راهنمای فارسی، کپی secret و ارسال خودکار TOTP؛ **تأیید زنده روی ۳۱۰۱**: QR خوانا رندر شد (base64 PNG)، دکمه فعال‌سازی auto-submit |
| F4 | اجرای suite بک‌اند و بیلد فرانت پس از F1/F2 | done | — | ۲۴۸ تست / ۱۲۶۲ assertion سبز؛ بیلد موفق؛ روی پورت ۳۱۰۱ مستقر و در مرورگر تأیید شد |
| F5 | ارائهٔ خلاصهٔ محصول، نقش‌ها، مدل کسب‌وکار و معماری زیرساختی برای سرمایه‌گذار و برنامه‌نویس فول‌استک | done | `SUMMARY.md`, `PLAN.md`, `COST-TIERING.md`, `TASKS.md` | توضیح محصول، نقش‌ها، درآمد، زیرساخت، وضعیت واقعی و backlog برای مخاطب سرمایه‌گذار و تیم فنی آماده شد |

## گروه G — تسک‌های ۲۰۲۶-۰۹-۲۵ (اولویت ۱)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| G1 | تغییرات هدر/فوتر و انتشار صفحه روی سایت اعمال نمی‌شود | done | `.env` بک‌اند | **ریشه: `REVALIDATE_URL` روی پورت ۳۱۰۰ بود ولی فرانت روی ۳۱۰۱** → webhook بی‌صدا fail می‌شد. اصلاح به ۳۱۰۱ + `optimize:clear`. تأیید: انتشار → سایت بلافاصله عوض شد. **بیلد لازم نیست** |
| G2 | درگ‌انددراپ در پیش‌نمایش زنده هدر/فوتر | done | `LayoutsClient.tsx` | **تأیید زنده ۳۱۰۱**: ۶ آیتم `sortable` (۳ بوم + ۳ لایه تعاملی)؛ state مشترک با بوم؛ RTL و لمسی |
| G3 | `/admin/blocks`: تغییرات در صفحه خانه اعمال نمی‌شود | done | `BlocksClient.tsx`, `LayoutController.php` | پیش‌فرض global روی صفحاتی که override ندارند به revision جدید منتقل شد؛ تگ‌های `pages`، `site-homepage` و `page:{slug}` ارسال شدند؛ endpoint smoke شد. |
| G4 | تب «بلاگ» نباید در حالت پیش‌فرض باشد | done | `page_types.php`, `LayoutsTest.php` | `blog` از هسته حذف شد؛ فقط page_types مانیفست پلاگین فعال ظاهر می‌شود؛ override قدیمی `global:blog` حفظ شد؛ ۲۵۰ تست/۱۲۸۴ assertion سبز |
| G5 | تب تک‌صفحه‌ای: فعال/غیرفعال در هر صفحه | done | migration, `PageController.php`, `PageEditor.tsx` | `is_single` با default false در API/خروجی عمومی و سوییچ «این صفحه تک‌صفحه‌ای است» در کارت انتشار؛ تست Feature و `tsc --noEmit` سبز |
| G6 | نوار پایین موبایل (`/admin/appearance`) درگ‌انددراپ | done | `appearance/page.tsx` | **تأیید زنده ۳۱۰۱**: ۴ آیتم `sortable` + ۴ چک‌باکس فعال؛ هدف لمسی ۴۴px |
| G7 | آیکون نشست‌ها بزرگ‌تر، زیر هم، رنگی، tooltip با نسخه دقیق | done | `ProfileClient.tsx` | **تأیید زنده ۳۱۰۱**: SVG ۳۴px عمودی، ۱۱ رنگ پر، tooltip «Chrome 154 روی Windows 10/11» و «Android 10» |

## گروه H — تسک‌های ۲۰۲۶-۰۹-۲۵ (اولویت ۱)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| H1 | عنوان سایت = «مدیریت محتوا پیشداد» | done | تنظیمات سایت (API) | از `TestABC123` تغییر کرد |
| H2 | فاوآیکون لود نمی‌شود | done | `src/app/page.tsx` | ریشه: `generateMetadata` روت `/` فیلد `icons` نداشت → اضافه شد |
| H3 | عنوان هدر و کادر جستجو «ناخوانا» | done | `layout.tsx`, `vazirmatn.css`, `public/fonts/` | **ریشه: فونت Vazirmatn از `cdn.jsdelivr.net` لود می‌شد** → در نبود دسترسی، مرورگر fallback می‌زد (`document.fonts` = unloaded). فونت ۵ وزن به‌صورت محلی + preload + قانون ضد CDN در `AGENTS.md`. تأیید: CSS صفر ارجاع CDN، فایل‌ها HTTP 200 (۵۰KB) |
| H4 | نمایش لوگو در تنظیمات هدر | done | — | تأیید: `site-brand` در HTML پنل با `img src=.../logo.png` رندر می‌شود |
| H5 | اسکریپت استقرار تا `public` فراموش نشود | done | `Run/deploy-frontend.ps1` | ریشه تکرار ۴۰۴ فونت: پوشه `public` در standalone کپی نمی‌شد. اسکریپت خودکار: kill → build → کپی `.next/static` **و** `public` → start → بررسی font+site |
| H6 | `/admin/tickets` خطای React #301 بعد از ارسال فرم تماس | done | `TicketsClient.tsx:105` | **ریشه: `if (ticket === null && !error) void load()` حین render** → setState در چرخه رندر. با `useEffect` + `useCallback` جایگزین. مورد مشابه در `central/clients/ClientsTable.tsx:138,143` هم فیکس شد. قانون ضد #301 در `AGENTS.md`. تأیید: بدون خطای کنسول، ۲ تیکت فرم تماس درست رندر شد |

### گروه I — تسک جدید ۲۰۲۶-۰۹-۲۵ (اولویت ۱)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| I1 | اصلاح آدرس مدیا و دسترسی MinIO از شبکهٔ محلی در dev | done | `cms-core/frontend`, `cms-core/backend`, `Run`, Docker/envهای محلی | مقدار LAN محلی `http://192.168.8.100:9100/cms` در `AWS_URL` و `NEXT_PUBLIC_MEDIA_URL`؛ fallbackهای runtime حذف شدند؛ MinIO روی `0.0.0.0:9100` تأیید شد؛ تست LAN و ۲۵۰ تست بک‌اند سبز؛ با تغییر IP وای‌فای به‌روزرسانی شود؛ production بدون تغییر |


## گروه K — انتشار عمومی «پیشداد» + بازار پلاگین/قالب (اولویت ۱)

**چشم‌انداز کاربر:** انتشار عمومی CMS مثل وردپرس (نصب مستقیم روی سرور) + بازار پلاگین/قالب با مسیر رایگان و فروشی (۳۰٪ سهم سایت) + مسیر آپلود مستقیم کاربر با «حالت توسعه‌دهنده» + پلتفرم مرکزی فقط به‌صورت پلاگین مرکزی (منتشر نمی‌شود).

### K0 — تصمیم‌های محصول/کسب‌وکار ✅ پاسخ داده شد (۱۴۰۵/۰۶/۰۴)

| # | سؤال | پاسخ کاربر |
|---|------|-----------|
| K0.1 | لیسانس هسته | ✅ **رایگان و متن‌باز** |
| K0.2 | بازار هدف نصب | ✅ **فعلاً فقط VPS** (Docker) — هاست اشتراکی پشتیبانی نمی‌شود |
| K0.3 | دسترسی پلاگین محلی به دیتابیس | ✅ **فقط جدول‌های خودش** (`plugin.{slug}_*`) |
| K0.4 | سازوکار «کلید عمومی» | ✅ **مهر یکپارچگی per-install** (کلید بیرون از ZIP) |
| K0.5 | نصب نسخه اول | ✅ تک‌دستور `docker compose` روی VPS |
| K0.6 | بازار federated یا تک‌مرکزی | ⏸️ تک‌مرکزی (فرض فعلی) |
| K0.7 | اگر توسعه‌دهنده پلاگین را از بازار حذف کند | ⏸️ منتظر پاسخ (پیشنهاد: `yanked`) |
| K0.8 | سقف پلاگین فعال | ⏸️ منتظر پاسخ (پیشنهاد: ۲۰) |
| K0.9 | تا کی آپدیت رایگان باشد | ⏸️ منتظر پاسخ (پیشنهاد: تا پایان نسخه اصلی هسته) |
| K0.10 | قالب سایت: همان اکوسیستم یا جدا | ⏸️ منتظر پاسخ (پیشنهاد: manifest مشترک، دو bundle جدا) |
| K0.11 | پلاگین محلی بدون تأیید مجاز به فعال‌سازی؟ | ⏸️ منتظر پاسخ (پیشنهاد: بله با هشدار اجباری) |

### K1 — فازبندی اجرایی (بر اساس گزارش متخصص)

| # | فاز | محتوا | وضعیت |
|---|-----|-------|--------|
| K1.0 | **فاز ۰ — بازنویسی مدل اعتماد** | تفکیک `signature_valid` به `publisher_verified`/`review_status`/`content_digest`/`digest_verified_at`؛ `system` به allowlist DB؛ پیش‌فرض `review_status` = `unverified`؛ جدول `publisher_keys`؛ افزودن `content_digest` | ✅ **تمام** — ۲۵۵ تست پاس، مهاجرت اجرا شد |
| K1.1 | فاز ۱ — هسته اجرایی | استخراج امن ZIP (۱۱ کنترل)، PSR-4 loader با prefix اجباری، `PluginRouter` catch-all، migration runner با prefix | pending |
| K1.2 | فاز ۲ — نصب پایه | `install.sh`/`docker-compose` امضاشده + setup token یک‌بارمصرف | pending |
| K1.3 | فاز ۳ — حالت توسعه‌دهنده | ۵ کلیک + re-auth، مهر یکپارچگی per-install، نشان «تأییدنشده» | pending |
| K1.4 | فاز ۴ — راهنما + اسکلت‌ساز | `pishdad-plugin:make` + راهنمای ترکیبی زنده/ثابت | pending |
| K1.5 | فاز ۵ — بازار خواندنی | ثبت ناشر، بازبینی، `yanked`، نصب از بازار (بدون پول) | pending |
| K1.6 | فاز ۶ — فروش + ۳۰٪ | سفارش، لایسنس، ledger تسویه، payout دستی | pending |
| K1.7 | فاز ۷ — درگاه | milestone جدا، فقط اگر از مشتری پول گرفته شود | pending |
| K1.8 | موازی (کندلی ۱-۲ روزه) | cache کردن `activeManifests()`، prefix پرمیشن با slug، registry برای search provider، سقف `file_count` | 🟡 **۱ از ۴** — cache انجام شد؛ سه مورد باقی |

### آنچه فاز ۰ عملاً تغییر داد (برای مراجعه بعدی)

| فایل | تغییر |
|---|---|
| `database/migrations/2026_10_01_000001_rewrite_plugin_trust_model.php` | ستون‌های `source`/`publisher_key_id`/`publisher_verified`/`content_digest`/`digest_verified_at` + جدول‌های `publisher_keys` و `system_plugins` + تغییر پیش‌فرض `review_status` |
| `app/Models/PublisherKey.php` | trust store چندناشره، `fingerprintOf()` |
| `app/Models/SystemPlugin.php` | allowlist پلاگین سیستمی + کش slugها |
| `app/Services/Plugins/PluginTrustStore.php` | تفکیک اصالت از تأیید، ابطال کلید fail-closed، تولید کلید مهر یکپارچگی |
| `app/Models/Plugin.php` | `isSystem()` از allowlist، `trustSummary()`، flush کش در `booted()` |
| `app/Services/Plugins/ManifestRegistry.php` | کش ۳۰۰ثانیه‌ای + `flushCache()` |
| `app/Http/Controllers/Api/V1/Admin/PluginController.php` | حذف خواندن `system` از مانیفست، `present()` با بلوک `trust` |

**سند معماری:** `docs/PLUGIN-ECOSYSTEM-ARCHITECTURE.md`

**دو باگ که حین کار فاز ۰ پیدا و رفع شد:**
- `system: true` در مانیفست ⇒ پلاگین غیرقابل‌حذف (هر کاربر `plugins.edit`). رگرسیون: `test_manifest_cannot_grant_system_flag`.
- `key_id` ناشناخته ⇒ `null` ⇒ fallback به کلید config ⇒ **fail-open**. حالا null خطاست.



**تصمیم کاربر:** هسته CMS بدون هیچ اثری از پنل مرکزی نصب شود؛ پنل مرکزی فقط روی سایت خود صاحب پلتفرم و کاملاً خصوصی باشد. پرداخت: فعلاً بدون درگاه (دوره آزمایشی، تنظیم دستی).

**یافته J1 (امکان‌سنجی):** سیستم پلاگین فعلی runtime نیست — فقط رجیستری اعلانی (permissions/widgets/page_types). نمی‌تواند مسیر API، مهاجرت یا صفحه فرانت اضافه کند و ZIP اصلاً extract نمی‌شود (تصمیم امنیتی Ed25519/fail-closed). **افزودن runtime = واگردی امنیتی برای یک مصرف‌کننده.** ← راه‌حل: دو توزیع از یک ریپو.

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| J1 | امکان‌سنجی تبدیل مرکزی به پلاگین | done | `docs/TASKS.md` | ❌ ممکن نیست با سیستم فعلی؛ گزارش کامل ثبت شد |
| J2 | **فاز ۱ — قطع وابستگی هسته**: `ClientSubscriptionResolver` → interface + `LocalSubscriptionResolver` (جداول بی‌نام) + `RemoteSubscriptionResolver` (HTTP) | pending | `Services/Subscription/ClientSubscriptionResolver.php` + ۶ مصرف‌کننده | **بحرانی**: `forUser()` با `firstOrCreate` روی `central_clients` ⇒ حذف جداول = ۵۰۰ روی سایت مشتری. ۶ مصرف‌کننده: Site/PageController:55, Site/SiteChromeController:31, Site/SiteStatusController:23, Admin/DashboardController:23,51, MediaController:189, SubscriptionStatusController |
| J3 | **فاز ۲ — بیلد فرانت جدا** بدون پوشه `(central)` | pending | `frontend/docker-compose.yml` + هدف دوم | ۲۰۶۰ خط TSX الان در بیلد هر مشتری است |
| J4 | **فاز ۳ — استخراج بک‌اند** مرکزی به استقرار دوم | pending | `app/Http/Controllers/Api/Central/` (۱۲ فایل)، `app/Models/Central/` (۱۹) | ترتیب: اول ماژول‌های مستقل (chabokan, monitoring, updates, operator, review)؛ آخر clients/billing |
| J5 | **فاز ۴ — قطع مسیرها/جداول/کامندها** از هسته | pending | `routes/api.php:208-288` (۶۱ route)، ۱۹ جدول، `bootstrap/app.php:16`، `routes/console.php:13-15` | ⚠️ جداول: central_clients/subscriptions/invoices/payments/plans/billing_events/support_tokens/backups/logs/chabokan_*/install_heartbeats/core_versions/update_rollouts*/operator_*/kill_switches. **وابستگی FK بین‌مایگریشنی** دارد |
| J6 | **فاز ۵ — پاک‌سازی** | pending | ۱۵ فایل تست هسته، `openapi.yaml` (۵۵ مسیر)، `api.d.ts` | |
| J7 | حل وابستگی حلقوی: `ReviewController` تأییدکننده پلاگین هسته است ولی در مرکزی | pending | `Api/Central/ReviewController.php` | تصمیم معماری لازم |
| J8 | تست: نصب تازه هسته = صفر اثر مرکزی | pending | `tests/Feature/` | مسیر مرکزی ۴۰۴، جداول غایب، `/central` حذف |
| J9 | راهنمای استقرار مرکزی فقط روی سایت خودت | pending | `docs/` | + تأیید دسترسی خصوصی |
| J10 | حذف مسیر پرداخت/درگاه (فعلاً بدون پرداخت) | pending | billing | مدیر مرکزی دستی تیر/مدت تنظیم می‌کند |


| # | تسک | وضعیت | یادداشت |
|---|------|--------|---------|
| E1 | درگاه واقعی زرین‌پال | cancelled | **به‌جایش: بدون پرداخت (دوره آزمایشی) — J7** |


| E2 | SMTP واقعی | pending | نیاز به creds از کاربر |
| E3 | پنل پیامک | pending | نیاز به creds |
| E4 | درایور واقعی چابکان | pending | نیاز به API key |
| E5 | بکاپ pg_dump | pending | |
| E6 | ایمیل فرم تماس | pending | |
| E7 | سقف منابع بر اساس تیر | pending | |
| E8 | آنبوردینگ اولین ورود | pending | |
| E9 | بازیابی 2FA | pending | |
| E10 | دیپلوی production | pending | |
| E11 | installer | pending | |
| E12 | قفل جهت بصری نهایی | blocked | منتظر تصمیم کاربر |
| E13 | ری‌استارت opencode برای ساب‌ایجنت‌های تخصصی | blocked | نیاز به اقدام کاربر |

## تاریخچه انجام‌شده (برای جلوگیری از دوباره‌کاری)

- لینک تودرتو nav/footer (hover + آکاردئون موبایل) ✅
- بلوک FAQ + صفحه FAQ نمونه ✅
- جستجوی زنده هدر + صفحه `/search` + provider معماری ✅
- پیش‌نمایش زنده هدر/فوتر با سوییچ دسکتاپ/موبایل ✅
- dnd-kit نصب و در `DragDropList` فعال ✅
- محتوای نمونه هدر/فوتر از طریق API واقعی ✅
- ساده‌سازی ویجت لوگو (حذف media_id و size) ✅
- مهاجرت تنظیمات `user_18:*` → `global:*` ✅
- رفع گیت اشتراک در `SiteChromeController` (تنظیمات سراسری همیشه خوانده شود) ✅
- رفع تمام P0/P1 ریسپانسیو موبایل (Drawer، مودال اسکرول، سایدبار تبلت، گرید تیکت، Topbar، BottomNav) ✅
- ارسال خودکار کد ۲FA بعد از ۶ رقم (debounce ۳۰۰ms) ✅
- نوار پایین موبایل قابل تنظیم با پیش‌فرض صفحات روزانه و ذخیره سراسری `ui/global` ✅
- ممیزی ریسپانسیو با ۲۹ اسکرین‌شات ✅
