# Contributing to Pishdad

Pishdad is a Laravel API with a Next.js frontend, split across two applications in one
repository. This guide covers getting it running, the commands the project expects, and
which parts of the codebase need care.

> 🇮🇷 [نسخهٔ فارسی این راهنما](#راهنمای-مشارکت-نسخهٔ-فارسی)

---

## Before you start

| | |
|---|---|
| PHP | `>= 8.3` |
| Composer | 2.x |
| Node.js | `>= 22` |
| PostgreSQL | `>= 18`, with an `fa-IR` collation available |
| Redis | `>= 8` |

Pishdad needs a real PostgreSQL. SQLite will not do, because the project relies on a
Persian-aware collation for correct sorting, and it normalises Arabic letter forms in
application code rather than in the database. See `app/Search/PersianText.php`.

---

## Getting it running

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git
cd Pishdad/cms-core

# Backend — http://127.0.0.1:8000
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve

# Frontend — http://127.0.0.1:3000
cd ../frontend
cp .env.example .env.local
npm install
npm run gen:types
npm run dev
```

`npm run gen:types` regenerates `src/types/api.d.ts` from
`backend/openapi/openapi.yaml`. Run it after any change to the API contract.

If you would rather not install PostgreSQL and Redis locally:

```bash
cd cms-core/backend  && docker compose up -d
cd cms-core/frontend && docker compose up -d
```

Never commit a real `.env`. Both `.env` and `.env.local` are git-ignored.

---

## Layout

```
cms-core/
  backend/     Laravel 13, API-only
    app/Services/Plugins/    the plugin runtime
    openapi/openapi.yaml     the API contract, source of truth
    tests/                   489 tests across 67 files
  frontend/    Next.js 16, App Router
    src/app/(client)/admin   client panel
    src/app/(central)/central central panel
    src/app/[...path]        public site
    src/types/api.d.ts       generated, do not hand-edit
```

`src/types/api.d.ts` is generated. Edit the YAML, run `gen:types`, commit both.

---

## Commands

Run these before you open a pull request.

| Backend | |
|---|---|
| `php artisan test` | the test suite |
| `vendor/bin/pint` | code style |
| `vendor/bin/pint --test` | style check, for CI |

| Frontend | |
|---|---|
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | Next.js lint |
| `npm run build` | production build |
| `npm run gen:types` | regenerate API types from OpenAPI |

A pull request that adds a feature without a test will be asked for one. The suite is
the reason this project can make security claims, so treat new code with no test as
unfinished.

---

## Writing a plugin

Plugins are declarative. A `manifest.json` is enough for the core to discover routes,
permissions, widgets and page types.

- `docs/PLUGIN-MANIFEST-CONTRACT.md` — the three hooks and their rules
- `docs/PLUGIN-GUIDE.md` — the long form, with examples
- `docs/SEARCH-PLUGIN-CONTRACT.md` — registering a search provider

Extension point names are slug-bound. A plugin may only declare points under its own
slug, and `PluginPackageValidator::slugBoundChecks()` enforces it. If a plugin seems to
need another plugin's point, the design is wrong, not the check.

---

## Changing the API

`backend/openapi/openapi.yaml` is the contract. The frontend's API client is generated
from it, so the order matters:

1. Edit the YAML
2. `cd frontend && npm run gen:types`
3. Run `php artisan test` — the suite checks the contract

---

## Code style

- **PHP**: Laravel Pint. Do not hand-format; run `vendor/bin/pint`.
- **TypeScript**: match the file you are in. No semicolons if the file has none.
- **Comments**: explain why, not what. The security code in particular should carry a
  short note on the threat it addresses, because a future reader needs to know what
  breaks if it is simplified.
- **RTL**: use CSS logical properties. Never `left` or `right`. If you need a physical
  side, that is a bug.
- **Dates**: Jalali in the interface, Gregorian in storage.
- **External assets**: none. Fonts and icons are local. A CDN reference will be rejected.

---

## Commit messages

Short summary in the imperative, then a body that explains why when the reason is not
obvious.

```
Add slug-bound check for widget extension points

A plugin could declare a header widget under another plugin's slug, which
let it write into that plugin's namespace. slugBoundChecks() now rejects
it at install.
```

Reference the task id from `docs/TASKS.md` when the change belongs to one.

---

## Pull requests

1. Open an issue first for anything beyond a fix, so the direction is agreed before you
   write the code.
2. Branch from `main`.
3. Keep the change focused. Unrelated cleanups in a feature branch make review harder.
4. Fill in what the change does, what it changes about behaviour, and how you tested it.
5. Screenshots for anything visual.

---

## Translations

The interface ships in Persian and is being extended to English. If you touch a
user-facing string, leave it in a translation file rather than inline in a component.

---

# راهنمای مشارکت — نسخهٔ فارسی

پیشداد یک API لاراول با فرانت‌اند Next.js است که هر دو در یک مخزن نگه داشته می‌شوند. این
راهنما دربارهٔ راه‌اندازی، دستورهای مورد انتظار پروژه، و بخش‌هایی از کد که نیاز به دقت
دارند است.

## پیش‌نیازها

| | |
|---|---|
| PHP | `>= 8.3` |
| Composer | 2.x |
| Node.js | `>= 22` |
| PostgreSQL | `>= 18` با collation `fa-IR` |
| Redis | `>= 8` |

پیشداد به PostgreSQL واقعی نیاز دارد. SQLite جواب نمی‌دهد، چون پروژه برای مرتب‌سازی
درست به collation آگاه به فارسی تکیه می‌کند و نرمال‌سازی شکل حروف عربی را در کد انجام
می‌دهد، نه در پایگاه‌داده. `app/Search/PersianText.php` را ببینید.

## راه‌اندازی

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git
cd Pishdad/cms-core

# بک‌اند — http://127.0.0.1:8000
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve

# فرانت‌اند — http://127.0.0.1:3000
cd ../frontend
cp .env.example .env.local
npm install
npm run gen:types
npm run dev
```

`npm run gen:types` فایل `src/types/api.d.ts` را از روی `backend/openapi/openapi.yaml`
دوباره می‌سازد. بعد از هر تغییر در قرارداد API آن را اجرا کنید.

اگر ترجیح می‌دهید PostgreSQL و Redis را نصب نکنید:

```bash
cd cms-core/backend  && docker compose up -d
cd cms-core/frontend && docker compose up -d
```

`env` واقعی را هیچ‌وقت commit نکنید. هر دو فایل `.env` و `.env.local` در `.gitignore`
هستند.

## ساختار

```
cms-core/
  backend/     لاراول ۱۳، فقط API
    app/Services/Plugins/    اجراکنندهٔ پلاگین
    openapi/openapi.yaml     قرارداد API، منبع حقیقت
    tests/                   ۴۸۹ تست در ۶۷ فایل
  frontend/    Next.js 16، App Router
    src/app/(client)/admin   پنل مشتری
    src/app/(central)/central پنل مرکزی
    src/app/[...path]        سایت عمومی
    src/types/api.d.ts       تولیدشده، دستی ویرایش نکنید
```

## دستورها

| بک‌اند | |
|---|---|
| `php artisan test` | اجرای تست‌ها |
| `vendor/bin/pint` | قالب‌بندی کد |
| `vendor/bin/pint --test` | بررسی قالب‌بندی، برای CI |

| فرانت‌اند | |
|---|---|
| `npm run typecheck` | `tsc --noEmit` |
| `npm run lint` | لینت Next.js |
| `npm run build` | بیلد production |
| `npm run gen:types` | تولید تایپ‌های API از OpenAPI |

پیش از باز کردن Pull Request این‌ها را اجرا کنید. PR بدون تست پذیرفته نمی‌شود؛ مجموعهٔ
تست همان چیزی است که به این پروژه اجازه می‌دهد دربارهٔ امنیت ادعا کند.

## نوشتن پلاگین

پلاگین‌ها اعلانی هستند. یک `manifest.json` برای کشف مسیرها، پرمیشن‌ها، ویجت‌ها و انواع
صفحه توسط هسته کافی است.

- `docs/PLUGIN-MANIFEST-CONTRACT.md` — سه هوک و قواعدشان
- `docs/PLUGIN-GUIDE.md` — راهنمای کامل با مثال
- `docs/SEARCH-PLUGIN-CONTRACT.md` — ثبت ارائه‌دهندهٔ جستجو

نام نقاط اتصال به slug قفل است. پلاگین فقط می‌تواند نقاط زیر slug خودش را اعلام کند و
`slugBoundChecks()` این را اجرا می‌کند.

## تغییر API

`backend/openapi/openapi.yaml` قرارداد است و کلاینت فرانت از آن تولید می‌شود، پس ترتیب
مهم است:

1. YAML را ویرایش کنید
2. `cd frontend && npm run gen:types`
3. `php artisan test` را اجرا کنید

## سبک کد

- **PHP**: با Laravel Pint. دستی قالب‌بندی نکنید.
- **TypeScript**: با فایلی که در آن هستید هماهنگ باشید.
- **کامنت**: «چرا» را توضیح بدهید، نه «چه». کد امنیتی به‌خصوص باید یادداشت کوتاهی دربارهٔ تهدیدی که پاسخ می‌دهد داشته باشد.
- **RTL**: از CSS logical properties استفاده کنید. هرگز `left` یا `right` نه.
- **تاریخ**: جلالی در رابط کاربری، میلادی در ذخیره‌سازی.
- **دارایی خارجی**: هیچ. فونت و آیکون محلی است.

## پیام کامیت

خلاصهٔ کوتاه به‌صورت امری، بعد توضیح «چرا» وقتی خودش واضح نیست.

```
Add slug-bound check for widget extension points

A plugin could declare a header widget under another plugin's slug, which
let it write into that plugin's namespace. slugBoundChecks() now rejects
it at install.
```

اگر تغییر به تسکی در `docs/TASKS.md` مربوط است، شماره‌اش را بیاورید.

## Pull Request

1. برای هر چیزی فراتر از یک اصلاح، اول Issue باز کنید تا مسیر قبل از نوشتن کد توافق شود.
2. از `main` شاخه بسازید.
3. تغییر را متمرکز نگه دارید.
4. بنویسید چه می‌کند، چه چیزی در رفتار عوض می‌شود، و چطور تستش کردید.
5. برای هر چیز بصری، اسکرین‌شات.

## ترجمه‌ها

رابط کاربری با فارسی تحویل می‌شود و در حال گسترش به انگلیسی است. اگر رشته‌ای که کاربر
می‌بیند را تغییر می‌دهید، آن را در فایل ترجمه بگذارید، نه داخل کامپوننت.
