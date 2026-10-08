# Installer (F1.1.B / F1.1.F)

نصب در ۶ گام، وب (Blade، بدون React) + خط فرمان (`php artisan pishdad:install`).

## قرارداد وضعیت (`storage/install/`)

- `journal.json` — گام‌های تمام‌شده + `db` (مشخصات اتصال) + `install_token`.
  نصب نیمه‌کاره از اولین گامِ ناتمام ادامه پیدا می‌کند (resume).
- `install.lock` — نصب تمام شده؛ تا هست، `/install*` قفل است (۴۰۴).

## چرا بیرون از middleware گروه `web`؟

`EncryptCookies` گروه `web` بدون `APP_KEY` معتبر ۵۰۰ می‌دهد. مسیرهای نصب
(`routes/install.php`، لود از `bootstrap/app.php → then:`) هیچ middleware
گروهی ندارند؛ `InstallGuard` فقط فایل می‌خواند و با `APP_KEY` خالی هم ۲۰۰
می‌دهد. POSTها با `install_token` سمت‌سرور (ژورنال) محافظت می‌شوند، نه CSRF.

## سوپرادمین و 2FA

`SuperAdminSeeder` در نصب صدا زده **نمی‌شود** (سکرت TOTP را به stdout چاپ
می‌کند). سوپرادمین اینلاین ساخته می‌شود (`google2fa_enabled=false`) و اولین
ورود، فعال‌سازی 2FA را اجبار می‌کند (`EnsureTwoFactorSetup` + پرچم
`two_factor_setup_required` در پاسخ لاگین).

## چک‌های واقعی preflight

- `sodium`: roundtrip رمزنگاری واقعی (native یا sodium_compat)، نه `extension_loaded`.
- `db_collation_privilege`: `CREATE COLLATION` واقعی با نام تصادفی + `DROP` فوری.
- مبنا: PHP 8.3 (مطابق `composer.json` → `config.platform.php`).
