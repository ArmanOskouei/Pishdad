# Installing Pishdad

Three ways to get the code, then one installer does the rest.

| Way | You need | Best for |
|---|---|---|
| **A. `git clone`** (recommended) | git | everyone — updates are one command |
| **B. ZIP download** | a browser | no git on the machine |
| **C. Docker Compose** | Docker only | zero local setup; services included |

**Fastest (one command, needs Node.js 18+):**

```bash
npx pishdad install [dir] [--yes] [--check]
```

This checks prerequisites, clones, runs `composer install` + `npm ci`,
writes `.env`, serves the backend and **opens `/install` in your browser**.
`--check` only reports what is missing; `--yes` auto-installs missing system
packages (needs sudo/admin). Details and flags: `npx pishdad install --help`.

> Status: the `pishdad` npm package publishes alongside each release. If
> `npx` says “404”, use way A/B/C below — same result, a few more commands.

> 🇮🇷 [راهنمای فارسی](INSTALL.fa.md)

---

## 0. What you need first (honest list)

Nothing below is installed for you — the installer **checks** these and stops
with a clear message when something is missing. Install them once per machine.

| Requirement | Version | Notes |
|---|---|---|
| PHP | 8.2+, 8.3 recommended | with extensions below |
| PHP extensions | `pdo_pgsql` · `sodium` · `intl` · `bcmath` · `mbstring` · `zip` · `gd` · `fileinfo` · `curl` · `openssl` | the web installer lists exactly which one is missing |
| PostgreSQL | 14+ | **MySQL/MariaDB do NOT work** — migrations use `jsonb` |
| Composer | 2.x | PHP dependencies |
| Node.js | 22 LTS | only needed to **build** the frontend once |
| A domain (server installs) | DNS pointed at the server | TLS terminates at your reverse proxy |

**You do NOT need:** Redis, MinIO/S3, or Supervisor. The defaults use the
database queue, file sessions, and the local disk — Redis/MinIO are optional
upgrades, not requirements.

### One-shot setup per OS

**Ubuntu / Debian 22.04–24.04** (everything, unattended):

```bash
sudo apt update && sudo apt install -y \
  php8.3 php8.3-fpm php8.3-pgsql php8.3-intl php8.3-mbstring php8.3-xml \
  php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath \
  postgresql-16 git unzip curl
# Composer (official installer):
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
# Node.js 22 LTS (NodeSource):
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**Windows 10/11** (winget, then one manual step):

```powershell
winget install PHP.PHP.8.3 Composer.Composer OpenJS.NodeJS.LTS PostgreSQL.PostgreSQL.16 Git.Git
```

Then open `php.ini` (wherever winget put PHP) and uncomment these two lines —
without them the installer stops at the database step:

```ini
extension=pdo_pgsql
extension=intl
```

**macOS** (Homebrew):

```bash
brew install php composer node@22 postgresql@16 git
brew services start postgresql@16
```

**Check-only alternative** (install nothing automatically — just verify):

```bash
php -v && php -m | grep -i -E 'pgsql|sodium|intl|bcmath|zip|gd|curl'
psql --version && composer --version && node --version
```

If every line prints a version, you are ready. The web installer re-checks all
of this anyway (`/install` → preflight) and tells you precisely what is missing.

---

## 1. Get the code

**A. git (recommended):**

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git
cd Pishdad
```

**B. ZIP (no git):**

- Always-fresh ZIP of `main` (no dependencies inside, ~2 MB):
  `https://github.com/ArmanOskouei/Pishdad/archive/refs/heads/main.zip`
- Or from a clone, a versioned ZIP with checksum:
  ```bash
  git archive --format=zip HEAD -o pishdad-v1.zip
  sha256sum pishdad-v1.zip   # publish this next to the file
  ```
  `git archive` only packs tracked files, so `vendor/`, `node_modules/`,
  `.next/` and `.env` can never sneak in.

**C. Docker (only Docker required):**

```bash
git clone https://github.com/ArmanOskouei/Pishdad.git && cd Pishdad
cp pishdad-core/backend/.env.example pishdad-core/backend/.env
cd pishdad-core/backend && docker compose up -d --build
```

This brings PHP-FPM, Nginx, PostgreSQL, Redis and MinIO. Then continue at
step 3 (the web installer).

---

## 2. What each command installs

Run from the repo root unless noted. Every command is idempotent — running it
twice is safe.

