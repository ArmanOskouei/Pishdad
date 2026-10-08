# Developer quickstart — build a plugin or a theme

**English** · **[فارسی / Persian](DEVELOPER-QUICKSTART.md)**

A short, verified path from a fresh checkout to a running plugin or theme. Every command, path
and class below exists in the repository today. For the full contract see
[`PLUGIN-GUIDE.en.md`](PLUGIN-GUIDE.en.md) and
[`PLUGIN-MANIFEST-CONTRACT.en.md`](PLUGIN-MANIFEST-CONTRACT.en.md).

---

## 0) Prerequisites

| Requirement | Version |
|---|---|
| PHP | >= 8.3 (with `sodium`, `zip`, `pdo_pgsql`, `intl`, `bcmath`, `mbstring`) |
| Composer | 2.x |
| Node.js | >= 22 |
| PostgreSQL | >= 18 |
| Redis | >= 8 |
| Docker (optional but recommended) | Compose |

Repo layout:

```
pishdad-core/
├── backend/    ← Laravel API (PHP)
└── frontend/   ← Next.js site (React)
```

---

## 1) Bring up a dev environment

### Option A — Docker Compose (recommended)

The compose stacks bring up Nginx, PHP-FPM, PostgreSQL, Redis and MinIO.

```bash
cd pishdad-core/backend  && docker compose up -d
cd pishdad-core/frontend && docker compose up -d
```

The backend container is `pishdad-app`.

### Option B — run directly

```bash
# Backend — http://127.0.0.1:8000
cd pishdad-core/backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve

# Frontend — http://127.0.0.1:3000
cd ../frontend
npm install
npm run dev
```

Health check for external dependencies (mail, SMS, MinIO, Redis, DDL role, seal keys, queue):

```bash
docker exec pishdad-app php artisan pishdad:doctor
# or, when running natively:
php artisan pishdad:doctor
```

Confirm the scaffolder commands are registered:

```bash
docker exec pishdad-app php artisan list | grep pishdad
```

You should see `pishdad-plugin:make`, `pishdad-theme:make`, `pishdad:doctor` and
`plugin-ddl:reset-password`.

---

## 2) Scaffold a plugin

The scaffolder generates the **minimum** package that already passes the core's package
validator — it is fail-closed: if validation fails, the generated ZIP is deleted and the command
returns `FAILURE`.

```bash
docker exec pishdad-app php artisan pishdad-plugin:make blog \
  --name="Blog" --description="Demo plugin" --author="Your Name"
```

Output (default root `storage/app/plugin-skeletons/`):

```
storage/app/plugin-skeletons/blog/
├── manifest.json
└── Laravel/
    ├── src/
    │   ├── ServiceProvider.php
    │   ├── Models/Item.php
    │   └── Http/ItemController.php
    ├── routes/api.php
    ├── database/migrations/…_create_blog_items.php
    └── config/plugin.php

storage/app/plugin-skeletons/blog-1.0.0.zip
```

Options:

| Option | Effect |
|---|---|
| `--name=` `--description=` `--author=` | Manifest metadata |
| `--path=` | Output root (default `storage/app/plugin-skeletons`) |
| `--force` | Overwrite an existing folder/file |
| `--no-validate` | Skip package validation |
| `--dev` | Register the skeleton in the local dev registry (requires developer mode to be ON) |

Notes that reflect the code:

- The slug must match `^[a-z0-9][a-z0-9._-]{1,39}$`. A slug used by a plugin **with tables** must
  also match `^[a-z0-9_]+$` (no dots or hyphens), because the final table name is built from it.
- Signing is optional in development. Set `PLUGIN_PUBLISHER_SECRET_KEY` in the backend `.env` to
  sign the manifest; otherwise the command prints a warning and validates with `allowUnsigned`.
- `--dev` is rejected unless developer mode is active (`DevScaffoldRegistry::active()`), and the
  check runs **before** any file is written.

### Plugin package tree the validator accepts

`manifest.json` must be at the ZIP root. Everything is deny-by-default; only these destinations
are allowed (`PluginPackageContract::ALLOWED_PATHS`):

```
manifest.json
Laravel/src
Laravel/routes/api.php
Laravel/database/migrations
Laravel/config/plugin.php
Next.js/panel
Next.js/blocks
```

`Next.js/panel.json` no longer exists (decision C2): the manifest is the single source of truth.

---

## 3) Scaffold a theme

