# فرانت‌اند CMS — پنل مشتری + سایت عمومی ISR

Next.js 15 + App Router + TypeScript strict. قرارداد مرجع: `../backend/openapi/openapi.yaml` نسخه 1.6.0.

## گیت‌ها (gate ها)

```bash
npx tsc --noEmit                # typecheck
npm test                        # تست‌های واحد (node:test، بدون jest)
npm run lint                    # ← tsc --noEmit  (توضیح پایین)
npm run gen:plugin-contract:check   # drift قراردادِ بستهٔ پلاگین
```

### چرا `npm run lint` همان `tsc --noEmit` است

`next lint` در **Next 16 حذف شده** — اسکریپت قبلی با `Invalid project directory …/lint` می‌افتاد و
هیچ‌چیز را نمی‌سنجید. جایگزین‌های واقعی (`eslint`، `oxlint`، `biome`) در این پروژه **وابستگی
نیستند** و عمداً اضافه نشده‌اند تا دامنهٔ پروژه بزرگ نشود. پس `lint` به سنجش سطحِ تایپ می‌افتد،
که در این کدبیس ارزشِ واقعی دارد: `strict` روشن است و کامپوننت‌ها تایپِ داده را دستی می‌نویسند.

برای همین `lint` **همان** فرمانِ گیت است، نه یک چیزِ جدا: اگر روزی لینترِ واقعی اضافه شد،
همین اسکریپت را گسترش بده، نه اینکه یک اسکریپتِ موازی بساز.

درِ دوم، `gen:plugin-contract:check`، driftِ فایلِ تولیدشدهٔ قرارداد را می‌سنجد و در CI اجرا
می‌شود — همان ایدهٔ `docs/tools/plugin-contract-guard.php` در سمت بک‌اند.

## اجرا

```bash
cd pishdad-core/frontend
npm install
npm run gen:types   # تولید تایپ از قرارداد (openapi-typescript)
npm run dev         # http://localhost:3000
```

داکر (dev با volume mount):

```bash
docker compose up   # web-front روی 3100 (پورت 3000/3001 روی هاست اشغال است)
```

بک‌اند باید بالا باشد: `cd ../backend && docker compose up` → API روی `http://localhost:8080/api`.

## env ها

| متغیر | مقدار dev | کاربرد |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | `http://localhost:8080/api` | baseURL کلاینت (مرورگر → بک‌اند) |
| `INTERNAL_API_URL` | `http://host.docker.internal:8080/api` | baseURL سرور (Route Handler → بک‌اند؛ داخل کانتینر `localhost` یعنی خود کانتینر) |

## تصمیم کوکی/توکن

بک‌اند (`AuthController@tokenResponse`) هم JSON `{token}` و هم کوکی httpOnly `auth_token` می‌دهد،
اما آن کوکی روی دامین بک‌اند (`:8080`) ست می‌شود و مرورگر آن را برای فرانت (`:3000`) پس نمی‌فرستد.
پس فرانت خودش کوکی می‌سازد:

1. `POST /api/auth/login` و `/api/auth/verify-2fa` توکن را از بک‌اند می‌گیرند و با همان نام `auth_token`
   به‌صورت httpOnly روی دامین فرانت ست می‌کنند.
2. JS هرگز توکن را نمی‌بیند (فقط `useAuth` وضعیت لاگین/چالش را نگه می‌دارد).
3. فراخوانی‌های احرازهویت‌شده از کلاینت به `/api/proxy/...` می‌روند؛ Route Handler توکن را از کوکی
   می‌خواند و با `Authorization: Bearer` به بک‌اند پروکسی می‌کند.
4. `middleware` روت‌های `/admin/*` را بدون کوکی به `/login` می‌فرستد.
5. `refresh`: بک‌اند دارد (`POST /v1/auth/refresh`)؛ فرانت F1 روی 401 به لاگین می‌فرستد (TODO: refresh خودکار).

فلو لاگین دقیقِ بک‌اند: `login` → اگر `two_factor_required` → `challenge` (۱۰ دقیقه) → `verify-2fa`
با TOTP یا recovery code (یک‌بارمصرف) → توکن. پیام 401 یکسان ضد enumeration. بعد از ورود، تم محلی
به `PUT /v1/ui-settings` سینک و سپس تم سرور pull می‌شود.

## ساختار

```
src/app/layout.tsx            # fa/rtl + Vazirmatn + ضد FOUC + پروایدرها
src/app/globals.css           # توکن‌ها (sahar/amaliyat/presets/radius/density/font-size) + شل + کامپوننت‌ها
src/app/(auth)/login          # ورود دوعاملی (۱.۱)
src/app/(client)/admin        # شل سپیده + dashboard (۱.۲) + appearance (۱.۱۴)
src/app/api/auth|proxy        # Route Handlers کوکی httpOnly + پروکسی
src/components/ui             # DataTable/Modal/Drawer/ConfirmDialog/EmptyState/SaveBar/StatCard/Badge/Alert/Stepper/Toast/Skeleton
src/components/layout         # Sidebar/Topbar/BottomNav/Shell
src/lib                       # api.ts (fetch wrapper + 401) / auth.tsx / theme.tsx / fa.ts (شمسی) / menu.ts
src/types/api.d.ts            # تولیدشده با npm run gen:types
src/middleware.ts             # محافظ admin
```

