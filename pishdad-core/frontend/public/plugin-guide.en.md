# The complete Pishdad plugin guide — from zero to shipping

**English** · **[فارسی / Persian](PLUGIN-GUIDE.md)**

> This is the canonical reference. Anything not marked "live" has its status spelled out in the
> status column.
> The Persian original is dated 1405/06/04; this English edition follows the **code as it is in
> this repository now**. See *Known differences* at the end.

## How to read this document

The **Status** column in every table:

| Mark | Meaning |
|---|---|
| 🟢 **live** | It actually works at runtime. You can rely on it |
| 🟡 **partial** | One side works, the other is silent |
| 🔵 **declared** | It exists only in the contract and validator. No code reads it yet |
| 🔴 **not built** | Not built at all |

> Per-point runtime status is exposed live by the core at
> `GET /api/v1/admin/plugins/contract` (source of truth: `PluginPackageContract::EXTENSION_POINTS`).

---

# Part 1 — What a plugin is and what it can do

## Operational definition

A plugin is a ZIP package with **two parts**: PHP code (backend) and structural declarations
(frontend). It can add **exactly three things** to the site:

| Dimension | Description |
|---|---|
| **1. API routes** | Its own endpoints under `/api/v1/p/{slug}/…` |
| **2. Data** | Its own tables with the `{slug}_` prefix |
| **3. UI extension points** | Menu item, widget, block, page, settings form |

**Everything else is forbidden.** Especially:

- ⛔ PHP files in core folders (nothing enters `app/`)
- ⛔ React or JavaScript components (would run in the admin browser ⇒ full panel takeover)
- ⛔ Running `build` (single-writer on `.next` ⇒ a half-finished build means a white page for the
  whole panel)
- ⛔ Accessing core tables as owned tables
- ⛔ Reading `.env` or Sanctum tokens

## Why these limits

They are not arbitrary. Each closes a specific risk:

| Prohibition | Risk it closes |
|---|---|
| A file in a core folder | Every Pishdad update would have to repeat 500 file operations; rollback has no transaction; two versions cannot coexist |
| A React component | Code runs in the admin browser and, from the same `/api/proxy` that carries the session cookie, gains full admin access |
| Build at runtime | `next build` takes minutes and `next start` serves from `.next` ⇒ a half-finished build = a white panel |
| Core tables | A malicious plugin runs `DROP TABLE users` |

## The most honest sentence in this guide

> **No in-process Laravel plugin provides a security boundary.** Running PHP can do anything.

Every control we have **reduces the attack surface, it does not remove it.** Remaining risks:

| Residual risk | Why it remains |
|---|---|
| Reading `.env` with `getenv()` | A separate process quota is not feasible |
| Unprotected network access | `PluginContext::httpClient()` + domain allowlist |
| DoS with an infinite loop | `set_time_limit` is ineffective in the same process. Only a capped subprocess — terrible performance |
| A chained attack from the five-click sequence | Closed with password re-auth |

This should also be written in the UI. Hiding it means surprising the user.

---

# Part 2 — Package structure

## The allowed package tree

```
package.zip
├── manifest.json          ← ⚠️ must be at the root. Identity + signature
├── Laravel/               ← backend code
│   ├── src/               ← PSR-4 root (namespace Pishdad\Plugins\{Studly}\)
│   │   ├── ServiceProvider.php
│   │   ├── Http/
│   │   ├── Services/
│   │   ├── Jobs/
│   │   ├── Models/
│   │   └── Policies/
│   ├── routes/api.php     ← API only (routes/web.php is forbidden)
│   ├── database/migrations/
│   └── config/plugin.php  ← default values only
└── Next.js/               ← UI-level declarations
    ├── panel/             ← data and assets
    └── blocks/            ← site block definitions
```

**Two critical points:**

**1. `manifest.json` must stay at the root.** If it is inside `Laravel/`, all uploads fail
because the core looks for it at the root. The `Laravel/` and `Next.js/` folders are only a path
contract, not the manifest's location.

**2. Anything outside this tree is rejected** (deny-by-default). This is deliberate: a positive
allowlist would need a new row for every new core folder, and dead lists accumulate.

The authoritative allowlist is `PluginPackageContract::ALLOWED_PATHS`:

```
manifest.json
Laravel/src
Laravel/routes/api.php
Laravel/database/migrations
Laravel/config/plugin.php
Next.js/panel
Next.js/blocks
```

(`Next.js/panel.json` was removed — decision C2: the manifest is the single source of truth.)

## Manifest

