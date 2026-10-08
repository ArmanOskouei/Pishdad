# نصب پیشداد

سه راه برای گرفتن کد هست، و بعدش یک نصب‌کننده بقیه را انجام می‌دهد.

| راه | لازم دارید | مناسب |
|---|---|---|
| **الف. `git clone`** (پیشنهادی) | git | همه — به‌روزرسانی فقط یک دستور است |
| **ب. دانلود ZIP** | مرورگر | وقتی روی دستگاه git نیست |
| **پ. Docker Compose** | فقط Docker | بدون هیچ نصبی؛ سرویس‌ها همراهش می‌آیند |

---

## ۰. اول چه لازم دارید (فهرست صادقانه)

هیچ‌کدام از این‌ها خودکار نصب **نمی‌شوند** — نصب‌کننده فقط **بررسی‌شان**
می‌کند و اگر چیزی کم باشد با پیامِ روشن می‌ایستد. هر کدام را یک‌بار روی
دستگاه نصب کنید.

| نیاز | نسخه | توضیح |
|---|---|---|
| PHP | 8.2 به بالا، 8.3 پیشنهادی | با افزونه‌های زیر |
| افزونه‌های PHP | `pdo_pgsql` · `sodium` · `intl` · `bcmath` · `mbstring` · `zip` · `gd` · `fileinfo` · `curl` · `openssl` | نصب‌کننده دقیقاً می‌گوید کدام کم است |
| PostgreSQL | 14 به بالا | **MySQL/MariaDB کار نمی‌کند** — مهاجرت‌ها از `jsonb` استفاده می‌کنند |
| Composer | نسخه ۲ | وابستگی‌های PHP |
| Node.js | نسخه 22 LTS | فقط برای **یک‌بار بیلد** فرانت‌اند لازم است |
| دامنه (نصب سروری) | DNS به‌سمت سرور | TLS روی reverse proxy خودتان است |

**این‌ها لازم نیستند:** Redis، MinIO/S3 و Supervisor. پیش‌فرض‌ها صفِ دیتابیسی،
نشستِ فایلی و دیسکِ محلی‌اند — Redis/MinIO ارتقای اختیاری‌اند، نه پیش‌نیاز.

### نصبِ یک‌ضربِ پیش‌نیازها در هر سیستم‌عامل

**اوبونتو / دبیان 22.04 تا 24.04** (همه، بدون سؤال):

```bash
sudo apt update && sudo apt install -y \
  php8.3 php8.3-fpm php8.3-pgsql php8.3-intl php8.3-mbstring php8.3-xml \
  php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath \
  postgresql-16 git unzip curl
# کامپوزر (نصب‌کنندهٔ رسمی):
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
# Node.js نسخه 22 LTS (مخزن NodeSource):
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**ویندوز ۱۰/۱۱** (winget، بعد یک قدم دستی):

```powershell
winget install PHP.PHP.8.3 Composer.Composer OpenJS.NodeJS.LTS PostgreSQL.PostgreSQL.16 Git.Git
```

بعد فایل `php.ini` را باز کنید و این دو خط را از حالت کامنت دربیاورید —
بدون‌شان نصب‌کننده سرِ قدمِ دیتابیس می‌ایستد:

```ini
extension=pdo_pgsql
extension=intl
```

**macOS** (Homebrew):

```bash
brew install php composer node@22 postgresql@16 git
brew services start postgresql@16
```

**راهِ فقط-بررسی** (هیچ‌چیز خودکار نصب نشود — فقط گزارش):

```bash
php -v && php -m | grep -i -E 'pgsql|sodium|intl|bcmath|zip|gd|curl'
psql --version && composer --version && node --version
```

اگر همه یک نسخه چاپ کردند، آماده‌اید. نصب‌کنندهٔ وب همهٔ این‌ها را دوباره
بررسی می‌کند (`/install` ← پیش‌پرواز) و دقیق می‌گوید چه کم است.

---

## ۱. گرفتن کد

**الف. git (پیشنهادی):**

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git
cd Pishdad
```

**ب. ZIP (بدون git):**

- ZIP همیشه‌تازهٔ `main` (بدون وابستگی، حدود ۲ مگ):
  `https://github.com/ArmanOskouei/Pishdad/archive/refs/heads/main.zip`
