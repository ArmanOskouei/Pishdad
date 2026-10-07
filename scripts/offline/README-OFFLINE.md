# Offline bundle

Everything needed to install this release **without downloading anything**.

## What makes it offline

| Normally | Here |
|---|---|
| `composer install` (packagist) | `vendor/` travels as `offline/vendor.tar.gz` and the installer unpacks it |
| `npm install` + `next build` | the frontend image is prebuilt and travels in the bundle |
| `docker pull` for every service | six images are preloaded from `offline/images/*.tar` |
| `docker compose build` | the compose files reference images by tag, with `pull_policy: never` |

The only thing this bundle cannot carry is **Docker itself**. Install Docker
Desktop (or Docker Engine) first — one time, on the machine.

> Why `vendor/` is a tarball and not loose files: composer unpacks each package
> file by file, and over a Windows bind mount that is slow enough to hit its own
> 300 s timeout on a large dependency (`aws/aws-sdk-php`). Building it inside a
> container and shipping one archive sidesteps that on both ends — the bundle
> build and the install.

## Install

```bash
./install.sh              # Linux / macOS
```
```powershell
.\install.ps1             # Windows
```

Then open **http://localhost:8080/install**.

Ports: `API_PORT` (8080) and `FRONTEND_PORT` (3100). Change them on the command
line:

```bash
API_PORT=9090 FRONTEND_PORT=3200 ./install.sh
```

`--backend-only` skips the frontend; `--load-only` just loads the images.

## What to enter in step 2 of the installer

The bundled database is created as **`cms` / `cms` / `cmssecret`**, so:

| Field | Value |
|---|---|
| Host | `db` |
| Port | `5432` |
| Database | `cms` |
| User | `cms` |
| Password | `cmssecret` |

> `db` and `5432` look wrong next to the published ports, but the installer runs
> **inside** the app container: `localhost` there is that container, and the
> database is reached by its compose service name on its internal port.

To create your own database instead, follow the guide on the step 2 page — the
image bundle also carries `psql`, so nothing needs downloading there either.

## Why the images are tagged `pishdad/...`

MinIO is pinned by content digest and the rest by tag. If the offline compose
kept those exact references, `docker load` would have to restore the identical
digest or tag for Compose to accept them — and any mismatch would silently fall
back to a registry pull, which is precisely what this bundle exists to prevent.
Loading and referencing simple local tags removes that ambiguity.

## Size and architecture

Expect roughly **1.5–2 GB** — that is the cost of carrying the dependencies
instead of fetching them.

⚠️ **The images are architecture-specific.** The ones in this bundle are built
for `amd64` (x86-64). On an `arm64` machine (Apple Silicon, ARM servers) Docker
will refuse to run them. Rebuild the bundle on that architecture if you need it.

## Verifying the bundle

From the source repository:

```bash
node scripts/verify-offline-bundle.mjs dist-offline
```

It checks that every image the compose files reference is actually present as a
tar, that no compose file can reach a registry, and that `vendor/` is complete.