```json
{
  "schema_version": 1,
  "slug": "blog",
  "name": "Blog",
  "version": "1.0.0",
  "requires": { "core": ">=1.4.0 <2.0.0", "php": ">=8.2" },

  "api": {
    "prefix": "blog",
    "routes": [
      {
        "path": "items",
        "method": "get",
        "handler": "Pishdad\\Plugins\\Blog\\Http\\ItemController@index",
        "middleware": ["auth:sanctum"]
      }
    ]
  },

  "db": { "tables": [{ "name": "items", "indexes": ["created_at"] }] },

  "access": [
    { "module": "blog", "title_fa": "بلاگ", "actions": ["view", "edit", "delete"] }
  ],

  "panel": {
    "extensions": [
      { "point": "admin.menu", "key": "blog", "label": "نوشته‌ها", "href": "/admin/blog" }
    ]
  },

  "publisher": { "key_id": "a1b2c3…" },
  "signature": "base64…"
}
```

| Field | Required? | Notes |
|---|---|---|
| `slug` | ✅ | Must match `^[a-z0-9][a-z0-9._-]{1,39}$`. A Persian slug becomes an empty string — always use an ASCII slug |
| `name` | ✅ | Display name |
| `version` | — | e.g. `1.0.0` |
| `requires.core` | — | **Enforced** by `CoreRequirementChecker` after signature verification (B25 closed) |
| `api.prefix` | ✅ for API | Must be exactly equal to `slug` |
| `api.routes[]` | — | List of objects: `path`, `method`, `handler` (`Class@method`), `middleware` |
| `db.tables[]` | — | Raw unprefixed names; the core adds the `{slug}_` prefix |
| `access` / `permissions` | — | Access modules (prefer `access`; `permissions` is a legacy fallback) |
| `panel.extensions[]` | — | Extension-point declarations |
| `settings`, `tools`, `menu`, `pages`, `blocks`, `docs` | — | Live top-level channels read by `ManifestRegistry` |
| `publisher.key_id` | — | Fingerprint (hex sha256) of the publisher's public key |
| `signature` | ✅ for release | Ed25519 signature over the manifest without the `signature` field |
| `system` | ⛔ | **Forbidden.** A system plugin only comes from the allowlist |

## Three trust concepts that must not be conflated

Before the rewrite these three were mixed under one name (`signature_valid`):

| # | Concept | What it proves | What it does **not** say |
|---|---|---|---|
| 1 | **Authenticity** | The manifest came from the publisher's private key | Whether the publisher is trustworthy |
| 2 | **Integrity** | The files on disk are what was signed | That the code was safe at install time |
| 3 | **Our approval** | The platform reviewed this version (administrative) | **Nothing cryptographic** |

They are now separated: `publisher_verified` (authenticity) · `review_status` (approval) ·
`content_digest` + `digest_verified_at` (integrity).

---

# Part 3 — How upload works (step by step)

## Current flow — exactly what happens

```
User selects a ZIP file
        ↓
POST /api/v1/admin/plugins/validate   (or upload directly)
        ↓
   ┌────────────────────────────────────┐
   │ Step 1: package listing controls │  ← nothing is extracted
   │         no DB, no files written  │
   └────────────────────────────────────┘
        ↓
   error? ──yes──→ 422 + the full analysis in the body. No install.
        ↓ no
   ┌────────────────────────────────────┐
   │ Step 2: signature check from trust │
   └────────────────────────────────────┘
        ↓
   ┌────────────────────────────────────┐
   │ Step 3: requires.core check        │
   └────────────────────────────────────┘
        ↓
   ┌────────────────────────────────────┐
   │ Step 4: install release on disk    │
   │         (PluginInstaller, atomic)  │
   └────────────────────────────────────┘
        ↓
   ┌────────────────────────────────────┐
   │ Step 5: create DB record           │
   │         → review_status=pending    │
   └────────────────────────────────────┘
        ↓
   awaiting review
        ↓
   activation → declared permissions are created; routes go live
```

## Step 1 — package-listing controls (🟢 live)

All of them run **before extraction**, on the ZIP's file listing. Reason:
`ZipArchive::extractTo()` does not fully protect against `..` paths.

