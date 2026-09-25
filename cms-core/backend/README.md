# بک‌اند CMS Core — Laravel 11 API-only (داکری)

فونداسیون + هسته احراز هویت و تنظیمات ظاهر (تسک‌های ۰.۱ و ۰.۲ Backend).
قرارداد API در [`openapi/openapi.yaml`](openapi/openapi.yaml) — contract-first، همان مسیرها/متدهای DEV-TASKS.

## پیش‌نیازها

- Docker 29+ (با `docker compose` v2) — روی ویندوز: Docker Desktop در حالت Linux containers
- Node لازم نیست (فرانت جدا می‌آید)؛ Git برای کلون
- بدون PHP/Composer محلی — همه‌چیز داخل کانتینر

پورت‌های هاست: API ‏`8080`، دیتابیس ‏`5433`، ردیس ‏`6380`، مینیو ‏`9100`‏ و کنسول ‏`9101`.

## بالا آوردن با یک دستور

```powershell
cd "E:\Website Design\ArmanOskouei.ir\cms-core\backend"
copy .env.example .env
docker compose up -d --build
```

بار اول، ایمیج‌ها (PHP 8.3، Postgres 16، Redis 7، MinIO، nginx) دانلود می‌شوند؛ چند دقیقه طول می‌کشد.

## راه‌اندازی اولیه (اولین بار)

