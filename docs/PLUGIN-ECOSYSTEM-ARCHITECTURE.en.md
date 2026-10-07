# Pishdad plugin ecosystem architecture

**English** · **[فارسی / Persian](PLUGIN-ECOSYSTEM-ARCHITECTURE.md)**

> Living document. If it drifts from the code, the code wins and this document must be fixed.
> Canonical manifest schema reference: `PLUGIN-MANIFEST-CONTRACT.en.md`.

## 0) An honest warning that should also be repeated in the UI

**No in-process Laravel plugin provides a security boundary.** Running PHP can do anything.
Every control below — a PSR-4 loader with a mandatory prefix, a capability gate, namespace
enforcement — **reduces the attack surface, it does not remove it**.

The only real way to run untrusted code safely is to isolate it in a separate process/container.
That is not done in phase 1.

| Residual risk | Why it remains | Current mitigation |
|---|---|---|
| A malicious plugin at the same level as the core | No process isolation | Declared honestly; a future phase |
| Unprotected network access | `file_get_contents('http://…')` | `PluginContext::httpClient()` + domain allowlist |
| Reading `.env` | `getenv()` from any code | A separate process quota — not feasible, declared |
| Reading Sanctum tokens | Shared DB model | A plugin only owns `plugin.{slug}_*` tables (decision K0.3) |
| DoS: infinite loop / memory | Same process | Only a capped subprocess — terrible performance. Accepted and documented |
| Route injection into the core | — | Structurally impossible with the catch-all (§3) |

---

## 1) The real state today

Before planning any marketplace, you must know this: the plugin runtime that executes code
(the PSR-4 autoloader, the extracting installer, the catch-all router, the migration runner and
developer mode) has **since been built and wired** — it is no longer a paper promise. See
"Known differences" at the end for what this document's earlier revisions claimed.

The trust foundation (phase 0) was what made it safe to build on:

1. Upload analyzes and validates the package **before** extracting anything, and only then
   installs it.
2. `plugin_hooks` was removed — 2026-09-27. The `hooks` manifest field was a promise the
   core did not keep: `PluginController::syncHooks()` wrote rows but no dispatcher read them.
   Instead of building a dispatcher, the field was removed from the contract; the table was
   dropped by migration `2026_10_06_000001_drop_plugin_hooks_table`. A manifest that still declares
   `hooks` neither errors nor writes anything.

---

## 2) The trust model — three concepts, three pillars (phase 0, done)

Before phase 0, three completely different things were mixed under one name:

| # | Concept | What it proves | Column | What it does **not** say |
|---|---|---|---|---|
| 1 | **Authenticity** | The manifest came from the publisher's private key | `publisher_verified` + `publisher_key_id` | Whether the publisher is trustworthy |
| 2 | **Integrity** | The files on disk are what was signed | `content_digest` + `digest_verified_at` | That the code was safe at install time |
| 3 | **Our approval** | The platform reviewed this version (administrative) | `review_status` | **Nothing cryptographic** |

The old model (`config/plugins.public_key` = a single key) conflated all three:
`signature_valid = true` effectively meant "signed by us" — i.e. **approval** wearing the clothes
of **authenticity**. Opening the local upload path would have collapsed this model.

### What phase 0 changed

- `publisher_keys` — a multi-publisher trust store. The manifest declares `publisher.key_id`
  (hex(sha256) of the public key), and the core reads the key from the trust store. The `config`
  key remains as a fallback so existing installs do not break.
- `system_plugins` — a system-plugin allowlist. **A manifest can no longer ever grant the
  `system` flag.**
- `review_status` defaults from `approved` → `unverified`. Previously any code path that did not
  set this field (seeder, `forceFill`, a future market path) automatically fell to "approved" —
  fail-open.
- Columns `source` (`local`/`market`/`core`), `content_digest`, `digest_verified_at`.
- `Plugin::trustSummary()` returns the three fields separately so the UI does not have to guess
  about security from a single boolean.

### The security bug phase 0 closed