| # | Control | Error code | What it stops |
|---|---|---|---|
| 1 | Incoming ZIP size | `zip.too_large` | A giant package |
| 2 | File count | `zip.too_many_files` | A bomb |
| 3 | Uncompressed size | `zip.bomb_size` | A zip bomb |
| 4 | Compression ratio | `zip.bomb_ratio` | A zip bomb |
| 5 | Unsafe path | `path.unsafe` | **zip-slip**: absolute path, `..`, Windows reserved names |
| 6 | symlink / special entry | `path.symlink`, `path.special_entry` | symlink-to-`/etc/passwd`, device/FIFO |
| 7 | Duplicate path | `path.duplicate` | Overwriting a file |
| 8 | Case collision | `path.case_collision` | `A.php` vs `a.php` on a case-insensitive filesystem — a real bug for an Iranian user on Windows hosting |
| 9 | Path outside the allowlist | `path.not_allowed` | Writing into core folders |
| 10 | Misplaced manifest | `manifest.misplaced` | A signature that does nothing |
| 11 | Package size ceiling | `package.large` | opcache overflow |

### Manifest controls

| Code | What it says |
|---|---|
| `manifest.missing` | No manifest found at the root |
| `manifest.missing_field` | `slug` or `name` is missing |
| `manifest.bad_slug` | The slug does not match the pattern |
| `manifest.system_forbidden` | The `system` field is in the manifest |
| `manifest.leaks_key` | A secret key inside the package |

### Signature and extension-point controls

| Code | Meaning |
|---|---|
| `signature.invalid` | The signature is invalid (or the publisher is unknown/revoked) |
| `signature.anonymous_publisher` | ⚠️ **warning** — valid signature but no publisher declared |
| `panel.unknown_point` | Unknown extension point + the valid list is shown |
| `panel.deferred_point` | The point is not supported in v1 + the reason |
| `panel.bad_point` | The object shape is wrong |

## UX — "where should this file go?"

For every path error the system **suggests the correct location**:

```
Outside the allowed package
The file "Laravel/BlogPostController.php" is not allowed.
Given its structure, it probably belongs at
"Laravel/src/Http/Controllers/BlogPostController.php".
Current path:  Laravel/BlogPostController.php
Correct path:  Laravel/src/Http/Controllers/BlogPostController.php
The file name suggests "Controller".
[related guide]
```

The suggestion logic is a **heuristic** (based on file name and extension) and should be labeled
"suggestion" in the UI, not "requirement" — a wrong "requirement" makes the developer distrust the
whole guide.

---

# Part 4 — What runs today

## Short answer: the plugin runtime is wired

| Thing | State |
|---|---|
| `PluginInstaller` (safe extraction + install) | 🟢 live — extracts with deny-by-default controls and atomic release layout |
| `PluginAutoloader` (runtime PSR-4) | 🟢 live — registered in `AppServiceProvider`, enforces the mandatory namespace |
| `PluginRouter` catch-all | 🟢 live — `GET|POST|… /api/v1/p/{slug}/{any?}` |
| `PluginDispatcher` / `PluginRouteTable` | 🟢 live — resolves handlers from the manifest and dispatches |
| `PluginMigrationRunner` / `PluginMigrator` | 🟢 live — verifies and rewrites migrations with the `{slug}_` prefix |
| Developer mode (`DevMode`, `DevModeTaps`, `DevModeUnlock`, `DevModeAudit`) | 🟢 live services; gate enforced by the `devmode.gate` middleware |
| `requires.core` | 🟢 enforced (`CoreRequirementChecker`) |
| Integrity seal (`ensureSealKey`) | 🟢 written at install; `content_digest` column present |
| Market API (`/v1/market/*`) | 🟢 submit → review → approve/reject → yank → install |

Upload ≠ activation, still: a package can be installed and waiting for review. But installation
now places a real release on disk, so routes and migrations have something to run.

## Extension points — actual statuses

Statuses below are exactly the `status` field in
`PluginPackageContract::EXTENSION_POINTS` (served live by `GET /api/v1/admin/plugins/contract`):

