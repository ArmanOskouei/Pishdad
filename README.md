<h1 align="center">The first free, open-source Persian CMS on your own server.</h1>
<p align="center"><b>نخستین سیستم مدیریت محتوای پارسی (فارسی) رایگان و متن‌باز روی سرور شخصی شما.</b></p>

<p align="center">
  <img alt="Pishdad — Laravel CMS with a Next.js frontend" src="assets/readme-banner-v2.png" width="100%">
</p>

A self-hosted Laravel CMS with a Next.js frontend, running on a server you control.
Visual block editor, a real plugin runtime, installable themes, and Persian baked in from
the first commit rather than bolted on by a translation file.

[![license](https://img.shields.io/badge/license-MIT-E7C069?style=flat-square&labelColor=141834)](LICENSE) [![tests](https://github.com/ArmanOskouei/Pishdad/actions/workflows/tests.yml/badge.svg)](https://github.com/ArmanOskouei/Pishdad/actions/workflows/tests.yml) [![PHP](https://img.shields.io/badge/PHP->=8.2-E7C069?style=flat-square&labelColor=141834)](https://php.net) [![Next.js](https://img.shields.io/badge/Next.js-15-E7C069?style=flat-square&labelColor=141834)](https://nextjs.org) [![PostgreSQL](https://img.shields.io/badge/PostgreSQL-14%2B-E7C069?style=flat-square&labelColor=141834)](https://postgresql.org) [![Redis](https://img.shields.io/badge/Redis-cache-E7C069?style=flat-square&labelColor=141834)](https://redis.io) [![cost](https://img.shields.io/badge/cost-free%20%2B%20self--hosted-E7C069?style=flat-square&labelColor=141834)](https://en.wikipedia.org/wiki/Free_and_open-source_software)

**English** · **[فارسی / Persian](README.fa.md)**

> 🇮🇷 این راهنما به زبان انگلیسی است. نسخهٔ کامل فارسی در
> [`README.fa.md`](README.fa.md) در دسترس است.

---

## Contents

- [The name](#the-name)
- [Why Pishdad](#why-pishdad)
- [Free and open source](#free-and-open-source)
- [Security](#security)
- [Architecture](#architecture)
- [Plugin system](#plugin-system)
- [Themes](#themes)
- [Block editor](#block-editor)
- [Built-in SEO](#built-in-seo)
- [Comparison](#comparison)
- [Installation](#installation)
- [Server requirements](#server-requirements)
- [Local development](#local-development)
- [Frequently asked questions](#frequently-asked-questions)
- [License](#license)
- [Contributing](#contributing)

---

## The name

**Pishdad** (پیشداد) takes its name from the **Pishdadians**, the first dynasty of the
*Shahnameh*. They are the legendary kings of the Persian world before the Sassanids, and
they open the Persian story.

It seemed right that the first Persian CMS should carry the first Persian name.

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#نام-پیشداد)

---

## Why Pishdad

Two pricing models dominate this space. Both cost you something.

A subscription platform hands you convenience and keeps your data on someone else's
servers. A self-hosted build hands you control and hands you the bill for plugins, themes
and security patches. Pishdad sits in the second camp and tries to remove the bill.

| | Pishdad | Subscription platform | Self-hosted build |
|---|:---:|:---:|:---:|
| No vendor account, no signup | ✅ | ❌ account required | ✅ |
| Monthly cost | **Free forever** | 💳 subscription | 💳 plugins + themes |
| Data stays on your server | ✅ | ❌ vendor servers | ⚠️ shared hosting |
| Plugin runtime in the core | ✅ | ⚠️ marketplace | ⚠️ often absent |
| Installable themes | ✅ | 💳 paid themes | ⚠️ manual upload |
| Visual block editor | ✅ | ✅ | ⚠️ often a paid add-on |
| SEO tooling in the box | ✅ | 💳 paid add-on | ⚠️ manual |
| Full RTL, Persian UI, Jalali dates | ✅ | ⚠️ partial | ⚠️ manual |
| Export everything, no lock-in | ✅ | ❌ | ✅ |

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#چرا-پیشداد)

---

## Free and open source

Pishdad is free. Not free-tier. The whole core is MIT-licensed, runs on a server you
control, and has no premium edition, no seat count, and no telemetry.

The plugins and themes you install are ones you picked, verified before they ran, on
infrastructure nobody else can see.

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#رایگان-و-متن‌باز)

---

## Security

Nothing runs before it is verified. That is the whole idea, and it is the reason this
section leads instead of sitting near the bottom.

A plugin is a PHP package that wants to run inside your server. Most systems install it
first and find out what it is afterwards. Pishdad checks first.

| What | Where |
|---|---|
| Every package is signed with Ed25519 | `sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached` in `PluginSignatureVerifier` |
| The manifest is canonicalised before signing, so a signed declaration cannot be swapped afterwards | `canonical()` + `sortRecursive()` |
| Embedded credentials inside a package are detected and the install is refused | `findSecretKeys()` in `PluginPackageValidator` |
| A plugin cannot declare extension points belonging to another plugin's slug, closing a privilege-escalation path most plugin systems leave open | `slugBoundChecks()` |
| Per-plugin seal keys, discardable on removal | `ensureSealKey()` / `forgetSealKeyFor()` in `PluginTrustStore` |
| Rejections are logged with a reason and a key id | `plugin.package_rejected` |
| Credential scanning on the repository itself | GitHub secret scanning and push protection enabled |
| Two-factor auth and namespaced roles | `google2fa` + `spatie/laravel-permission` |

Plugin permissions are namespaced as `plugin:{slug}:{module}.{action}`, so a plugin module
can never collide with a core module or with another plugin's.

None of this is a track-record claim. What is worth claiming is the mechanism: plugin
code is untrusted input that has to earn execution. `app/Services/Plugins/` has the code.

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#امنیت)

---

## Architecture

```
                      ┌───────────────────────────────────┐
    Browser  ────────▶ │  Next.js 15  (React 19, App Router) │
                      │  RTL · Vazirmatn · cacheHandler    │
                      └────────────────┬──────────────────┘
                                       │ typed client, generated
                                       │ from OpenAPI 3.1
                                       ▼
                      ┌───────────────────────────────────┐
                      │  Laravel 11   (API-only)           │
                      │  Sanctum · RBAC · 2FA              │
                      │  Plugin runtime (Ed25519)          │
                      └──┬───────────┬───────────┬─────────┘
                         ▼           ▼           ▼
                   PostgreSQL     Redis      MinIO (S3)
```

| Layer | Technology |
|---|---|
| Backend | Laravel `^11.31` · PHP `≥8.2` · Sanctum · `spatie/laravel-permission` · `pragmarx/google2fa` · `morilog/jalali` |
| Frontend | Next.js `^15.5` · React `19` · TypeScript `5.7` · `@dnd-kit` · `jodit-react` · `jalaali-js` |
| Data | PostgreSQL `≥14` (ICU `fa-IR` collation) · Redis `≥6` · MinIO (S3-compatible) |
| Runtime | Nginx · PHP-FPM · Composer 2 · Node.js `≥20` |
| API | OpenAPI 3.1, **135 endpoints**, client types generated into the frontend |
| Tests | PHPUnit — **489 tests across 67 files** (63 feature, 3 unit) |
| Assets | Fully self-hosted. Fonts and icons served locally, no external CDN |

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#معماری)

---

## Plugin system

Plugins are declarative. You ship a `manifest.json`, and the core works out the routes,
permissions, widgets, page types and settings UI on its own. You write the feature, not
the registration code.

```jsonc
{
  "permissions": [
    { "module": "blog", "title_fa": "بلاگ", "actions": ["view", "edit", "delete"] }
  ],
  "widgets": {
    "header": {
      "promo": {
        "title": "بنر تخفیف",
        "description": "نوار تخفیف بالای هدر.",
        "schema": { "type": "object", "properties": { "text": { "type": "string" } } },
        "ui": { "labels": { "text": "متن بنر" } }
      }
    }
  },
  "page_types": { "faq": { "title": "سوالات پرتکرار" } }
}
```

| Hook | What you get |
|---|---|
| `permissions` | A new access module in the role matrix, namespaced as `plugin:{slug}:{module}.{action}` |
| `widgets` | A new header or footer widget, with its settings form generated from the JSON Schema |
| `page_types` | A new page type and block tab in the editor, with default blocks |

All three hooks are optional, so a plugin that only needs one does not have to declare the
others. Turning a plugin off removes it from the role matrix, the widget palette and the
block tabs, and leaves its stored data alone.

Full contract: [`docs/PLUGIN-MANIFEST-CONTRACT.md`](docs/PLUGIN-MANIFEST-CONTRACT.md)
· Full guide: [`docs/PLUGIN-GUIDE.md`](docs/PLUGIN-GUIDE.md)

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#سیستم-پلاگین)

---

## Themes

Themes are packages with the same install path as plugins. A theme carries its own
layout, its header and footer widgets, and its default blocks, and it installs from the
panel rather than by copying files.

Five visual directions ship in the core, and any of them can be switched at runtime with
a live preview before you commit. No rebuild, no redeploy.

![Theme switcher mockup](./assets/mockup-themes.png)
*Mockup for illustration — replace with a real screenshot or GIF of live theme switching.*

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#قالب‌ها)

---

## Block editor

Pages are composed from typed blocks rather than templates.

![Block editor mockup](./assets/mockup-editor.png)
*Mockup for illustration — replace with a real screenshot or GIF of the editor.*

- Drag and drop reordering via `@dnd-kit`
- Rich text through Jodit, with a JSON fallback so an unknown block type degrades instead
  of disappearing
- Draft, then revision, then publish, with a single `published_revision_id` pointer
- Media picker backed by S3 or MinIO
- Header, footer and columns configurable without touching code

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#ویرایشگر-بلوکی)

---

## Built-in SEO

Search tooling is part of the core, not a plugin to buy.

- `sitemap.xml` and `robots.txt` served from the app router
- `llms.txt`, a machine-readable description of the site for AI crawlers
- An on-site search endpoint
- An OpenAPI 3.1 contract, typed end to end
- No third-party script origins, so no third-party script penalty

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#سئوی-داخلی)

---

## Comparison

Pishdad is scored against WordPress and Django + Wagtail across 16 criteria, with the
rows where Pishdad scores low kept in rather than dropped.

Read it before you decide anything: **[COMPARISON.md](COMPARISON.md)** ·
**[مقایسهٔ فارسی](COMPARISON.fa.md)**

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#مقایسه)

---

## Installation

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git
cd Pishdad/cms-core

# --- Backend ---
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate

# --- Frontend ---
cd ../frontend
npm install
npm run build
```

Point Nginx at the backend `public/` and the frontend `.next/`, and the site is running.

> 🇮🇷 [راهنمای نصب به زبان فارسی](README.fa.md#نصب)

---

## Server requirements

| | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 LTS | Ubuntu 24.04 LTS |
| CPU | 2 vCPU | 4 vCPU |
| RAM | 2 GB | 4 GB |
| Disk | 20 GB SSD | 40 GB SSD |
| Domain | 1 domain with DNS pointed at the server | plus 1 subdomain |

<details>
<summary>What the installer provisions automatically</summary>

| Service | Version |
|---|---|
| PHP | 8.3 (FPM) |
| PHP extensions | `pdo_pgsql` · `redis` · `sodium` · `intl` · `bcmath` · `mbstring` · `zip` · `gd` · `fileinfo` · `curl` · `openssl` |
| Nginx | 1.27+ |
| PostgreSQL | 14+ with `fa-IR` collation |
| Redis | 6+ |
| MinIO | latest, S3-compatible object storage |
| Composer | 2.x |
| Node.js | 20 LTS, 22 recommended |
| TLS | automatic certificate and renewal |

</details>

> 🇮🇷 [الزامات سرور به زبان فارسی](README.fa.md#الزامات-سرور)

---

## Local development

```bash
# Backend — http://127.0.0.1:8000
cd cms-core/backend
cp .env.example .env && composer install && php artisan serve

# Frontend — http://127.0.0.1:3000
cd cms-core/frontend
npm install && npm run dev
```

Docker Compose brings up Nginx, PHP-FPM, PostgreSQL, Redis and MinIO:

```bash
cd cms-core/backend  && docker compose up -d
cd cms-core/frontend && docker compose up -d
```

| Frontend command | |
|---|---|
| `npm run dev` | Dev server |
| `npm run build` | Production build |
| `npm run typecheck` | Type check |
| `npm run gen:types` | Regenerate API types from the OpenAPI contract |

| Backend command | |
|---|---|
| `php artisan test` | Test suite |
| `php artisan serve` | Dev server |
| `composer run dev` | Server, queue, logs and Vite together |

> 🇮🇷 [راهنمای توسعهٔ محلی به زبان فارسی](README.fa.md#توسعه-محلی)

---

## Frequently asked questions

**Is Pishdad really free?**
The core is MIT-licensed. There is no premium edition, no seat count, and no telemetry. You
pay for a server and nothing else.

**Do I need a plugin for Persian and right-to-left?**
No. Right-to-left runs on CSS logical properties throughout, Jalali dates are in both the
backend and the frontend, and the admin interface is Persian out of the box.

**How are plugins checked before they run?**
Every package is signed with Ed25519. The trust store verifies that signature before any
plugin code loads, the validator rejects packages carrying embedded credentials, and a
plugin cannot declare extension points belonging to another plugin's slug. Rejections are
logged with a reason and a key id. See [SECURITY.md](SECURITY.md).

**Is there an API for a mobile app?**
The backend is API-only and exposes 135 endpoints described in an OpenAPI 3.1 contract,
with client types generated into the frontend. That is the same surface a mobile client
would use.

**What does it need to run?**
A server you control. Ubuntu 22.04 or 24.04, 2 vCPU, 2 GB of RAM and 20 GB of disk is
enough for a small site. The full list is under
[Server requirements](#server-requirements).

**Can I take my content and move away?**
Yes. PostgreSQL is yours and the schema is plain, so a `pg_dump` is the whole export.

> 🇮🇷 [این بخش به زبان فارسی](README.fa.md#پرسش‌های-پرتکرار)

---

## License

MIT. See [LICENSE](LICENSE).

## Credits

- **Pishdad**, named after the Pishdadians, the first dynasty of the *Shahnameh*.
- **Vazirmatn**, the Persian typeface.
- Built on Laravel, Next.js, PostgreSQL, Redis and MinIO.

## Contributing

Contributions are welcome. Open an issue before a pull request so the direction can be
discussed first.