Before phase 0, `system` was read from the uploaded manifest. That meant any user with the
`plugins.edit` permission could build a plugin that could neither be deactivated nor deleted —
i.e. they could not remove malware they had installed themselves. Regression:
`test_manifest_cannot_grant_system_flag`.

### A fail-open bug the tests surfaced

If a publisher declared a `key_id` but its key was not in the trust store, the code reached
`null` and a `??` in the verifier **fell back to the config key** — which is another publisher's
key. So a missing key was itself a bypass. Fixed: `null` is explicitly an error, not a fallback.

---

## 3) Folder structure and namespace (phase 1)

### Disk layout

```
storage/app/private/plugins/
├── _packages/{slug}/{slug}-{version}-{zip8}.zip     ← downloaded archive
├── {slug}/
│   ├── releases/1.2.0-a3f91c2d/                    ← extracted versions
│   │   ├── manifest.json
│   │   ├── src/  routes/  database/  resources/  config/
│   ├── manifest.lock.json                          ← integrity seal
└── _staging/{uuid}/                                 ← temporary extraction
```

**Why `releases/` instead of in-place overwrite:** if extraction happens on the previous path and
the user interrupts it, the site is left half-installed. The atomic pattern
(extract-to-temp → verify → rename) is the only reliable way. It also makes real rollback
possible — only versions that are kept on disk can be rolled back to.

The `local` disk root is `storage/app/private`, i.e. outside `public/` — inherently correct for
PHP files. It must **never** go on the `public` disk.

### PSR-4 without touching `composer.json`

PSR-4 is registered at runtime with `ClassLoader::addPsr4()` on the `ClassLoader` instance (what
October CMS and WordPress do).

| Rejected option | Why |
|---|---|
| Adding `psr-4` to `composer.json` | Requires `composer install` on the target |
| `composer dump-autoload` after each install | Rewrites `vendor/composer/autoload_psr4.php`; an error ⇒ the whole site fails to boot |
| `classmap-authoritative` | Breaks every runtime solution |
| Raw `spl_autoload_register` | The plugin's `autoload` in `composer.json` neutralizes it |