| Point | Status | Details |
|---|---|---|
| `site.page_type` | 🟢 **live** | Open vocabulary; data in `page.meta` |
| `site.header_widget` | 🔵 declared | Read through the `widgets` channel; type locked, settings come from the plugin's own block type |
| `site.footer_widget` | 🔵 declared | Same as header |
| `admin.menu` | 🔵 declared | Closed shape (`key`/`label`/`href`/`icon?`/`order?`/`permission?`) |
| `admin.plugin_tools` | 🔵 declared | Header tools drawer card (no `href`; optional `notification_id`) |
| `admin.data_collection` | 🔵 declared | On paper until `DataTable` columns become declarative |
| `site.block_type` | 🔵 declared | Open vocabulary, closed grammar |
| `core.service_provider` | 🔵 declared | Override a core interface; class must live under `Pishdad\Plugins\` |
| `admin.settings_schema` | 🟠 **deprecated** | Moved to the top-level `settings` manifest key |
| `admin.dashboard_slot` | ⛔ deferred | No primitive and no consumer; a schema would be a structured lie |
| `admin.page_registry` | ⛔ deferred | Waits on `next/dynamic` behavior |
| `admin.component` | ⛔ never (v1) | React component — rejected in v1 |

> Declarations must also carry correct `since` / `schema_version`; the core checks them against
> `CORE_CONTRACT_VERSION` (`core_contract.*` error codes).

## ⛔ The "two parallel worlds" problem

Historically the validator checked `panel.extensions`, but the runtime read different top-level
keys (`manifest.widgets`, `manifest.page_types`, `manifest.pages`, `manifest.settings`,
`manifest.tools`, `manifest.menu`). This divergence — a plugin written exactly to the old
documentation validating green but doing nothing — is tracked under task `I1-b`, whose goal is to
make `panel.extensions` (or its replacement) the single shape. Until then:

- Live channels are the **top-level manifest keys**: `widgets`, `page_types`, `settings`, `tools`,
  `menu`, `pages` / `admin.pages`, `blocks`, `docs`.
- `panel.extensions` is only read for a subset of points (notably `site.page_type` and the widget
  points) and for `core.service_provider`.

## ⛔ A silent partial is the worst partial

An unknown block type in `BlockRenderer` renders `null` with only a `console.warn`. The user sees
the block in the editor, sees **nothing** on the site, and gets no message. That is worse than a
loud failure, because they think it works. Treat "declared but not consumed" as a bug, not a
feature.

---

# Part 5 — Extension points: what you can add

## Governing principle

> Every extension point has **two things** that did not exist before:
> 1. **A defined schema** — exactly which fields are allowed
> 2. **A degree of openness** — deliberately closed or deliberately open

## Three degrees of openness

| Degree | Meaning | Example |
|---|---|---|
| `closed` | Only fixed fields. Nothing to "open" | `admin.menu` — a `MenuItem` with four fixed fields |
| `schema_defined` | Type locked, content open | `site.header_widget` |
| `open_vocabulary` | **The plugin defines its own vocabulary** | `site.page_type` · `site.block_type` |

## The micro-schema grammar

| Allowed | Forbidden | Why forbidden |
|---|---|---|
| `string` `integer` `number` `boolean` | nested `object` | `SchemaForm` only iterates a flat map. A nested object has neither a form nor a renderer — the author writes it, sees one text input, and loses an afternoon |
| `enum` | `array of object` | Same |
| `media_id` `media_ids` `string_list` | `$ref` · `oneOf` · `anyOf` | Complexity without a consumer |
| `richtext` (only with the `body` key) | `format:uri` | `SchemaForm` conditions on the `body` key |
| | `patternProperties` | Unused attack surface |
| | **free URL · free markup · file path** | Attack surface |

**Openness ceiling:** unlimited block/page types (up to the 20-declaration cap) · nesting depth
**1** · keys `^[a-z][a-z0-9_]{0,39}$` · values scalar or enum only. Limits are hard errors, not
warnings (`PluginPackageContract::GRAMMAR_LIMITS`).

## ⛔ Why `free_form` does not exist

If it were on the list, one day a point would be tagged with it and the React-component
prohibition would come back in through the back door. **Its absence is a security decision, not a
design decision.**

## Per-point table

| Point | Degree | Status | You can define | Deliberately removed |
|---|---|---|---|---|
| `admin.menu` | closed | 🔵 | `key` `label` `href` `icon?` `order?` `permission?` | `endpoint` · `component` · free `icon` |
| `admin.plugin_tools` | closed | 🔵 | `key` `title_fa` `icon?` `notification_id?` `permission?` | **No `href`, no `order`, no `body`** |
| `site.page_type` | open_vocabulary | 🟢 | `slug` `title_fa` `desc_fa?` `default_blocks[]` + **arbitrary vocabulary** | `component` |
| `site.block_type` | open_vocabulary | 🔵 | `type` `title_fa` `schema!` `labels?` | `type` must not be a core type |
| `site.header_widget` | schema_defined | 🔵 | `type` `title` `description` | `area` (the point *is* the area) |
| `site.footer_widget` | schema_defined | 🔵 | same | same |
| `admin.settings_schema` | schema_defined | 🟠 deprecated | use top-level `settings` | `component` |
| `admin.data_collection` | schema_defined | 🔵 | `entity!` `title_fa!` `table!` `columns!` | — |
| `admin.page_registry` | deferred | ⛔ | — | declaring it is an error (`panel.deferred_point`) |
| `core.service_provider` | schema_defined | 🔵 | `interface!` `class!` | — |
| `admin.dashboard_slot` | deferred | ⛔ | — | **a schema without a renderer = a structured lie** |

## Per-point security rules

| Point | Rule | What it stops |
|---|---|---|
| `admin.menu` | `href` must match `^/admin(/[a-z0-9._-]+)*$` | open-redirect and XSS |
| `admin.plugin_tools` | no `href`, no `order` | phishing · core-layout conflicts |
| `site.block_type` | `type` must not collide with a core type | the registry would silently drop it |
| `admin.page_registry` | path under `/admin/`, outside the `admin.menu` prefix | path collision · unprotected route |
| **all points** | `component`/`render`/`js`/`import`/`endpoint`/`html`/`style`/`path`/`template` **globally forbidden** | bypassing level 0 |
| **all points** | depth ≤ 1 · total JSON ≤ 64KB · enum ≤ 50 members · maxLength ≤ 4000 | resource use |

## ⛔ An XSS in the core to be aware of

The CTA block in `components/site/BlockRenderer.tsx` renders `href` without sanitizing it.

```tsx
<a className={`btn ${style}`} href={str(data.href) || "#"}>…</a>
```

`safeHtml` only sanitizes rich-text content, not this attribute, so `javascript:alert(1)` in a CTA
block is live. This must be fixed before opening `site.block_type` more widely. (Tracked as B24.)

---

# Part 6 — Developer mode

## Why it exists

Two install paths:

| Path | User | Risk |
|---|---|---|
| **Market** | Normal user | Low — reviewed by the platform |
| **Local** | Developer, bespoke plugin | High — untrusted code |

The local path must be **closed** because untrusted code runs on the customer's server.

## Real status

**The server-side gate exists and works.** `devmode.gate` is active on both `plugins/upload` and
`plugins/{plugin}/upgrade` (`routes/api.php`) and only confirms the "developer access" claim when
the `X-Pishdad-Dev-Token` header is valid. The services are complete (`DevMode`, `DevModeTaps`,
`DevModeUnlock`, `DevModeAudit`) and the frontend gate components are the intended control
surface.

Practically: **a direct unsigned ZIP upload is always rejected** unless developer mode is on, and
only a **signed** package gets through.

## Approved design

```
5 consecutive clicks on the developer-mode option in settings
   ↓ (more than 2 seconds between clicks ⇒ reset)