Themes are **code, not ZIP** (decision Q5). The scaffolder writes a theme source folder into the
Next.js frontend, where the site compiles it.

```bash
docker exec pishdad-app php artisan pishdad-theme:make mytheme \
  --name="My Theme" --description="Demo theme"
```

Output (default root `pishdad-core/frontend/src/themes/mytheme/`):

```
pishdad-core/frontend/src/themes/mytheme/
├── theme.json    ← ThemeManifest (slug, name, description, version, modes, slots, tokens)
└── index.tsx     ← a component typed against ThemeProps + ThemeShell
```

`theme.json` required keys are `slug`, `name`, `description`, `version`, `modes`, `slots` and
`tokens` (contract: `pishdad-core/frontend/src/themes/theme-manifest.ts`). `modes` = `light`/`dark`/
`system`; `slots` = `header`/`footer`/`main`/`left`/`right`/`banner`.

**Register the theme** so it activates — add it to both files:

- `pishdad-core/frontend/src/themes/manifests.ts` — the pure slug→manifest registry
  (`THEME_MANIFESTS`, plus the `theme.json` import).
- `pishdad-core/frontend/src/themes/registry.tsx` — the render registry (`DEFINITIONS`, mapping the
  slug to the component).

The scaffolder prints this reminder itself. Unknown slugs always fall back to `DEFAULT_THEME_SLUG`
(`minimal`); a public route never crashes on an unknown theme.

Options: `--name=`, `--description=`, `--path=`, `--force`, `--dev`.

---

## 4) Run it in dev mode

| Piece | Command |
|---|---|
| Backend dev server | `docker exec pishdad-app php artisan serve` (or `php artisan serve` natively) |
| Frontend dev server | `cd pishdad-core/frontend && npm run dev` → http://127.0.0.1:3000 |
| Backend tests | `docker exec pishdad-app php artisan test` |
| Frontend type check | `cd pishdad-core/frontend && npm run typecheck` |
| Regenerate API types | `cd pishdad-core/frontend && npm run gen:types` |

To exercise the local-install path, developer mode must be on. The gate is enforced server-side
by the `devmode.gate` middleware on `plugins/upload` and `plugins/{plugin}/upgrade`; a request
only passes when the `X-Pishdad-Dev-Token` header is valid. The dev-mode services
(`DevMode`, `DevModeTaps`, `DevModeUnlock`, `DevModeAudit`) plus the frontend gate components are
the intended control surface.

---

## 5) Validate, package and submit

### Validate before install (no extraction, no DB writes)

```bash
curl -X POST http://127.0.0.1:8000/api/v1/admin/plugins/validate \
  -H "Authorization: Bearer <token>" \
  -F "file=@storage/app/plugin-skeletons/blog-1.0.0.zip"
```

This endpoint always returns **200** (even for a broken package) so the analysis is always
displayable. Errors are reported as a categorized list with codes (for example `zip.too_large`,
`path.not_allowed`, `manifest.missing_field`, `db.table_already_prefixed`).

### Package

The scaffolder already writes `blog-1.0.0.zip`. To sign, set `PLUGIN_PUBLISHER_SECRET_KEY` and
re-run `pishdad-plugin:make`; the manifest carries an Ed25519 `signature` and a
`publisher.key_id`.

### Install (local, developer mode)

```
POST /api/v1/admin/plugins/upload     (multipart form-data, field `file`)
```

Upload analyzes the package, verifies the publisher signature from the trust store, checks
`requires.core`, installs the release onto disk (`PluginInstaller`) and creates the DB record
(`review_status = pending`, or `approved` for the review-exempt path).

### Publish to the market

```
POST /api/v1/market/submissions        (multipart form-data, field `file`, requires plugins.edit)
POST /api/v1/market/plugins/{slug}/approve | /reject   (reviewer permission)
```

Publisher trust store (public): `GET /api/v1/market/publishers`. Approved, non-yanked plugins are
listed at `GET /api/v1/market/plugins`.

---

## Known differences (docs vs. code)

- The Persian `PLUGIN-GUIDE.md` still says "no plugin executes" and marks the loader, router,
  installer, migration runner and dev mode as 🔴 not built. That is stale. In the current code the
  plugin runtime is wired end-to-end; see `PLUGIN-GUIDE.en.md` → *Known differences*.
- The preferred manifest key for permissions is the top-level `access`; `permissions` is read as a
  legacy fallback.
