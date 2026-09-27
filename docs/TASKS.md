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

### کارهای پیدا‌شده حین اجرا (خارج از رجیستری اصلی)

| ID | کار | چرا باز است |
|---|---|---|
| **K5.0-W** | `PluginAutoloader` ساخته شد ولی **هیچ‌جا صدا زده نمی‌شود** | مسیر نصب کد هنوز ساخته نشده (K5.2). تا آن موقع `core.service_provider` هم بی‌اثر است چون کلاس افزونه بارگذاری نمی‌شود. باید در `boot()` هسته و در `activate()`/`deactivate()` صدا زده شود. |
| **K5.2-W** | `ensureSealKeyFor` در `upload()` صدا زده می‌شود ولی **مهر زدن روی فایل** ندارد | کلید ساخته می‌شود ولی هیچ‌جا روی فایل‌های استخراج‌شده اعمال نمی‌شود. به K5.2 گره خورده. |
| **K1.5-B** | `activeManifests()` فقط `active` را چک می‌کند، نه `review_status` | سه مصرف‌کننده دارد و تغییرش خارج از دامنهٔ تسک‌های منفرد بود. رجیستری سخت‌گیرانه‌تر است ولی این یکی نه — یعنی افزونهٔ تأییدنشده در dispatch هوک می‌تواند اثر بگذارد. |

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
| E11 | installer | pending | **نصب تک‌دستوری از README حذف شد** چون هر شکلی از ذکرش (در حال ساخت / نسخهٔ بعد) لو می‌داد هنوز نیست. وقتی واقعی شد، این سه‌جا برمی‌گردد: پاراگراف اول README، سطر جدول «چرا پیشداد»، و بخش نصب با بلوک `curl \| bash`. فایل `assets/social-preview.png` آمادهٔ ساخت است |
| E12 | قفل جهت بصری نهایی | blocked | منتظر تصمیم کاربر |
| E13 | ری‌استارت opencode برای ساب‌ایجنت‌های تخصصی | blocked | نیاز به اقدام کاربر |
| E14 | انتشار پوشهٔ پروژه در گیت‌هاب | done | `ArmanOskouei/Pishdad` — **آخرین تسک پروژه.** ۵ فایل متنی منتشر شد (bio ۱۳۰/۱۶۰، description ۱۸۹/۲۰۰، topics ۲۰/۲۰). کد `master` محلی دست‌نخورده ماند |

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
| **B1** | 🔴 تشخیص نشت کلید فقط سطح‌بالا را می‌گیرد. مانیفست تودرتو است، پس `{"publisher":{"private_key":"…"}}` رد می‌شود. تناقض با خود کد: `PluginTrustStore.php:107` کلید را از `$manifest['publisher']['key_id']` می‌خواند. regex فعلی `api_key`/`token`/`password`/`salt`/`webhook_secret` را هم نمی‌گیرد | `PluginPackageValidator.php:233-240` | اسکن **بازگشتی** + فهرست کامل | done |
| **B2** | 🔴 `slug` فارسی → رشتهٔ خالی. `Str::slug('پنل')` → `''` ⇒ ردیف با `slug=''` و prefix بی‌نام | `PluginController.php:103` · `Validator.php:210-217` | `^[a-z0-9][a-z0-9._-]{1,39}$` با **خطا** | done |
| **B3** | 🔴 `dev_mode` اصلاً وجود ندارد. `upload()` و `upgrade()` هیچ بررسی سمت سرور ندارند | `PluginController.php:64,175` | middleware روی هر دو route | done |
| **B4** | 🔴 `/api/proxy` فهرست مسیر مجاز ندارد. `$target = ${UPSTREAM}/${path.join("/")}` بدون بررسی ⇒ از مرورگر مدیر با کوکی، **هر `/api/*`** قابل فراخوانی است — از جمله `central/*` و `internal/heartbeat`. **همین مسیر دلیل رد کامپوننت پلاگین است (K1.5.8) و مرز امنیتی‌اش allowlist ندارد** | `api/proxy/[...path]/route.ts:9` · `lib/server-api.ts:23` | `if (path[0] !== 'v1') return 403` + فهرست بستهٔ prefix | done |
| **B5** | 🔴 رجیستری جستجوی کلاینت دقیقاً K1.5.8 را دور می‌زند: `registerSearchSource({fetchItems})` روی `window.__ADMIN_SEARCH__` و نمونه‌اش `fetch('/api/proxy/v1/admin/blog/posts')` | `lib/search-registry.ts:111,336-361` · `docs/SEARCH-PLUGIN-CONTRACT.md` | حذف + انتقال به بک‌اند | done |
| **B6** | 🔴 `PackageAnalyzer` مقدار اولیهٔ prop را دوباره نمی‌خواند ⇒ مسیر «آپلود رد شد» بی‌اثر است | `PackageAnalyzer.tsx:152` · `PluginsClient.tsx:73,147` | `useEffect` sync یا `key` | done |
| **B7** | 🟠 `ApiError` فقط از FormData. `authed` هنوز `Error` ساده می‌دهد و هر دو در ۴۰۱ خطای ساده ⇒ تمیز دادن «نشست منقضی» از «خطای اعتبارسنجی» ممکن نیست | `auth.tsx:110,113,126,127` | `authed` هم `ApiError` | done |
| **B8** | 🟠 `suggestLocation` اولویت‌ها را برعکس زده: نام کلاس قبل از پسوند ⇒ `Model.png` → پیشنهاد `Laravel/src/Models/Model.png`. **نکتهٔ امنیتی:** پسوندهای `.ts/.tsx/.js/.jsx` به `Next.js/panel/` پیشنهاد می‌شوند ولی آن پوشه هرگز اجرا نمی‌شود — اگر روزی به `public/` سرو شود، اسکریپت same-origin روی origin ادمین است | `PluginPackageContract.php:296-324` | پسوند قبل از نام + **حذف** `.ts/.tsx/.js/.jsx` از پیشنهاد | done |
| **B9** | 🟠 `readManifest` هر `manifest.json` را پیدا می‌کند نه فقط ریشه را. نقض مستقیم K1.5.2. امروز تصادفی امن است چون `manifest.misplaced` جلویش را می‌گیرد — ولی در `upload()` همین خروجی `slug` و امضا و کل ردیف DB را تعیین می‌کند | `Validator.php:422` · `PluginController.php:434` | `getFromName('manifest.json')` | done |
| **B10** | 🟠 دو شکل برای یک مفهوم: `manifestChecks` رشتهٔ ساده می‌پذیرد ولی `extensionPointsOf` فقط آبجکت ⇒ مانیفست با رشتهٔ ساده از اعتبارسنجی رد می‌شود ولی در مقایسه با `panel.json` نامرئی است ⇒ اختلاف کاذب | `Validator.php:248,380-384` | فقط آبجکت، در هر دو جا | done |
| **B11** | 🟠 `upgrade()` فایل قدیمی را **قبل** از موفقیت جدید پاک می‌کند. دیسک پر ⇒ پلاگین فعال با `path` ناموجود | `PluginController.php:207-212` | ذخیرهٔ جدید → ردیف → حذف قدیم | done |
| **B12** | 🟠 سه درخواست سریالی در layout هر صفحهٔ پنل | `admin/layout.tsx:12,19,26` | `Promise.allSettled` با حفظ سه `catch` جدا | done |
| **B13** | 🟡 زنگ اعلان `<button>` بدون `onClick`؛ `alertCount` هرگز پاس داده نمی‌شود. جدول `notifications` + `TicketNotification` بی‌خواننده | `Topbar.tsx:48-50` · `Shell.tsx:13` | K5.8 | done |
| **B14** | 🟡 breadcrumb روی همهٔ صفحات «پنل › مدیریت» است. ریشه‌اش یک باگ است نه یک نقطهٔ اتصال | `admin/layout.tsx:36` | از page descriptor | done |
| **B15** | 🟡 `SaveBar` برچسب «تأیید و ذفیره **قالب**» را هاردکد کرده — کامپوننت عمومی با واژهٔ دامنهٔ قالب | `primitives.tsx:66` | پارامتری کردن | done |
| **B16** | 🟡 پرمیشن‌ها namespaced نیستند ⇒ دو پلاگین با ماژول هم‌نام پرمیشن مشترک می‌گیرند | `ManifestRegistry.php:51-58` · `PluginController.php:409-412` | I6 | done |
| **B17** | 🟡 روی ZIP باز‌نشدنی، `result()` به `$summary['slug']` دست می‌زند که وجود ندارد | `Validator.php:43-45,462` | تست بستهٔ خراب | done (کاذب) |
| **B18** | 🟡 ثابت‌های مرده: `MAX_TABLE_COUNT`، `MAX_INDEXES_PER_TABLE`، `MAX_ACTIVE_PLUGINS` بی‌استفاده. `ensureSealKey()` هیچ caller ندارد ⇒ **K0.4 پیاده نشده**. `PackageAnalysis::items()` بی‌caller | `Contract.php:41,43,45` · `TrustStore.php:90` | مصرف یا حذف | done |
| **B19** | 🟡 `ensureSealKey` از `Cache::forever` ⇒ یک flush ریدایس، کلید را بی‌صدا بی‌اعتبار می‌کند | `TrustStore.php:100` | جدول settings | done |
| **B20** | 🟡 `hooks.not_yet_dispatched` هشدار است ولی `syncHooks` ردیف می‌نویسد و فرانت toast می‌دهد «هوک‌ها ثبت شدند». هوک‌ها **اجرا نمی‌شوند** | `Validator.php:293` · `PluginsClient.tsx:43` | متن صادقانه تا K5.5 | done |
| **B21** | 🟡 عدد جادویی ۲۰۰۰ کنار `MAX_FILES`=۵۰۰۰ | `Validator.php:191` | ثابت | done |
| **B22** | 🟡 تورفتگی شکسته در گروه `admin` | done | تمیز | ✅ **انجام شد.** بررسی اولیهٔ من اشتباه بود: ۴ فاصله در `app/Http/Controllers` سطح استاندارد PSR-12 است نه نشانهٔ خرابی. تست درست با Pint نشان داد فقط **۵ فایل** مشکل دارند نه ۱۶. اصلاح: `DashboardController` یک import اضافه شد و مسیر کامل به import تبدیل شد؛ `RoleController` یک import بلااستفاده حذف شد؛ `LayoutController` و `PageController` و `SiteSettingsController` فقط CRLF←LF شدند و محتوا بایت‌به‌بایت یکسان است. Pint روی هر ۱۶ فایل PASS و ۴۹۵ تست پاس. **نکتهٔ باقی‌مانده:** `.gitattributes` قاعده‌ای برای `*.php` ندارد و `core.autocrlf=true` است، پس هر توسعه‌دهنده با autocrlf دوباره CRLF می‌گیرد و Pint قرمز می‌شود ⇒ افزودن `*.php text eol=lf` یک تسک جدا است |
| **B23** | 🟡 `api.d.ts` (۲۰۸KB) نوع دستی `PackageAnalysis` دارد که با `PackageAnalyzer.tsx:27` تکراری است | done | I8 | ✅ **با گزارش بسته شد — ایجنت درست تشخیص داد صورت‌مسئله غلط است.** بررسی شد: `PackageAnalysis` اصلاً در `api.d.ts` نیست (صفر تطابق) و هیچ فایلی `@/types/api` را import نمی‌کند (صفر import). پس فایل تولیدشده دست‌نخورده است و خطر بازنویسی وجود ندارد. ایجنت سه راه مستقل آورد: هدر خودکار، hash یکسان قبل و بعد از `gen:types`، و صفر importer. `result()` در `PluginPackageValidator` با فیلدهای UI هم‌خوان است پس drift وجود ندارد. **تنها نقص واقعی:** این سه type در فایل کامپوننت نشسته‌اند در حالی که خانهٔ typeهای دامنه `src/lib/domain.ts` است و آن خارج از مالکیت فایل بود. **کشف مهم:** `api.d.ts` با ۲۰۸KB امروز کد مرده است و هیچ‌چیز از آن import نمی‌کند |
| **B24** | 🔴 **XSS در بلوک CTA — تأییدشده.** `href` بدون هیچ sanitize مستقیم در `<a>` می‌رود. `safeHtml` (`:12`) فقط روی متن ریچ‌تکست کار می‌کند و خط `:19` پاک‌سازی‌اش فقط داخل `safeHtml` است. یعنی `javascript:alert(1)` در بلوک CTA زنده است. **قبل از باز کردن `site.block_type` باید درست شود** | `components/site/BlockRenderer.tsx:109` | رد `href` از `safeHtml` | done |
| **B25** | 🔴 **`requires.core` هرگز بررسی نمی‌شود** — تأییدشده با جست‌وجوی کامل. فقط در یک کامنت ذکر شده. یعنی کل لایهٔ سازگاری نسخه (`since`/`schema_version`) روی کاغذ است | `PluginPackageContract.php:18` | K3.0 | done |
| **B26** | 🔴 `example` نقطهٔ `admin.menu` ۳ از ۴ فیلدش غلط است — تأییدشده با مقایسهٔ واقعی. `Contract.php:87` می‌گوید `title`/`path` ولی `menu.ts:3` می‌خواهد `{ href, label, icon, key }`. عملاً داریم به برنامه‌نویس **فیلد غلط** یاد می‌دهیم | `Contract.php:87` × `menu.ts:3` | K3.1 | done |
| **B27** | 🔴 `extensionPoints()` فیلدهای تازهٔ قرارداد را **بی‌صدا drop** می‌کند. هر فیلدی که دستی به این map اضافه نشود از پاسخ API حذف می‌شود و UI نشانش نمی‌دهد | `Contract.php:137` | K3.2 | done |
| **B28** | 🔴 فلگ `json` سیگنال **ضدّهمبسته با واقعیت** است: تنها نقطهٔ زنده (`site.page_type`) آن را ندارد ولی ۵ نقطهٔ مرده دارند. اگر `openness` اضافه شود و `json` بماند، دو سیگنال رقیب داریم | `Contract.php:80,88-123,145` | K3.6 | done |
| **B29** | 🟠 کامنت `Contract.php:81` دربارهٔ کلید `builder` نوشته ولی هیچ نقطه‌ای `builder` ندارد. مستندات مرده | `Contract.php:81` | تمیز | done |
| **B30** | 🟠 `ManifestRegistry` برخورد `type` را بی‌صدا `continue` می‌کند. پلاگینی که `type` تکراری اعلام کند نصب می‌شود و **اصلاً دیده نمی‌شود** — بدترین حالت بی‌صدا | `ManifestRegistry.php:99-105,152-154` | قاعدهٔ مقطعی «عدم برخورد» ⇒ خطا | done |
| **B31** | 🟠 ۴ نقطه از ۶ `example` ندارند و `example` هیچ‌کدام رندر نمی‌شود | `Contract.php:95-123` · `PackageAnalyzer.tsx:264-267` | K3.7 | done |
| **B32** | 🟡 تصحیح اولویت B17: در `Validator.php:43-45` مقدار `$manifest` برابر `null` است پس `result()` شاخهٔ `:444` را رد می‌کند. مواجههٔ واقعی فقط undefined-index روی `Log::info` در `:457/459` است — warning نه crash. **اولویتش پایین‌تر از آن است که TASKS نشان می‌داد** | `Validator.php:41-46,457-463` | پایین | done |

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
| **K3.0** | ⭐ **enforce کردن `requires.core`** | `requires` **الان هیچ‌جا بررسی نمی‌شود** — فقط در کامنت `PluginPackageContract.php:18`. یعنی کل لایهٔ سازگاری روی کاغذ است. **پیش‌شرط I5** | done |
| **K3.1** | ⭐ اصلاح `example` غلط `admin.menu` | 🔴 `Contract.php:87` می‌گوید `title`/`path`/`icon`/`permission` ولی واقعیت `lib/menu.ts:3` است: `{ href, label, icon, key }`. **۳ از ۴ فیلد اشتباه** — عملاً داریم به برنامه‌نویس فیلد غلط یاد می‌دهیم. `permission: 'posts.view'` هم بدون prefix اسلاگ است | done |
| **K3.2** | اصلاح `extensionPoints()` که فیلدها را drop می‌کند | 🔴 `Contract.php:137` یک map **دستی** است. هر فیلد تازهٔ قرارداد که دستی اضافه نشود **بی‌صدا از پاسخ API حذف می‌شود** و UI نشانش نمی‌دهد | done |
| **K3.3** | ⭐ `openness` + `status` + `since` + `schema_version` + `forbidden` + `max` | done | ⏳ | ✅ **از قبل انجام شده بود — فهرست کهنه بود.** `openness` · `status` · `since` · `schema_version` · `forbidden` همگی در `PluginPackageContract` موجودند 
| **K3.4** | `validateDeclaration()` — متد خالص، دستی، fail-closed | done | done | ✅ **از قبل انجام شده بود — فهرست کهنه بود.** `validateDeclaration()` در `PluginPackageContract.php` موجود است 
| **K3.5** | 🔴 حذف `Next.js/panel.json` از `ALLOWED_PATHS` + حذف `panelFileChecks` + رفع وابستگی تست | تصمیم C2 | done |
| **K3.6** | ⛔ حذف فلگ `json` | سیگنال **ضدّهمبسته با واقعیت**: تنها نقطهٔ زنده (`site.page_type`) این فلگ را ندارد ولی ۵ نقطهٔ مرده دارند | done |
| **K3.7** | `example_ok` + `example_bad` برای هر نقطه | تست و راهنما هر دو از آن استفاده می‌کنند | done |
| **K3.8** | ⭐ schema فقط برای `admin.menu` (بسته) + `site.page_type` (باز) | **این دو همهٔ چهار درجه را با دو نمونه ثابت می‌کنند.** نوشتن ۱۰ schema برای ۱۰ renderer غایب = ۱۰ منبع حقیقت که در سه ماه دروغ می‌گویند | done |
| **K3.9** | schema بقیهٔ نقاط — **فقط بعد از ساخت renderer هرکدام** | | ⏳ |
| **K3.10** | کدهای خطای schema تولیدشده از خود قرارداد | `CODE_FA` در `PackageAnalyzer.tsx:46` دستی است ⇒ ۱۰ کد تازه بدون ترجمه، `issue.code` خام در UI می‌افتد | done |
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
| K4.1 | شمارندهٔ کلیک در cache با کلید `devmode:taps:{user_id}|{ip}` | **انجام شد:** `DevModeTaps`. کلید `per-user` است نه فقط IP — وگرنه یک نفر می‌توانست برای همهٔ کاربران پشت یک NAT پنج کلیک را جلو ببرد. ۶ تست | done |
| K4.2 | قاعده‌های reset: ۲ ثانیه بین کلیک، ۱۰ ثانیه از اولین، درخواست غیر POST، خروج از حساب، ۵ دقیقه بی‌فعالیتی | **انجام شد:** هر ۵ قاعده در `assertTapAllowed()` + `prune()`. پنجره از **اولین** کلیک شمرده می‌شود نه آخر، وگرنه زنجیره هرگز نمی‌شکست. کلیک ردشده شمرده نمی‌شود. ۵ تست | done |
| K4.3 | مودال تأیید **با رمز عبور** (نه checkbox) + re-auth سمت سرور | **انجام شد:** `DevModeUnlock::unlock()` هم `Hash::check` می‌خواهد هم ژستور کامل. در ازایش `unlock_token` یک‌بارمصرف ۳۲ بایتی صادر می‌شود — اگر همان رمز بود، هر بار که ذخیره می‌شد برای همیشه بی‌ارزش نبود. ژستور بعد از باز کردن **مصرف** می‌شود. ۵ تست | done |
| K4.4 | وضعیت `dev_mode` در `settings` گروه `system` + `expires_at` (۸ ساعت) + `unlock_token` | **از قبل کامل بود** — `DevMode` با `TTL_HOURS=8`، انقضای غیرقابل مذاکره و `enabled` بدون `expires_at` که باز حساب نمی‌شود. فهرست کهنه بود. | done |
| K4.5 | ثبت در `operator_activities` | **انجام شد:** `OperatorActivity::record()` از قبل وجود داشت ولی **هیچ‌کس صدایش نمی‌زد** — جدول حرف بود بدون نویسنده. حالا `DevModeAudit` موفق و **ناموفق** را هر دو ثبت می‌کند (تلاش ناموفق یعنی کسی خواسته دروازهٔ بدون امضا را باز کند). یک تست هم ثابت می‌کند `unlock_token` هرگز به ردپا نمی‌رسد. **نکتهٔ معماری:** جدول مرکزی است و K7.8 آن را می‌برد، پس `DevModeAudit` عمداً تنها adapter مرزی است و در `ALLOWED` تست مرزی نشسته — نه در `KNOWN_VIOLATIONS`، چون آن فهرست بدهی است نه راه دور زدن نگهبان. ۵ تست | done |
| K4.6 | ⛔ **enforce سمت سرور** روی `upload()` و `upgrade()` | done | ⏳ | ✅ **از قبل انجام شده بود — فهرست کهنه بود.** `GateDevModeUpload` صفت `dev_mode_granted` را می‌گذارد و هر دو مسیر enforce می‌کنند: `upload()` خط ۹۸ و `upgrade()` خط ۲۴۶ با شرط `!== true` پاسخ ۴۲۲ می‌دهند و لاگ `plugin.upload_rejected` و `plugin.upgrade_rejected` می‌نویسند. کار در commit `616d316` انجام شده بود. یعنی هیچ مسیر امنیتی بازی وجود ندارد 
| K4.7 | فرانت: ۵ کلیک + مودال در تنظیمات | **انجام شد:** `DevModeGate` با props تزریق‌شده، نه fetch داخلی — چون route ندارد و fetch داخلی یعنی کامپوننتی که در هر اجرا خطا می‌دهد. مودال **رمز عبور** دارد نه checkbox، و فقط وقتی ژستور کامل شد باز می‌شود. فاصلهٔ ۲ ثانیه سمت فرانت هم رعایت می‌شود تا کاربر چهار خطای «خیلی سریع» نبیند. ۱۶ تست | done |
| K4.8 | فرانت: گیت کردن دکمهٔ آپلود + نمایش راهنما | **انجام شد:** `UploadGateNotice` + قاعدهٔ خالص `gateNotice()`. سه حالت: باز / قفل‌با-راهنما / هیچ. وقتی حالت توسعه‌دهنده بسته است ولی راه رسمی هست، لینک داده می‌شود. **هرگز** راه دور زدن امضا توضیح داده نمی‌شود — یک تست هم هر عبارت مشکوک را رد می‌کند. جدا از `PluginsClient` ساخته شد چون آن فایل مال K6.2 است. ۷ تست | done |
| K4.9 | نمایش زمان باقی‌مانده در UI | **انجام شد:** `timeRemaining()` در `lib/devmode-time.ts` با ارقام فارسی. یک شکاف واقعی را تست گرفت: ۳۰ ثانیهٔ باقی‌مانده با گرد کردن به پایین «۰ دقیقه» می‌شد، یعنی کاربر فکر می‌کرد همین الان منقضی شده. حالا «کمتر از یک دقیقه» می‌گوید. سه حالت `null` هستند: انقضا تنظیم نشده، تاریخ خوانا نیست، وقتش گذشته. ۶ تست | done |