confirmation modal with the password  ← not a checkbox
   ↓
dev_mode = true, expires_at = now() + 8h
   ↓
recorded in the install's activity log
   ↓
the upload button + guide appear
```

| Rule | Why |
|---|---|
| Counter per-user + per-IP | Per-install would let a malicious page unlock with a single POST |
| Final state per-install | It is a site-level security posture, not a user preference |
| **Password re-auth** | The five-click gesture is client-side and weak against CSRF |
| 8-hour auto-expiry | Permanent developer mode = a permanent open door |
| Full audit log | After installing a bad plugin, the trail is the difference between "we can debug" and "we are blind" |

## What developer mode opens

| Path | State |
|---|---|
| Local plugin upload button | ✅ open |
| Plugin/theme authoring guide pages | ✅ open |
| Viewing manifest and file list | ✅ open |
| `system: true` from the manifest | ⛔ closed (moved to the allowlist) |
| Installing onto an approved market plugin's slug | ⛔ closed |
| Receiving `install_token` | ⛔ closed |
| **Turning off the "unverified" warning** | ⛔ closed |
| `config:cache` / `route:cache` | ⛔ closed |
| `db.raw` · `exec` · writing outside `public` | ⛔ closed |

## The "unverified" warning must **not** be bypassable

A bypassable warning teaches the user to click without reading and destroys its value for the 95%
of users who do not install unverified plugins. **Developer mode opens access, not immunity.**

Three display points: an amber mark in the list · a modal with a mandatory checkbox and the
publisher name · a persistent banner on the plugin's own pages.

The copy must be honest: "not technically verified" means *we did not review it*, not "it is
unsafe".

---

# Part 7 — System plugins

## The difference between two things

| | Normal plugin | System plugin |
|---|---|---|
| Gets `system: true` from the manifest? | ⛔ never | ⛔ never |
| Where it comes from | User upload | `system_plugins` allowlist |
| Can be deactivated | ✅ | ⛔ |
| Can be uninstalled | ✅ | ⛔ |
| Can live in core folders | ⛔ | ⛔ (unless excepted) |

## The security bug phase 0 closed

Before phase 0, `system` was read from the uploaded manifest, so any user with `plugins.edit`
could build a plugin that could neither be deactivated nor deleted — unable to remove malware
they had installed themselves. Now the manifest can never grant this flag; only the
`system_plugins` allowlist can.

---

# Part 8 — What a plugin can do

## 1. API

```
/api/v1/p/{slug}/…
```

| Feature | Why |
|---|---|
| **One** real route in the whole core | The `PluginRouter` catch-all; everything else is dispatched inside it |
| Name collisions structurally impossible | Because the core owns the only route |
| `route:cache` stays valid | Installing a plugin is a cache-file flag, not a rebuild |
| Instant deactivation | One flag |

**Why catch-all and not a real prefixed route?**

| Option | Problem |
|---|---|
| A real route with `prefix()` | In Laravel `prefix()` only changes the URL text; it does not create a scope. A malicious plugin could do `Route::get('/v1/admin/users')` and hijack the core |
| A real route | `route:cache` boots and serializes routes; installing a plugin means `route:clear && route:cache`, impossible on hosting without SSH |
| **catch-all** ✅ | Both problems solved |

**Middleware:** a plugin **never registers a middleware class** — it declares a string and the
core resolves it from a closed registry. If a plugin had the full service container it could
mutate any core singleton and no security boundary would form. Declaring a permission middleware
for a module the plugin does not own is rejected (`permission_not_owned`).

## 2. Database

```sql
CREATE TABLE blog_posts (...);   -- mandatory prefix: {slug}_
```

| Rule | Why |
|---|---|
| Mandatory prefix | Removing the plugin = chained drop, and `DROP TABLE users` is blocked |
| At most 20 tables | Resource cap |
| At most 10 indexes per table | Same |
| `^[a-z0-9_]+$`, at most 40 characters | Uncontrolled names blocked |
| Collision check against core tables | Otherwise a plugin could hijack a core table |

**⛔ A real `CREATE DATABASE` was rejected.** A second DB = a second pool + a second backup + a
second migration trail, and it breaks the product promise of "one `docker compose`".

**⚠️ Remaining limitation:** if a plugin can run `DB::statement('DROP TABLE users')` directly, it
bypasses all naming rules. The real closure is two PostgreSQL roles — an owner role for
`migrate`, and an app role whose DDL is limited to `{slug}_*`. The `PluginDdlConnection`
and the database guard are in place; the DDL role is provisioned by
`plugin-ddl:reset-password` / `PLUGIN_DDL_PASSWORD`.

## 3. UI — declarative only

A plugin **cannot** place any code in the browser. It only **declares** what it wants; the core
renders it:

| Thing | How |
|---|---|
| Menu | `admin.menu` → merged into server props |
| Header/footer widget | `site.header_widget` → from schema |
| Page block | `site.block_type` → `BlockRenderer` from schema |
| Dedicated page | `admin.page_registry` → page descriptor |
| Settings form | top-level `settings` → `SchemaForm` |
| Data table | `admin.data_collection` → `DataTable` + `Pagination` |
| Header tools card | `admin.plugin_tools` → drawer |

The backend half of several of these already exists (`widgetSchemas()`, `pageTypes()`,
`DashboardController::widgets()`, `SchemaForm`).

---

# Part 9 — Lifecycle

## Review statuses

| Status | Meaning | Who gets it |
|---|---|---|
| `unverified` | 🟢 **default** | Any plugin not from the market |
| `pending` | Awaiting review | A locally uploaded plugin |
| `approved` | Technically approved | A reviewer approved it |
| `rejected` | Rejected with a recorded reason | A reviewer |

> ⚠️ **The default used to be `approved`** — fail-open. Phase 0 fixed it to `unverified`.

## Cycle

```
upload → analyze structure → signature check → requires.core → install on disk
   → pending → activate → upgrade → deactivate → uninstall
                 ↓
             reject (with reason)