## F3 — تکمیل فرانت (صفحات مشتری ۱.۳/۱.۴/۱.۷–۱.۱۳ + سایت عمومی)

```
src/app/(client)/admin/managers      # ۱.۳ تب مدیران/نقش‌ها + ماتریس دسترسی
src/app/(client)/admin/profile       # ۱.۴ فرم + رمز + 2FA + AppearancePanel فشرده
src/app/(client)/admin/tickets       # ۱.۷ Master-Detail چت + پاسخ
src/app/(client)/admin/settings      # ۱.۸ فرم + MediaPicker لوگو
src/app/(client)/admin/socials       # ۱.۹ لیست کلید/آدرس/فعال
src/app/(client)/admin/plugins       # ۱.۱۰ لیست + آپلود ZIP + سوییچ + Badge آپدیت
src/app/(client)/admin/header-footer # ۱.۱۱ تب + DragDropList + تنظیمات JSON ویجت
src/app/(client)/admin/blocks        # ۱.۱۲ تب نوع صفحه + درگ + _enabled + JSON خام
src/app/(client)/admin/themes        # ۱.۱۳ گالری + آپلود + فعال‌سازی (+revalidate) + پیش‌نمایش
src/app/[...path]                    # سایت عمومی ISR (revalidate=300، تگ pages) + سئو
src/app/api/revalidate               # تأیید HMAC+nonce+timestamp (منطق RevalidateSigner) + مسیر local ادمین
src/components/site                  # BlockRenderer (sanitize allowlist) + ContactForm (honeypot)
cache-handler.mjs                    # کش ISR روی Redis با fallback حافظه (پیش‌فرض خاموش)
```

## env های F3 (جدید)

| متغیر | کاربرد | عمومی؟ |
|---|---|---|
| `NEXT_PUBLIC_SITE_ID` | شناسه نصب (برای سئو و متادیتا؛ خالی = بدون شناسه) | بله |
| `NEXT_PUBLIC_MEDIA_URL` | بیس MinIO برای رندر مستقیم مدیا | بله |
| `REVALIDATE_SECRET` | سکرت مشترک HMAC با بک‌اند — **سرور-only** | **خیر** |
| `REDIS_URL` / `PISHDAD_CACHE_PREFIX` | فعال‌سازی cacheHandler روی Redis | خیر |

## cacheHandler روی Redis (چابکان)

پیش‌فرض repo بدون Redis بیلد/ران می‌شود. فعال‌سازی:

```bash
npm i redis
# .env: REDIS_URL=redis://localhost:6379 (+ PISHDAD_CACHE_PREFIX=tenantA:)
```

بعد کامنت `cacheHandler` در `next.config.ts` را باز کنید (+ `cacheMaxMemorySize: 0`).
نکته: nonceهای revalidate در حافظه process-local است — در استقرار چندنمونه‌ای،
با همان Redis (کلید `revalidate:nonce:*`) یکپارچه شود.

## TODOهای واقعاً باقی‌مانده (نیازمند بک‌اند یا تصمیم محصول)

- معادل **public** (بدون auth، کلیدخورده با domain/site_id) برای
  `layouts/header|footer`، `settings/site|socials` و قالب فعال — تا آن زمان سایت عمومی پیش‌فرض رندر می‌کند.
- اندپوینت فهرست عمومی صفحات منتشرشده برای `generateStaticParams` واقعی (فعلاً on-demand ISR).
- resolve شناسه‌های `media_id` بلوک‌ها به URL در payload عمومی `site/pages/{path}`.
- رجیستری schema ویجت‌ها برای WidgetSettingsForm واقعی (فعلاً JSON خام).
- `GET /v1/themes/panel` (از F1) و WebAuthn واقعی کلید سخت‌افزاری (TODO خود بک‌اند).
- refresh خودکار توکن روی 401 (فعلاً ریدایرکت به لاگین).

## TODO مرحله بعد (scope-creep نکردیم)

- DataTable سمت سرور + MediaPicker/SchemaForm/DragDropList/ThemeCard کامل
- refresh خودکار توکن روی 401 (فعلاً ریدایرکت به لاگین)
- `GET /v1/themes/panel` (در قرارداد DEV-TASKS ۲.۴ هست ولی بک‌اند فعلی ندارد) — فعلاً ۵ جهت هاردکد از DESIGN-DIRECTIONS
- cacheHandler روی Redis + prewarm (یافته PLAN.md) هنگام ISR سایت عمومی