Three constraints the loader must **enforce**, not merely promise:
1. Mandatory namespace `Pishdad\Plugins\{StudlySlug}\` ↔ `{release}/src/`
2. Reject any namespace outside this prefix — otherwise a plugin could define a class in `App\`
   and shadow the core. **This is the one place where load security is actually enforced.**
3. Reject non-PSR-4 file names with a clear error

**Real trap:** `my-plugin`, `my_plugin` and `my plugin` all studly to `MyPlugin`. Installing the
second silently shadows the first class. `plugins.slug` is unique but studly-slug is not — so a
collision check on the studly form must run at install time.

### Required/optional files

| File | Status | Notes |
|---|---|---|
| `manifest.json` | **required** | The only required file |
| `Laravel/src/ServiceProvider.php` | optional | Its absence = a "declarative" plugin |
| `Laravel/routes/api.php` | optional | API only. `routes/web.php` is forbidden |
| `Laravel/database/migrations/` | optional | With a mandatory prefix |
| `Laravel/config/plugin.php` | optional | Default values only. Real settings live in the DB |
| `Laravel/resources/views/` | ⚠️ | Effectively useless — the backend is API-only |
| `Next.js/panel/`, `Next.js/blocks/` | optional | The UI lives in **Next.js** |

**Important architectural note:** because the backend is API-only, a Laravel plugin's "template"
cannot have UI. All plugin UI must be defined for Next.js — a second, completely separate
contract.

### Database tables

**Proposal: table prefix**, enforced at migration runtime rather than only documented.

| Option | Advantage | Disadvantage |
|---|---|---|
| A separate PG schema | Clean `DROP SCHEMA CASCADE` | `search_path` is session-scoped; problematic with connection pooling; a new failure lever where the user lacks full control |
| **Table prefix** | Works with every driver, easy to test | Long names; removal = chained drop |

Rules: prefix `{slug}_`, at most 40 characters, `^[a-z0-9_]+$`, at most 20 tables and 10 indexes
per table, and a collision check against core tables.

**Decision 2026-09-27 — declare in the manifest, do not blind-rewrite.** The prefix is applied at
runtime, but "runtime" needs a declaration channel or the rewriter has to guess from code text
which tables are involved — and a wrong guess is worse than no feature, because it silently
writes into core tables. So the manifest gets a `db.tables` section, and the runner:

1. extracts every table reference in the migration
2. rewrites it with `{slug}_`
3. if a reference is **outside the declared list** — or cannot be detected at all — rejects the
   whole install **fail-closed**
4. checks the limits (20 tables, 10 indexes per table) and core-table collisions **before
   running any DDL**

The index limit is **per table**, not per plugin: a limit of 10 indexes for 20 tables would mean
a plugin with 20 tables could only index half of them — incompatible with real plugin patterns.

### Safe ZIP extraction

The existing library is `ZipArchive` (`ext-zip` in the `Dockerfile`) — nothing to install.
Running `unzip`/`7z` in a shell was rejected: it pulls in `ext-zip` for no reason and opens the
attack surface.

**Controls in execution order** — the key point: zip-slip must be caught on the ZIP listing
**before** extraction, not during it. `ZipArchive::extractTo()` does not fully protect against
`..` paths.

1. Inspect the ZIP listing before extraction
2. **zip-slip:** normalize every path; reject if absolute, contains `..`, or after normalization
   falls outside the root
3. **symlink/device/FIFO:** read `external_attributes`; reject `S_IFLNK`, `S_IFCHR`, `S_IFBLK`,
   `S_IFIFO`
4. **zip bomb:** three independent caps — file count, total uncompressed size, and compression
   ratio (>100:1 rejected)
5. Reject names containing `\` (`..\..\` is harmless on Alpine with `/`, but the reverse is
   dangerous)
6. **Case collision:** `A.php` and `a.php` are both valid PSR-4, but on a case-insensitive
   filesystem one overwrites the other — a real bug for an Iranian user on Windows hosting
7. Duplicate path after normalization ⇒ reject the whole package
8. Illegal names: only `[A-Za-z0-9._-]` and `/`; reject `\0`, control characters, and Windows
   reserved names (`CON`, `NUL`, `COM1-9`, …)
9. Incoming ZIP size 20MB (matching `client_max_body_size` in nginx)
10. **Extract to staging, then verify, then rename** — never directly onto the live path

---

## 4) A dedicated API path + middleware (phase 1)

### Selected option: a core-owned catch-all

The core owns exactly **one** real route:

```
GET|POST|…  /api/v1/p/{slug}/{any?}   →  App\Http\Controllers\PluginRouter
```

`PluginRouter` reads the table of activated routes from a cache file (not the DB on every
request) and `dispatch()`es.

Three serious problems with the "real route with a contractual prefix" option:

1. **Route cache.** `route:cache` boots the app once and serializes routes. Installing a plugin
   means `route:clear && route:cache`, which cannot be done on hosting without SSH. Deferred
   providers are also not run during `route:cache`.
2. **The prefix is contractual, not enforced.** In Laravel `Route::get('/admin/users')` inside a
   `prefix()` group registers **at the root**. So a malicious plugin could do
   `Route::get('/v1/admin/users')` and hijack the core.
3. **Route-name collisions** are global; a plugin registering a duplicate name breaks the site.

Catch-all advantages: name collisions are **structurally impossible**, `route:cache` stays valid,
deactivating a plugin is one flag in a cache file and is immediate.

### Middleware

**Principle: a plugin never registers a middleware class directly.** It declares only a string,
and the core resolves it from a closed registry:

```
auth:sanctum                        → authentication
perm:plugin.{slug}.{module}.{action} → plugin permission
throttle:...                        → rate limiting
plugin.active                       → the plugin must be active
```

**Rejected solution:** giving the plugin `app()`. If a plugin has the full service container it
can mutate any core singleton and no security boundary forms at all. Alternative: a limited
`PluginContext` facade with `settings()`, `log()`, `db()`, `cache()`, `storage()`, `menu()`, and
`httpClient($url)` that checks domains against the plugin allowlist.

### Name-collision table

| Item | Rule |
|---|---|
| route | `plugin.{slug}.` + catch-all |
| class | `Pishdad\Plugins\{StudlySlug}\` mandatory |
| permission | `plugin.{slug}.{module}.{action}` |
| table | `{slug}_` |
| cache/settings | `plugin:{slug}:…` |

---

## 5) Manifest and integrity seal

### Two manifest modes

Shared block: `schema_version`, `slug`, `name`, `version`, `requires`, `api`, `db`,
`capabilities`, `permissions`, `widgets`, `page_types`, `panel`, `integrity`.

- **Market:** a `publisher` block (key + signature) + a `review` block
- **Local:** a `local` block instead of `publisher` with `declared_author` and `contact`

Hard rules enforced in the validator:
- `api.prefix` must be exactly equal to the slug — one source of truth
- `system` is **never** accepted from a manifest (phase 0: moved to an allowlist)
- `requires.core` is checked before anything else
- `capabilities` must not have `exec`, `db.raw` or `filesystem.write` outside `public` without an
  explicit grant

### The integrity seal (decision K0.4)

The owner asked for a "public key per plugin". To be explicit: **if it means a key placed inside
the artifact, it is meaningless** — a public key next to the thing it signs is like putting the
stamp next to the document itself.

What is actually useful: a random 32-byte key is generated **at install time, on the user's own
server**. The private half stays outside the artifact (the `settings` table) and the public half
is in `manifest.lock.json`. A re-seal operation on each boot or every N minutes signs the current
file digest and compares it with the existing public half.

| Guarantees | Does **not** guarantee |
|---|---|
| The files on disk are what they were at install time | That the code was safe at install time |
| Later tampering is detected | That the plugin came from the real publisher |
| Incomplete extraction / interrupted download is detected | That the code has no vulnerabilities |
| A replay of an older artifact is detected | That the user saw it was "unverified" |

The key **must be per-install and outside the artifact**. If it is inside the ZIP,
uninstall + reinstall the same ZIP neutralizes it.

**For the UI:** do not call it a "public key". Call it an "integrity seal" or "install
fingerprint". Telling the user "public key" builds a security expectation that is not met and
will later be used against you as a security bug.

### content digest

Algorithm `sha256`. Rejected: MD5/SHA-1 (broken), SHA-512 (no benefit), BLAKE3 (no maintained PHP
binding and needs a compiler — indefensible for a CMS installed on hosting).

```
1. File list, each entry normalized (separator → '/'), no case-fold
2. For each file except manifest.json: digest_file = hash('sha256', contents)
3. Byte sort (strcmp) and build lines:
     "<relpath>\0<8-hex of mode>\0<digest_file>\n"