```

## Rollback — now structured

Releases are laid out as `releases/{version}-{hash}/` with a `current` pointer; switching a
version is one atomic rename. `PluginReleaseManager` owns this layout, and an upgrade targets a
new content-addressed path so opcache never holds a stale entry.

---

# Part 10 — Integrity seal

## What was asked for and why it should not be that way

Request: "every plugin gets a public key and install must match the uploaded files."

If that means a key **inside the artifact, it is meaningless** — a public key next to the thing it
signs is like putting the stamp next to the document. Anyone who can change the artifact can
change the key too.

## What actually works: a per-install integrity seal

A random 32-byte key is generated **at install time, on the user's own server**. The private half
is outside the artifact (the `settings` table); the public half is in `manifest.lock.json`. A
re-seal on each boot or every N minutes signs the current file digest and compares it with the
existing public half.

| Guarantees | Does **not** guarantee |
|---|---|
| The files on disk are what they were at install time | That the code was safe at install time |
| Later tampering is detected | That the plugin came from the real publisher |
| Incomplete extraction / interrupted download is detected | That the code has no vulnerabilities |
| A replay of an older artifact is detected | That the user saw it was "unverified" |

The key **must be per-install and outside the artifact**. If it is inside the ZIP, uninstall +
reinstall the same ZIP neutralizes it.

**For the UI:** call it an "integrity seal" or "install fingerprint", not a "public key".
**For a market plugin, authenticity + integrity is solved by one signature:** because
`content_digest` is inside the signed manifest, the publisher's Ed25519 signature transitively
covers the whole payload.

## Digest algorithm

```
sha256. Other algorithms were rejected:
MD5/SHA-1 (broken) · SHA-512 (no benefit)
BLAKE3 (no maintained PHP binding and needs a compiler — indefensible for a
        CMS installed on hosting)