- یا از روی کلون، ZIP نسخه‌دار با checksum:
  ```bash
  git archive --format=zip HEAD -o pishdad-v1.zip
  sha256sum pishdad-v1.zip   # این را کنار فایل منتشر کنید
  ```
  `git archive` فقط فایل‌های trackشده را می‌بندد، پس `vendor/` و
  `node_modules/` و `.next/` و `.env` هیچ‌وقت قاطی‌اش نمی‌شوند.

**پ. Docker (فقط Docker لازم است):**

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git && cd Pishdad
cp pishdad-core/backend/.env.example pishdad-core/backend/.env
cd pishdad-core/backend && docker compose up -d --build
```

این PHP-FPM و Nginx و PostgreSQL و Redis و MinIO را بالا می‌آورد. بعد بروید
سراغ قدم ۳ (نصب‌کنندهٔ وب).

---

## ۲. هر دستور چه نصب می‌کند

از ریشهٔ پروژه اجرا کنید مگر خلافش گفته شود. همه idempotent‌اند — دوبار اجرا
بی‌خطر است.

| دستور (کجا) | چه می‌کند | کی اجرا شود |
|---|---|---|
| `composer install` (‏`pishdad-core/backend`) | وابستگی‌های PHP در `vendor/` (حدود ۱۵۰ مگ، هرگز کامیت نمی‌شود) | بعد از کلون، و بعد از هر `git pull` که `composer.lock` را عوض کرده باشد |
| `cp .env.example .env` + `php artisan key:generate` (‏`backend`) | کانفیگ خصوصی + کلید برنامه | یک‌بار در هر نصب — **هرگز `.env` کسی دیگر را کپی نکنید** |
| `php artisan migrate --force` (‏`backend`) | ساخت همهٔ جدول‌ها | بعد از کلون و بعد از به‌روزرسانی‌هایی که migration دارند |
| `php artisan db:seed` (‏`backend`) | نقش‌ها، ۳ قالب درون‌ساخت، و با `PishdadSiteSeeder` کل دمو (۸ صفحهٔ منتشر، هدر/فوتر، پیش‌تنظیم سپیده، قالب فعال commerce) | یک‌بار در هر نصب؛ اجرای دوباره امن است، صفحهٔ منتشرشده بازنویسی نمی‌شود |
| `php artisan storage:link` (‏`backend`) | لینک `public/storage` تا مدیای آپلودشده دیده شود | یک‌بار در هر نصب (نصب‌کنندهٔ وب خودش می‌زند) |
| `npm ci` (‏`pishdad-core/frontend`) | وابستگی‌های دقیق Node از روی lockfile | بعد از کلون و بعد از pullهایی که `package-lock.json` را عوض کرده‌اند (`ci`، نه `install` — تکرارپذیر) |
| `npm run build` (‏`frontend`) | بیلد production (‏`.next/standalone`) | بعد از کلون و بعد از pullهایی که `frontend/src` را عوض کرده‌اند |
| `php artisan pishdad:install` (‏`backend`) | همان ۶ قدمِ نصب‌کنندهٔ وب، برای ترمینال | سرورهای بدون مرورگر |
| `php artisan pishdad:doctor --strict` (‏`backend`) | ممیزی کامل محیط، با exit≠0 روی مشکل | پیش از انتشار؛ قدم ۶ اسکریپت `deploy/deploy.sh` هم همین است |

---

## ۳. نصب‌کننده (وب یا CLI)

```bash
cd pishdad-core/backend
php artisan serve                    # http://127.0.0.1:8000
# باز کنید: http://127.0.0.1:8000/install
```

شش قدم: پیش‌پرواز (بررسی‌های §۰) ← دیتابیس ← کلید برنامه ← مهاجرت ← سوپرادمین ←
پایان. محتوای دمو خودکار seed می‌شود؛ اولین ورود شما به یک سایتِ تمام‌شده
می‌رسد، نه صفحهٔ خالی.

استقرار سرور از ریشهٔ پروژه (وقتی `.env` آماده است):

```bash
bash deploy/deploy.sh        # migrate ← seal ← outbox ← doctor --strict ← frontend build
```

این خط cron (صف‌ها، خلاصه‌ها و انتشار زمان‌بندی‌شده همه از همین تیک می‌گذرند):

```cron
* * * * * cd <root>/pishdad-core/backend && php artisan schedule:run
```

---

## ۴. به‌روزرسانی (بدون دانلود دوباره)

`git pull` فقط **فایل‌های تغییریافته** را می‌آورد — هرگز کل پروژه را نه. بعدش
فقط همان چیزی را دوباره اجرا کنید که عوض شده:

| اگر pull این را عوض کرد… | …این را اجرا کنید |
|---|---|
| `composer.lock` | `composer install` (بک‌اند) |
| `pishdad-core/frontend/src/**` یا `package-lock.json` | `npm ci && npm run build` (فرانت‌اند) |
| `pishdad-core/backend/database/migrations/**` | `php artisan migrate --force` (بک‌اند) |
| `pishdad-core/backend/.env.example` | با `.env` خودتان مقایسه کنید و کلیدهای تازه را اضافه کنید (هرگز `.env` را بازنویسی نکنید) |
| هیچ‌کدام | هیچ‌کار — تمام است |

به‌روزرسانی با ZIP: زیپِ تازه را دانلود کنید، روی درختِ قبلی باز کنید، `.env`
و `storage/` خودتان را نگه دارید، بعد همان جدول بالا.

بررسی سلامت: `php artisan pishdad:doctor --strict` (بک‌اند) باید exit 0 بدهد.

---

## ۵. هاست اشتراکی (cPanel): پیش‌نیازها و نصب

شدنی است — **اگر** هاست هر پنج مورد را بدهد. اگر یکی کم باشد، VPS لازم است؛
راهِ دور زدن نیست (دلیلش پایین است).

| # | نیاز در cPanel | کجا بررسی کنید |
|---|---|---|
| ۱ | PHP نسخه **8.2 به بالا** در «Select PHP Version» با افزونه‌های `pdo_pgsql` و `intl` و `mbstring` و `zip` و `gd` و `sodium` و `bcmath` و `curl` و `fileinfo` و `openssl` | Select PHP Version ← همهٔ تیک‌ها |
| ۲ | دیتابیس **PostgreSQL** + کاربر (نه MySQL) | بخش «PostgreSQL Databases» باید وجود داشته باشد |
| ۳ | Node.js نسخه **22** («Setup Node.js App») **یا** اجازهٔ آپلود فرانتِ ازپیش‌بیلدشده | اگر هیچ‌کدام: روی PC خودتان `npm run build` بگیرید و `.next/standalone` + `public/` را آپلود کنید |
| ۴ | Cron job (یک خط، هر دقیقه) | بخش «Cron Jobs» |
| ۵ | ریشهٔ دامنه روی `backend/public`، و اجازهٔ symlink (‏`storage:link`) — یا آپلود دستیِ محتویات `storage/app/public` در `public/storage` | Domains ← Document Root |

قدم‌ها: آپلود ZIP (یا `git clone` در Terminal اگر فعال است) ← اجرای جدول §۲
با Terminal (یا ابزار Composer هاست) ← ساخت دیتابیس PostgreSQL و نوشتنش در
`.env` با `DB_CONNECTION=pgsql` و `CACHE_STORE=database` و
`QUEUE_CONNECTION=database` و `FILESYSTEM_DISK=local` ← اشارهٔ دامنه به
`backend/public` ← باز کردن `/install` در مرورگر ← افزودن خط cron.

**چرا این‌ها شرطِ سخت‌اند، نه سلیقه:** ۹ migration از `jsonb` مخصوص PostgreSQL
استفاده می‌کنند (ایمپورت روی MySQL روی همان اولی می‌افتد)؛ جست‌وجو و سیستم
قالب هم در runtime به آن تکیه دارند. فرانت‌اند یک سرورِ Node.js است
(`standalone`)، نه HTML ایستا — بدون Node روی هاست اجرا نمی‌شود.

---

## ۶. سؤال‌های پرتکرار

**Redis/MinIO/Supervisor لازم است؟**
نه. پیش‌فرض‌ها صفِ دیتابیسی، نشستِ فایلی و دیسکِ محلی‌اند. فقط وقتی از یک
سرور بزرگ‌تر شدید اضافه‌شان کنید.

**فقط MySQL دارم. کار می‌کند؟**
نه — ببینید §۵. PostgreSQL اجباری است.

**حجم دانلود چقدر است؟**
سورس حدود ۸ مگ (ZIP حدود ۲ مگ). وابستگی‌ها (`vendor/` + `node_modules/` حدود
۵۰۰ مگ) روی دستگاه خودتان ساخته می‌شوند و هرگز به‌صورت فایل دانلود نمی‌شوند.

**از کجا بفهمم به‌روزرسانی سالم است؟**
`php artisan pishdad:doctor --strict` قبل و بعد. بکاپ: `php artisan backup:run`
(دیتابیس + آپلودها + مانیفست).