| Command (where) | Installs / does | When to run |
|---|---|---|
| `composer install` (`pishdad-core/backend`) | PHP dependencies into `vendor/` (~150 MB, never committed) | after clone, and after every `git pull` that touches `composer.lock` |
| `cp .env.example .env` + `php artisan key:generate` (`backend`) | your private config + app key | once per install — **never copy someone else's `.env`** |
| `php artisan migrate --force` (`backend`) | creates all tables | after clone and after updates that add migrations |
| `php artisan db:seed` (`backend`) | roles, the 3 built-in themes, and — via `PishdadSiteSeeder` — the full demo (8 published pages, header/footer, Sahar panel preset, `commerce` active) | once per install; safe to re-run, published pages are never overwritten |
| `php artisan storage:link` (`backend`) | `public/storage` symlink so uploaded media is reachable | once per install (the web installer does it for you) |
| `npm ci` (`pishdad-core/frontend`) | exact Node dependencies from the lockfile | after clone, and after pulls that touch `package-lock.json` (`ci`, not `install` — reproducible) |
| `npm run build` (`frontend`) | production build (`.next/standalone`) | after clone and after pulls that touch `frontend/src` |
| `php artisan pishdad:install` (`backend`) | the same 6-step flow as the web installer, for terminals | servers without a browser |
| `php artisan pishdad:doctor --strict` (`backend`) | full environment audit, exit≠0 on problems | before going live; also step 6 of `deploy/deploy.sh` |

---

## 3. The installer (web or CLI)

```bash
cd pishdad-core/backend
php artisan serve                    # http://127.0.0.1:8000
# open http://127.0.0.1:8000/install
```

Six steps: preflight (checks §0) → database → app key → migrate → superadmin →
done. The demo content is seeded automatically; your first login lands in a
finished site, not an empty one.

Server deploy from the repo root (after `.env` is ready):

```bash
bash deploy/deploy.sh        # migrate → seal → outbox → doctor --strict → frontend build
```

Add this cron line (queues, digests, scheduled publishing all tick through it):

```cron
* * * * * cd <root>/pishdad-core/backend && php artisan schedule:run
```

---

## 4. Updating (no re-download)

`git pull` transfers **only changed files** — never the whole project. After
pulling, re-run only what changed:

| If the pull touched… | …then run |
|---|---|
| `composer.lock` | `composer install` (backend) |
| `pishdad-core/frontend/src/**` or `package-lock.json` | `npm ci && npm run build` (frontend) |
| `pishdad-core/backend/database/migrations/**` | `php artisan migrate --force` (backend) |
| `pishdad-core/backend/.env.example` | compare with your `.env`, add the new keys (never overwrite `.env`) |
| nothing above | nothing — you are done |

ZIP updaters: download the new ZIP, unpack **over** the old tree, keep your
`.env` and `storage/`, then apply the same table.

Check it worked: `php artisan pishdad:doctor --strict` (backend) must exit 0.

---

## 5. Shared hosting (cPanel): requirements and install

Possible — **if** the host gives you all five. If any one is missing, you need
a VPS instead; there is no workaround (see “why not” below).

| # | Requirement in cPanel | Where to check |
|---|---|---|
| 1 | PHP **8.2+** in “Select PHP Version”, with extensions `pdo_pgsql`, `intl`, `mbstring`, `zip`, `gd`, `sodium`, `bcmath`, `curl`, `fileinfo`, `openssl` | Select PHP Version → check every box |
| 2 | A **PostgreSQL** database + user (not MySQL) | “PostgreSQL Databases” section must exist |
| 3 | Node.js **22** (“Setup Node.js App”) **or** permission to upload a prebuilt frontend | if neither: build on your own PC (`npm run build`) and upload `.next/standalone` + `public/` |
| 4 | Cron jobs (one line, every minute) | “Cron Jobs” |
| 5 | Document root pointed at `backend/public`, and symlinks allowed (`storage:link`) — or FTP-upload `storage/app/public` contents into `public/storage` manually | Domains → Document Root |

Steps: upload the ZIP (or `git clone` in Terminal, if enabled) → in Terminal
(or the host’s Composer tool) run the §2 table → create the PostgreSQL DB and
put it in `.env` with `DB_CONNECTION=pgsql`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local` → point the domain at
`backend/public` → open `/install` in the browser → add the cron line.

**Why these are hard requirements, not preferences:** 9 migrations use
PostgreSQL-only `jsonb` (a MySQL import fails on the first one); the search
and theme systems rely on it at runtime too. The frontend is a Node.js server
(`standalone`), not static HTML — without Node on the host it cannot run.

---

## 6. FAQ

**Do I need Redis/MinIO/Supervisor?**
No. Defaults are database queue, file sessions, local disk. Add them only when
you outgrow one server.

**I only have MySQL. Will it work?**
No — see §5. PostgreSQL is mandatory.

**How big is the download?**
~8 MB source (ZIP ~2 MB). Dependencies (`vendor/` + `node_modules/`, ~500 MB)
are built on your machine, never downloaded as a blob.

**How do I know an update is safe?**
`php artisan pishdad:doctor --strict` before and after. Backups:
`php artisan backup:run` (database + uploads + manifest).