4. files_digest = hash(concat of lines in order)
5. manifest without the signature field → canonical JSON → manifest_digest
6. content_digest = hash('sha256',
     "pishdad-digest-v1\0" + manifest_digest + "\0" + files_digest)
```

- **`manifest.json` is outside the file list** because it carries its own signature. Including it
  would be circular.
- **A versioned prefix** ensures a future algorithm change does not silently invalidate the old
  digest.
- **Without normalization** a ZIP could name the same code `./src/A.php` and `src/A.php` and give
  two different digests, while PSR-4 sees both as one.
- This same `content_digest` is the market download-cache key.
- Besides the DB, it is also written to `manifest.lock.json` (if the DB is lost there is at least
  one other copy to compare against). **This is a layer, not a replacement for re-verify on
  boot.**

---

## 6) Developer mode (phase 3)

**Per-install but per-user for the counter:**

- The **click counter** lives in cache under `devmode:taps:{user_id}|{ip}` — not per-install. If
  it were per-install, a malicious page could unlock developer mode with a single POST (with no
  user interaction).
- The **final state** lives in the `settings` table, group `system`, key `dev_mode` —
  **per-install**, because it is a site-level security posture, not a user preference.

| Reset condition | Behavior |
|---|---|
| More than 2 seconds between two clicks | Reset |
| More than 10 seconds since the first click | Reset |
| A non-POST request to the same endpoint | Reset |
| The user logs out | Reset |
| **30 minutes after a successful unlock** | Reset |

After unlock: `dev_mode = true` with `expires_at = now() + 8h` and a random `unlock_token`.
**Automatic expiry is non-negotiable** — permanent developer mode is a permanent open door on any
install that enables it once.

**The confirmation modal uses the user's password** (not just a checkbox): the five-click
gesture is client-side state and vulnerable to CSRF. The event must be recorded in the install's
activity log.

### What is open and what is closed

| Path | Developer mode |
|---|---|
| Local plugin upload button | ✅ open |
| Plugin/theme authoring guide pages | ✅ open |
| Viewing a manifest and an installed plugin's file list | ✅ open |
| `system: true` via the manifest | ⛔ closed (phase 0: moved to an allowlist) |
| Installing onto an approved market plugin's slug | ⛔ closed — identity theft prevention |
| Receiving/showing the install's `install_token` | ⛔ closed — impersonation risk |
| Removing the "unverified" warning | ⛔ closed |
| `config:cache` / `route:cache` from inside a plugin | ⛔ closed |
| `db.raw`, `exec`, `filesystem.write` outside public | ⛔ closed unless explicitly capable |

### The "unverified" warning must not be bypassable

Reason: a warning that can be bypassed teaches the user to click without reading — and destroys
its value for the 95% of users who do not install unverified plugins. Developer mode opens
**access**, not **immunity**.

Three display points: the list row (amber mark — red for "rejected"), a pre-activation modal
with a mandatory checkbox + publisher name, and a persistent banner on the plugin's own admin
pages.

The copy must be **honest**: "not technically verified" means *we did not review it*, not "it is
unsafe".

---

## 7) Installing onto the target server (phase 2)

### Options and rejects

| Method | Assessment |
|---|---|
| **loopback** (your site requests the target) | Inbound on shared hosting is almost always blocked |
| **reverse loopback** (the target requests you) | `allow_url_fopen`/`ext-curl`; some firewalls block at DNS level |
| **manual bootstrap** (an install file the user uploads) | ✅ works on 100% of hosting, near-zero risk |
| **host plugin** (panel API) | DirectAdmin API is effectively disabled; most Iranian hosting is DirectAdmin ⇒ near-zero coverage |
| **FTP push** | ⛔ **rejected.** Asking for FTP credentials from a user is the worst possible decision |

### Decision (K0.2 / K0.5): VPS-first

Shared-hosting support is effectively impossible for this architecture: no Docker, and the
Next.js frontend has no path on DirectAdmin. The backend is nearly VPS-ready (`SESSION_DRIVER=file`,
`CACHE_STORE=database`, `QUEUE_CONNECTION=database` — i.e. Redis is not required).

**Backbone: a single `docker compose` command on a VPS.** Then bootstrap to install onto the
target.

---

## 8) Market

### Data model

`publishers`, `market_items`, `market_versions`, `licenses`.

**Key point:** the signature must be on `market_versions`, not `market_items`. A signature means
a specific version of the code.

### Statuses

`pending` / `approved` / `rejected` / `unverified` (local) / **`yanked`** / `deprecated`

**`yanked` is non-removable:** without it, a vulnerable plugin with a thousand installs is taken
out of reach by just "removing it from the market" — but a thousand active installs still run it
and get no notice. `yanked` lets you push to active installs.

### License model

**What is bought:** the right to run the code on N installs. **Not** a WordPress-style license
key.

| Option | Assessment |
|---|---|
| Perpetual, unlimited installs, no phone-home | Simple and honest, but stealable |
| Perpetual + activation with phone-home | **Brand problem:** blocking a working site due to a service outage is indefensible |

**Explicit recommendation:** perpetual license, unlimited installs, no blocking phone-home.
**The main lever is not price; it is compatibility.** When updates are free as long as the core
is alive, a developer is incentivized to align with the core's stability — and you get a real
reason to rein in breaking core changes.

---

## 9) Developer guide (phase 4)

**Split — and this combination is better than either extreme:**

| Part | Source | Why |
|---|---|---|
| Manifest field reference | **live** (from JSON Schema) | Cannot drift |
| List of un-dispatched hooks | **live** (from the registry) | Honest feedback — if it is not dispatched it must not be documented |
| Generated permission names | **live** (from active manifests) | Same logic as `GET /admin/permissions` |
| Tips and best practices | **fixed** in `docs/` | Needs human judgment |
| The `pishdad-plugin:make` scaffolder CLI | **code** | The best guide is one that builds the project itself |

**Why "fully live" is wrong:** a guide generated from code has no reasons or decisions. A
developer asks "why `src/` and not the root?" and "because the template file says so" is not an
answer.

**Why "fully fixed" is wrong:** a fixed guide without an owner lies within three months.

**Best mechanism:** a "plugin guide" dashboard inside the panel that shows the core header and
version and reads the code-derived parts live.

---

## 10) Residual inconsistencies with the current code

| # | Inconsistency | Location | Severity | Status |
|---|---|---|---|---|
| 1 | Single public key = single-publisher trust model | `config/plugins.php` | 🔴 | ✅ **fixed in phase 0** (`publisher_keys`) |
| 2 | `system` was read from the manifest | `PluginController` | 🔴 | ✅ **fixed in phase 0** (`system_plugins` allowlist) |
| 3 | `review_status` default `approved` (fail-open) | schema | 🔴 | ✅ **fixed in phase 0** |
| 4 | Plugin is not extracted/executed | whole backend | 🔴 | ✅ **built** (`PluginInstaller`, `PluginAutoloader`, `PluginRouter`, `PluginDispatcher`) |
| 5 | `plugin_hooks` had no dispatcher | ~~`PluginController::syncHooks`~~ | 🔴 | ✅ **fixed** — the field was removed |
| 6 | Permission without a slug prefix | `PluginController::ensurePermissions` | 🟠 | ✅ fixed — namespaced `plugin:{slug}:{module}.{action}` |
| 7 | `config('search.providers')` static | `SearchManager` | 🟠 | ✅ registry-based (`ServiceProviderRegistry`) |
| 8 | No backend route level for the search contract | `SEARCH-PLUGIN-CONTRACT.md` | 🟠 | ✅ superseded by `SearchableProvider` via `core.service_provider` |
| 9 | `activeManifests()` without cache | `ManifestRegistry` | 🟠 | ✅ **fixed** (300s cache + flush in `Plugin::booted`) |
| 10 | `theme` uses the same `system`/`signature` path but is not a plugin | `ThemeController` | 🟡 | later phase |
| 11 | No `then:` hook in `withRouting` | `bootstrap/app.php` | 🟡 | ✅ plugin lifecycle boot is wired |

---

## 11) A strategic note

The biggest risk of this project is not building the market; it is attracting the **first 10
developers**. That is why phase 4 (guide + scaffolder) must arrive earlier than expected — if a
developer cannot build and install their first plugin in 30 minutes, the market stays empty.

---

## Known differences (docs vs. code)

This document's earlier revisions claimed the plugin system did not execute code at all
(`extractTo` 0, `addPsr4` 0, `PluginRouter` 0, `dev_mode` 0, `requires.core` unchecked). That is
no longer true. The current code has:

- `PluginInstaller` — actually extracts and installs packages (deny-by-default, safe zip
  handling), called from `PluginController::upload()`.
- `PluginAutoloader` — registers the mandatory `Pishdad\Plugins\{Studly}\` namespace at runtime
  (wired in `AppServiceProvider`).
- `PluginRouter` — the single core catch-all `GET|POST|… /api/v1/p/{slug}/{any?}` dispatching
  through `PluginDispatcher` / `PluginRouteTable`.
- `PluginMigrationRunner` / `PluginMigrator` — migration verification and rewriting with the
  `{slug}_` prefix, plus the PostgreSQL DDL role.
- Developer mode — `DevMode`, `DevModeTaps`, `DevModeUnlock`, `DevModeAudit`, gated by the
  `devmode.gate` middleware on `plugins/upload` and `plugins/{plugin}/upgrade`.
- `CoreRequirementChecker` — `requires.core` is now enforced (B25 closed).
- A market API (`/v1/market/*`) with publisher registration, submission review, yank, install
  and licenses.