```powershell
docker compose exec app php artisan key:generate
docker compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="permission-migrations"
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="permission-config"
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

سیدر چه می‌سازد:

| چه | مقدار (توسعه محلی) |
|---|---|
| سوپرادمین مخفی (2FA اجباری، غیرقابل لیست/حذف) | `superadmin@local` + `SUPERADMIN_PASSWORD` (پیش‌فرض `ChangeMe!123` — عوضش کن)؛ TOTP secret و recovery codeها در خروجی سیدر چاپ می‌شوند |
| ادمین نمونه (بدون 2FA) | `admin@example.com` / `Admin!123` |
| ظاهر پیش‌فرض | عملیات دارک (`amaliyat`/`dark`/`sharp`/`compact`) |

## اجرای تست‌ها

```powershell
docker compose exec app php artisan test
```

تست‌ها روی دیتابیس جداگانه `cms_test` اجرا می‌شوند (خودکار ساخته می‌شود)؛ دیتای توسعه دست نمی‌خورد.

> نکته: اگر `.env` را عوض کردی، کانتینر `app` را بازسازی کن تا محیط جدید اعمال شود
> (`docker compose up -d --force-recreate app`) — متغیرهای `environment:` در لحظه ساخت کانتینر فریز می‌شوند.

## API (خلاصه — جزئیات در openapi.yaml)

- `POST /api/v1/auth/login` → توکن، یا `{two_factor_required, challenge}` اگر 2FA فعال باشد
- `POST /api/v1/auth/2fa/verify` → `{challenge, code}` → توکن
- `POST /api/v1/auth/logout` / `POST /api/v1/auth/refresh` (با Bearer)
- `POST /api/v1/auth/forgot-password` / `POST /api/v1/auth/reset-password` (پیام یکسان، ضد شمارش حساب)
- `GET/PUT /api/v1/ui-settings` (با Bearer؛ مقادیر dir/preset/mode/radius/density/font طبق DESIGN-DIRECTIONS)

امنیت ورود: پیام 401 یکسان برای همه حالت‌ها، قفل بروت‌فورس (۱۰ تلاش در ۱۵ دقیقه به‌ازای ایمیل+IP → ‏429)،
توکن Sanctum + کوکی httpOnly آماده برای Next.js، تاریخ شمسی (`created_at_jalali`) در پاسخ‌ها.

## API مرحله ۱ — حلقه پول (تسک‌های ۱.۱ تا ۱.۴)

قرارداد کامل در [`openapi/openapi.yaml`](openapi/openapi.yaml) (نسخه 1.4.0) — contract-first.

- **صفحات** (`auth`، مشترک بین مدیران): `GET/POST /api/v1/admin/pages`، `GET/PUT /api/v1/admin/pages/{id}`،
  `POST .../publish` (revision + published + لاگ revalidate امضاشده HMAC+nonce)،
  `POST .../restore/{revision}` (append-only)، `GET .../revisions`،
  `GET /api/v1/site/pages/{path}` (عمومی، فقط published + `Cache-Control: public, s-maxage=300`)،
  `GET /api/v1/admin/blocks/schema` (رجیستری `config/blocks.php`).
- **فایل**: `GET /api/v1/admin/media`، `POST .../presign` (PUT امضاشده MinIO/S3، ۱۵ دقیقه)،
  `PUT/DELETE .../{id}` (سافت‌دیلیت = سطل زباله)، `POST .../restore`، `DELETE .../permanent`.
- **اشتراک من**: `GET /api/v1/admin/subscription`، `GET .../invoices`،
  `POST .../invoices/{id}/pay` (session درگاه Stub + `pay_url` سندباکس، بدون شارژ واقعی)،
  `GET /api/v1/subscription-status?site_id=` (عمومی، سبک، polling).
- **Billing مرکزی** (فقط `role=operator` یا سوپرادمین — میدل‌ویر `central.operator`):
  CRUD `/api/central/plans`، `/api/central/invoices` + `PUT .../{id}/status`،
  `GET /api/central/payments`، `GET /api/central/subscriptions/suspended` (با `days_remaining`)،
  `POST .../{id}/suspend|resume|delete`، `GET/PUT /api/central/gateway-settings` (کلید ماسک‌شده)،
  `POST /api/webhooks/payment` (HMAC هدر `X-Signature` + idempotent با ref → paid + رفع تعلیق خودکار).
- **مشتریان مرکزی**: CRUD `/api/central/clients` (فیلتر `tier/status/health`)، `GET .../{id}`،
  `POST .../upgrade-tier` (فقط Budget→Standard→Enterprise + لاگ)،
  `POST .../support-token` (خام فقط یک‌بار؛ ذخیره هش + TTL ۱۵ دقیقه) + `POST .../support-token/consume` (یکباره)،
  `POST .../suspend|resume|delete`، `GET .../backups` + `POST .../backup|restore` (Stub).
- **Cron مرکزی** (ثبت در `routes/console.php`، روزانه ۰۸:۰۰/۰۸:۱۵/۰۸:۳۰):
  `billing:remind` (D-7..D-0، روزی یک‌بار برای هر اشتراک)، `billing:suspend` (به‌محض گذشتن لحظه سررسید)،
  `billing:purge` (≥۷ روز تعلیق → حذف منطقی). رویدادها در `central_billing_events` قابل حسابرسی‌اند.

### مالکیت جدول‌های مرکزی (همان Postgres، تفکیک منطقی)

- پیشوند `central_*` = مالک ماژول مرکزی؛ نوشتن فقط از `/api/central/*` با نقش operator.
- نصب مشتری فقط ردیف خودش را می‌خواند: تطبیق `central_clients.owner_user_id` / `site_id`؛
  هیچ کوئری سمت مشتری بدون این فیلتر به جدول‌های مرکزی نمی‌زند.
- کاربر عادی (`role=admin`) روی `/api/central/*` خطای 403 فارسی می‌گیرد؛
  سوپرادمین مخفی (`is_hidden`) در هیچ لیستی نیست ولی گیت مرکزی را پاس می‌کند.

### Stubها و فرض‌ها (۱.۱–۱.۴)

- درگاه = `ZarinpalStubDriver` (سندباکس، بدون شارژ واقعی)؛ درگاه واقعی = پیاده‌سازی `PaymentGatewayInterface` + یک خط در `AppServiceProvider`.
- ارسال واقعی ایمیل/پیامک یادآوری، پاک‌سازی فیزیکی D+7 (دیتابیس/MinIO)، و `pg_dump` واقعی بکاپ TODO مرحله ۵‌اند
  (بکاپ/ریستور فعلاً رکورد Stub با `meta.todo` مشخص).
- پیش‌نمایش draft با توکن HMAC (ADMIN-PAGES-SPEC) در اسکوپ این مرحله نبود — تصمیم باز برای فرانت.
- متغیرهای جدید فقط در `.env.example` آمده‌اند (`PAYMENT_*`)؛ مقادیر پیش‌فرض در `config/billing.php` است
  پس `.env` موجود دست نخورده و نیازی به recreate کانتینر نبود.

## دیباگ رایج ویندوز

- **Line-endings (CRLF):** فایل‌های `docker/php/entrypoint.sh` و `docker/postgres/init/*` باید LF باشند؛
  `.gitattributes` همین را اجبار می‌کند. اگر کانتینر `app` با خطای `not found` بالا نیامد:
  `docker compose exec app sed -i 's/\r$//' /usr/local/bin/entrypoint.sh` (داکرفایل خودش این را تمیز می‌کند).
- **دسترسی ولیوم‌ها:** سورس با bind-mount سوار می‌شود؛ entrypoint مالکیت `storage` و `bootstrap/cache` را به `www-data` می‌دهد.
  اگر خطای permission دیدی: `docker compose exec app chown -R www-data:www-data storage bootstrap/cache`.
- **پورت اشغال:** اگر `5432` محلی اشغال است مشکلی نیست (هاست روی `5433` مپ شده). برای `8080`/`9000` اشغال:
  مپ پورت را در `docker-compose.yml` عوض کن.
- **دیتابیس بالا نیامد:** `docker compose logs db` — اسکریپت `01-collation.sql` اگر ICU در ایمیج نباشد فقط NOTICE می‌دهد و ادامه می‌دهد (fallback مستند داخل همان فایل).
- **تست قرمز روی DB:** مطمئن شو `cms_test` ساخته شده: `docker compose exec db psql -U cms -l`.

## تصمیمات باز / فرض‌ها

- مسیرها نسخه‌دار `/api/v1/...` هستند (۰.۲ در DEV-TASKS بدون نسخه نوشته شده؛ نسخه‌دار کردن تصمیم این مرحله است).
- Sanctum با توکن Bearer + کوکی httpOnly؛ سشن‌درایور `file` و کش روی دیتابیس است (طبق threat-model: ردیس مرز امنیتی نیست).
- `recovery_codes` و `value` از نوع `jsonb`؛ collation فارسی `fa-IR` با fallback توضیح‌دار.