---

# 🟠 K5 — هستهٔ اجرایی پلاگین (K1.1)

> تا این فاز، هیچ پلاگینی اجرا نمی‌شود و بازار و ۳۰٪ معنا ندارد.

| # | تسک | توضیح | وضعیت |
|---|------|-------|--------|
| **K5.0** | ⭐ **نقطهٔ `core.service_provider`** (bootstrap/binding) | **کوچک‌ترین کار با بیشترین بازگشت کل پروژه.** فقط کلاس‌هایی که interface مشخصِ هسته را پیاده کنند. بدون آن: `SubscriptionSource` قابل ثبت نیست ⇒ گزینهٔ الفِ K7.2 شکست می‌خورد؛ `SearchableProvider` قابل ثبت نیست ⇒ B5/I7 بسته نمی‌شود؛ `PaymentGatewayInterface` و `ChabokanClientInterface` (هر دو singleton هاردکد در `AppServiceProvider.php:20-39`) قابل تعویض نیستند | done |
| K5.1 | `PluginExtractor` — ۱۱ کنترل استخراج امن | گام ۱ (فهرست ZIP) **در K1.6.3 انجام شد**. گام‌های ۲ تا ۱۱ باقی است | 🟡 ۱ از ۱۱ |
| K5.2 | ساختار `releases/{version}-{hash}/` + `current` + استخراج atomic | staging → verify → rename. هرگز مستقیم روی مسیر زنده | done || K5.3 | PSR-4 loader با prefix اجباری `Pishdad\Plugins\{Studly}\` | با `ClassLoader::addPsr4()` روی نمونه. **رد هر namespace خارج از prefix** — تنها جایی که امنیت بارگذاری واقعاً enforce می‌شود | done |
| K5.3 | PSR-4 loader با prefix اجباری `Pishdad\Plugins\{Studly}\` | با `ClassLoader::addPsr4()` روی نمونه. **رد هر namespace خارج از prefix** — تنها جایی که امنیت بارگذاری واقعاً enforce می‌شود | done |
| K5.4 | `PluginRouter` catch-all ΓÇö ╪¬┘å┘ç╪º route ┘ê╪º┘é╪╣█î ┘à╪º┘ä┌⌐ ┘ç╪│╪¬┘ç ╪¿╪º╪┤╪» | ╪¬╪»╪º╪«┘ä ╪│╪º╪«╪¬╪º╪▒█î ┘å╪º┘à┘à┌⌐┘å╪î `route:cache` ┘à╪╣╪¬╪¿╪▒╪î ╪║█î╪▒┘ü╪╣╪º┘äΓÇî╪│╪º╪▓█î = █î┌⌐ ┘ü┘ä┌» | done |
| K5.5 | dispatcher هوک **یا** حذف `hooks` از مانیفست | B20. فعلاً هیچ‌کدام | done |
| K5.6 | migration runner با prefix `{slug}_` + سقف ۲۰ جدول + ۱۰ ایندکس **در هر جدول** | **گام ۱ — `a6cb1b7`:** اعلام `db.tables` + validator fail-closed + سقف‌ها + تداخل با هسته. **گام ۲ — `33a85eb`:** `PluginMigrationRunner` (تحلیل و بازنویسی، fail-closed). **گام ۳ — `da54077`:** `PluginMigrator` (اجرا در تراکنش، سابقه در `pishdad_plugin_migrations`، بازنویسی **همهٔ فایل‌ها پیش از هر require**) + اتصال به `activate()`. **کشف مهم:** `upload` اصلاً `PluginInstaller` را صدا نمی‌زند و installer هم فایل اشاره‌گر `.current` را نمی‌نویسد، پس `currentDir()` همیشه `null` است و مسیر سرتاسری هنوز کار نمی‌کند. این وضعیت در `test_activation_is_refused_while_the_release_lifecycle_is_unwired` قفل شده و `test_the_release_pointer_is_not_written_yet` عمداً وقتی وصل شود قرمز می‌شود. **گام ۴ = K5.2-W:** وصل کردن upload به install به pointer به activate. + بررسی تداخل با جدول‌های هسته. **K5.2-W بسته شد (`256c045`):** `upload()` حالا `PluginInstaller` را صدا می‌زند و `activate()` اشاره‌گر `.current` را *پیش* از migration می‌نویسد چون `PluginMigrator` نسخه را از `currentDir()` می‌خواند. دو شکاف واقعی هنگام صادق‌کردن تست‌ها پیدا و رفع شد: (۱) `pluginZip()` پارامتر `$extra` را می‌گرفت و **دور می‌ریخت**، پس تست «migration جدول اعلام‌نشده را لمس می‌کند» هرگز فایل بدافزاری را داخل ZIP نگذاشته بود و به دلیل اشتباه سبز بود؛ (۲) `PluginMigrator` هیچ post-condition نداشت — افزونه‌ای که `db.tables` اعلام می‌کرد ولی migration همراه نداشت، فعال می‌شد بدون آنکه جدولی ساخته شود. `missingTables()` اضافه شد. ۶۴۶ تست سبز | done |
| K5.7 | ⭐ نقش دوم PostgreSQL (K1.5.3) | نقش `pishdad_owner` برای `migrate`، نقش `pishdad_app` برای runtime با DDL فقط روی `{slug}_*`. تا وقتی این enforce نشده، prefix فقط **انتخاب** است نه **محدودیت**. ⚠️ متخصص نامطمئن است که مکانیزم migration لاراول ۱۱ زیر این تفکیک بدون تغییر کار کند ⇒ spike دو-روزه | ⏳ |
| K5.8 | سرویس اعلان (`POST /v1/admin/notifications` با namespacing بر slug) + drawer + نشانگر | B13. **بیرون از فهرست نقاط اتصال** — سرویسی است که پلاگین صدایش می‌زند نه چیزی که اعلام می‌کند | ⏳ |
| K5.9 | مهر یکپارچگی per-install (K0.4) | `ensureSealKey` الان بی‌caller است (B18/B19). کلید در `settings` نه cache | done |
| K5.10 | سقف `file_count` و سرپوش opcache | **انجام شد.** ایجنت موازی: سقف `file_count` دو لایه شد. `install.too_many_files` فقط ورودی‌های **پذیرفته‌شده** را می‌شمرد، پس یک بسته با ۱۰ فایل مجاز + ۵۰۰۰ ورودی زائد از سقف رد می‌شد در حالی که validator همان بسته را رد می‌کرد — سوراخ defence-in-depth. حالا `install.too_many_entries` روی `numFiles` همان جمعیت دوم را می‌گیرد. مقدار `MAX_FILES` عمداً روی ۵۰۰۰ ماند: سقف بایتی جایگزینش نمی‌شود (۵۰۰۱ فایل یک‌بایتی زیر ۵KB است) و ۲۰۰۰ کردنش یعنی تبدیل هشدار به خطا. **دربارهٔ opcache:** بررسی شد و روشن است (`max_accelerated_files=20000`)، پس کامتن `Dockerfile` که می‌گوید غایب است **غلط است**. `opcache_reset()` نه لازم است و نه بی‌خطر: نسخه‌ها content-addressed هستند پس کش کهنه نمی‌شود، و reset کل کش هسته را پاک می‌کند. شکاف واقعی **بودجهٔ تجمیعی** است (۵۰ پلاگین × ۵۰۰۰ ≈ ۲۵۰ هزار ≫ ۲۰ هزار) که هیچ سقف per-package نمی‌تواند مهارش کند — به‌عنوان شکاف باز ثبت شد. ۸ تست جدید، همه mutation-verified. | done |
| K5.11 | rollback واقعی | **انجام شد.** `upgrade()` هیچ‌کدام از `PluginInstaller` / `PluginReleaseManager` / `PluginMigrator` را صدا نمی‌زد، پس بعد از ارتقا DB نسخهٔ ۲.۰.۰ را می‌گفت ولی `currentDir()` نسخهٔ ۱.۰.۰ را برمی‌گرداند و `PluginRouter` کد قدیم را اجرا می‌کرد — مدیر ارتقایی می‌دید که اصلاً اتفاق نیفتاده بود. حالا ترتیب «نصب → اشاره‌گر → migration → رکورد» است: اگر نصب رد شود رکورد دست‌نخورده می‌ماند، اگر migration شکست بخورد `pointerBack()` نسخهٔ قبلی را دوباره فعال می‌کند. فقط ZIP قدیمی پاک می‌شود نه پوشهٔ نسخه — چون پوشه‌ها content-addressed هستند و همان آرتیفکتی‌اند که rollback واقعی رویشان حساب می‌کند. ۳ تست جدید: نصب نسخهٔ جدید روی دیسک، بازگشت به نسخهٔ قبلی بعد از شکست migration، و دست‌نخورده‌ماندن رکورد وقتی بستهٔ ارتقا رد می‌شود | done |

---

# 🟠 K6 — UI پلاگین در فرانت (سطح ۰)

> ⛔ رد‌شده در v1: کامپوننت React از سمت پلاگین (B5) · build در زمان اجرا · `next/dynamic` برای افزونهٔ عمومی (I4).

| # | تسک | ارجاح | وضعیت |
|---|------|--------|--------|
| K6.1 | `admin.menu` — merge روی props سرور | `lib/menu.ts:6` ثابت → `core + mergePluginMenu()`. `admin/layout.tsx:33` همه‌چیز را props می‌گیرد ⇒ `Sidebar.tsx` بدون تغییر bundle | ⏳ |
| K6.2 | 🆕 `admin.plugin_tools` — دکمهٔ «ابزارهای پلاگین» + drawer (تصمیم C1) | `Topbar.tsx:20-73` خطی و هاردکد. **نوار اصلی دست‌نخورده می‌ماند** — یک دکمه اضافه می‌شود، drawer باز می‌کند، هر پلاگین یک کادر مستقل با برچسب نامش می‌گیرد. `href` آزاد ممنوع؛ `notification_id: integer` که هسته به URL تبدیلش می‌کند | ⏳ |
| K6.3 | `site.block_type` — شاخهٔ `default` در `BlockRenderer` | **انجام شد.** شاخهٔ `default` در `BlockRenderer` از قبل وجود داشت و فقط `return null` می‌کرد — همان «نیمه‌کاری بی‌صدا» که خودِ تسک توصیف می‌کند. **سیاست:** هرگز throw نمی‌کند (یک بلوک بد نباید کل صفحهٔ عمومی را بخواباند)، همیشه لاگ سمت سرور می‌نویسد، و در حالت تشخیصی یک اعلان `role="note"` نشان می‌دهد. تشخیص به‌طور پیش‌فرض در هر چیزی جز `production` روشن است. **برنده‌بودن بلوک‌های هسته** با سه گارانتی: ساختاری (در `switch` فقط وقتی هیچ `case` نخورد)، تستی (برچسب‌های `case` از سورس استخراج و با واژگان هسته مقایسه می‌شوند)، و运行时‌ای (اگر دو منبع واگرا شوند، `console.error` با دلیل `registry-drift`). ۱۸ تست، ۵ mutation کشته شد. | done |
| K6.4 | `site.header_widget` + `site.footer_widget` | `Chrome.tsx:83-85` همان مشکل. **عمداً ساخته نشد:** هر دو نقطه `fields => []` با `open_schema => false` دارند که validator آن را `no_schema` خطای سخت می‌دهد ⇒ هیچ مانیفستی قانوناً نمی‌تواند پرشان کند. `Chrome.tsx` یک switch بسته بود که default آن `null` برمی‌گرداند، پس ویجت پلاگین می‌شد ذخیره و بی‌هیچ اعلانی رندر نشود. default حالا لاگ می‌کند و در حالت diagnostic اعلان غیرکشنده می‌دهد (درمان K6.3). **باز کردن این دو نیازمند تصمیم محصول است:** یا `fields` پر شود، یا کلید سطح‌بالای مانیفست مثل `widgets` | blocked |
| K6.5 | `admin.page_registry` — catch-all داخل گروه admin | ⚠️ **تصحیح برنامه:** K1.6.5 اشتباه گفت `[...path]/page.tsx`. آن رندرکنندهٔ **سایت عمومی** است (`:119` `SiteHeader`، `:137` `SiteFooter`) — مسیر `/admin/xyz` با کروم سایت عمومی رندر می‌شد. درست: `app/(client)/admin/[...slug]/page.tsx` | ⏳ |
| K6.6 | ⛔ قید: مسیرهای پلاگین **باید** زیر `/admin/` باشند | **انجام شد — ولی با یک کشف مهم.** قید «مسیرها زیر `/admin/`» اصلاً جایی **اجبار** نمی‌شد: نه فایل `app/(client)/admin/[...slug]/page.tsx` وجود داشت (K6.5 هنوز ⏳ است)، نه کد فرانتی مسیر ادمین پلاگین را می‌ساخت. ضمناً بررسی کوکی فقط **وجود** کوکی را می‌سنجید (`if (!token)`) — یعنی `"x"` یا `"null"` هم عبور می‌کرد. حالا: `src/lib/plugin-admin-path.ts` تنها جایی است که مسیر ادمین پلاگین ساخته می‌شود و `assert` می‌کند زیر `/admin/` بماند؛ `src/lib/session-cookie.ts` بررسی شکل توکن را جدا کرده (نه اصالتش — اصالت کار بک‌اند است). قاعده عیناً از `^/admin(/[a-z0-9._-]+)*$` قرارداد پذیرفته‌شده گرفته شد تا دو لایه یکی نگویند. ۲۳ تست با `node --test` بدون هیچ وابستگی جدید؛ matcher پروکسی با تست به ریشهٔ سازنده قفل شد. | done |
| K6.7 | `admin.settings_schema` | `SchemaForm.tsx` همین حالا وجود دارد. **مصرف‌کننده اضافه شد، محل سوار شدن نه:** `ManifestRegistry::pluginSettingsSchemas()` کانال را از `panel.extensions` به کلید سطح‌بالای `manifest.settings` برد چون میکرو-اسکیما سقف عمق ۱ دارد و اسکیمای JSON تودرتو است. خود نقطهٔ قرارداد `deprecated` شد و مثال نامعتبرش پاک شد (هر مثالی که آنجا بود، validator خودش ردش می‌کرد). کارت جعلی «اسلات پلاگین» با سه input هاردکدِ `readOnly` حذف شد. **اما هنوز** endpoint و مسیر ذخیره وجود ندارد، پس هر فرمی ساخته شود جایی رندر نمی‌شود ⇒ برای بستن، `routes/api.php` لازم است | blocked |
| K6.8 | `site.page_type` → وصل به `panel.extensions` | **انجام شد.** اعلان از راه `panel.extensions` **اعتبارسنجی می‌شد ولی هرگز به فهرست نمی‌رسید** — `pageTypes()` فقط کلید سطح‌بالای `manifest.page_types` را می‌خواند. یعنی نویسنده اعلانش تأیید می‌گرفت و هیچ خطایی هم نمی‌دید، ولی نوع صفحه ظاهر نمی‌شد: همان «نصب شد ولی دیده نشد». حالا هر دو کانال merge می‌شوند و ترتیب عمداً طوری است که کانال مستقیم‌تر برنده شود (چون `$adopt` اولین پذیرنده را نگه می‌دارد). `point` و `path` از تعریف merge‌شده برداشته می‌شوند چون فیلد داخلی اعلان‌اند. ۷ تست جدید تنها نقطهٔ کارکن | done |
| K6.10 | پاک کردن یا واقعی کردن کارت ویجت داشبورد | `dashboard/page.tsx:155-163` با `admin.dashboard_slot` تعویق بخورد، این کارت یا حذف یا واقعی شود | ✅ **حذف شد** — `DashboardController::widgets` آرایهٔ خالیِ هاردکد برمی‌گرداند و هیچ نویسنده‌ای ندارد؛ `admin.dashboard_slot` هم `deferred`/`closed` است (اعلامش در مانیفست خطاست). کارت + fetch + نوع `DashboardWidgets` حذف شد؛ نگهبان: `src/lib/dashboard-widget-card.test.ts`. endpoint بک‌اند دست‌نخورده ماند (در `routes/api.php` + openapi + تست) |
| K6.9 | merge crumbs از page descriptor | **انجام شد، ولی صورت مسئلهٔ متفاوتی بود.** تابع `crumbsForPath` — که تمام breadcrumb پنل ادمین را می‌سازد — داخل `Shell.tsx` بود و **هیچ تستی نداشت**، چون `Shell` یک کامپوننت است و بدون رندر کل شل قابل‌آزمون نبود. یعنی هر تغییر منوی ادمین می‌توانست مسیر ناوبری را بی‌سروصدا خراب کند. منطق به `lib/crumbs.ts` منتقل و ۱۰ تست اضافه شد، شامل تله‌های واقعی: تطبیق باید روی مرز مسیر باشد (`/admin/plug` نباید زیرمجموعهٔ `/admin/plugins` باشد)، عمیق‌ترین آیتم برنده باشد، برچسب از رجیستری منو بیاید نه از URL خام (وگرنه کاربر `page_registry` را می‌بیند به‌جای «فهرست صفحات»)، و تکرار مجاور حذف شود | done |
| K6.11 | راهنمای برنامه‌نویس از قرارداد تولید شود | K1.6.2. سه خطای محتوایی فعلی: ساختار ZIP، فیلد `system`، کلید مرکزی | done |

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
| **K7.1** | ⭐ اصلاح نقشهٔ وابستگی — تست grep | **پیش‌نیاز همه‌چیز.** تستی که بگیرد هیچ فایل هسته‌ای به `Central\*` یا `central_`/`chabokan_`/`install_heartbeats`/`operator_*`/`core_versions`/`update_roll*`/`kill_switches` ارجاع ندارد | done |
| **K7.2** | ⭐ رابط `SubscriptionSource` + `NullSubscriptionSource` پیش‌فرض | **گزینهٔ الف.** هسته یک interface؛ پیش‌فرض همیشه `active` برمی‌گرداند و هیچ جدولی نمی‌خواند ⇒ **پنل مشتری دیگر به نصب پلاگین وابسته نیست.** نبودِ پلاگین = سایت کار می‌کند، فقط بی‌اشتراک (برای نصب عمومی دقیقاً درست است) | ⏳ |
| **K7.3** | ثبت پیاده‌سازی از سمت پلاگین | ⚠️ **نیازمند `core.service_provider` (K5.0)**. بدون آن این گام غیرممکن است | ⏳ |
| **K7.4** | تصمیم محصول دربارهٔ `now()->addDays(30)` | `ClientSubscriptionResolver.php:37` هنگام autoprovision **۳۰ روز اشتراک رایگان می‌سازد**. اگر داخل پلاگین برود رفتار عوض می‌کند و `SubscriptionTest.php` می‌شکند | ⏳ منتظر تصمیم |
| **K7.5** | ۱۹ `ALTER TABLE ... RENAME` با prefix | ⚠️ **۱۱ جدول بی‌پیشند را دستی نام‌گذاری کن** — وگرنه از قلم می‌افتند | done |
| **K7.6** | مهاجرت داده برای نصب‌های موجود | ترتیب FKها مهم است (`central_subscriptions.client_id → central_clients.id`) | ⏳ |
| **K7.7** | تست grep برای ۴۰+ ارجاع رشته‌ای | `InvoiceController.php:31,67` · `UpdateController.php:55` · `ClientController.php:73` · `PlanController.php:60` | ⏳ |
| K7.11 | ⭐ **پیش‌نیاز K7.8/K7.9 — `PluginRouter` کد را اجرا نمی‌کند** | `PluginRouter.php:80-90` برای هر مسیر resolve‌شده **۵۰۱** برمی‌گرداند و `PluginAutoloader` به هیچ ServiceProviderی وصل نیست (`PluginRouter.php:42`، `PluginRouteTable.php:48`). یعنی افزونه می‌تواند مسیر **اعلام** کند ولی هیچ مسیری از افزونه **اجرا** نمی‌شود. تا این وصل نشود، انتقال ۶۱ route یعنی نوشتن کدی که هرگز اجرا نمی‌شود — و هیچ ردیفی این پیش‌نیاز را نداشت | ⏳ |
| K7.12 | FK به جدول هسته در migration افزونه | **مانع قطعی K7.8.** ۸ جدول مرکزی به `users` FK دارند. `PluginMigrationRunner::verify()` هر جدول اعلام‌نشده را رد می‌کند و `rewrite()` هم روی همان patternها prefix می‌زند ⇒ `->constrained('users')` هم رد می‌شود و هم اگر رد نشود به `central_users` تبدیل می‌شود که وجود ندارد. **گزینهٔ کم‌ریسک:** جداول `central_*` در هسته بمانند و فقط کد به افزونه برود، از راه یک interface — دقیقاً الگویی که `DevModeAudit` برای `operator_activities` ساخت. گزینهٔ تهاجمی (سیاه‌لیست کردن `constrained()` برای جداول هسته) یک ارتقای امنیتی است و نیازمند تصمیم صریح مالک محصول | ⏳ |
| K7.13 | یکی از ۶۱ route مرکزی به کنترلر **هسته** می‌رود | `routes/api.php:270` ⇒ `Api\V1\Admin\TicketController::operatorReply` (`:114`). خودش `Ticket`/`TicketMessage`/`Media`/`User`/`TicketNotification` هسته را می‌کشد. یا باید استثنا شود، یا `operatorReply` به افزونه برود که خودش وابستگی هسته می‌آورد | ⏳ |
| K7.8 | انتقال کنترلرها/مدل‌های مرکزی به افزونه | ⚠️ **اعداد ردیف غلط بود و اصلاح شد:** کنترلرها **۱۲** تا نه ۱۳، مدل‌ها **۱۹** تا نه ۱۲ (تأیید: دیتابیس زنده ۱۹ جدول + کامنت `2026_10_05_000001:15` + خودِ ردیف U93 که «۱۹ جدول» می‌گوید). **و `InvoiceService` جهتِ برعکس است:** هیچ کنترلر مرکزی آن را صدا نمی‌زند؛ خودش سرویسِ هسته است که به ۴ مدل مرکزی چسبیده (`InvoiceService.php:5-8`) ⇒ انتقالش هسته را بی‌مرز می‌کند نه افزونه را مستقل. **«کار مکانیکی» نیست:** ۲۵ جفت‌شدگی خارجی + ۸ FK به `users` + یک route که به کنترلر *هسته* می‌رود **نگهبانِ همین مهاجرت (K7.7) تعمیر شد:** `CentralBoundaryTest` فهرست جدول‌هایش دستی و **سه نام غلط** داشت (`central_reviews` و `central_updates` هرگز جدول نبودند، `central_hardware_units` نام اشتباه `central_operator_hardware_keys` بود) و در عوض **۱۱ جدول واقعی** را نمی‌دید. یعنی نگهبانی که باید K7.8 را ایمن کند، خودش سه‌جدول متروک و یازده جدول غایب را می‌سنجید: سبز بود بی‌آنکه چیزی را تضمین کند. حالا فهرست از خود مدل‌ها با `getTable()` استخراج می‌شود و یک تست دیگر خودِ نگهبان را نگهبانی می‌کند (هیچ جدول واقعی غایب، هیچ نام مرده). همان تست همان روز `central_logs` را گرفت که پارس دستی از قلم افتاده بود. اسلات مردهٔ `app/Services/Central` هم حذف شد — پوشه وجود ندارد | blocked |
| K7.9 | انتقال ۶۱ route با **همان prefix** `/api/central/*` | تعداد ۶۱ **درست** تأیید شد و «صفر تغییر فرانت» هم درست (۵۵ مسیر در `api.d.ts`). **اما با مکانیزم فعلی افزونه ممکن نیست:** `PluginRouter.php:80-90` برای مسیر resolve‌شده **۵۰۱** برمی‌گرداند و `PluginAutoloader` به هیچ ServiceProviderی وصل نیست — یعنی افزونه امروز route اجرا نمی‌کند. و routeهای افزونه فقط زیر `/api/v1/p/{slug}/` سرو می‌شوند، نه prefix دلخواه. ⇒ اول `K7.11` و `K7.12` | blocked |
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

# 🆕 L — قابلیت‌های جدید (فاز ۱ ممیزی ۲۰۲۶-۰۹-۲۶)

> **ثبت‌شده در فاز ۱ تحلیل.** همه `⏸️ منتظر تأیید` — هیچ‌کدام بدون تأیید کارفرما شروع نمی‌شود.
> کدگذاری `L` برای جلوگیری از تداخل با `K`/`J`/`I`/`B`/`E` پیشنهاد و تأیید شد.
> خروجی: `docs/L-FEASIBILITY.md`

## وضعیت گروه‌ها

| گروه | موضوع | حکم ممیزی | حجم | ردیف |
|---|---|---|---|---|
| **L1** | باگ‌های امنیتی حیاتی (B24/B4/B25) | ✅ تایید — با اصلاح | S | ⏸️ |
| **L2** | جداسازی مرکزی از هسته | ⚠️ **نیاز به تغییر** — گزینهٔ ۳ توصیه شد، نه پلاگین runtime | S-M | ⏸️ |
| **L3** | هستهٔ اجرای پلاگین (K5) | ✅ تایید با اصلاحات — ۴ اصلاح ترتیب | L | ⏸️ |
| **L4** | اجرای قالب در runtime | ✅ تایید — اما `themeVars()` امروز بی‌مصرف است | M | ⏸️ |
| **L5** | نوتیفیکیشن | ✅ پنل تایید · ⚠️ سایت عمومی **تعویق** | M | ⏸️ |
| **L6** | PWA پنل | ⚠️ **نیاز به بازبینی** — ساب‌ایجنت تقلیل/حذف را پیشنهاد کرد | S→M-L | ⏸️ |
| **L7** | PWA سایت عمومی | ✅ تایید · ⛔ Web Push رد شد | M | ⏸️ |
| **L8** | سه‌قالبه + رنگ کامل | ✅ تایید — **نیاز به بازطراحی مدل داده** | M | ⏸️ |
| **L9** | زیرساخت (installer/SMTP/داک/دایرکتوری) | ✅ تایید — installer بالاتر از همهٔ K3+ | L | ⏸️ |

## باگ‌های تازه کشف‌شده در فاز ۱ (ثبت می‌شوند، شماره‌گذاری بعد از تأیید)

| # | موضوع | محل | شدت | حالت |
|---|---|---|---|---|
| **L-B1** | 🔴 XSS ذخیره‌شده در JSON-LD — `JSON.stringify` اسلش را escape نمی‌کند | `app/[...path]/page.tsx:118` | 🔴 عمومی، بی‌احرازهویت | ⏸️ |
| **L-B2** | 🔴 XSS ویجت CTA هدر/فوتر — بدون `safeHref` و بدون اعتبارسنجی سرور | `components/site/Chrome.tsx:53` | 🔴 عمومی | ⏸️ |
| **L-B3** | 🔴 `upgrade()` پلاگین سیستمی را با کلید هر ناشر تأییدشده‌ای تصاحب می‌کند (publish-confusion) | `PluginController.php:226-233,266` | 🔴 | ⏸️ |
| **L-B4** | 🔴 مسیر عمومی سایت بدون جداول مرکزی ۵۰۰ می‌دهد ⇒ صفحهٔ خانه ۴۰۴ | `Site/PageController.php:59` + ۲ کنترلر دیگر | 🔴 تولید فعال | ⏸️ |
| **L-B5** | 🔴 `vendor/` از allowlist پلاگین دور می‌زند (`Laravel/src/vendor/**` مجاز است) | `PluginPackageContract.php:471-474` | 🔴 زنجیرهٔ تأمین | ⏸️ |
| **L-B6** | 🔴 `sms/request` به کاربر می‌گوید پیامک ارسال شد، در حالی که هیچ‌چیز ارسال نمی‌شود | `ProfileController.php:381-402` | 🔴 دروغ به کاربر | ⏸️ |
| **L-B7** | 🟠 `themeVars()` هیچ مصرف‌کننده‌ای ندارد — سایت ۱۰۰٪ از توکن‌های **پنل** استایل می‌گیرد | `Chrome.tsx:12` + `globals.css:594` | 🟠 نشت تم | ⏸️ |
| **L-B8** | 🟠 `site-chrome` در allowlist محلی revalidate نیست ⇒ فعال‌سازی قالب کروم را پاک نمی‌کند | `api/revalidate/route.ts:18` | 🟠 کهنگی ۳۰۰ث | ⏸️ |
| **L-B9** | 🟠 `site-status`/`site-homepage` هرگز dispatch نمی‌شوند ⇒ تعلیق فقط با انقضای ISR | `RevalidateDispatcher` call-sites | 🟠 | ⏸️ |
| **L-B10** | 🟠 کنترل symlink فقط `S_IFLNK` را می‌گیرد؛ char/block/FIFO/socket رد نمی‌شوند | `PluginPackageValidator.php:516` | 🟠 | ⏸️ |
| **L-B11** | 🟡 `HREF_OK`/`SRC_OK`/`LinkItems` با `/\evil.com` دور زده می‌شوند (open-redirect) | `BlockRenderer.tsx:21,27` | 🟡 | ⏸️ |

## ردیف‌های موجود که ممیزی فاز ۱ **کهنه** تشخیص داد

| ردیف | ادعای فعلی TASKS.md | واقعیت کد | اقدام |
|---|---|---|---|
| B24 (`:341`) | 🔴 XSS CTA تأییدشده | ✅ **رفع شده** — `safeHref`/`HREF_OK` در `BlockRenderer.tsx:21,39-41,145` | ⏸️ بسته شود |
| B25 (`:342`) | «هرگز بررسی نمی‌شود» | 🟡 **نیمه‌رفع** — ۲ caller در `PluginController.php:107,236`؛ `activate()`/`validatePackage()` هنوز نه | ⏸️ بازنویسی |
| B26 (`:343`) | 🔴 | ✅ رفع شده | ⏸️ بسته شود |
| B27 (`:344`) | 🔴 | ✅ رفع شده | ⏸️ بسته شود |
| «۱۱ فایل هسته» (`:652,696`) | ۱۱ | **۱۵** (۱۲ در مسیر درخواست + ۳ کامند)؛ ۴ فایل جا افتاده | ⏸️ اصلاح |
| «۸ نقطهٔ اتصال» (`:733`) | ۸ | **۱۰** نقطه در `EXTENSION_POINTS` | ⏸️ اصلاح |
| «۱۱ کنترل validator» | ۱۱ | ۱۰ ساختاری + کنترل‌های مانیفست | ⏸️ اصلاح |
| J1 (`:222`) | «❌ ممکن نیست» | صورت‌بندی غلط: «از نظر اقتصادی توجیه ندارد» ≠ «فنی ناممکن» | ⏸️ بازنویسی |

---

# 🚀 فازهای اجرایی جدید (ثبت‌شده ۲۰۲۶-۰۹-۲۶ · فاز ۲)

> **قواعد ثبت:** هیچ تسکی بدون «نظرخواهی ساب‌ایجنت» ثبت نشده. موارد دارای UI، وایرفریم + UI/UX آن‌ها هم ثبت شده.
> **وضعیت‌ها:** `⏸️` آمادهٔ اجرا · `⏸️⏳` در حال اجرا · `⏸️⊘` بسته‌شده · `⏸️؟` منتظر تصمیم مالک
> **کدگذاری:** `F` فاز · `Fx.y` تسک. برای موارد UI هر قابلیت ۴ ردیف دارد: `.W` وایرفریم · `.U` UI/UX · `.B` بک‌اند · `.F` فرانت‌اند

## وضعیت فعلی کد (بازبینی ۲۰۲۶-۰۹-۲۶ — ۵ کامیت جدید از زمان ممیزی فاز ۱)

| بسته‌شده | کامیت |
|---|---|
| ✅ **B4** allowlist پروکسی + اجرای موازی پنل | `d645a24` |
| ✅ **B3** دروازهٔ سمت سرور حالت توسعه‌دهنده | `616d316` |
| ✅ **B9**, **B31** | `6d65ee8` |
| ✅ **K5.0** `core.service_provider` (`ServiceProviderRegistry` ۱۱.۸KB) | `e291945` |
| ✅ **K5.3** PSR-4 autoloader (`PluginAutoloader` ۱۵KB + تست ۳۱KB) | `2ecfc22` |
| ✅ **K5.9** مهر یکپارچگی — منتقل از `Cache::forever` به جدول `settings` | `2ecfc22` |
| ✅ **B12** سه فراخوانی موازی در layout پنل | `d645a24` |
| تست‌ها: ۵۶ → **۶۵ فایل** | — |

> ⚠️ **یافتهٔ مهم:** `ServiceProviderRegistry` + `OVERRIDABLE_INTERFACES` (۳ اینترفیس) + `PluginAutoloader` **از قبل وجود دارند**. طراحی `F0.5` (جداسازی central) روی همین‌ها سوار می‌شود، نه از صفر.

---

## فاز ۰ — بستن ریسک‌های زنده

> اولویت مطلق. بدون این، هیچ فاز دیگری معنا ندارد. موازی‌پذیر: `F0.1..F0.4` یک شاخه، `F0.5..F0.7` شاخهٔ دوم.

| کد | وضعیت | شرح | مشورت ساب‌ایجنت |
|---|---|---|---|
| **F0.1** | ⏸️✅ | 🔴 XSS ذخیره‌شده در JSON-LD صفحهٔ عمومی | **امنیت (S1):** `JSON.stringify` اسلش را escape نمی‌کند. ۲ لایه: escape در `page.tsx:118` + قاعدهٔ `no_markup` در بک‌اند. **افزودن `perm:pages.edit` به `routes/api.php:71,73` توصیه شد** (الان هر مدیر لاگین‌کرده می‌نویسد). S |
| **F0.2** | ⏸️✅ | 🔴 XSS ویجت CTA هدر/فوتر + یکسان‌سازی sanitizer | **امنیت (S2):** `Chrome.tsx:53` بدون `safeHref`. ریشه: `LayoutController.php:69-77` فقط `nav`/`links` را validate می‌کند، `cta` نه. **راه‌حل معماری:** کلاس `App\Validation\SafeUrl` در بک‌اند به‌عنوان منبع حقیقت + استخراج `safeHref` به `lib/sanitize.ts`. اعتبارسنجی بر اساس `config/widgets.php` نه `if type==='cta'` ⇒ ویجت پلاگینی هم خودکار امن. M |
| **F0.3** | ⏸️ | 🔴 تصاحب پلاگین سیستمی در `upgrade()` | **امنیت (S3):** تأیید شد. `key_id` از مانیفست جدید خوانده می‌شود (`:242`) و `publisher_key_id` بازنویسی می‌شود (`:293`). ⇒ RCE + ماندگار (سیستمی حذف‌ناپذیر است). راه‌حل: پین ناشر در `system_plugins.publisher_key_fingerprint` + گارد در `upload()` و `upgrade()` **قبل از `storePackage`**. **۴ تست** شامل اثبات «فایل جدید روی دیسک نوشته نشد». S |
| **F0.4** | ⏸️ | 🔴 دروغ پیامک در `sms/request` | **امنیت (S4):** تأیید شد + یافتهٔ زنجیره‌ای: کاربر با کدی که **هرگز از SMS نگرفته** شماره را «تأییدشده» می‌کند و ۹۰ روز می‌ماند (`:422,:424`). **تصمیم مالک: گزینهٔ (ب)** — صریح به کاربر اعلام شود «هنوز فعال نیست» + UI «نمایش‌با-غیرفعال» (نه حذف؛ پروژه الگوی «شفافیت صادقانه» را در `PluginController:196` و `page.tsx:9` رعایت کرده). S |
| **F0.5** | ⏸️ | 🔴 جداکردن هسته از `central_subscriptions` | **بک‌اند (C1):** ۵ مصرف‌کنندهٔ `ClientSubscriptionResolver` + ۱۰ ارجاع `Central\` (همه در `CentralBoundaryTest::KNOWN_VIOLATIONS`). راه‌حل: `SubscriptionSource` interface + `SubscriptionState` value-object + `NullSubscriptionSource` پیش‌فرض. **`/v1/site/status` زیر Null باید `200 {status:"active"}` بدهد نه 404** (وگرنه خانهٔ سایت ۴۰۴ می‌شود). یافته: `SiteChromeController.php:29-32` متغیر استفاده‌نشده ⇒ حذف ۴ خط. **XL — ۲۱ فایل** |

| **F0.7** | ⏸️ | تگ‌های مردهٔ revalidate | **بک‌اند (C3):** `ALLOWED_LOCAL_TAGS` (`route.ts:18`) = `["pages","theme"]` ولی `site-chrome` مصرف‌شده و هرگز پاک‌نشدنی است؛ `theme` تگ مرده (هیچ fetcherی ندارد). + `SiteSettingsController:147` فقط `site-chrome` می‌فرستد ⇒ تغییر عنوان سایت ۳۰۰ ثانیه کهنه می‌ماند. + `ThemeController` اصلاً dispatcher صدا نمی‌زند. S/M — ۸ فایل |
| **F0.8** | ⏸️ | 🟠 `themeVars()` بی‌مصرف + نشت تم پنل به سایت | **فرانت:** صفر قاعدهٔ CSS `--theme-*` را مصرف می‌کند. سایت ۱۰۰٪ از توکن‌های **پنل** استایل می‌گیرد. `ThemeProvider` در روت mount شده ⇒ تم پنل مدیر به سایت عمومی نشت می‌کند و بازدیدکننده `:root` تیره می‌بیند. **پیش‌نیاز مستقیم `F4.1`** |
| **F0.9** | ⏸️ | افزودن test runner به فرانت‌اند | **پیش‌نیاز گیت** `F0.1/F0.2` (تست واحد) و `F4.1` (تابع `resolve()`) و `F5` (استراتژی کش). الان `package.json` فقط `lint`+`typecheck` دارد |
| **F0.10** | ⏸️ | SMTP واقعی (config-only) | `config/mail.php:38-49` آماده است ⇒ **صفر کد**. فقط `.env` + `timeout: 10` (الان `null`) + mailer نوع `failover`. **بستگی به `F0.6`** |
| **F0.11** | ⏸️ | 🔴 `ContactForm.tsx` orphan — به سایت وصل نیست | **تصمیم ۶ مالک.** فرم تماس اصلاً در سایت عمومی رندر نمی‌شود ⇒ نه فرم تماس کار می‌کند نه تیکت. رفع در فاز امنیتی/صحت |
| **F0.12** | ⏸️ | اتصال Arvan CDN | هدرهای `s-maxage=300` **همین حالا** روی APIهای عمومی هستند. گیت `F5` |

### 📐 یادداشت طراحی F0.6 — الزام معماری (بدون تغییر وضعیت)

> **پاسخ Q7:** چابکان از اجرای خودکار و زمان‌بندی‌شده (کرون) پشتیبانی **نمی‌کند**.
> این یک الزام معماری است که باید از همین حالا در طراحی F0.6 لحاظ شود.

**تغییر طراحی نسبت به نسخهٔ قبلی:**

| | نسخهٔ قبلی (کرون بومی) | نسخهٔ اصلاح‌شده |
|---|---|---|
| محرک | `* * * * *` روی خود میزبان (پنل چابکان) | سرویس بیرونی زمان‌بند (مثل cron-job.org) که یک URL را صدا می‌زند |
| وابستگی به ارائه‌دهنده | 🔴 وابسته به PaaS ⇒ با تعویض ارائه‌دهنده می‌شکند | 🟢 فقط یک URL + یک راز ⇒ **مستقل از نوع ارائه‌دهنده** (چابکان، لیارا، هر جا) |
| راز | — | 🔑 **رمز مجزا و مستقل از `REVALIDATE_SECRET`** — دو راز جدا، دلیل جدا: یکی سطح کش، آن یکی سطح نگهبانی |
| سطح حمله | کم | 🟠 یک route عمومی ⇒ نیازمند rate-limit + مقایسهٔ زمان‌ثابت |

**الزامات طراحی که باید در F0.6 رعایت شود:**

1. **مسیر امن HTTP**، نه `queue:work` دائمی و نه کرون میزبان. یک route کنترلر با prefix داخلی (مثلاً `/api/internal/tick`) که:
   - با **هدر** رمز اختصاصی خودش (نه query string — که در access log می‌ماند) احراز هویت کند
   - `hash_equals` برای مقایسهٔ زمان‌ثابت داشته باشد
   - `throttle` سخت داشته باشد
   - **fail-closed** باشد: رمز نادرست ⇒ 401 و **هیچ کاری اجرا نشود**
2. **دو مسیر جدا با دو راز جدا.** `REVALIDATE_SECRET` برای purge کش ISR بماند؛ راز جدید فقط برای tick. یکی compromise شد نباید دیگری را بی‌اثر کند و برعکس.
3. **توکن از env**، با **پیش‌فرض خالی** ⇒ اگر تنظیم نشده باشد، route باید ۵۰۳ بدهد (fail-closed)، نه اینکه با رشتهٔ پیش‌فرض کار کند. (همان دلیلی که `F0-B8` برای `REVALIDATE_SECRET` یک باگ است.)
4. **idempotent بودن.** سرویس بیرونی ممکن است چند بار پشت‌سرهم صدا بزند (retry، اجرای همزمان دو tick). `outbox:drain` باید با claim اتمیک خودش این را تحمل کند — که در طراحی قبلی **قبلاً** دیده شده بود و تغییر نمی‌کند.
5. **جداسازی provider در آینده.** اگر بعداً لیارا اضافه شد و کرون بومی هم داشت، مسیر HTTP همچنان کار می‌کند ⇒ هیچ‌چیز برای بازنویسی لازم نیست. این دقیقاً همان الزامی است که مالک در تصمیم ۴ گذاشت.
6. **بازهٔ فراخوانی.** سرویس بیرونی معمولاً حداقل ۵ دقیقه (یا حتی ۱ ساعت در پلن رایگان) فاصله می‌دهد ⇒ `schedule:run` داخلی لاراول که هر دقیقه کارهای عقب‌افتاده را اجرا می‌کند باید **داخل همان tick** فراخوانی شود، نه جداگانه. یعنی tick = «اجرای دیرهنگام schedule» + «drain صف».

> ⚠️ **ریسک باقی‌مانده:** اگر سرویس بیرونی از کار بیفتد، `billing:suspend` (تعلیق D-0) اجرا نمی‌شود ⇒ درآمد MRR بدون enforcement. این باید در UI پنل مرکزی **نمایش داده شود** (مثلاً «آخرین tick: ۳ ساعت پیش» با هشدار وقتی از حد گذشت) تا خرابی خاموش نباشد.

---

### ✅ گزارش تکمیل F0.1 و F0.2

**تاریخ:** ۲۰۲۶-۰۹-۲۶ · **کنترل رگرسیون:** ۴۷۴ تست پاس · ۱۵ شکست از قبل موجود (F0-B10) · `tsc --noEmit` پاک

#### F0.1 — XSS در JSON-LD

| لایه | تغییر | فایل |
|---|---|---|
| رندر | `safeJsonLd()` — escape کردن `<` `>` `&` قبل از `dangerouslySetInnerHTML` | `frontend/src/app/[...path]/page.tsx` |
| اعتبارسنجی | قاعدهٔ `no_markup` روی `title` + ۵ کلید متا | `PageController.php::rules()` |
| اعتبارسنجی | قاعدهٔ `no_markup` روی `title`, `description`, `ai_summary`, `address` | `SiteSettingsController.php::updateSite()` |
| مجوزدهی | `perm:pages.edit` روی **۴ مسیر نوشتن**: `POST pages` · `PUT pages/{page}` · `POST pages/{page}/publish` · `POST pages/{page}/restore/{revision}` | `routes/api.php` |
| زیرساخت | کلاس `NoMarkup` + ثبت `Validator::extend` | `app/Validation/NoMarkup.php` · `AppServiceProvider.php` |

**تصمیم طراحی:** قاعده فقط `<` را می‌گیرد، نه `>`. باز کردن تگ با `<` شروع می‌شود و بستنش اختیاری است، پس مسدودکردن `<` کافی است — و `>` در متن فارسی («پلن A > B») رایج است و بستنش نویسنده را می‌بندد بی‌هیچ سود امنیتی. تست `test_benign_greater_than_is_accepted` این را قفل می‌کند.

**تست‌ها:** `tests/Feature/PageMarkupGuardTest.php` — ۹ تست
- رد `<script>` breakout، رد تگ بازِ تنها، رد markup در متا، رد در update
- پذیرش `>` سالم (ضدّ-رگرسیونِ سخت‌گیری بی‌مورد)
- `viewer` بدون `pages.edit` نمی‌تواند create/update/publish کند (۳ تست)
- `viewer` هنوز می‌تواند **بخواند** (مشترک بودن نصب یعنی همه می‌بینند، نه همه می‌نویسند)

#### F0.2 — XSS ویجت CTA + یکسان‌سازی allowlist

| لایه | تغییر | فایل |
|---|---|---|
| منبع حقیقت | کلاس `SafeUrl` — رد `//evil.com` و `/\evil.com`، `DENY_PREFIXES`، حذف نویسهٔ کنترلی **فقط برای آزمون** | `app/Validation/SafeUrl.php` |
| رجیستری محور | `widgetSchemaIndex()` + `urlFieldsOf()` + `validateWidgetHrefs()` — اعتبارسنجی از روی `config/widgets.php` **و** ویجت‌های اعلام‌شدهٔ پلاگین/قالب | `LayoutController.php` |
| حذف تکرار | `LinkItems::isValidHref()` به `SafeUrl` واگذار شد ⇒ allowlist دو نسخه ندارد | `LinkItems.php` |
| رندر | `safeHref` در **۴ نقطهٔ بی‌محافظ**: `Chrome.tsx` ویجت `cta` · `Chrome.tsx` آیتم فوتر · `Chrome.tsx` شبکه‌های اجتماعی · `SiteNav.tsx` منوی هدر | ۲ فایل |
| استخراج | `safeHref`/`safeSrc`/`safeHtml` به `lib/sanitize.ts` منتقل و الگوها با بک‌اند هم‌تراز شد (`?` و `tel:` اضافه، `\` رد) | `lib/sanitize.ts` · `BlockRenderer.tsx` |

**تصمیم طراحی:** اعتبارسنجی از رجیستری، نه `if ($type === 'cta')`. ریشهٔ باگ همین بود که هر نوع تازه برای پوشش‌دادن ویرایش دستی می‌خواست. حالا یک بار که `href` در schema اعلام شود، **ویجت پلاگینی هم خودکار** پوشش می‌گیرد.

**تست‌ها:** `tests/Feature/WidgetHrefGuardTest.php` — ۱۲ تست
- تزریق `javascript:` در **سه نوع ویجت مختلف**: `cta` · `nav` · `links` (الزامِ شما)
- بردارهای دور زدن: `//evil.com` · `/\evil.com` · `java\tscript:` · `data:`
- ضدّ-رگرسیون: ۷ نشانی معتبر پذیرفته می‌شوند · نبودن `href` خطا نیست
- ماتریس کامل `SafeUrl` (۱۷ مورد) + سخت‌گیری بیشتر `src` · واگذاری `LinkItems`

#### باگ بحرانی که حین اجرا پیدا و رفع شد

**`F0-B0` — اپ بوت نمی‌شد.** `AppServiceProvider.php:36` کلاس `ServiceProviderRegistry` را بدون `use` صدا می‌زد؛ PHP آن را در `App\Providers` حل می‌کرد ولی کلاس در `App\Services\Plugins` است ⇒ `BindingResolutionException` در هر درخواست. **از کامیت `e291945` (K5.0) بود، نه از این فاز** — ولی تا وقتی درست نشد، هیچ تستی اجرا نمی‌شد. یک `use` اضافه شد.

#### fixtureهای تستی که به‌روزرسانی شدند

۴ فایل تست، چون قبلاً رفتار باگ‌آلود (هر کاربر لاگین‌کرده می‌تواند بنویسد) را ناخواسته تثبیت کرده بودند: `PagesTest` · `BlockTypesTest` · `FaqBlockTest` · `SidePresetsTest`. هر چهار `setUp` با `RolesPermissionsSeeder` و پرمیشن `pages.edit` گرفتند.

> ⚠️ نکتهٔ روش‌شناختی: در همین مسیر، یک اسکریپت patch خودکار نوشتم که به‌خاطر خطای کامپایل regex، `file_put_contents($path, null)` سه فایل تست را **صفر بایت** کرد. با `git checkout` بازگردانده شد و بعد با ابزار ویرایش مستقیم انجام شد. بدون این، تشخیص اشتباه ممکن بود.
### باگ‌های تازهٔ کشف‌شده در مشورت (ثبت، اجرا در فاز ۳)

| کد | شرح | محل | کشف |
|---|---|---|---|
| **F0-B0** | 🔴 **اپ بوت نمی‌شود**: `AppServiceProvider` کلاس `ServiceProviderRegistry` را بدون `use` صدا می‌زد ⇒ PHP آن را در `App\Providers` حل می‌کرد در حالی که کلاس در `App\Services\Plugins` است ⇒ `BindingResolutionException` در هر درخواست. **از کامیت `e291945` (K5.0) — نه از F0.1** | `AppServiceProvider.php:36` | ضمن F0.1 |
| **F0-B1** | 🔴 ناسازگاری allowlist بک‌اند/فرانت: `LinkItems.php:219` `//evil.com` را قبول می‌کرد ولی `HREF_OK` رد می‌کرد | `LinkItems.php:219` | S2 — ✅ در F0.2 بسته شد |
| **F0-B2** | 🔴 `ServiceProviderRegistry` در `OVERRIDABLE_INTERFACES` عدد ۳ دارد ⇒ پلاگینی که ۴ رابط اعلام کند `too_many_declarations` می‌خورد | `PluginPackageContract.php:401` | C1 گام ۷ |
| **F0-B3** | 🟠 `relativeFa` فقط دقت روز دارد ⇒ برای فید اعلان بی‌مصرف | `lib/fa.ts:31` | UI/UX |
| **F0-B4** | 🟠 باگ RTL سوییچ: `translateX(-18px)` در RTL کلید را به چپ می‌برد | `globals.css:454` | UI/UX |
| **F0-B5** | 🟠 کلاس `.dot` (نشان اعلان) هیچ قاعدهٔ CSS ندارد | `globals.css` | UI/UX |
| **F0-B6** | 🟡 `Stepper` فقط ۴ مرحلهٔ هاردکد دارد و حالت `failed/locked/skipped` ندارد | `primitives.tsx:40` | UI/UX |
| **F0-B7** | 🟡 بلوک `prefers-reduced-motion` فقط `.topbar-action` را پوشش می‌دهد | `globals.css:701` | UI/UX |
| **F0-B8** | 🟠 `REVALIDATE_SECRET` پیش‌فرض `change-me-in-production` ⇒ HMAC قابل جعل اگر env ست نشود | `config/revalidate.php:8` | C3 |
| **F0-B9** | 🟠 `CachedSettings::flushTag()` روی درایور `database` **کاملاً مرده** است | `CachedSettings.php:46-53` | C2 |
| **F0-B10** | ⚠️ **۱۵ تست از قبل شکسته‌اند** — `MonitoringTest` · `MonitoringExtrasTest` · `OperatorTest` · `OperatorHardwareSetupTest` · `ProfileTest` · `ProfileExtrasTest` · `UpdatesTest` · `UpdatesExtrasTest`. علت: مهاجرت **commit‌نشدهٔ** `2026_10_05_000001_prefix_central_tables.php` جدول‌های `core_versions` / `operator_hardware_keys` / `install_heartbeats` را به `central_*` تغییر نام داده ولی مدل‌ها و تست‌های دیگر هنوز نام قدیمی را می‌خواهند. **با `git stash` کل کار F0.1/F0.2 کنار گذاشته شد و همان ۱۵ تست همچنان شکست خوردند ⇒ تأیید‌شده که ربطی به این فاز ندارد.** | — | کنترل رگرسیون F0.1 |

---

## فاز ۱ — توزیع (موازی با فاز ۲)

> **بالاترین اثر راهبردی:** بدون installer، هیچ‌کس CMS را نمی‌بیند. وعدهٔ `K0.5` («تک‌دستور») در repo **هیچ مصنوعی ندارد** — هر دو compose dev-grade هستند.

| کد | وضعیت | شرح | مشورت ساب‌ایجنت |
|---|---|---|---|
| **F1.1.W** | ⏸️✅ | وایرفریم installer — **انجام شد** | ۷ گام: preflight(۲۱ چک) → database(۱۴ فیلد) → app_key → migrate → superadmin → finalize → done. ماتریس ۸ حالتهٔ نصب نیمه‌کاره + ژورنال resume. `cms-core/installer/` **وجود ندارد** |
| **F1.1.U** | ⏸️✅ | UI/UX installer — **انجام شد** (بخش A + حرکت + دسترس‌پذیری) | Blade + progressive enhancement (نه React). `StepRail` RTL، `PreflightTable` با ستون remedy، `SecretRevealBlock` با QR درون‌خطی. ⚠️ **یافته:** `SuperAdminSeeder` سکرت TOTP را بی‌قید به stdout چاپ می‌کند و `google2fa_enabled=true` از ابتدا ⇒ نصب‌کننده نباید آن سیدر را صدا بزند |
| **F1.1.B** | ⏸️ | بک‌اند installer | `InstallGuard` بدون میدانلور `web` (تا با `APP_KEY` خالی ۲۰۰ بدهد) · `storage/install/{install.lock,journal.json}` · preflight با **رُن‌تاپ واقعی `sodium`** نه `extension_loaded` · `CREATE COLLATION` واقعی نه فقط `has_database_privilege` · **PHP ۸.۳** (نه ۸.۲ — `composer.json:72`) |
| **F1.1.F** | ⏸️ | پوستهٔ وب + CLI | `routes/install.php` + `views/install/*` + `php artisan cms:install`. **بدون دکمهٔ «رد کردن»** (پیش‌ینوس `K1.7`) |
| **F1.2** | ⏸️ | دایرکتوری لینک‌سادهٔ پلاگین/قالب (K8 v1) | `registry/curated.json` + بررسی CI با validator موجود. **`execution_status: "unavailable"` صریح تا K5** — دکمهٔ نصب جعلی نقض اصل پروژه است. S — ۳ ردیف |
| **F1.3** | ⏸️ | outbox ایمیل | وابسته به `F0.6`. یک outbox، **سه مصرف‌کننده**: ایمیل + SMS + Push |

---

## فاز ۴ — قابلیت‌های کاربر

| کد | وضعیت | شرح | مشورت ساب‌ایجنت |
|---|---|---|---|
| **F4.1.W** | ⏸️✅ | وایرفریم سیستم سه‌قالبه — **انجام شد** | ادیتور `/admin/site-theme` سه‌ستونه. **۱۰ scope × ۸ role = ۸۰ جای‌خالی در هر mode**، ولی کاربر **همیشه یک scope** می‌بیند ⇒ **حداکثر ۹ کنترل**. روشن/تاریک = دو مجموعهٔ مستقل + `mode: system`. override به‌صورت sparse. جداسازی ۳ قالب با `key='draft:{template}'` (نه قالب‌بندی کلید) |
| **F4.1.U** | ⏸️✅ | UI/UX سه‌قالبه — **انجام شد** | ⭐ **یافتهٔ کلیدی:** `--primary-soft` روی `:root` یک‌بار با `color-mix()` محاسبه و **ارث** می‌برد ⇒ نوشتن `--primary` روی کانتینر آن را بازمحاسبه **نمی‌کند**. راه‌حل: ۶ خط بازاعلام `-soft` روی `.theme-scope`. پیش‌نمایش: `rAF` + `ref.style.setProperty` (نه debounce — حس مرده می‌دهد). ⚠️ **بازاعلام `-soft` باید کامنت‌دار باشد** وگرنه کسی حذفش می‌کند |
| **F4.1.B** | ⏸️ | بک‌اند سه‌قالبه | ۵ اندپوینت `site-theme/*` · seed ۳ قالب در `themes` · `CachedSettings::forget('site_chrome')` در `PUT` (وگرنه ۲ دقیقه کهنه) · تگ `site-theme` به `ALLOWED_LOCAL_TAGS` اضافه شود |
| **F4.1.F** | ⏸️ | فرانت ادیتور + گالری + `/preview` | `/admin/themes` بازطراحی (تب تمپلیت + تب ZIP) · `/preview` سراسری (بدون پوستهٔ پنل) — بدهی فعلی `ThemeController:150` که ۴۰۴ است. وابسته به `F0.8` |
| **F4.2.W** | ⏸️✅ | وایرفریم اعلان پنل — **انجام شد** | ۳ سطح: زنگ + پاپ‌اور · صندوق `/admin/notifications` · تنظیمات. **۲۷ تریگر بررسی → ۲۰ فعال، ۷ حذف** (پلاگین فعال/غیرفعال/ارتقا/حذف، قالب فعال، صفحه منتشر شد، هشدار سهمیه) — استدلال: «کنندهٔ رویداد = بینندهٔ اعلان» ⇒ نویز خالص. **صندوق باید استثنای `SuspensionGuard` باشد** وگرنه اعلان تعلیق نوشته‌نشده‌دیده‌می‌شود |
| **F4.2.U** | ⏸️✅ | UI/UX اعلان پنل — **انجام شد** | زنگ مردهٔ `Topbar:48` زنده می‌شود (ایموجی 🔔 → SVG). خوانده‌نشده **۴ کانال** (ریل لبه/وزن/رنگ/SR) — نه تینت پس‌زمینه. ترجیحات: ۵ کارت × ۳ کانال، **سوییچ اصلی تعامل غالب** + استثناها پشت دکمه. خاموشی با **توکن نه `opacity`** (`--text-muted` ۵.۹:۱ دارد؛ `opacity:.45` می‌بردش زیر ۲:۱) |
| **F4.2.B** | ⏸️ | بک‌اند اعلان | `notifications` **افزودن ستون** (نه تعویض — `Notifiable` + `DatabaseChannel` شکلش را لازم دارند) · `notification_deliveries` (**همان outbox**) · `notification_preferences` · `App\Notifications\Catalog` منبع حقیقت. **امنیت: بدنه هرگز HTML + `action_href` از کاتالوگ با ۸ شرط allowlist** |
| **F4.2.F** | ⏸️ | فرانت اعلان | `NotificationBell` + popover + inbox + preferences. ⚠️ **برخورد مسیر:** `K5.8` مسیر `POST /v1/admin/notifications` را رزرو کرده — باید آگاهانه حل شود |
| **F4.3** | ⏸️ | central/billing → پلاگین واقعی runtime | **تصمیم ۱ مالک.** وابسته به `F2` (هستهٔ اجرا). ۱۵ فایل هستهٔ coupled (نه ۱۱). مسیر: `CentralSubscriptionSource` → بستهٔ پلاگین |
| **F4.4** | ⏸️ | **زبان رابط کاربری — انتخاب از تنظیمات** | **درخواست کاربر.** پنل + سایت عمومی به فارسی و انگلیسی. ⛔ وضعیت فعلی: `config/app.php` هنوز مقادیر پیش‌فرض اسکلت لاراول است (`en`/`en_US`)، **پوشهٔ `lang/` وجود ندارد**، هیچ فایل ترجمه‌ای نیست، فرانت‌اند هیچ لایهٔ i18n ندارد. قدم ۱: `lang/fa/` + `lang/en/` + `composer.json` تنظیم کرده شود. قدم ۲: انتقال رشته‌های هاردکد فرانت به دیکشنری. قدم ۳: سوییچر در `admin/settings`. ⚠️ RTL باید از لوکال بیاید نه از زبان — `dir="ltr"` برای انگلیسی الزامی است وگرنه کل چیدمان می‌شکند |
| **F4.5** | ⏸️ | **محتوای چندزبانهٔ سایت** | **درخواست کاربر — احتمالی.** هر صفحه/بلوک به چند زبان. ⚠️ تصمیم معماری لازم: `page_revisions` فعلاً تک‌زبانه است و `published_revision_id` یک اشاره‌گر. گزینه‌ها: (الف) ستون `locale` روی `pages` + ایندکس یکتا، (ب) `page_variants` جدا، (ج) `locale` روی `page_revisions` که `published_revision_id` را چندتایی می‌کند. مسیر URL: `/` + `/en/` یا `/fa/`. ⚠️ سئو: `hreflang` + `sitemap` چندزبانه لازم می‌شود که به `SitemapService` دست می‌زند |

---

## فاز ۵ — PWA

| کد | وضعیت | شرح |
|---|---|---|
| **F5.1** | ⏸️ | PWA سایت عمومی — **بعد از سنجش نتیجهٔ `F0.12`** (CDN احتمالاً بیشترین سود را بدون ریسک کهنگی می‌دهد) |
| **F5.2** | ⏸️ | **PWA پنل: فقط manifest + آیکون Home Screen، بدون Service Worker** (تصمیم ۵ مالک). ~۲ ردیف، XS |
| **F5.3** | ⏸️⊘ | Web Push — **رد شد** (iOS + نبود queue + بازگشتی بودن برای سایتی که RSS/تلگرام دارد) |

---

## فاز ۶ — بازار

| کد | وضعیت | شرح |
|---|---|---|
| **F6.1** | ⏸️ | K8 بازار واقعی — فقط وقتی `F2` واقعاً کار می‌کند. `F1.2` نسخهٔ اول آن است |

---

## پرسش‌های باز (منتظر تصمیم مالک)

| # | پرسش | مرحلهٔ ثبت |
|---|---|---|
| **Q1** | 🔴 **ابهام D5:** «نصب» یعنی کاربر یا سایت؟ `ClientSubscriptionResolver:23` روی **کاربر** کلید می‌زند ولی `tickets`/`settings` **نصب‌سطح** هستند. بررسی داده: **الان دوبار صورتحساب نشده**، ولی **سوپرادمین مخفی (id 17) خودش کلاینت+اشتراک فعال دارد** و با اضافه‌شدن مدیر دوم، همان نصب دوباره صورتحساب می‌شود | `F0.5` |
| **Q2** | سهمیهٔ فضای فایل در نبود پلاگین: نامحدود (`0`) یا ثابت «رایگان»؟ | `F0.5` |
| **Q3** | آیا `NEXT_PUBLIC_SITE_ID` در نصب واقعی ست می‌شود؟ اگر بله، تصمیم ۴۰۴-در-برابر-۲۰۰ یک حادثهٔ تولیدی است | `F0.5` |
| **Q4** | تم سایت **سراسری** باشد یا per-user؟ وایرفریم پیشنهاد **سراسری** داد (`settings` فیلد `user_id` ندارد و ظاهر سایت به بازدیدکنندهٔ بدون حساب می‌رسد) | `F4.1` |
| **Q5** | ۳ قالب از **کد** بیایند یا ZIP؟ وایرفریم پیشنهاد **کد** داد (ZIP استخراج نمی‌شود ⇒ شکاف امنیتی) | `F4.1` |
| **Q6** | دامنهٔ `F4.1`: فقط رنگ + پوسته، یا ساختار چیدمان هم؟ ۲۶ مقدار هاردکد `BlockRenderer` در کدام فاز تبدیل به متغیر شود؟ | `F4.1` |
| **Q7** | `schedule:run` روی PaaS قابل استقرار است؟ اگر نه ⇒ کانال ایمیل/SMS در `F4.2` نباید ساخته شود | `F0.6`, `F4.2` |
| **Q8** | آیا ۱۱ باگ تازهٔ ممیزی فاز ۱ (`L-B1..L-B11` در بخش «L») قبل از `F4.1` بسته شوند؟ `F4.1` روی `Chrome.tsx` و `BlockRenderer.tsx` سنگین کار می‌کند | `F4.1` |
| **Q9** | آیا فرآیند ۴ مرحله‌ای برای ردیف‌های غیر-UI کوتاه شود؟ (خودِ ساب‌ایجنت گفت برای installer/outbox/دایرکتوری «بی‌معنا» است) | — |

---

# 📋 ترتیب اجرای قطعی

> هر گام بدون گام قبلش معنا ندارد یا بی‌فایده است.
> ردیف‌های L بعد از تأیید کارفرما به این جدول اضافه می‌شوند (فعلاً در `docs/L-FEASIBILITY.md` است).

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

---

## گروه M — مهاجرت کش/استور از پوشه موقت سیستم به داخل پروژه (درخواست کاربر)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| M1 | کشف داده‌های وابستگی/کش خارج از پروژه | done | — | فقط `npm cache` در `C:\Users\...\AppData\Local\npm-cache` (۲۴۲MB) متعلق به این پروژه بود. `pnpm store` ۲GB متعلق به پروژه‌های دیگر (این ریپو pnpm ندارد) → خارج از دامنه. `node_modules` و `.next` از قبل داخل پروژه‌اند |
| M2 | انتخاب مقصد + محافظت گیت و آپلود | done | `.gitignore`, `.dockerignore` | مقصد `.local/cache/npm`؛ افزوده شد به `.gitignore` و `.dockerignore`؛ اثبات با `git check-ignore` و `git ls-files` |
| M3 | اعمال تنظیم npm در سطح پروژه | done | `cms-core/frontend/.npmrc` (gitignored) + `.npmrc.example` (committed) | مسیر مطلق فقط در فایل gitignored؛ فایل نمونهٔ committed بدون مسیر مطلق. هیچ تغییری در `package-lock.json` |
| M4 | مهاجرت داده (کپی اول) و اثبات صحت | done | — | `robocopy /E`، برابری تعداد فایل/حجم، hash نمونه، سپس `npm ci --offline` سبز |
| M5 | اثبات: lint / typecheck / test / build / اجرای واقعی | done | — | همه سبز با کامندهای مرحله ۶ |
| M6 | پاک‌سازی مسیر قدیمی و اثبات پس از آن | done | — | فقط `npm-cache` قدیمی حذف شد؛ build/test دوباره سبز؛ `git status` بدون تغییر کار کاربر |

## گروه U — بروزرسانی زیرساخت و فریم‌ورک (درخواست کاربر)

ترتیب بر اساس **ریسک** است، نه شمارهٔ نسخه: اول وصله‌های امنیتی، بعد کارهای کم‌ریسک، آخر ارتقای major.
همهٔ نسخه‌های «آخرین» از منابع رسمی تأیید شده‌اند (GitHub API / npm registry / Docker Hub / GitHub release notes).

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| U1 | MinIO: پین کردن نسخه + وصلهٔ امنیتی | Blocked | cms-core/backend/docker-compose.yml | ⛔ **Blocked — مسیرها امتحان شدند و هیچ‌کدام از این ماشین کار نمی‌کنند.** (۱) `pull` از quay: `401`. (۲) رجیستری‌های دیگر: `ghcr` رد می‌شود، `public.ecr.aws` و Docker Hub برای `minio/minio` وجود ندارند، و `bitnami/minio` **همهٔ تگ‌هایش را پاک کرده** (صفر تگ). (۳) build از سورس: تگ سورس موجود است (`9e49d5e`) و clone شد، و `Dockerfile.pishdad` هم برای compile از سورس نوشته شد — ولی `golang:1.24-alpine` با `403 Forbidden` از CDN رد می‌شود و Go روی ویندوز هم نصب نیست. **⇒ این تسک فقط روی ماشینی با دسترسی quay یا Docker Hub قابل انجام است.** **نکتهٔ معماری که باید ثبت شود:** ریپوی `minio/minio` در `2026-04-25` آرشیو شده (`archived: true`) ⇒ `RELEASE.2025-10-15` **آخرین انتشار MinIO برای همیشه** است. وصلهٔ CVE اعمال می‌شود ولی هیچ وصلهٔ بعدی‌ای در کار نخواهد بود، و README رسمی دیگر `quay.io` را حتی ذکر نمی‌کند. این یک تصمیم محصول است نه یک تسک به‌روزرسانی |
| U2 | PHP ‏8.3.33 ← 8.3.35 | done | cms-core/backend/Dockerfile |  ✅ تمام. `8.3.33` → `8.3.35` با `docker compose build --no-cache app` (بسته‌های `php83` از ریپوی آلپاین تازه می‌آیند چون Dockerfile نسخه را تگ نمی‌کند). ریپوی آلپاین ۳.۲۴ الان `php83 8.3.35-r0` می‌دهد. ۴۹۵ تست پاس روی نسخهٔ جدید. **بدون تغییر فایل** — به همین دلیل U2 جزئی بود |
| U3 | حذف docker-compose.yml کهنهٔ ریشه | done | docker-compose.yml (ریشه) |  ✅ تمام — از قبل. فایل `docker-compose.yml` ریشه هرگز در گیت commit نشده بود، یعنی یک فایل زائد روی دیسک بود و کسی (سشن دیگر) پاکش کرده. تأیید: `git log` صفر commit برای این مسیر، و الان فقط دو compose واقعی باقی است: `cms-core/backend` و `cms-core/frontend` |
| U4 | Alpine ‏3.24.0 ← 3.24.2 | done | cms-core/backend/Dockerfile |  ✅ تمام — خودبه‌خود. `alpine:3.24` تگ شناور است و ایمیج موقع `build --no-cache` برای U2 تازه شد ⇒ `3.24.0` → `3.24.2` بدون هیچ تغییری در فایل |
| U5 | Nginx ‏1.27 ← 1.31.6 | done | cms-core/backend/docker-compose.yml | 🟡. سرور وب. بدون breaking change |
| U6 | Laravel ‏11.56.1 ← 12.69.2 | done | cms-core/backend/composer.json |  ✅ تمام. `11.56.1` → `12.69.2` در `4a49dd9`. tinker `2.10.2` شد (نسخهٔ ۲.۱۰.۰ فقط تا `illuminate/support ^11.0` را می‌گیرد). ۴۸۹ تست پاس. **کشف:** mirror سراسری composer به `mirrors.aliyun.com` داخل کانتینر `canonical` بود ⇒ وقتی متادیتایش کهنه می‌شد fallback به packagist نمی‌کرد و update شکست می‌خورد. با `canonical:false` حل شد؛ **ولی داخل کانتینر است و با rebuild از بین می‌رود** |
| U7 | Laravel ‏12.69.2 ← 13.33.0 | done | cms-core/backend/composer.json |  ✅ تمام. `12.69.2` → `13.33.0` در `d611fd0`. tinker مجبور شد `2`→`3` (۲.۱۱.۱ سقف `^12.0` دارد). **پیامد پنهان:** guzzle `7`→`8` و psr7 `2`→`3` هم major عوض کردند، روی لایهٔ HTTP اثر دارند که Chabokan و MinIO از آن استفاده می‌کنند. ۴۸۹ تست پاس، بدون deprecation، ۱۷۰ route، صفر خطای جدید در لاگ |
| U8 | Next.js ‏15.5.26 ← 16.3.6 + React ‏19.0.0 ← 19.3.0 | done | cms-core/frontend/package.json |  ✅ تمام در `bdd235a` و `b5f43da`. `15.5.26`→`16.3.6` و React `19.0.0`→`19.3.0`. **دو breaking change واقعی:** (۱) `revalidateTag` آرگومان دوم خواست ⇒ `{ expire: 0 }`. دام: `updateTag` جایگزین تمیز‌تری است ولی فقط در Server Action کار می‌کند و در Route Handler پرتاب می‌شود — typecheck سبز می‌شد و runtime می‌ترکید. (۲) convention `middleware` منسوخ شد ⇒ `proxy.ts`، و نام export هم باید `proxy` باشد وگرنه خطای E394. تأیید: `tsc` پاک، build از صفر ۳۸ صفحه، `/admin` و `/central` بدون کوکی `307` به `/login` در dev و production. **دام دوم:** dev server را با `Stop-Force` وسط نوشتن فایل type کشتم و `.next/dev/types/routes.d.ts` نیم‌کاره شد که `tsc` را با خطای parse و `exit=-1` می‌انداخت 
ext.config، middleware احراز هویت، و **cacheHandler روی Redis** (ISR چابکان) که تغییرات breaking در caching دارد. توجه: 15.5.26 همین حالا آخرین وصلهٔ امنیتی شاخهٔ ۱۵ است (dist-tag: backport) و پروژه از کد آسیب‌پذیر 
ext/og استفاده نمی‌کند ⇒ ارتقا اختیاری است نه فوری |
| U9 | PostgreSQL ‏16.15 ← 18.6 | done | cms-core/backend/docker/postgres/Dockerfile | ✅ تمام. `16.15` → `18.6`. روش: dump/restore نه `pg_upgrade` — دیتابیس فقط ۱۱ مگابایت و ۴۸ جدول بود پس dump/restore امن‌تر و قابل بازگشت است. **بکاپ هر ۶ دیتابیس** (cms + ۵ تست) قبل از هر کاری به host کپی شد. دیتابیس‌های تست موازی `cms_test_a..d` بعد از ساخت مجدد volume وجود نداشتند و دوباره ساخته شدند. تداخل `collation fa-IR already exists` با `--clean --if-exists` رفع شد (اسکریپت init خودش collation را می‌سازد و dump هم دارد). **تأیید:** هر ۶ شمارش داده دقیقاً برابر baseline (۴۸ جدول، ۶ کاربر، ۲۷ permission، ۱۱ جدول مرکزی)؛ مرتب‌سازی فارسی با `fa-IR` بین ۱۶ و ۱۸ **یکسان** است (`پ` قبل از `ت` می‌آید، که بایتی غلط است)؛ ۴۹۵ تست پاس. ⇒ `U93` هم کشف شد |
| U10 | Redis ‏7.4.11 ← 8.10.2 | done | cms-core/backend/docker-compose.yml | ⚠️ یک major. روی کش/صف و cacheHandler اثر دارد |
| U11 | TypeScript ‏5.7 ← 7.0.2 | done | cms-core/frontend/package.json | ✅ **تمام در `1896188`.** `5.9.3` → `7.0.2`. اندازه‌گیری قبل از تصمیم نشان داد کد پروژه برای TS7 آماده است: `tsc --noEmit` و `next build` صفر خطا. تنها مانع `openapi-typescript` بود که از `ts.factory` استفاده می‌کند و در بازنویسی Go نیست. راه‌حل: `scripts/gen-types.mjs` ابزار را با TS5 پین‌شده در پوشهٔ موقت اجرا می‌کند و پاکش می‌کند، پس TS5 وارد `node_modules` و lock نمی‌شود. `overrides` هم ناسازگاری peer را صریح کرد تا `npm ls` خطا ندهد (برای CI). دام ویندوز: `spawnSync npm` بدون shell با `ENOENT` می‌مرد چون `npm` یک `npm.cmd` است. تأیید: `gen:types` idempotent و خروجی‌اش همان نسخهٔ commit‌شده |
| U99 | بازرسی و رفع ایرادات پس از بروزرسانی | done | سراسر ریپو | ✅ تمام. چهار ایراد واقعی پیدا و رفع شد: **`U90`** درخواست API بدون احراز هویت به‌جای ۴۰۱ یک hang می‌داد (پیش‌فرض لاراول `route('login')` را صدا می‌زند و این بک‌اند چنین route ندارد)؛ **`U92`** `REVALIDATE_SECRET` در فرانت نبود ⇒ باطل‌سازی کش مرده و محتوای کهنه؛ **`U93`** مهاجرت K7.5 روی دیتابیس dev هرگز اجرا نشده بود ⇒ مدل‌ها جدول اشتباه می‌دیدند؛ **`U94`** `openapi.yaml` تورفتگی غلط داشت ⇒ `gen:types` از کار افتاده و type کهنه بود. **تأیید نهایی روی کل استک جدید:** ۴۹۵ تست پاس، `tsc` پاک، build از صفر ۳۸ صفحه، ورود `۴۰۱`/`۴۲۲` با پیام فارسی، rate limit در تلاش چهارم `۴۲۹`، محافظت `/admin` و `/central` با `۳۰۷` به `/login` در dev و production، مرتب‌سازی فارسی یکسان بین PG16 و PG18. **آنچه هنوز انجام نشده:** تست چشمی در مرورگر واقعی — همهٔ اینها سطح HTTP است نه رابط کاربری |


## گروه U90 — ایرادهای کشف‌شده حین کار بروزرسانی

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| U90 | درخواست API بدون احراز هویت 504 می‌دهد به‌جای 401 | done | بک‌اند، مسیر auth | ✅ تمام در `984f201`. ریشه: پیش‌فرض لاراول `redirectGuestsTo(fn () => route(\"login\"))` است ولی این بک‌اند route ورود ندارد (ورود در Next.js است) ⇒ آن closure داخل خودِ middleware می‌مرد، برای همین اصلاح در `withExceptions` بی‌اثر بود و باید در `withMiddleware` بود. **نکتهٔ عملی:** فرانت چون همیشه `Accept: application/json` می‌فرستد ۴۰۱ درست می‌گرفت، پس باگ ۳ روز پنهان مانده بود؛ فقط curl/wget/navbar مرورگر آن را می‌دیدند. ۶ تست جدید + mutation شد تا ثابت شود تست واقعاً باگ را می‌گیرد 
oute('login') صدا می‌زند ⇒ RouteNotFoundException ⇒ nginx 504. تست‌ها این مسیر را نمی‌زنند چون اول authenticate می‌کنند، برای همین سبز می‌مانند. برای API باید 401 JSON برگردد نه redirect. ریشه را پیدا کن و رفع کن |
| U91 | mirror سراسری composer با canonical:true گیر می‌اندازد | done | تنظیمات سراسری داخل کانتینر | 🟡 **راه‌حل موقت اعمال شد، راه‌حل دائمی نه.** `canonical:false` روی mirror سراسری داخل کانتینر گذاشته شد و `composer update` بعد از آن کار کرد. ولی این فایل `/root/.composer/config.json` است و **با هر rebuild از بین می‌رود** ⇒ دفعهٔ بعد `Problem 1` برمی‌گردد. راه‌حل دائمی: بیاورد در Dockerfile، یا از راه env تزریق شود |
epositories سراسری به mirrors.aliyun.com/composer/ بود با اولویت بالا ⇒ وقتی متادیتایش کهنه می‌شد composer به packagist fallback نمی‌کرد و فقط نسخه‌های dev می‌داد ⇒ composer update شکست می‌خورد و نسخهٔ واقعی Laravel اصلاً دیده نمی‌شد. برای این نشست canonical:false شد، ولی **داخل کانتینر است و با هر rebuild از بین می‌رود**. راه‌حل دائمی: بگذار در Dockerfile بیاید یا از راه env تزریق شود، نه با دستی |

| U92 | REVALIDATE_SECRET در فرانت تنظیم نشده بود ⇒ باطل‌سازی کش مرده | done | .env.local فرانت | ✅ **در این نشست به‌صورت محلی رفع شد و تأیید شد** (`REVALIDATE_SECRET` در `.env.local` فرانت گذاشته شد، مقدار چاپ نشد). بعد از آن endpoint باطل‌سازی کش درست کار کرد: timestamp منقضی `۴۰۰`، امضای جعلی `۴۰۳`. **ولی ریشهٔ ساختاری هنوز هست:** `.env.local` داخل gitignore است، پس هر clone تازه همین‌طور می‌شکند و هیچ هشداری هم نمی‌دهد. کار درست: یک بررسی در boot یا یک تست که بدون هر دو مقدار، مسیر باطل‌سازی را از کار بیندازد |

| U93 | مهاجرت K7.5 روی دیتابیس dev هرگز اجرا نشده بود ⇒ مدل و دیتابیس ناهماهنگ | done | `cms` DB · `2026_10_05_000001_prefix_central_tables` | 🔴 **کشف شد در U99.** مدل‌های مرکزی `$table = central_*` دارند ولی ۸ جدول بدون پیشوند هنوز وجود داشتند و migration در وضعیت Pending بود ⇒ هر چیزی که این مدل‌ها می‌خواند (core_versions، kill_switches، update_rollouts، operator_hardware_keys، setup_tokens…) جدول اشتباه یا خالی می‌دید. **چرا تست‌ها سبز بودند:** هر تست دیتابیس تازه می‌سازد و migration را خودش اجرا می‌کند، پس این ناهماهنگی هرگز دیده نمی‌شد — فقط در خود دیتابیس dev وجود داشت. با `migrate --force` اجرا و تأیید شد: ۱۹ جدول مرکزی، همه پیشونددار، صفر بدون پیشوند. ۴۹۵ تست پاس |
| U94 | `openapi.yaml` سه خط با تورفتگی غلط داشت ⇒ `gen:types` از کار افتاده بود | done | `cms-core/backend/openapi/openapi.yaml` | 🔴 **کشف شد در U11.** خط‌های `'201'` و `'200'` با ۹ فاصله بودند در حالی که ۳۰۳ خط دیگر با ۸ فاصله ⇒ YAML نامعتبر و `openapi-typescript` روی `bad indentation of a mapping entry` می‌افتاد. یعنی `npm run gen:types` **از قبل** خراب بود و `src/types/api.d.ts` کهنه مانده بود. اصلاح شد و اسکریپت برای اولین بار کار کرد. تأیید: صفر `$ref` شکسته، صفر مقدار null، و خروجی **idempotent** است (دو بار اجرا = یک نتیجه) — تنها تفاوت با نسخهٔ commit‌شده سه توضیح `data.is_single` است که در YAML بود ولی هرگز به type نرسیده بود. **اشتباه خودم:** اسکریپت اصلاح اولم با regex فقط کلید را نگه داشت و مقدار را دور انداخت؛ با `git diff` دیده شد و هر دو خط برگردانده شد |

## گروه K95 — پیگیری‌های باز مربوط به K5.4 و K5.5 (کشف‌شده حین اجرا)

| # | تسک | وضعیت | فایل/محل | یادداشت |
|---|------|--------|-----------|---------|
| K95 | DeveloperGuide.tsx به پلاگین‌نویس یک بخش کامل hooks آموزش می‌دهد | done | cms-core/frontend/src/components/DeveloperGuide.tsx:22,78-90,123 | 🔴 **بدترین مورد باقی‌مانده.** جدول نام hookها و جملهٔ «هوک‌ها اعلانی‌ند: هنگام فعال‌سازی، عین مانیفست در رجیستری ثبت می‌شود (جایگزینی کامل)» ⇒ دقیقاً قابلیت حذف‌شده را به همان مخاطبی تبلیغ می‌کند که حذفش به او آسیب می‌زند |
| K96 | PLUGIN-GUIDE.md نمودار چرخهٔ عمر هنوز ثبت هوک را وعده می‌دهد | done | docs/PLUGIN-GUIDE.md:180 | 🟠 سند رو‌به‌پلاگین‌نویس و حالا نادرست است |
| K97 | openapi.yaml در summary هنوز «هوک‌ها» را وعده می‌دهد | done | openapi.yaml:1578,1591 | 🟠 فقط متن است نه شکل — Plugin در خط ۱۹۱۹ هیچ property به اسم hooks ندارد، پس فقط دو رشته اصلاح و gen:types دوباره اجرا شود (که pi.d.ts:5319,5366 را تغییر می‌دهد) |
| K98 | PluginPackageValidator هنوز کد hooks.not_yet_dispatched می‌دهد | done | PluginPackageValidator.php:317-320 + تست خط ۷۲۱ | 🟠 کامنتش می‌گوید «فاز ۱ هنوز نیامده» — یعنی همان دروگویی که حذفش کردیم، تکرارشده. تست‌اش هنوز سبز است. اگر ادغام شود، PackageAnalyzer.tsx:90 هم باید نگاشت کد را عوض کند |
| K99 | PluginRouteTable::flush() هیچ caller ندارد | done | PluginRouteTable.php · Plugin.php | ✅ **کار شد.** `PluginRouteTable::flush()` هیچ caller نداشت، پس «غیرفعال‌سازی فوری» که سند معماری وعده می‌داد عملی نبود: پلاگین غیرفعال تا ۳۰ ثانیه (TTL) هنوز route سرو می‌کرد. سه تغییر: (۱) `Plugin::booted()` کنار `ManifestRegistry::flushCache()` حالا `PluginRouteTable::flush()` را صدا می‌زند — داخل همان closure تا هر دو hook یک منطق داشته باشند؛ (۲) `flush()` از instance به **static** تغییر کرد، چون تنها کارش `Cache::forget` روی یک ثابت کلاسی است و از `booted()` — که خودش static context است — به‌صورت instance قابل فراخوانی نبود؛ (۳) تست `PluginRouteCacheInvalidationTest` با ۴ تست. **نکتهٔ تست:** `Builder::update()`/`Builder::delete()` در لاراول model event نمی‌فرستد، پس نسخهٔ اول با query-builder قرمز می‌شد؛ تست عمداً از model instance استفاده می‌کند و این را کامنت کرده. علاوه بر آن ثابت شد جدول خالی تست را vacuous نکند: هندلر باید در namespace `Pishdad\Plugins\` باشد (همان بازتاب `PluginAutoloader`) وگرنه `compileRoutes()` آن را رد می‌کند. **Mutation:** با بی‌اثر کردن `flush()` هر ۴ تست قرمز شد ⇒ تست‌ها واقعاً این رفتار را می‌گیرند. کل تست: ۵۳۱ تست / ۲۸۳۰ assertion. Pint: `line_ending` و `phpdoc_align` قدیمیِ `Plugin.php` هم رفع شد |
| K100 | ثبت route در routes/api.php انجام نشد (مالکیت ایجنت نبود) | ⏳ | cms-core/backend/routes/api.php | 🟡 یک خط لازم است. snippet داخل docblock کلاس PluginRouter آمده و تست setUp() همان را ثبت می‌کند، پس کار می‌کند — ولی در فایل واقعی ثبت نشده |
| K101 | domain.ts فیلد مردهٔ hooks_count را نگه داشته | done | cms-core/frontend/src/lib/domain.ts:440 | ⚪ اختیاری است پس هنوز کامپایل می‌شود، ولی دیگر هیچ‌وقت پر نمی‌شود |
| K102 | .gitattributes برای *.php text eol=lf ندارد | done | .gitattributes | ✅ **کار شد.** `*.php text eol=lf` به `cms-core/backend/.gitattributes` اضافه شد. **اول سنجیدم که مشکل واقعاً کجاست:** index از قبل پاک بود (۰ فایل CRLF، ۲۳۹ فایل LF) — یعنی تاریخچه خراب نبود و مسئله فقط **working tree** بود. دلیلش `core.autocrlf=true` روی ویندوز است که LF را در checkout به CRLF برمی‌گرداند. **چرا برای PHP مهم است و برای shell نه:** ظرف کانتینر نمی‌شکند؛ `line_ending` فیکسرِ Pint است که LF را الزام می‌کند، پس روی ویندوز هر بار اجرای `pint` همهٔ ۲۳۹ فایل PHP را بازنویسی می‌کرد — یک diff کل‌ریپو که ربطی به تغییر تحت بازبینی ندارد. همان چیزی که در K99 روی `Plugin.php` دیده شد. قاعده `eol=lf` برای این مسیرها `core.autocrlf` را بازنویسی می‌کند و working tree را با index هم‌تراز نگه می‌دارد. **اثبات:** `git check-attr` حالا `text: set` و `eol: lf` را برای فایل‌های PHP گزارش می‌کند، و هشدار git برعکس شد — از «LF will be replaced by CRLF» به «CRLF will be replaced by LF». بررسی شد که هر ۲۳۹ فایل PHP در همین `cms-core/backend` هستند پس `.gitattributes` محلی کافی است و نیازی به قاعدهٔ جهانی نیست. `git add --renormalize` هم زده شد و چون index از قبل LF بود هیچ تغییری در فایل‌های tracked ایجاد نکرد |

