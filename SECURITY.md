# Security Policy

Pishdad verifies every plugin before it runs, and this document explains how to report a
problem in Pishdad itself.

> 🇮🇷 [نسخهٔ فارسی این سند](#امنیت-نسخهٔ-فارسی)

---

## Supported versions

| Version | Supported |
|---|---|
| `main` | ✅ |

Security fixes land on `main`. There are no long-term support branches at this point.

---

## Reporting a vulnerability

**Do not open a public issue.** A public issue tells everyone about the flaw before you
have had a chance to verify it, and it stays indexed forever.

Email **oskouei.arman@gmail.com** with the subject line `security:`.

Please include:

- what the vulnerability lets an attacker do
- the file, route, or plugin you believe is responsible
- the steps to reproduce it
- the version or commit you tested
- whether it needs an authenticated account, and of which role

You will get an acknowledgement. After a fix is ready you decide whether and when it
becomes public, and the advisory credits you unless you would rather stay unnamed.

---

## What counts as a vulnerability

| In scope | Out of scope |
|---|---|
| Remote code execution without an attacker being a signed plugin author | Running PHP from a plugin you installed yourself, by design |
| Authentication or authorisation bypass | Missing hardening in your own nginx or PHP configuration |
| SQL injection, SSRF, path traversal, unsafe deserialisation | Denial of service from a large enough dataset |
| Signing bypass: accepting a plugin whose Ed25519 signature does not verify | A publisher signing their own plugin with their own key |
| Trust store leaking, rotating, or reusing another plugin's seal key | A trusted publisher shipping a vulnerable plugin |
| `findSecretKeys()` missing a credential class | Denial of service by uploading an oversized plugin on purpose |
| Privilege escalation through a slug-bound extension point | Wording and translation issues |
| Session, 2FA, or revalidation secret disclosure | Performance complaints |

The two rows worth reading twice are the signing ones. If you can get unsigned or
mismatched code to run, that is a vulnerability in Pishdad, not in the plugin.

---

## Reporting a malicious plugin

A plugin package that carries an embedded credential, claims another plugin's extension
points, or ships a signature that does not verify is rejected at install and logged as
`plugin.package_rejected`.

Send those to the same address, and include the package if you can. If the package came
from the official library, mention that separately, because it means a publisher key was
compromised rather than a package being tampered with in transit.

---

## Security properties you can check yourself

| Property | Where |
|---|---|
| Ed25519 signing and verification | `cms-core/backend/app/Services/Plugins/PluginSignatureVerifier.php` |
| Manifest canonicalisation before signing | `canonical()`, `sortRecursive()` in the same file |
| Credential detection in packages | `findSecretKeys()` in `PluginPackageValidator.php` |
| Extension-point slug binding | `slugBoundChecks()` in `PluginPackageValidator.php` |
| Per-plugin seal keys and rotation | `PluginTrustStore.php` |
| Rejection logging | `plugin.package_rejected` |
| Two-factor authentication | `pragmarx/google2fa` |
| Role permissions | `spatie/laravel-permission` |

Anyone can audit these. Please do.

---

# امنیت — نسخهٔ فارسی

پیشداد هر پلاگین را پیش از اجرا بررسی می‌کند. این سند توضیح می‌دهد چطور مشکلی در خودِ
پیشداد را گزارش کنید.

## نسخه‌های پشتیبانی‌شده

| نسخه | پشتیبانی |
|---|---|
| `main` | ✅ |

## گزارش آسیب‌پذیری

**Issue عمومی باز نکنید.** Issue عمومی یعنی همه قبل از بررسی شما از مشکل باخبر می‌شوند و
برای همیشه ایندکس می‌شود.

به **oskouei.arman@gmail.com** ایمیل بزنید و موضوع را `security:` بگذارید.

در ایمیل بنویسید:

- مهاجم با این آسیب‌پذیری چه کاری می‌تواند بکند
- کدام فایل، مسیر یا پلاگین مسئول است
- مراحل بازتولید
- روی کدام نسخه یا کامیت تست کردید
- آیا حساب کاربری احراز هویت‌شده لازم است و با چه نقشی

پس از بررسی، دربارهٔ عمومی شدن تصمیم می‌گیرید و در گزارش به نام شما ذکر می‌شود، مگر
خودتان نخواهید.

## چه چیزی آسیب‌پذیری محسوب می‌شود

| در محدوده | خارج از محدوده |
|---|---|
| اجرای کد از راه دور بدون اینکه مهاجم نویسندهٔ پلاگین امضاشده باشد | اجرای PHP از پلاگینی که خودتان نصب کرده‌اید، طبق طراحی |
| دور زدن احراز هویت یا دسترسی | نبود سخت‌سازی در پیکربندی nginx یا PHP خودتان |
| تزریق SQL، SSRF، path traversal، deserialisation ناامن | انکار سرویس با حجم دادهٔ زیاد |
| دور زدن امضا: پذیرش پلاگینی که امضای Ed25519 آن تأیید نمی‌شود | ناشری که پلاگین خودش را با کلید خودش امضا می‌کند |
| نشت، چرخش اشتباه یا استفادهٔ مجدد seal key پلاگین دیگر | ناشر مورد اعتماد که پلاگین آسیب‌پذیر منتشر می‌کند |
| رد کردن یک دستهٔ کلید توسط `findSecretKeys()` | مشکلات نگارشی و ترجمه |
| ارتقای سطح دسترسی از طریق نقطهٔ افزونهٔ با slug دیگر | شکایت از سرعت |

دو سطر این جدول را دو بار بخوانید: سطرهای مربوط به امضا. اگر بتوانید کد امضانشده یا
نامعتبر را اجرا کنید، آن آسیب‌پذیریِ خود پیشداد است، نه پلاگین.

## گزارش پلاگین مخرب

بسته‌ای که کلیدی در خود دارد، نقطهٔ افزونهٔ دیگری را ادعا می‌کند، یا امضای نامعتبر
دارد، هنگام نصب رد می‌شود و با شناسهٔ `plugin.package_rejected` ثبت می‌گردد.

همان را به همان نشانی بفرستید و اگر می‌توانید خودِ بسته را هم پیوست کنید. اگر بسته از
کتابخانهٔ رسمی آمده، این را جداگانه بنویسید، چون آن‌وقت یعنی کلید ناشر لو رفته نه اینکه
بسته در مسیر دستکاری شده باشد.

## ویژگی‌هایی که خودتان می‌توانید بررسی کنید

| ویژگی | کجا |
|---|---|
| امضا و تأیید Ed25519 | `cms-core/backend/app/Services/Plugins/PluginSignatureVerifier.php` |
| کانونی‌سازی مانیفست پیش از امضا | `canonical()` و `sortRecursive()` در همان فایل |
| کشف کلید در بسته | `findSecretKeys()` در `PluginPackageValidator.php` |
| اتصال نقطهٔ افزونه به slug | `slugBoundChecks()` در `PluginPackageValidator.php` |
| seal key اختصاصی و چرخش آن | `PluginTrustStore.php` |
| ثبت رد کردن بسته | `plugin.package_rejected` |
| ورود دومرحله‌ای | `pragmarx/google2fa` |
| پرمیشن نقش‌ها | `spatie/laravel-permission` |

این‌ها را هر کسی می‌تواند حسابرسی کند. لطفاً همین کار را بکنید.