1. File list, each entry normalized (separator → '/'), no case-fold
2. For each file except manifest.json: digest_file = sha256(contents)
3. Byte sort (strcmp), build a line:
     "<relpath>\0<8-hex of mode>\0<digest_file>\n"
4. files_digest = sha256(concat of lines)
5. manifest without the signature field → canonical JSON → manifest_digest
6. content_digest = sha256("pishdad-digest-v1\0" + manifest_digest + "\0" + files_digest)
```

- **`manifest.json` is outside the list** because it carries its own signature; including it would
  be circular.
- **A versioned prefix** ensures a future algorithm change does not silently invalidate the old
  digest.
- **Without normalization** a ZIP could name the same code `./src/A.php` and `src/A.php` and give
  two different digests, while PSR-4 sees both as one.

The seal key is created at install (`PluginTrustStore::ensureSealKeyFor`) and the `content_digest`
column is part of the plugin row.

---

# Part 11 — Market

## Data model

`publishers` · `market_items` · `market_versions` · `licenses`

**Key point:** the signature must be on `market_versions`, not `market_items`. A signature means
a specific version of the code.

## Statuses

| Status | Mark | Meaning |
|---|---|---|
| `pending` | grey/amber | Awaiting review |
| `approved` | green | Technically approved |
| `rejected` | red | Rejected with a reason |
| `unverified` | amber | **Local**, never reviewed |
| **`yanked`** | red | ⚠️ **non-removable** — after a security incident |
| `deprecated` | yellow | Works but discouraged |

**Why `yanked` is non-removable:** without it, a vulnerable plugin with a thousand installs is
taken out of reach by just "removing it from the market" — but a thousand active installs still
run it and get no notice. `yanked` lets you **push** to active installs.

## License model

**What is bought:** the right to run the code on N installs. **Not** a WordPress-style license
key.

| Option | Assessment |
|---|---|
| Perpetual, unlimited installs, no phone-home | Simple and honest, but stealable |
| Perpetual + activation with phone-home | **Brand problem:** blocking a working site due to an outage is indefensible |

**Recommendation:** perpetual license, unlimited installs, no blocking phone-home. **The main
lever is not price; it is compatibility.** When updates are free as long as the core is alive, a
developer is incentivized to align with the core's stability.

## Live market API

```
GET  /v1/market/publishers              (public trust store)
POST /v1/market/publishers              (publisher self-registration)
POST /v1/market/submissions             (upload-to-review, plugins.edit)
GET  /v1/market/submissions             (review queue, reviewer permission)
POST /v1/market/plugins/{slug}/approve | /reject | /yank | /unyank
POST /v1/market/plugins/{slug}/install
GET  /v1/market/licenses
```

---

# Part 12 — What belongs in the core, not in a plugin

## The rule

> **If removing the plugin would break the ability to use the public site, that file belongs in
> the core.**

The corollary: a plugin must never become a hard dependency of the public site. When the core
needs to call into optional behavior, the pattern is a **core-owned interface with a harmless
default**, and the plugin registers an implementation through `core.service_provider`:

```
Core:    an interface the core defines
Default: a no-op implementation that reads no tables and changes nothing
Plugin:  registers its own implementation via core.service_provider
```

No plugin installed = the site still works, with the default. The registrable interfaces are
closed and small (`PluginPackageContract::OVERRIDABLE_INTERFACES`); the search provider
(`App\Search\SearchableProvider`) is the canonical example.

---

# Part 13 — The developer guide

## Sections it must have

**A) Structure and packaging**
1. ZIP anatomy · 2. The namespace contract and why `Pishdad\Plugins\{Studly}\` is mandatory ·
3. Naming rules · 4. The `src/` boundary

**B) Manifest**
5. Full field reference · 6. The market/local modes · 7. `capabilities` · 8. `requires.core`

**C) API**
9. `api.routes[]` shape and the mandatory prefix · 10. Allowed middleware strings · 11. What
`PluginContext` gives and does not give

**D) Data**
12. Migrations with the mandatory prefix · 13. Isolation from the core · 14. The limits

**E) Extension points**
15. The three degrees of openness with examples · 16. The micro-schema grammar · 17. `href` rules ·
18. The forbidden list

**F) Work**
19. The scaffolder CLI · 20. Testing · 21. How to build the ZIP · 22. How to debug with dev mode

## Live or fixed? — both, split

| Part | Source | Why |
|---|---|---|
| Manifest field reference | **live** (from the contract) | Cannot drift |
| Generated permission names | **live** (from active manifests) | Same logic as `GET /admin/permissions` |
| Tips and best practices | **fixed** | Needs human judgment |
| The scaffolder CLI | **code** | The best guide is one that builds the project itself |

**Why "fully live" is wrong:** a guide generated from code has no reasons. **Why "fully fixed" is
wrong:** a fixed guide without an owner lies within three months — the old `DeveloperGuide.tsx`
had three content errors (old ZIP structure, the removed `system` field, a single hardcoded key
instead of a trust store).

## PDF

`window.print()` with an honest label. A real Persian PDF is **out of scope**: there is no PDF
engine in the project and Vazirmatn is `woff2`. Client-side libraries do not shape Persian
correctly.

---

# Part 14 — What you should know

## 1. The most honest limitation

**No in-process Laravel plugin provides a security boundary.** Write this in the UI too. Hiding it
means surprising the user.

## 2. Real stats

| Thing | State |
|---|---|
| Package-listing controls | 🟢 live |
| Safe extractor / installer | 🟢 live |
| PSR-4 loader | 🟢 live |
| `PluginRouter` catch-all | 🟢 live |
| Migration runner + DDL role | 🟢 live |
| Developer mode services + gate | 🟢 live |
| Separation of the trust model | 🟢 live |
| Package-analysis UI | 🟢 live |
| Extension points | 1 live, several declared, 2 deferred (see Part 4) |
| Market API | 🟢 live (submit → review → yank → install → licenses) |
| Per-point `panel.extensions` single shape | 🔵 tracked as `I1-b` |

## 3. The biggest danger is not building the market

**It is attracting the first 10 developers.** If a developer cannot build and install their first
plugin in 30 minutes, the market stays empty. That is why the guide plus
scaffolder must arrive early — see [`DEVELOPER-QUICKSTART.en.md`](DEVELOPER-QUICKSTART.en.md).

## 4. Three things the UI must state honestly

1. "Not technically verified" means *we did not review it*, not "it is unsafe".
2. "Integrity seal" — not "public key".
3. Do not promise a field the contract does not have. Either it really works or it is not in the
   manifest.

---

## Known differences (docs vs. code)

This English edition follows the code. Where the Persian original diverges:

1. **"No plugin executes"** (Persian Part 4) is stale. The loader, installer, router, dispatcher,
   migration runner, dev mode and market are all built and wired.
2. **Manifest route shape.** The Persian example used `"routes": "routes/api.php"`. The code reads
   `api.routes` as a **list of objects** with `path`/`method`/`handler`/`middleware`
   (`PluginRouteTable`), which is what `pishdad-plugin:make` generates.
3. **`db` shape.** The Persian example used `db.table_prefix`/`db.migrations`; the live contract
   is `db.tables[]` (raw names, indexes), verified against the migration text by
   `PluginMigrationRunner`.
- **`entry.service_provider` is not read** by the runtime. A backend service provider is
   discovered by convention (`Pishdad\Plugins\{Studly}\ServiceProvider` through
   `PluginAutoloader`), and interface overrides are declared via the `core.service_provider`
   extension point. Do not rely on `entry`.
4. **Permissions key.** Preferred top-level key is `access`; `permissions` is a legacy fallback.
5. **`hooks` is gone** — the field and its warning no longer exist.
6. **`admin.settings_schema`** is deprecated; settings are a top-level `settings` key.
7. **`admin.plugin_tools`** is now `declared_only` (built), not "not built".
8. **`requires.core`** is enforced (`CoreRequirementChecker`), closing B25.
9. **Review default** is `unverified` (fail-open closed in phase 0).
10. **Rollback** is now release-based (`releases/{version}-{hash}/` + `current`), not a bare
    `previous_version` string.
