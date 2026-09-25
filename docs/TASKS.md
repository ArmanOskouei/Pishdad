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

### K1.5 — تصمیم‌های معماری از نقد متخصص (۱۴۰۵/۰۶/۰۴) ✅ همه تأیید شد

مشخصات کارفرما دربارهٔ «ساختار پراکندهٔ `Plugin/{slug}/` در پوشه‌های هسته»، «ساختار ZIP با `Laravel/` و `Next.js/`»، «قید فقط-API»، «حالت توسعه‌دهنده» و «پوشهٔ `plugin/` در Next.js» بررسی و نقد شد.

| # | موضوع | تصمیم | دلیل |
|---|-------|--------|------|
| K1.5.1 | محل فایل‌های پلاگین | ✅ **پوشهٔ اختصاصی و ایزوله** — ساختار استاندارد لاراول داخل پلاگین، وصل‌شده در زمان اجرا با PSR-4 واحد | پراکندگی در `app/` باعث می‌شد هر آپدیت هسته ۵۰۰ عملیات فایل را تکرار کند، rollback تراکنشی نداشته باشد، دو نسخه هم‌زیستی نکنند، و prefix اجباری namespace اصلاً قابل enforce نباشد (چون فایل داخل `App\` می‌نشیند) |
| K1.5.2 | ساختار ریشهٔ ZIP | ✅ `Laravel/` و `Next.js/` فقط **قرارداد مسیر**؛ `manifest.json` **در ریشه می‌ماند** | `readManifest()` ریشه را می‌گردد؛ اگر manifest جابه‌جا شود همهٔ آپلودها ۴۲۲ می‌شوند. خواستهٔ کارفرما بدون شکستن امضا برآورده می‌شود |
| K1.5.3 | دسترسی دیتابیس پلاگین | ✅ **تضادی نیست** — پلاگین می‌تواند جدول بسازد، فقط `{slug}_*`. enforcement در فاز ۱ + نقش دوم PostgreSQL | اگر `DB::statement()` آزاد باشد، `DROP TABLE users` از قوانین نام‌گذاری رد می‌شود |
| K1.5.4 | قید «بک‌اند فقط API» | ✅ از قبل برقرار — هیچ کوپل مستقیم فرانت↔بک‌اند نیست | **همین قید سؤال UI را به گزینه‌های (۱) و (۳) محدود می‌کند** — پلاگین نمی‌تواند UI سمت بک‌اند داشته باشد چون جایی برای رندر نیست |
| K1.5.5 | دامنهٔ حالت توسعه‌دهنده | ✅ **per-install با لاگ کامل** در `operator_activities` | بعد از نصب یک پلاگین بد، همین ردپا تفاوت «می‌توانیم عیب‌یابی کنیم» و «کور هستیم» است |
| K1.5.6 | نقاط اتصال افزونه | ✅ **فهرست بسته و مشخص**؛ افزودن مورد تازه = ارتقای هسته | کنترل کیفیت دست پلتفرم می‌ماند |
| K1.5.7 | UI پلاگین در Next.js | ✅ **سطح ۰ — اعلانی/داده‌ای، بدون build و بدون JS پلاگینی** | نیمهٔ بک‌اندی از قبل نوشته شده (`widgetSchemas`، `pageTypes`، `DashboardController::widgets`، `SchemaForm`). تنها شکاف: `BlockRenderer.tsx:195` برای block پلاگین `null` برمی‌گرداند |
| K1.5.8 | کامپوننت React از سمت پلاگین | ⛔ **رد** در v1 | در مرورگر مدیر اجرا می‌شود ⇒ از همان مسیر `/api/proxy` که کوکی نشست را حمل می‌کند دسترسی کامل ادمین دارد. افزونهٔ بدافزاری = تصاحب کامل پنل |
| K1.5.9 | build در زمان اجرا | ⛔ **رد قطعی** | چند دقیقه، تک‌نویسنده روی `.next`، build نیمه‌کاره = صفحهٔ سفید برای **کل پنل** |
| K1.5.10 | PDF فارسی | ⏸️ `window.print()` با برچسب صادقانه؛ PDF واقعی بیرون اسکوپ | هیچ موتور PDF در پروژه نیست و Vazirmatn `woff2` است |
| K1.5.11 | جدول نگاشت «نوع فایل → پوشهٔ مجاز» | ✅ **deny-by-default با بستهٔ کمینهٔ ۷ پیشوند**، نه جدول نگاشت مثبت | جدول نگاشت مثبت با هر تغییر ساختار هسته drift می‌کند |
| K1.5.12 | حالت توسعه‌دهنده: enforce سمت سرور | ✅ **الزامی** — `upload()`/`upgrade()` باید `dev_mode` را چک کنند | اگر فقط UI باشد، با یک POST ساده دور می‌خورد |

### K1.7 — تصمیم UX هنگام رد بسته (۱۴۰۵/۰۶/۰۴)

> **کاربر انتخاب کرد: «الف) فقط نتیجهٔ تحلیل (لیست خطاها) — سریع و تمیز».**

یعنی **هیچ راهی برای «نصب با وجود خطا» وجود ندارد.** خطا یعنی بسته واقعاً ناسازگار یا ناامن است.

| وضعیت بسته | رفتار |
|---|---|
| فقط هشدار | نصب انجام می‌شود، هشدارها قبلش نمایش داده می‌شوند |
| حداقل یک خطا | نصب رد می‌شود (422) و کل تحلیل در بدنهٔ پاسخ برمی‌گردد |

این رفتار در `PluginPackageValidator` از قبل حاضر بود (`ok = errors === []`)، پس تصمیم محصول با کد هم‌راستا بود و چیزی برای تغییر لازم نشد.

### K1.6 — فاز ۱.۵ (جدید، بیشترین بازده) ✅


| # | تسک | وابستگی | وضعیت |
|---|------|---------|--------|
| K1.6.1 | قرارداد بسته: `panel.json` schema + بستهٔ ۷ پیشوند + رجیستری نقاط اتصال **به‌صورت کد** | ندارد | ⏳ **در حال اجرا** |
| K1.6.2 | رفع ۳ خطای `DeveloperGuide.tsx` (ساختار ZIP، فیلد `system`، کلید مرکزی) + تولیدش از قرارداد | K1.6.1 | pending |
| K1.6.3 | `POST /v1/admin/plugins/validate` — تابع خالص روی فهرست ZIP، بدون استخراج و بدون نوشتن DB | ندارد | ⏳ **در حال اجرا** |
| K1.6.4 | صفحهٔ «تحلیل بسته» در UI + پیشنهاد محل درست + ارجاع به راهنما | K1.6.3 | ✅ **تمام** — تست مرورگر با بستهٔ خراب و بستهٔ ساختار-درست |
| K1.6.5 | `PluginRouter` catch-all + dispatch صفحهٔ پلاگین در `[...path]/page.tsx` | فاز ۱ | pending |

> **چرا فاز ۱.۵ قبل از فاز ۱:** «بررسی فهرست ZIP قبل از استخراج» دقیقاً نیمهٔ اول فاز ۱ است (گام ۱ از ۱۱ کنترل). یعنی کار تکراری نیست، نیمهٔ بی‌استفادهٔ کاری است که قرار است انجام شود. کاربر **کل UX خواستهٔ بند ۵** را می‌گیرد — خطای جای‌گذاری، پیشنهاد محل درست، ارجاع به راهنما، لینک PDF — بدون اینکه یک خط کد پلاگینی اجرا شود.

### بستهٔ مجاز ZIP (deny-by-default — مرجع `K1.5.11`)

```
ریشهٔ ZIP
├── manifest.json          ← هویت + امضا. تنها فایل الزامی. حتماً در ریشه بماند (K1.5.2)
├── Laravel/
│   ├── src/               ← ریشهٔ PSR-4 اجباری
│   ├── routes/api.php     ← فقط API. web.php ممنوع
│   ├── database/migrations/  ← با prefix اجباری {slug}_
│   └── config/plugin.php  ← فقط مقادیر پیش‌فرض
└── Next.js/
    ├── panel.json         ← قرارداد سطح UI
    ├── panel/             ← دارایی/دادهٔ UI
    └── blocks/            ← تعریف بلوک سایت
```

هر چیز دیگری رد می‌شود. تغییر ساختار پوشه‌های هسته **اصلاً مسئلهٔ validator نیست** چون پلاگین در پوشه‌های هسته نمی‌نشیند؛ در عوض به `requires.core` منتقل می‌شود.

### باگ‌های کشف‌شده حین این بازبینی

- `upload()`/`upgrade()` هیچ بررسی `dev_mode` ندارند ⇒ قفل سمت کلاینت با یک POST دور می‌خورد.
- `DeveloperGuide.tsx:20,55-58,98,100` سه خطای محتوایی دارد ⇒ کاربر را به دستور غلط می‌فرستد.
- `readManifest()` ریشهٔ ZIP را می‌گردد ⇒ با دو پوشه در ریشه، همه ۴۲۲ می‌شوند.
- `BlockRenderer.tsx:195` برای block type پلاگین `null` برمی‌گرداند.
- `next.config.ts:4` `output: 'standalone'` را فعال می‌کند ولی `frontend/Dockerfile:9` از `npm start` استفاده می‌کند و `.dockerignore` برای فرانت نیست ⇒ هر چیزی که روی runtime build بنا شود روی یک اشتباه پیکربندی بنا شده است.


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

---

# 🔴 بخش K2 — نتیجهٔ ممیزی متخصص (۱۴۰۵/۰۶/۰۴)

## ⛔ سه کشفی که ترتیب کار را عوض می‌کند

### کشف ۱ — هیچ‌کدام از ۸ نقطهٔ اتصال در runtime کار نمی‌کند

`PluginPackageContract::EXTENSION_POINTS` فقط **سه** مصرف‌کننده دارد و هر سه «اعتبارسنجی/نمایش»‌اند نه «اجرا»:
`PluginPackageValidator.php:245-270` · `:356-371` · `PackageAnalyzer.tsx:251-270`.

یعنی پلاگینی که دقیقاً طبق قرارداد بنویسد `panel.extensions: [{point:"admin.menu"}]`، بسته‌اش سبز می‌گیرد و **هیچ اتفاقی نمی‌افتد.**

| نقطه | وضعیت واقعی | شاهد |
|---|---|---|
| `site.page_type` | ✅ **تنها نقطهٔ واقعی** | `ManifestRegistry.php:136-165` · `LayoutController.php:21` · `LayoutsTest.php:149` |
| `site.header_widget` | ⚠️ بک‌اند merge، فرانت `return null` | `ManifestRegistry.php:86-117` · `Chrome.tsx:83-85` |
| `site.footer_widget` | ⚠️ همان | همان |
| `site.block_type` | ⚠️ بک‌اند merge، فرانت `default: return null` | `BlockRenderer.tsx:195-199` |
| `admin.menu` | ❌ منو یک `const` هاردکد است | `lib/menu.ts:6-48` (static) · `Shell.tsx:22,30` |
| `admin.dashboard_slot` | ❌ بک‌اند `[]`، فرانت فقط عنوان‌ها را در `<ul>` | `DashboardController.php:106-115` · `dashboard/page.tsx:155-163` |
| `admin.settings_schema` | ❌ صفحهٔ schema-driven وجود ندارد؛ ۱۴ `useState` دستی | `SettingsClient.tsx:11-25` |
| `admin.data_collection` | ❌ `DataTable` ستون‌اش **تابع** می‌خواهد ⇒ غیرقابل توصیف | `DataTable.tsx:5` |

### کشف ۲ — دو جهان موازی و ناسازگار

| کلید واقعی در مانیفست | خوانندهٔ امروز | معادل ادعاشده در قرارداد |
|---|---|---|
| `manifest.widgets.header/footer` | `ManifestRegistry.php:86-117` | `site.header_widget` / `site.footer_widget` |
| `manifest.page_types` | `ManifestRegistry.php:146-160` | `site.page_type` |
| `manifest.permissions` | `ManifestRegistry.php:271-305` | **در فهرست نیست** |
| `manifest.hooks` | `PluginController.php:380-397` | **در فهرست نیست** |

**این ریشهٔ همهٔ گیجی‌هاست.** گران‌ترین تصمیم پروژه: کدام یک مرجع شود. → I1 در K3.

### کشف ۳ — K1.6.4 «تمام» اعلام شد ولی مسیر اصلی کاربر خراب است

`PackageAnalyzer.tsx:152` — `useState(initialAnalysis)`. مقدار اولیه فقط بار اول خوانده می‌شود.
`PluginsClient.tsx:73` مقدار را `setAnalysis` می‌کند، `:147` به‌صورت prop می‌دهد، و **هیچ اتفاقی نمی‌افتد.** کاربر toast می‌بیند که می‌گوید «نتیجهٔ بررسی ساختار را ببینید» و چیزی نمایش داده نمی‌شود.

**تست من فقط مسیر «انتخاب فایل در تحلیلگر» را پوشش داد، نه مسیر «آپلود رد شد».** ادعای DoD نادرست بود. → B6.

---

# 🔴 K2 — باگ‌های امنیتی و صحت (پیش‌نیاز همه‌چیز)

> هیچ فاز دیگری نباید قبل از این شروع شود. B4 و B5 امنیتی‌اند و B2 با پایهٔ کاربر ایرانی محتمل است.

| # | باگ | ارجاع | کار | وضعیت |
|---|-----|--------|-----|--------|
| **B1** | 🔴 تشخیص نشت کلید فقط سطح‌بالا را می‌گیرد. مانیفست تودرتو است، پس `{"publisher":{"private_key":"…"}}` رد می‌شود. تناقض با خود کد: `PluginTrustStore.php:107` کلید را از `$manifest['publisher']['key_id']` می‌خواند. regex فعلی `api_key`/`token`/`password`/`salt`/`webhook_secret` را هم نمی‌گیرد | `PluginPackageValidator.php:233-240` | اسکن **بازگشتی** + فهرست کامل | ⏳ |
| **B2** | 🔴 `slug` فارسی → رشتهٔ خالی. `Str::slug('پنل')` → `''` ⇒ ردیف با `slug=''` و prefix بی‌نام | `PluginController.php:103` · `Validator.php:210-217` | `^[a-z0-9][a-z0-9._-]{1,39}$` با **خطا** | ⏳ |
| **B3** | 🔴 `dev_mode` اصلاً وجود ندارد. `upload()` و `upgrade()` هیچ بررسی سمت سرور ندارند | `PluginController.php:64,175` | middleware روی هر دو route | ⏳ |
| **B4** | 🔴 `/api/proxy` فهرست مسیر مجاز ندارد. `$target = ${UPSTREAM}/${path.join("/")}` بدون بررسی ⇒ از مرورگر مدیر با کوکی، **هر `/api/*`** قابل فراخوانی است — از جمله `central/*` و `internal/heartbeat`. **همین مسیر دلیل رد کامپوننت پلاگین است (K1.5.8) و مرز امنیتی‌اش allowlist ندارد** | `api/proxy/[...path]/route.ts:9` · `lib/server-api.ts:23` | `if (path[0] !== 'v1') return 403` + فهرست بستهٔ prefix | ⏳ |
| **B5** | 🔴 رجیستری جستجوی کلاینت دقیقاً K1.5.8 را دور می‌زند: `registerSearchSource({fetchItems})` روی `window.__ADMIN_SEARCH__` و نمونه‌اش `fetch('/api/proxy/v1/admin/blog/posts')` | `lib/search-registry.ts:111,336-361` · `docs/SEARCH-PLUGIN-CONTRACT.md` | حذف + انتقال به بک‌اند | ⏳ |
| **B6** | 🔴 `PackageAnalyzer` مقدار اولیهٔ prop را دوباره نمی‌خواند ⇒ مسیر «آپلود رد شد» بی‌اثر است | `PackageAnalyzer.tsx:152` · `PluginsClient.tsx:73,147` | `useEffect` sync یا `key` | ⏳ |
| **B7** | 🟠 `ApiError` فقط از FormData. `authed` هنوز `Error` ساده می‌دهد و هر دو در ۴۰۱ خطای ساده ⇒ تمیز دادن «نشست منقضی» از «خطای اعتبارسنجی» ممکن نیست | `auth.tsx:110,113,126,127` | `authed` هم `ApiError` | ⏳ |
| **B8** | 🟠 `suggestLocation` اولویت‌ها را برعکس زده: نام کلاس قبل از پسوند ⇒ `Model.png` → پیشنهاد `Laravel/src/Models/Model.png`. **نکتهٔ امنیتی:** پسوندهای `.ts/.tsx/.js/.jsx` به `Next.js/panel/` پیشنهاد می‌شوند ولی آن پوشه هرگز اجرا نمی‌شود — اگر روزی به `public/` سرو شود، اسکریپت same-origin روی origin ادمین است | `PluginPackageContract.php:296-324` | پسوند قبل از نام + **حذف** `.ts/.tsx/.js/.jsx` از پیشنهاد | ⏳ |
| **B9** | 🟠 `readManifest` هر `manifest.json` را پیدا می‌کند نه فقط ریشه را. نقض مستقیم K1.5.2. امروز تصادفی امن است چون `manifest.misplaced` جلویش را می‌گیرد — ولی در `upload()` همین خروجی `slug` و امضا و کل ردیف DB را تعیین می‌کند | `Validator.php:422` · `PluginController.php:434` | `getFromName('manifest.json')` | ⏳ |
| **B10** | 🟠 دو شکل برای یک مفهوم: `manifestChecks` رشتهٔ ساده می‌پذیرد ولی `extensionPointsOf` فقط آبجکت ⇒ مانیفست با رشتهٔ ساده از اعتبارسنجی رد می‌شود ولی در مقایسه با `panel.json` نامرئی است ⇒ اختلاف کاذب | `Validator.php:248,380-384` | فقط آبجکت، در هر دو جا | ⏳ |
| **B11** | 🟠 `upgrade()` فایل قدیمی را **قبل** از موفقیت جدید پاک می‌کند. دیسک پر ⇒ پلاگین فعال با `path` ناموجود | `PluginController.php:207-212` | ذخیرهٔ جدید → ردیف → حذف قدیم | ⏳ |
| **B12** | 🟠 سه درخواست سریالی در layout هر صفحهٔ پنل | `admin/layout.tsx:12,19,26` | `Promise.allSettled` با حفظ سه `catch` جدا | ⏳ |
| **B13** | 🟡 زنگ اعلان `<button>` بدون `onClick`؛ `alertCount` هرگز پاس داده نمی‌شود. جدول `notifications` + `TicketNotification` بی‌خواننده | `Topbar.tsx:48-50` · `Shell.tsx:13` | K5.8 | ⏳ |
| **B14** | 🟡 breadcrumb روی همهٔ صفحات «پنل › مدیریت» است. ریشه‌اش یک باگ است نه یک نقطهٔ اتصال | `admin/layout.tsx:36` | از page descriptor | ⏳ |
| **B15** | 🟡 `SaveBar` برچسب «تأیید و ذفیره **قالب**» را هاردکد کرده — کامپوننت عمومی با واژهٔ دامنهٔ قالب | `primitives.tsx:66` | پارامتری کردن | ⏳ |
| **B16** | 🟡 پرمیشن‌ها namespaced نیستند ⇒ دو پلاگین با ماژول هم‌نام پرمیشن مشترک می‌گیرند | `ManifestRegistry.php:51-58` · `PluginController.php:409-412` | I6 | ⏳ |
| **B17** | 🟡 روی ZIP باز‌نشدنی، `result()` به `$summary['slug']` دست می‌زند که وجود ندارد | `Validator.php:43-45,462` | تست بستهٔ خراب | ⏳ |
| **B18** | 🟡 ثابت‌های مرده: `MAX_TABLE_COUNT`، `MAX_INDEXES_PER_TABLE`، `MAX_ACTIVE_PLUGINS` بی‌استفاده. `ensureSealKey()` هیچ caller ندارد ⇒ **K0.4 پیاده نشده**. `PackageAnalysis::items()` بی‌caller | `Contract.php:41,43,45` · `TrustStore.php:90` | مصرف یا حذف | ⏳ |
| **B19** | 🟡 `ensureSealKey` از `Cache::forever` ⇒ یک flush ریدایس، کلید را بی‌صدا بی‌اعتبار می‌کند | `TrustStore.php:100` | جدول settings | ⏳ |
| **B20** | 🟡 `hooks.not_yet_dispatched` هشدار است ولی `syncHooks` ردیف می‌نویسد و فرانت toast می‌دهد «هوک‌ها ثبت شدند». هوک‌ها **اجرا نمی‌شوند** | `Validator.php:293` · `PluginsClient.tsx:43` | متن صادقانه تا K5.5 | ⏳ |
| **B21** | 🟡 عدد جادویی ۲۰۰۰ کنار `MAX_FILES`=۵۰۰۰ | `Validator.php:191` | ثابت | ⏳ |
| **B22** | 🟡 تورفتگی شکسته در گروه `admin` | `routes/api.php:174-181` | تمیز | ⏳ |
| **B23** | 🟡 `api.d.ts` (۲۰۸KB) نوع دستی `PackageAnalysis` دارد که با `PackageAnalyzer.tsx:27` تکراری است | — | I8 | ⏳ |
| **B24** | 🔴 **XSS در بلوک CTA — تأییدشده.** `href` بدون هیچ sanitize مستقیم در `<a>` می‌رود. `safeHtml` (`:12`) فقط روی متن ریچ‌تکست کار می‌کند و خط `:19` پاک‌سازی‌اش فقط داخل `safeHtml` است. یعنی `javascript:alert(1)` در بلوک CTA زنده است. **قبل از باز کردن `site.block_type` باید درست شود** | `components/site/BlockRenderer.tsx:109` | رد `href` از `safeHtml` | ⏳ |
| **B25** | 🔴 **`requires.core` هرگز بررسی نمی‌شود** — تأییدشده با جست‌وجوی کامل. فقط در یک کامنت ذکر شده. یعنی کل لایهٔ سازگاری نسخه (`since`/`schema_version`) روی کاغذ است | `PluginPackageContract.php:18` | K3.0 | ⏳ |
| **B26** | 🔴 `example` نقطهٔ `admin.menu` ۳ از ۴ فیلدش غلط است — تأییدشده با مقایسهٔ واقعی. `Contract.php:87` می‌گوید `title`/`path` ولی `menu.ts:3` می‌خواهد `{ href, label, icon, key }`. عملاً داریم به برنامه‌نویس **فیلد غلط** یاد می‌دهیم | `Contract.php:87` × `menu.ts:3` | K3.1 | ⏳ |
| **B27** | 🔴 `extensionPoints()` فیلدهای تازهٔ قرارداد را **بی‌صدا drop** می‌کند. هر فیلدی که دستی به این map اضافه نشود از پاسخ API حذف می‌شود و UI نشانش نمی‌دهد | `Contract.php:137` | K3.2 | ⏳ |
| **B28** | 🔴 فلگ `json` سیگنال **ضدّهمبسته با واقعیت** است: تنها نقطهٔ زنده (`site.page_type`) آن را ندارد ولی ۵ نقطهٔ مرده دارند. اگر `openness` اضافه شود و `json` بماند، دو سیگنال رقیب داریم | `Contract.php:80,88-123,145` | K3.6 | ⏳ |
| **B29** | 🟠 کامنت `Contract.php:81` دربارهٔ کلید `builder` نوشته ولی هیچ نقطه‌ای `builder` ندارد. مستندات مرده | `Contract.php:81` | تمیز | ⏳ |
| **B30** | 🟠 `ManifestRegistry` برخورد `type` را بی‌صدا `continue` می‌کند. پلاگینی که `type` تکراری اعلام کند نصب می‌شود و **اصلاً دیده نمی‌شود** — بدترین حالت بی‌صدا | `ManifestRegistry.php:99-105,152-154` | قاعدهٔ مقطعی «عدم برخورد» ⇒ خطا | ⏳ |
| **B31** | 🟠 ۴ نقطه از ۶ `example` ندارند و `example` هیچ‌کدام رندر نمی‌شود | `Contract.php:95-123` · `PackageAnalyzer.tsx:264-267` | K3.7 | ⏳ |
| **B32** | 🟡 تصحیح اولویت B17: در `Validator.php:43-45` مقدار `$manifest` برابر `null` است پس `result()` شاخهٔ `:444` را رد می‌کند. مواجههٔ واقعی فقط undefined-index روی `Log::info` در `:457/459` است — warning نه crash. **اولویتش پایین‌تر از آن است که TASKS نشان می‌داد** | `Validator.php:41-46,457-463` | پایین | ⏳ |

### کاستی تست

| مورد | وضعیت |
|---|---|
| `isAllowedPath` هرگز برای **سمت مجاز** تست نمی‌شود. `describeAllowedPaths()` ۸ مقصد وعده می‌دهد و **۴ تایش بی‌تست‌اند**: `manifest.json`، `Next.js/panel`، `Next.js/blocks`، `Next.js/panel.json` | ⏳ |
| هیچ تستی تضمین نمی‌کند `isAllowedPath($suggest['path']) === true` — همان invariantی که کارفرما پرسید | ⏳ |
| نشت کلید فقط سطح‌بالا تست شده، نه تودرتو | ⏳ |
| مسیر ZIP باز‌نشدنی تست نشده | ⏳ |
| collision حروف بزرگ/کوچک بین مسیر مجاز و غیرمجاز تست نشده | ⏳ |

---

# 🟠 K3 — چارچوب قرارداد نقاط اتصال (I1 + اصل جدید کارفرما)

## اصل کارفرما (۱۴۰۵/۰۶/۰۴)

> «وقتی مشخص می‌کنیم هر نقطه چه اتفاقی می‌افتد، باید از او طرف هم پیاده شود که آن نقطه قابلیت اضافه شدن داشته باشد. یعنی **تمام بخش‌ها استاتیک باشه** و قابلیت تعریف **استاتیک** رو در **چارچوب خودش** داشته باشه. مثلاً سایدبار فقط **اسم و لینک** بتواند تعریف کند و چیز دیگری نتواند. ولی برای **ایجاد صفحات** دست را **کاملاً باز** بگذاریم.»

یعنی هر نقطهٔ اتصال دو چیز دارد که **الان هیچ‌کدام وجود ندارد**:
1. **schema مشخص** — دقیقاً چه فیلدهایی مجاز است
2. **درجهٔ باز/بسته بودن** — عمداً بسته یا عمداً باز

الان `EXTENSION_POINTS` فقط `label_fa`/`desc_fa`/گاهی `example` دارد. **هیچ schemaای نیست و اعتبارسنجی روی محتوای اعلان انجام نمی‌شود** — فقط نام نقطه چک می‌شود.

## تنش مرکزی و حل آن

کارفرما «باز» می‌خواهد. ولی اگر «باز» شامل JSX شود، قرد قرمز K1.5.8 می‌خورد.

> **«باز» فقط روی محور «نام فیلد» مجاز است، نه «نوع» و نه «ساختار».**

| گزینه | مشکل |
|---|---|
| پلاگین JSON Schema کامل بدهد | `SchemaForm.tsx:33` فقط یک map تفت را می‌چرخاند. `object` تودرتو و `array` از object **نه فرم دارند نه رندر**. نویسنده می‌نویسد، یک input متنی می‌بیند، یک بعدازظهر گم می‌کند |
| renderer عمومی بسازیم | `BlockRenderer.tsx:62` یک `switch` روی ۹ رشتهٔ هاردکد است. ۳–۵ روز کار اضافه |
| **گرامر micro-schema بسته + واژگان باز** ✅ | با `SchemaForm` و `BlockRenderer` موجود **منطبق** است |

```
مجاز:  string · integer · number · boolean · enum · media_id · media_ids · richtext
ممنوع: object تودرتو · array-of-object · $ref · oneOf/anyOf · additionalProperties · patternProperties · format:uri
```

**سقف باز بودن:** بی‌نهایت نوع بلوک/صفحه (تا سقف شمارشی ۲۰) · عمق تودرتویی **۱** · کلیدها `^[a-z][a-z0-9_]{0,39}$` · مقادیر فقط اسکالر یا enum — **هرگز** markup آزاد، **هرگز** URL، **هرگز** مسیر فایل.

⛔ **درجهٔ `free_form` ساخته نشود.** اگر در فهرست باشد، یک روز نقطه‌ای با آن برچسب می‌خورد و K1.5.8 عملاً از در بیرون می‌آید. نبودنش یک تصمیم امنیتی است.

## تصمیم‌های کارفرما (۱۴۰۵/۰۶/۰۴)

| # | موضوع | تصمیم |
|---|-------|--------|
| C1 | هدر پنل | ✅ **گزینهٔ سوم کارفرما:** یک دکمهٔ «ابزارهای پلاگین» در هدر، که drawer باز می‌کند و **هر پلاگین یک کادر مستطیل مستقل** می‌گیرد. نوار اصلی دست‌نخورده می‌ماند ⇒ مشکل فیشینگ حل شد |
| C2 | `Next.js/panel.json` | ✅ **حذف کامل** — کپی دومی بود که فقط می‌توانست drift کند. مانیفست تنها منبع حقیقت. امضا سالم می‌ماند چون `manifest.json` در ریشه است |
| C3 | میزان بازی schema | ✅ **بسته با واژگان باز** — گرامر بسته، نام فیلدها دلخواه |

> **قید C1:** داخل کادر، پلاگین **همان سطح ۰** را دارد — داده و schema، نه React. وگرنه همان K1.5.8 می‌شود.

## سه لایهٔ schema

| لایه | چیست | بسته؟ |
|---|---|---|
| **L1** | شکل آبجکتی که پلاگین در `panel.extensions[]` می‌نویسد | **همیشه** |
| **L2** | payload نقطه (مثلاً `admin.menu` خودش آیتم‌ها را اعلام می‌کند) | بسته |
| **L3** | واژگان باز: مجموعه فیلدهایی که *پلاگین* تعریف می‌کند | گرامر بسته، واژگان باز |

## ساختار دادهٔ قرارداد (جایگزین `EXTENSION_POINTS`)

```
'{point}' => [
  key, label_fa, desc_fa,
  status        => 'live' | 'declared_only' | 'deferred',   ← وضعیت واقعی runtime
  openness      => 'closed' | 'schema_defined' | 'open_vocabulary',
  openness_why  => 'یک جمله — برای راهنما، نه برای ماشین',
  since         => '1.2.0',        ← نسخهٔ هسته که نقطه را اضافه کرد
  schema_version => 1,              ← فقط وقتی L1/L2 عوض شد
  max           => ['declarations'=>10, 'bytes'=>4096, 'depth'=>1, 'properties'=>30],
  forbidden     => ['component','render','js','import','endpoint','html','style','path','template'],
  schema        => ['fields'=>[...], 'cross'=>[...]],
  open_schema   => bool,
  example_ok    => [...],
  example_bad   => [['why'=>'…','decl'=>[...]]],
]
```

`forbidden` **سراسری** است نه تک‌تک در schema — چون همهٔ نقاط باید داشته باشند و تکرارش یعنی drift.

## جدول تصمیم — هر نقطه

| نقطه | درجه | دلیل انتخاب درجه | وضعیت | فیلدهای L1/L2 |
|---|---|---|---|---|
| `admin.menu` | **closed** | خروجی‌اش `MenuItem` است با ۴ فیلد ثابت. هیچ چیزی برای «باز کردن» نیست | live | `key!` `label!` `href!` `icon?` `order?` `permission?` |
| `site.page_type` | **open_vocabulary** | خواستهٔ صریح کارفرما. داده در `page.meta` (ستون JSON آزاد). هیچ سطح حمله‌ای ندارد چون خودش رندر نمی‌شود | live ✅ | `slug!` `title_fa!` `desc_fa?` `default_blocks[]` + **L3** |
| `site.block_type` | **open_vocabulary** | تنها جایی که «بی‌نهایت» واقعاً ارزش دارد | declared_only | `type!` `title_fa!` `schema!`(L3) `labels?` |
| `site.header_widget` | **schema_defined** | نوع ویجت قفل (هسته رندر می‌کند) ولی `settings` باز است چون از `block_type` خودِ پلاگین می‌آید | declared_only | `type!` `title_fa!` `order?` `settings?`(L3) |
| `site.footer_widget` | **schema_defined** | همان | declared_only | همان |
| `admin.settings_schema` | **schema_defined** | `SchemaForm` آماده است؛ فقط صفحهٔ میزبان کم است | declared_only | `key!` `title_fa!` `group!` `schema!`(L3) |
| `admin.data_collection` | **schema_defined** | `DataTable.tsx:5` ستون‌اش **تابع** می‌خواهد ⇒ تا اعلانی نشده روی کاغذ است | declared_only | `entity!` `title_fa!` `table!` `columns!`(L3) |
| `admin.plugin_tools` | **closed** | 🆕 کادرهای drawer هدر پنل (تصمیم C1). خروجی = یک کادر با عنوان و محتوای schema-driven | 🆕 | `key!` `title_fa!` `icon?` `order?` `body!`(L2) |
| `admin.page_registry` | **schema_defined** | 🆕 صفحهٔ اختصاصی. فقط مسیر + پرمیشن؛ render از page descriptor | 🆕 | `path!` `title_fa!` `permission!` `layout!`(enum) |
| `admin.dashboard_slot` | ⛔ **deferred** | نه primitive دارد نه مصرف‌کننده. **schema بدون renderer = دروغ ساختاریافته** | ❌ | — |

### چه چیزی عمداً حذف شده و چرا

| حذف‌شده | دلیل |
|---|---|
| `component` / `render` / `component_path` از **همهٔ** نقاط | K1.5.8. `forbidden` سراسری |
| `href` آزاد از `admin.plugin_tools` | اگر لازم است: `notification_id: integer` که هسته به URL تبدیلش می‌کند |
| `area` از `header_widget`/`footer_widget` | نقطه خودش area است ⇒ فیلد تکراری فقط اختلاف‌ساز |
| `style`/`class`/`html` از همه | CSS-in-JS / HTML خام = همان سطح خطر JS |
| `endpoint` از `admin.menu` و `plugin_tools` | اگر پلاگین `endpoint` بدهد، خودش fetch می‌کند ⇒ دور زدن schema |
| `icon` آزاد | آیکون‌ها SVG درون‌خطی هسته‌اند. باید از **فهرست بستهٔ هسته** انتخاب شود |

### قواعد امنیتی خاص هر نقطه

| نقطه | قاعده |
|---|---|
| `admin.menu` | `href` باید دقیقاً `^/admin(/[a-z0-9._-]+)*$` باشد. **نه** `http://`، **نه** `//evil.com` (protocol-relative)، **نه** `javascript:`. یک regex، هم open-redirect و هم XSS را می‌کُشد |
| `admin.plugin_tools` | `href` اصلاً وجود ندارد. `order` ندارد (حذف آیتم = برخورد با چیدمان هسته) |
| `site.block_type` | `type` نباید یکی از ۹ نوع هسته باشد — چون `BlockRenderer.tsx:99-105` برخورد را **بی‌صدا** `continue` می‌کند و پلاگین نصب می‌شود ولی اصلاً دیده نمی‌شود |
| `admin.page_registry` | `path` باید زیر `/admin/` باشد (K6.6) و در prefix جدول `admin.menu` نباشد ⇒ برخورد مسیر |
| همهٔ نقاط | عمق ≤ ۱، مجموع JSON اعلان‌ها ≤ ۶۴KB، `enum` ≤ ۵۰ عضو، `maxLength` ≤ ۴۰۰۰ |

### قواعد مقطعی (`cross`) — سراسری

| قاعده | دلیل |
|---|---|
| `type`/`key` نباید با `config/blocks.php` یا `config/widgets.php` برخورد کند | `ManifestRegistry.php:99-105,152-154` با `continue` **بی‌صدا** رد می‌کند. امروز پلاگین بی‌دلیل نادیده گرفته می‌شود؛ با schema باید **خطا** بدهد |
| `permission` باید با `plugin:{slug}:` شروع شود | B16 |
| `entity` باید دقیقاً `{manifest.slug}_{x}` باشد | K1.5.3 |
| `title_fa` اجباری برای هر چیزی که به کاربر نشان داده می‌شود | وگرنه UI متن خالی می‌شنود |

## لایهٔ اعتبارسنجی

| موضوع | تصمیم |
|---|---|
| **کجا** | متد **تازه و خالص** روی `PluginPackageContract`: `validateDeclaration(string $point, array $decl): list<Issue>` — بدون DB، بدون ZIP |
| **کدام step در `analyze()`** | **جدا از `manifestChecks()`** (`:206`). چون `panelFileChecks()` (`:310`) هم به همین نیاز دارد و `manifestChecks` در `:300` آن را صدا می‌زند ⇒ اگر داخلش باشد schema دوبار اجرا می‌شود |
| **کتابخانه یا دستی** | **دستی.** `composer.json` هیچ JSON-Schema library ندارد. ضمناً ما JSON Schema کامل **نمی‌خواهیم** — زیرمجموعهٔ بسته. یک اعتبارسنج ۳۰ خطی بهتر از وابستگی‌ای است که `$ref` و `patternProperties` را هم می‌پذیرد |
| **fail-closed** | نقطهٔ ناشناخته یا schema ناسازگار ⇒ **خطا**. هیچ حالت «هشدار» برای نقض schema |
| **شکل خطا** | `code` + `path` (`panel.extensions[0].href`) + پیام فارسی + **`allowed[]`** + **`pattern`**. چون کاربر برنامه‌نویس است و باید بتواند درست کند |

## سازگاری رو به جلو

| پرسش | پاسخ |
|---|---|
| پلاگین قدیمی + نقطهٔ جدید | خطای تمیز + فهرست معتبر + `since` هر نقطه. پیام: «نقطهٔ X در هستهٔ ۱.۳ اضافه شد. `requires.core` شما `^1.2` است» |
| `since` جای `requires.core` را می‌گیرد؟ | **نه.** لایهٔ دوم است: `requires.core` سازگاری *هسته*، `since` سازگاری *نقطه*. پیام خطا هر دو را گزارش کند |
| گسترش schema | **افزایشی‌تنها درون یک major.** فیلد جدیدِ **اختیاری** ⇒ `schema_version++`. `additionalProperties:false` همیشه |
| **جهت واقعی مشکل** | پلاگین **جدید روی هستهٔ قدیم** (نه برعکس). `since`/`schema_version` این را می‌گیرد |
| حذف نقطه | هرگز ناگهانی. سه سطل: `live` · `deprecated` · `deferred`. `unknown` فقط برای نقطه‌ای که **هرگز** وجود نداشته |

## گام‌ها

| # | تسک | وضعیت |
|---|------|--------|
| **K3.0** | ⭐ **enforce کردن `requires.core`** | `requires` **الان هیچ‌جا بررسی نمی‌شود** — فقط در کامنت `PluginPackageContract.php:18`. یعنی کل لایهٔ سازگاری روی کاغذ است. **پیش‌شرط I5** | ⏳ |
| **K3.1** | ⭐ اصلاح `example` غلط `admin.menu` | 🔴 `Contract.php:87` می‌گوید `title`/`path`/`icon`/`permission` ولی واقعیت `lib/menu.ts:3` است: `{ href, label, icon, key }`. **۳ از ۴ فیلد اشتباه** — عملاً داریم به برنامه‌نویس فیلد غلط یاد می‌دهیم. `permission: 'posts.view'` هم بدون prefix اسلاگ است | ⏳ |
| **K3.2** | اصلاح `extensionPoints()` که فیلدها را drop می‌کند | 🔴 `Contract.php:137` یک map **دستی** است. هر فیلد تازهٔ قرارداد که دستی اضافه نشود **بی‌صدا از پاسخ API حذف می‌شود** و UI نشانش نمی‌دهد | ⏳ |
| **K3.3** | ⭐ `openness` + `status` + `since` + `schema_version` + `forbidden` + `max` | ساختار دادهٔ بالا | ⏳ |
| **K3.4** | `validateDeclaration()` — متد خالص، دستی، fail-closed | | ⏳ |
| **K3.5** | 🔴 حذف `Next.js/panel.json` از `ALLOWED_PATHS` + حذف `panelFileChecks` + رفع وابستگی تست | تصمیم C2 | ⏳ |
| **K3.6** | ⛔ حذف فلگ `json` | سیگنال **ضدّهمبسته با واقعیت**: تنها نقطهٔ زنده (`site.page_type`) این فلگ را ندارد ولی ۵ نقطهٔ مرده دارند | ⏳ |
| **K3.7** | `example_ok` + `example_bad` برای هر نقطه | تست و راهنما هر دو از آن استفاده می‌کنند | ⏳ |
| **K3.8** | ⭐ schema فقط برای `admin.menu` (بسته) + `site.page_type` (باز) | **این دو همهٔ چهار درجه را با دو نمونه ثابت می‌کنند.** نوشتن ۱۰ schema برای ۱۰ renderer غایب = ۱۰ منبع حقیقت که در سه ماه دروغ می‌گویند | ⏳ |
| **K3.9** | schema بقیهٔ نقاط — **فقط بعد از ساخت renderer هرکدام** | | ⏳ |
| **K3.10** | کدهای خطای schema تولیدشده از خود قرارداد | `CODE_FA` در `PackageAnalyzer.tsx:46` دستی است ⇒ ۱۰ کد تازه بدون ترجمه، `issue.code` خام در UI می‌افتد | ⏳ |
| **K3.11** | `admin.plugin_tools` — نقطهٔ drawer هدر پنل (تصمیم C1) | | ⏳ |

## چیزهایی که با خواستهٔ کارفرما مخالف شد

| # | مخالفت | چرا |
|---|--------|-----|
| **X1** | `admin.topbar` به‌عنوان نقطهٔ اتصال ❌ | `Topbar.tsx:20-73` نوار **affordanceهای اصلی** است. افزودنی که آنجا بنشیند یا نامرئی است یا بردار فیشینگ. **⇒ راه‌حل کارفرما (drawer) جایگزین بهتری است و پذیرفته شد** |
| **X2** | `admin.dashboard_slot` به `DEFERRED` | **schema این بدافزار را بدتر می‌کند:** مثال معتبر + validator سبز + نصب موفق + بعد هیچی رندر نمی‌شود = دروغ ساختاریافته |
| **X3** | ۱۰ schema همزمان ننویس | ۴ نقطه مصرف‌کننده ندارند |
| **X4** | `panel.json` حذف شود نه هم‌سان‌سازی | تصمیم C2 پذیرفته شد |
| **X5** | درجهٔ `free_form` ساخته نشود | تنها راهی که ممکن است K1.5.8 را دور بزند |

## نامطمئن‌ها

| مورد | چطور بفهمیم |
|---|---|
| آیا `panel.json` باید کل اعلان را برساند یا فقط کلیدها؟ | **حذف شد (C2) ⇒ بی‌موضوع** |
| آیا `richtext` در micro-grammar بماند؟ | تصمیم محصول: `SchemaForm.tsx:95` روی کلید `body` شرطی است، پس فقط یک شکل خاص پشتیبانی می‌شود |
| رفتار `admin.page_registry` با `next/dynamic` | `K3.9` آن را برای افزونهٔ عمومی رد کرده ⇒ قبل از schema باید تکلیفش روشن شود |


| # | تسک | توضیح | وضعیت |
|---|------|-------|--------|
| **I1** | ⭐ `panel.extensions` را تنها شکل کن | `widgets` / `page_types` / `permissions` / `hooks` مهاجرت کنند. `ManifestRegistry` از `panel.extensions` بخواند. مهاجرت تدریجی با dual-read | ⏳ |
| **I1.1** | `manifest.widgets.*` → `site.header_widget` / `site.footer_widget` | `ManifestRegistry.php:86-117` | ⏳ |
| **I1.2** | `manifest.page_types` → `site.page_type` | `ManifestRegistry.php:146-160` | ⏳ |
| **I1.3** | `manifest.permissions` → نقطهٔ مستقل یا فیلد مجزا | `ManifestRegistry.php:271-305` | ⏳ |
| **I1.4** | تصمیم `manifest.hooks`: یا dispatcher بساز (K5.5) یا از مانیفست حذف کن | `PluginController.php:380-397` | ⏳ |
| **I2** | رجیستری نقاط اتصال از **یک منبع** | `PackageAnalyzer.tsx` نباید فهرست را hardcode کند. الان دو منبعِ جدا داریم که قطعاً drift می‌کنند | ⏳ |
| **I3** | `example` هر نقطهٔ اتصال در UI رندر شود | `Contract.php:87,93` دارد ولی `PackageAnalyzer.tsx:42` آن را `unknown` می‌گیرد | ⏳ |
| **I4** | ⛔ `next/dynamic` برای افزونهٔ عمومی رد شد | بازی با شانس build. فقط `SystemPlugin` می‌تواند از catch-all استفاده کند ⇒ دقیقاً خلاف K1.5.8 | ⏳ |
| **I5** | سازگاری نسخه برای نقاط اتصال | وقتی `requires.core` به سازگاری می‌رسد، **نقاط اتصال هم باید نسخه بگیرند** | ⏳ |
| **I6** | namespacing پرمیشن با slug | B16 | ⏳ |
| **I7** | رجیستری جستجوی سمت بک‌اند | `SearchableProvider` + `SearchManager` از `config('search.providers')` استاتیک به رجیستری داینامیک | ⏳ |
| **I8** | `api.d.ts` از تولید | حذف نوع دستی، تولید از OpenAPI | ⏳ |

## فهرست نهایی نقاط اتصال (نسخهٔ متخصص)

| key | چه می‌کند | اولویت | برآورد | سطح ۰ کافی؟ |
|---|---|---|---|---|
| `admin.menu` | آیتم منوی کناری + `BottomNav` خودکار | **بحرانی** | ۱ روز | ✅ |
| `site.block_type` | شاخهٔ `default` در `BlockRenderer` از روی schema | **بحرانی** | ۱–۱.۵ | ✅ |
| `site.footer_widget` | شاخهٔ `default` در `ChromeWidget` | **بالا** | ۰.۵ | ✅ |
| `site.header_widget` | همان | **بالا** | ۰.۵ | ✅ |
| `admin.page_registry` | 🆕 صفحهٔ اختصاصی — `admin/[...slug]/page.tsx` | **بالا** | ۲ | ✅ |
| `admin.settings_schema` | `SchemaForm` عمومی + ذخیره در گروه `plugin:{slug}` | متوسط | ۱.۵ | ✅ |
| `admin.topbar` | 🆕 **افزودنی** — هدر پنل (یادآوری کارفرما) | متوسط | ۱.۵–۲ | ✅ با sanitize سخت‌گیرانه |
| `site.page_type` | کار می‌کند؛ فقط به `panel.extensions` وصل شود | متوسط | ۰.۵ | ✅ |
| `admin.data_collection` | جدول + CRUD اعلانی | **پایین (v1.1)** | ۳–۴ | ⚠️ `DataTable` باید اعلانی شود |
| `admin.dashboard_slot` | ویجت داشبورد | ⛔ **تعویق به `DEFERRED_EXTENSION_POINTS`** | ۳–۴ | ❌ |

### سه چیزی که متخصص صریحاً پیشنهاد نکرد

1. **`admin.dashboard_slot` در v1.** تنها نقطه‌ای که به primitive تازه نیاز دارد و هیچ مصرف‌کنندهٔ واقعی ندارد. نگه‌داشتنش در فهرست بسته = «قولی که پیاده نشده» — و `PackageAnalyzer.tsx:264` دقیقاً همین را به کاربر قول می‌دهد.
2. **افزودن هر نقطهٔ تازه قبل از واقعی کردن ۵ نقطهٔ موجود.** الان ۸ نقطه اعلام شده و ۱ کار می‌کند؛ ساختن دهمی بدهی را دو برابر می‌کند بی‌آنکه نیازی را حل کند.
3. **باز نگه‌داشتن فهرست برای «شاید بعداً».** مکانیزم `DEFERRED_EXTENSION_POINTS` + تست + برچسب فارسی دلیل از قبل وجود دارد. هر «نه» باید **نوشته‌شده** باشد، نه غایب.

### سطوحی که بررسی شد و پیشنهاد **نشد**

| نقطهٔ فرضی | چرا نه |
|---|---|
| `admin.bottom_nav` | نقطهٔ مستقل نیست. `BottomNav.tsx:44-46` از `groups` می‌گیرد ⇒ با merge منو خودکار کار می‌کند (۰.۲۵ روز، داخل `admin.menu`) |
| `admin.command_palette` | میانبرهای `dashboard/page.tsx:117-124` هاردکد و کم‌ارزش |
| `site.seo_meta` | ستون `meta` از قبل JSON آزاد است. زیرساخت هست |
| `admin.email_template` | **v2.** سطح pre-compiled (PHP). ارزش واقعی دارد ولی الان کسی نیازش ندارد |
| `admin.export/import`، `admin.media_library`، `admin.user_form` | هیچ‌کدام زیرساخت موجودی ندارند |
| `admin.component` | ⛔⛔ رد قطعی (K1.5.8). تنها استثنا: رجیستری جستجوی کلاینت که **الان** این را دور می‌زند (B5) |

---

# 🟠 K4 — حالت توسعه‌دهنده (بند ۱ و ۲ مشخصات)

> `dev_mode` طبق B3 **صفر پیاده‌سازی** دارد. تصمیم‌ها قبلاً گرفته شده: per-install، لاگ کامل، re-auth با رمز، انقضای خودکار.

| # | تسک | توضیح | وضعیت |
|---|------|-------|--------|
| K4.1 | شمارندهٔ کلیک در cache با کلید `devmode:taps:{user_id}\|{ip}` | شمارنده per-user+ip ولی وضعیت per-install. بدون per-user یک POST ساده در می‌زند | ⏳ |
| K4.2 | قاعده‌های reset: ۲ ثانیه بین کلیک، ۱۰ ثانیه از اولین، درخواست غیر POST، خروج از حساب، ۵ دقیقه بی‌فعالیتی | — | ⏳ |
| K4.3 | مودال تأیید **با رمز عبور** (نه checkbox) + re-auth سمت سرور | چون gesture سمت کلاینت است و در برابر CSRF ضعیف | ⏳ |
| K4.4 | وضعیت `dev_mode` در `settings` گروه `system` + `expires_at` (۸ ساعت) + `unlock_token` | انقضای خودکار غیرقابل مذاکره است | ⏳ |
| K4.5 | ثبت در `operator_activities` | بعد از نصب یک پلاگین بد، همین ردپا تفاوت «می‌توانیم عیب‌یابی کنیم» و «کور هستیم» است | ⏳ |
| K4.6 | ⛔ **enforce سمت سرور** روی `upload()` و `upgrade()` | B3. middleware، نه gate سمت کلاینت | ⏳ |
| K4.7 | فرانت: ۵ کلیک + مودال در تنظیمات | — | ⏳ |
| K4.8 | فرانت: گیت کردن دکمهٔ آپلود + نمایش راهنما | بند ۲ | ⏳ |
| K4.9 | نمایش زمان باقی‌مانده در UI | — | ⏳ |

---

# 🟠 K5 — هستهٔ اجرایی پلاگین (K1.1)

> تا این فاز، هیچ پلاگینی اجرا نمی‌شود و بازار و ۳۰٪ معنا ندارد.

| # | تسک | توضیح | وضعیت |
|---|------|-------|--------|
| **K5.0** | ⭐ **نقطهٔ `core.service_provider`** (bootstrap/binding) | **کوچک‌ترین کار با بیشترین بازگشت کل پروژه.** فقط کلاس‌هایی که interface مشخصِ هسته را پیاده کنند. بدون آن: `SubscriptionSource` قابل ثبت نیست ⇒ گزینهٔ الفِ K7.2 شکست می‌خورد؛ `SearchableProvider` قابل ثبت نیست ⇒ B5/I7 بسته نمی‌شود؛ `PaymentGatewayInterface` و `ChabokanClientInterface` (هر دو singleton هاردکد در `AppServiceProvider.php:20-39`) قابل تعویض نیستند | ⏳ |
| K5.1 | `PluginExtractor` — ۱۱ کنترل استخراج امن | گام ۱ (فهرست ZIP) **در K1.6.3 انجام شد**. گام‌های ۲ تا ۱۱ باقی است | 🟡 ۱ از ۱۱ |
| K5.2 | ساختار `releases/{version}-{hash}/` + `current` + استخراج atomic | staging → verify → rename. هرگز مستقیم روی مسیر زنده | ⏳ |
| K5.3 | PSR-4 loader با prefix اجباری `Pishdad\Plugins\{Studly}\` | با `ClassLoader::addPsr4()` روی نمونه. **رد هر namespace خارج از prefix** — تنها جایی که امنیت بارگذاری واقعاً enforce می‌شود | ⏳ |
| K5.4 | `PluginRouter` catch-all — تنها route واقعی مالک هسته باشد | تداخل ساختاری ناممکن، `route:cache` معتبر، غیرفعال‌سازی = یک فلگ | ⏳ |
| K5.5 | dispatcher هوک **یا** حذف `hooks` از مانیفست | B20. فعلاً هیچ‌کدام | ⏳ |
| K5.6 | migration runner با prefix `{slug}_` + سقف ۲۰ جدول / ۱۰ ایندکس | + بررسی تداخل با جدول‌های هسته | ⏳ |
| K5.7 | ⭐ نقش دوم PostgreSQL (K1.5.3) | نقش `pishdad_owner` برای `migrate`، نقش `pishdad_app` برای runtime با DDL فقط روی `{slug}_*`. تا وقتی این enforce نشده، prefix فقط **انتخاب** است نه **محدودیت**. ⚠️ متخصص نامطمئن است که مکانیزم migration لاراول ۱۱ زیر این تفکیک بدون تغییر کار کند ⇒ spike دو-روزه | ⏳ |
| K5.8 | سرویس اعلان (`POST /v1/admin/notifications` با namespacing بر slug) + drawer + نشانگر | B13. **بیرون از فهرست نقاط اتصال** — سرویسی است که پلاگین صدایش می‌زند نه چیزی که اعلام می‌کند | ⏳ |
| K5.9 | مهر یکپارچگی per-install (K0.4) | `ensureSealKey` الان بی‌caller است (B18/B19). کلید در `settings` نه cache | ⏳ |
| K5.10 | سقف `file_count` و سرپوش opcache | ۵۰ پلاگین × ۲۰۰ فایل = ۱۰هزار فایل | ⏳ |
| K5.11 | rollback واقعی | `upgrade()` الان فایل قبلی را حذف می‌کند ⇒ rollback فقط یک رشتهٔ `previous_version` بدون آرتیفکت است | ⏳ |

---

# 🟠 K6 — UI پلاگین در فرانت (سطح ۰)

> ⛔ رد‌شده در v1: کامپوننت React از سمت پلاگین (B5) · build در زمان اجرا · `next/dynamic` برای افزونهٔ عمومی (I4).

| # | تسک | ارجاح | وضعیت |
|---|------|--------|--------|
| K6.1 | `admin.menu` — merge روی props سرور | `lib/menu.ts:6` ثابت → `core + mergePluginMenu()`. `admin/layout.tsx:33` همه‌چیز را props می‌گیرد ⇒ `Sidebar.tsx` بدون تغییر bundle | ⏳ |
| K6.2 | 🆕 `admin.plugin_tools` — دکمهٔ «ابزارهای پلاگین» + drawer (تصمیم C1) | `Topbar.tsx:20-73` خطی و هاردکد. **نوار اصلی دست‌نخورده می‌ماند** — یک دکمه اضافه می‌شود، drawer باز می‌کند، هر پلاگین یک کادر مستقل با برچسب نامش می‌گیرد. `href` آزاد ممنوع؛ `notification_id: integer` که هسته به URL تبدیلش می‌کند | ⏳ |
| K6.3 | `site.block_type` — شاخهٔ `default` در `BlockRenderer` | `BlockRenderer.tsx:195` الان `null` برمی‌گرداند و فقط `console.warn` می‌کند. **بدترین نوع نیمه‌کاری: بی‌صدا** | ⏳ |
| K6.4 | `site.header_widget` + `site.footer_widget` | `Chrome.tsx:83-85` همان مشکل | ⏳ |
| K6.5 | `admin.page_registry` — catch-all داخل گروه admin | ⚠️ **تصحیح برنامه:** K1.6.5 اشتباه گفت `[...path]/page.tsx`. آن رندرکنندهٔ **سایت عمومی** است (`:119` `SiteHeader`، `:137` `SiteFooter`) — مسیر `/admin/xyz` با کروم سایت عمومی رندر می‌شد. درست: `app/(client)/admin/[...slug]/page.tsx` | ⏳ |
| K6.6 | ⛔ قید: مسیرهای پلاگین **باید** زیر `/admin/` باشند | `src/middleware.ts:14` فقط `/admin/:path*` و `/central/:path*` را محافظت می‌کند و آن هم فقط **وجود کوکی** را می‌سنجد نه اعتبارش. مثال قرارداد رعایت می‌کند ولی هیچ‌جا **اجبار** نشده | ⏳ |
| K6.7 | `admin.settings_schema` | `SchemaForm.tsx` همین حالا وجود دارد | ⏳ |
| K6.8 | `site.page_type` → وصل به `panel.extensions` | تنها نقطهٔ کارکن | ⏳ |
| K6.9 | merge crumbs از page descriptor | B14. یک باگ واقعی را هم درست می‌کند | ⏳ |
| K6.10 | پاک کردن یا واقعی کردن کارت ویجت داشبورد | `dashboard/page.tsx:155-163` با `admin.dashboard_slot` تعویق بخورد، این کارت یا حذف یا واقعی شود | ⏳ |
| K6.11 | راهنمای برنامه‌نویس از قرارداد تولید شود | K1.6.2. سه خطای محتوایی فعلی: ساختار ZIP، فیلد `system`، کلید مرکزی | ⏳ |

---

# 🔵 K7 — تبدیل پنل مرکزی به پلاگین (درخواست کارفرما)

> **۱۱ فایل هسته** به جداول مرکزی چسبیده‌اند. تا این ۱۱ تا جدا نشوند، «پنل مرکزی = پلاگین» فقط روی کاغذ است.

## واقعیت‌های کلیدی

| واقعیت | عدد |
|---|---|
| فایل‌های هستهٔ وابسته به جداول مرکزی | **۱۱** |
| جدول‌ها | **۱۹** — ولی **۱۱ تایش اصلاً `central_` ندارند!** یک `grep central_` فقط ۱۰ جدول پیدا می‌کند و **نیمی از blast radius از دست می‌رود**: `install_heartbeats`, `central_logs`, `core_versions`, `update_rollouts`, `update_rollout_items`, `operator_hardware_keys`, `operator_setup_tokens`, `operator_activities`, `kill_switches` |
| routeهای مرکزی | ۶۱ (`routes/api.php:210-288`) |
| TSX پنل مرکزی | ۲۰۶۰ خط در ۹ کامپوننت |
| ارجاع رشته‌ای به نام جدول | ۴۰+ مورد — با `rename` نمی‌شکنند ولی **هیچ ابزار refactor خودکاری هم پیدایشان نمی‌کند** |

## ۱۱ فایل هسته — حساسیت هرکدام

| فایل | اگر پلاگین مرکزی حذف شود |
|---|---|
| `Services/Subscription/ClientSubscriptionResolver.php:22-39` | 💥 **کل پنل مشتری ۵۰۰ می‌دهد.** `admin/layout.tsx:12` روی هر صفحه صدایش می‌زند |
| `Api/Webhooks/PaymentWebhookController.php:6` | 💥💥 **callback درگاه.** زرین‌پال ۵۰۰ ⇒ پرداخت ضریب می‌شود |
| `Console/Commands/BillingSuspend.php:5-7` | 💥 **تعلیق خودکار از کار می‌افتد ⇒ هیچ‌کس هرگز تعلیق نمی‌شود** |
| `Api/V1/Admin/DashboardController.php:6` | 💥 داشبورد + بنر تعلیق |
| `Api/V1/Admin/SubscriptionController.php:6-9` | 💥 صفحهٔ اشتراک (`exists:central_plans,id`) |
| `Api/V1/SubscriptionStatusController.php:6` | 💥 polling عمومی |
| `Services/Billing/InvoiceService.php:5-8` | 💥 صدور فاکتور |
| `Console/Commands/BillingRemind.php` · `BillingPurge.php` | یادآوری/نشت رکورد |
| `Api/Internal/HeartbeatController.php:6-7` | مانیتورینگ مرکزی |
| `Api/V1/Admin/ProfileController.php:6` | ⚠️ نمایش کلید سخت‌افزاری 2FA |

## گام‌ها

| # | تسک | توضیح | وضعیت |
|---|------|-------|--------|
| **K7.1** | ⭐ اصلاح نقشهٔ وابستگی — تست grep | **پیش‌نیاز همه‌چیز.** تستی که بگیرد هیچ فایل هسته‌ای به `Central\*` یا `central_`/`chabokan_`/`install_heartbeats`/`operator_*`/`core_versions`/`update_roll*`/`kill_switches` ارجاع ندارد | ⏳ |
| **K7.2** | ⭐ رابط `SubscriptionSource` + `NullSubscriptionSource` پیش‌فرض | **گزینهٔ الف.** هسته یک interface؛ پیش‌فرض همیشه `active` برمی‌گرداند و هیچ جدولی نمی‌خواند ⇒ **پنل مشتری دیگر به نصب پلاگین وابسته نیست.** نبودِ پلاگین = سایت کار می‌کند، فقط بی‌اشتراک (برای نصب عمومی دقیقاً درست است) | ⏳ |
| **K7.3** | ثبت پیاده‌سازی از سمت پلاگین | ⚠️ **نیازمند `core.service_provider` (K5.0)**. بدون آن این گام غیرممکن است | ⏳ |
| **K7.4** | تصمیم محصول دربارهٔ `now()->addDays(30)` | `ClientSubscriptionResolver.php:37` هنگام autoprovision **۳۰ روز اشتراک رایگان می‌سازد**. اگر داخل پلاگین برود رفتار عوض می‌کند و `SubscriptionTest.php` می‌شکند | ⏳ منتظر تصمیم |
| **K7.5** | ۱۹ `ALTER TABLE ... RENAME` با prefix | ⚠️ **۱۱ جدول بی‌پیشند را دستی نام‌گذاری کن** — وگرنه از قلم می‌افتند | ⏳ |
| **K7.6** | مهاجرت داده برای نصب‌های موجود | ترتیب FKها مهم است (`central_subscriptions.client_id → central_clients.id`) | ⏳ |
| **K7.7** | تست grep برای ۴۰+ ارجاع رشته‌ای | `InvoiceController.php:31,67` · `UpdateController.php:55` · `ClientController.php:73` · `PlanController.php:60` | ⏳ |
| K7.8 | انتقال ۱۳ کنترلر `Api/Central/*` + ۱۲ مدل + `InvoiceService` + ۳ کامند | کار مکانیکی | ⏳ |
| K7.9 | انتقال ۶۱ route با **همان prefix** `/api/central/*` | ⇒ **صفر تغییر فرانت.** بزرگ‌ترین بازدهی کار | ⏳ |
| **K7.10** | ⛔ `EnsureCentralOperator` **در هسته بماند** | `app/Http/Middleware/EnsureCentralOperator.php:13-27`. **نگهبانِ نگهبان در هسته می‌ماند** — اگر داخل پلاگین باشد، یک پلاگین بد می‌تواند خودش را operator کند | ⏳ |
| **K7.11** | ⛔ `tickets` + `operatorReply` **در هسته بماند** | `routes/api.php:260` مسیر مرکزی به کنترلر **مشتری** اشاره می‌کند. سیستم تیکت دادهٔ هسته است — این یک الگوی درست است: پلاگین روی دادهٔ هسته کار می‌کند | ⏳ |
| **K7.12** | ⛔ `PaymentWebhookController` **در هسته بماند** | **عمدی.** callback درگاه باید همیشه زنده باشد. پلاگین مالک *invoice* است نه *مسیر callback*. باید fail-closed با پیام فارسی و retry باشد | ⏳ |
| **K7.13** | تصمیم فرانت — ⭐ **گزینهٔ ۱ الان، گزینهٔ ۲ به‌عنوان هدف** | استثنای `SystemPlugin` **فقط سمت بک‌اند جواب می‌دهد.** فرانت: ⛔ هیچ `next/dynamic`، هیچ registry، هیچ catch-all. ۲۰۶۰ خط TSX بدون سازوکار.<br>**۱) پوستهٔ نازک در هسته** — ۳ روز، ۸۰٪ ارزش، هدف K1.0 کامل نیست<br>**۲) پوشهٔ vendorشده** `frontend/src/plugins-vendor/{slug}/` — ۲ روز + هزیز مستمر، ۱۰۰٪<br>**۳) بازسازی اعلانی** — ۶–۱۰ روز، ۶۰٪. ❌ **پیشنهاد نمی‌کنم**: ۲۰۰۰ خط UI خوب را دور می‌ریزید | ⏳ منتظر تصمیم |
| **K7.14** | اگر گزینهٔ ۲: ۴ شرط تست‌شدنی استثنا | ۱) دامنهٔ slug: CI fail شود اگر `plugins-vendor/` چیزی غیر از `system_plugins` داشته باشد. ۲) فقط `frontend/src/plugins-vendor/{slug}/`. ۳) قاعدهٔ هم‌commit در `AGENTS.md`. ۴) تست کامپایل. **بدون ۳ و ۴، استثنا یک کانال جانبی بدون نگهدارنده است** | ⏳ |
| **K7.15** | داده‌محور کردن `lib/menu.ts:44-47` و `Topbar.tsx:34-37` | سوییچ مشتری/مرکزی الان هاردکد است. حذف پلاگین باید این دو را بردارد ⇒ باید داده‌محور شوند | ⏳ |
| **K7.16** | نمایش `dev_mode`/قفل‌های مرکزی به‌صورت داده‌محور | `kill_switches` و `AppServiceProvider`/`routes/api.php:284-288` دستی هاردکد شده‌اند | ⏳ |

## قاعدهٔ کلی خط هسته/پلاگین

> **اگر حذف پلاگین، توانایی استفاده از سایت عمومی را از کار بیندازد، آن فایل در هسته است.** امروز **۱۱ فایل نقض می‌کنند.**

---

# ⚪ K8 — بازار و فروش (بعد از K5)

| # | تسک | وضعیت |
|---|------|--------|
| K8.1 | ثبت ناشر + trust store عمومی | pending |
| K8.2 | آپلود به بازبینی + تأیید/رد | pending |
| K8.3 | `yanked` — **غیرقابل حذف**، برای incident امنیتی | pending |
| K8.4 | نصب از بازار (بدون پول) | pending |
| K8.5 | سفارش + لایسنس + ledger تسویه + payout دستی | pending |
| K8.6 | درگاه | ⏸️ milestone جدا، فقط اگر از مشتری پول گرفته شود |

---

# 📋 ترتیب اجرای قطعی

> هر گام بدون گام قبلش معنا ندارد یا بی‌فایده است.

| ترتیب | گام | چرا اینجا |
|---|---|---|
| ۱ | **K2** — باگ‌های امنیتی | B4 و B5 امنیتی‌اند؛ B6 یعنی مسیر اصلی کاربر خراب است؛ B2 با پایهٔ کاربر ایرانی محتمل است |
| ۲ | **K3 / I1** — `panel.extensions` مرجع شود | تا این حل نشود، هر نقطهٔ اتصالی روی دو جهان موازی ساخته می‌شود |
| ۳ | **K5.0** — `core.service_provider` | کوچک‌ترین کار، بیشترین بازگشت. بازکنندهٔ `SearchableProvider`، `SubscriptionSource`، `PaymentGatewayInterface` |
| ۴ | **K4** — حالت توسعه‌دهنده | با enforce سمت سرور. on-ramp توسعه‌دهنده، پیش‌نیاز بازار |
| ۵ | **K6.1 + K6.2 + K6.3** — منو، drawer ابزارها، block_type | نقطه‌هایی که واقعاً مصرف‌کننده دارند. **اول ۵ تای موجود را واقعی کن، بعد دهمی** |
| ۵.۵ | **K3.8** — schema برای `admin.menu` + `site.page_type` | قبل از اینکه K6.1/K6.8 رندر را بسازد، قراردادش باید ثابت باشد. دو نمونه، همهٔ چهار درجه را پوشش می‌دهند |
| ۶ | **K6.5** — بستر صفحهٔ ادمین | `admin.page_registry` |
| ۷ | **K5.1–5.6** — هستهٔ اجرایی | زیرساخت اجرا. بدون این، بازار و ۳۰٪ بی‌معنا |
| ۸ | **K5.7** — نقش دوم PostgreSQL | بستن واقعی مرز دیتابیس |
| ۹ | **K7** — تبدیل پنل مرکزی | اول `K7.1` + `K7.2` که مستقل‌اند |
| ۱۰ | **K8** — بازار و فروش | بعد از اجرا |

## سه چیزی که باید صریح گفته شود

1. **«۸ نقطهٔ اتصال داریم» جملهٔ گمراه‌کننده‌ای بود.** فقط ۱ نقطه کار می‌کند، ۲ نیمه، و ۵ فقط اعلام‌شده‌اند. صادقانه‌تر: «قرارداد ۸ نقطه داریم، یکی‌اش زنده است.»
2. **فرانت پنل مرکزی** برای تبدیل به پلاگین ۲ روز کار می‌برد ولی **هزینهٔ مستمر** دارد و هر تغییر UI هسته را می‌شکند. گزینهٔ جایگزین (پوستهٔ نازک، ۳ روز) ۸۰٪ ارزش را با یک‌سوم ریسک می‌دهد.
3. **بزرگ‌ترین خطر پروژه ساختن بازار نیست؛ جذب اولین ۱۰ توسعه‌دهنده است.** اگر توسعه‌دهنده نتواند در ۳۰ دقیقه اولین پلاگینش را بسازد، بازار خالی می‌ماند و ۳۰٪ هرگز اتفاق نمی‌افتد.
