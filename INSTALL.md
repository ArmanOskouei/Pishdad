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
packages (needs sudo/admin). PHP is searched everywhere first — `PATH`,
XAMPP/Laragon/winget folders, then running Docker containers (you pick when
several qualify; `--docker-container <name>` forces one). Details and flags:
`npx pishdad install --help`.

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
§3 (the web installer).

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

## 3. Installing — the one command, then the 6-step web installer

Installing has two halves. Only the second half asks about **your** setup:

| Half | Where | What it asks |
|---|---|---|
| **`npx pishdad install`** | terminal (needs only Node.js 18+) | a few questions, all about the **machine** — never about your site |
| **the web installer** | `http://127.0.0.1:8080/install` (or `:8000/install`) | the database, the admin account, and the site address |

```bash
cd pishdad-core/backend
php artisan serve                    # http://127.0.0.1:8000
# open http://127.0.0.1:8000/install
```

---

### 3A. `npx pishdad install` — every question, and the right answer

Run it in an empty folder, or pass the directory you want. It prints every
command before it runs it, and when something is wrong it stops with a plain
message instead of a stack trace.

```bash
npx pishdad install              # installs into ./Pishdad
npx pishdad install ./mysite    # installs into ./mysite
npx pishdad install --check     # report only: what is missing, install/ask nothing
npx pishdad install --help      # the full flag list
```

Five phases, in order — you only intervene in phase 1:

| Phase | What happens | What it will **not** do |
|---|---|---|
| 1/5 prerequisites | reports each requirement as `ok`/`MISS`; missing ones are offered one by one: show the official command → ask → install → **re-check that exact tool** | never install anything without a confirmed answer (unless `--yes`), never assume a re-check passed |
| 2/5 code | `git clone --depth 1` into the target dir — or `git pull --ff-only` if it is already a checkout | never clone over a folder that is not Pishdad (it aborts, saying why) |
| 3/5 backend | `composer install --no-dev --optimize-autoloader`, `.env` from `.env.example`, and an app key if none exists | **never** overwrite an existing `.env` (prints “kept as is”) |
| 4/5 frontend | `npm ci` in `pishdad-core/frontend` | skipped entirely with `--no-frontend`, and says so |
| 5/5 serve + open | starts `php artisan serve` on `127.0.0.1:8080`, waits up to 3 minutes for the API, then opens `http://127.0.0.1:8080/install` | never start a second server when it runs inside a Docker container (that container already serves) |

**Flags:**

| Flag | Effect |
|---|---|
| `--check` | report missing prerequisites and exit — nothing installed, nothing asked |
| `--yes`, `-y` | install every missing prerequisite without asking (needs sudo/admin) |
| `--dir <path>` or a bare path | install into that directory (default `./Pishdad`) |
| `--no-frontend` | skip the frontend entirely (backend only) |
| `--no-serve` | do not start the server at the end (prints the URL) |
| `--no-open` | do not open the browser |
| `--docker-container <name>` | run backend commands inside this container instead of a native PHP — the checkout must be mounted in it, which is **proven, not assumed** |
| `--native` | ignore Docker even when a container has a working PHP |

**The questions, exactly as they appear:**

| You see | When | What to answer | Why |
|---|---|---|---|
| `Install it now? [Y/n]` | a prerequisite is missing (PHP, Composer, PostgreSQL client, Node, git) | `Y` — or just press Enter | It offers the **official** command for your OS (`apt`, `dnf`, `brew`, `winget`…), installs it, then re-verifies that exact tool. A failed install stops the run with its output. |
| `Use [1-2]?` | more than one working PHP was found — e.g. a native PHP **and** a Docker container that has PHP | type the number of the PHP **this project** should use | Native PHP and a container are different machines; the installer refuses to guess between them. Default is the first one listed. |
| `Is that YOUR project (same code)? [Y/n]` | you picked (or forced) a Docker container | `Y` only if the printed host path is **this** checkout | A container can have PHP 8.3 while mounting a completely different folder; the install would run in the wrong place. `n` stops the run with the reason. |

Answer format: `y`, `yes`, `بله`, `آره`, `1`, `true` → yes · `n`, `no`, `نه`,
`خیر`, `0`, `false` → no · **empty answer takes the default in brackets** ·
anything else is asked again, politely.

When the questions are *not* asked:

- `--check` → nothing is installed, nothing is asked.
- `--yes` → every missing prerequisite is installed without asking.
- Non-interactive output (CI, piped logs) → it cannot ask, so it prints the exact
  commands for you to run by hand and exits with code 2.

**What each “no” costs:**

- `n` to **Node.js** → continues with **backend only**, and says so loudly: the
  public site will not run until Node is installed and the command is re-run.
- `n` to **PHP / Composer / PostgreSQL client / git** → the install aborts.
  Nothing is left half-built.

**One version trap:** the command-line check accepts PHP **8.2**, but the web
installer’s preflight (step 1) requires **8.3**. Install 8.3 and the question
never comes up — `composer.json` allows 8.2, the installer does not.

**When it succeeds** you are looking at `/install`. That is §3B.

---

### 3B. The web installer — every step, every question, the right answer

Order: `1 Requirements → 2 Database → prepare (key + migrations) → 5 Superadmin → 6 Finish → done`.

- **No step can be skipped.** An interrupted install resumes at the first
  incomplete step — just refresh the page; finished work is never redone.
- Steps 3 (app key) and 4 (migrations) are merged into one **prepare** page, so
  once the database is accepted there is exactly one more button before the
  admin account.
- Finishing writes `install.lock` and every `/install` route becomes **404
  forever** — a re-run of the wizard is impossible by design.

#### Step 1 — Requirements: no questions, one button

Read the table, then press **“Continue to database”**. If any row is red the
button stays disabled; each row names its own fix in the “Fix” column.

Two rows are **not defects** and must not be “fixed”:

| Row | What you see | Meaning |
|---|---|---|
| `APP_KEY` | “Key empty/invalid; it will be generated in the ‘app key’ step” | normal — created in step 3 |
| `Database connection` / `CREATE COLLATION privilege` | “pending” | normal — probed for real in step 2 |

Everything else is a real requirement: PHP 8.3+, `mbstring`, `openssl`,
`pdo_pgsql`, `intl`, `fileinfo`, a working `sodium` (native extension or the
bundled `paragonie/sodium_compat`), and write access to `storage/`,
`bootstrap/cache` and `.env`. The page carries a complete per-platform guide
(VPS, cPanel, DirectAdmin, Windows, macOS, Docker) with copy-ready commands.

#### Step 2 — Database: five fields (where most installs stop)

| Field | Default | What to enter |
|---|---|---|
| Host | `127.0.0.1` | see the table below |
| Port | `5432` | see the table below |
| Database name | `Pishdad` | `pishdad` locally; `cms` when the installer detects Docker |
| Username | `Pishdad` | the role that **owns** that database |
| Password | — | that role’s password (never echoed back) |

Exact values by environment:

| Environment | Host | Port | Database | Username | Password |
|---|---|---|---|---|---|
| Local install (Postgres on this machine) | `127.0.0.1` | `5432` | `pishdad` | `pishdad` | the one you chose |
| Docker — site **and** DB in compose | `db` (service name) | `5432` | `cms` | `cms` | `cmssecret` (the shipped compose default) |
| Docker — DB in a container, site on the host | `127.0.0.1` | the **published** port (left number in `-p 5435:5432`) | `cms` | `cms` | your `POSTGRES_PASSWORD` |
| Remote database server | its IP or domain (`db.example.com`) | `5432` | as created | as created | as created |
| Managed cloud (Supabase/Neon/Aiven/RDS) | host from the connection string | from the string (use the **Direct**/pooler-free address) | chunk after the last `/` | `USER` chunk | `PASSWORD` chunk |
| cPanel / shared | `localhost` or the host’s DB server | `5432` | full prefixed name, e.g. `user_pishdad` | full prefixed user | as set in the panel |

Rules the form enforces:

- Database name: **letters, digits, underscore only**, max 63 characters.
- The user must **own** the database: the installer probes a real
  `CREATE COLLATION`. Ownership is what grants it — `GRANT CREATE ON SCHEMA
  public` does not.
- On managed services the provided user deliberately does not own the database:
  create a fresh database and give the user ownership, or ask support to set
  `ALTER DATABASE "<db>" OWNER TO "<user>";`.

If you have no database yet, do not leave the page: it has a step-by-step guide
per OS (Windows / Linux / macOS / Docker / cloud), a live command generator
(type what you are about to create; every command updates as you type) and a
connection-string builder for panels and external tools.

Press **“Test connection & continue”**. What each failure means:

| Message | Cause | Fix |
|---|---|---|
| connection failed / could not reach | wrong host/port, Postgres not running, firewall | start the service; match the table above; `listen_addresses` + `pg_hba.conf` for a remote DB; open port 5432 for the site’s IP only |
| password authentication failed | wrong user or password | re-enter; the password is the one you set when creating the role |
| database “…” does not exist | not created | run the guide’s `CREATE DATABASE` first |
| permission denied for schema public | the user does not own the DB | `ALTER DATABASE "<db>" OWNER TO "<user>";` |
| leftover/duplicate tables | an earlier partial run | press **“Try again”** on the prepare page — it continues |

#### The “prepare” page — one button (steps 3 + 4 merged)

Press **“Start preparation & build the database”**. It runs both remaining jobs
automatically and shows the phases: generate the app key (written to `.env`),
create the tables (migrations), move on. It may take a few minutes — keep the
page open. If something fails you get a plain-language diagnosis plus the
server log excerpt **on the page**, and a **“Try again”** button — there is no
file to hunt for.

#### Step 5 — Superadmin: the account you will log in with

| Field | Rules | What to enter |
|---|---|---|
| Display name | required, ≤255, defaults to `Superadmin` | any label you like |
| Email | required, valid, **unique** | your real address — this is the login |
| Frontend site address | optional, must be a valid URL | see below |
| Password / Repeat password | required, **min 8 chars**, must match | a strong password — it is shown once on the final page, then gone |

The frontend address matters because this server runs **only the backend**; the
final page uses it to build the “open site” / “open panel” links:

| Situation | What to write |
|---|---|
| Local development | `http://localhost:3100` (wherever your frontend runs) |
| Production | `https://example.com` (the address visitors use) |
| Not decided yet | leave empty — set `FRONTEND_URL` in `.env` later |

There is no hidden or undeletable account: this is a normal account whose access
comes from role/permission, and any admin can enable two-factor from their own
profile. **The first login with this account forces two-factor (TOTP) setup** —
no admin API answers before that is done.

#### Step 6 — Finish: one button, and one-way

Review, press **“Finish installation”**. It writes `install.lock`; `/install`
and all install routes return 404 from then on. A genuinely fresh start means
deleting the lock, `.env` and the database by hand — the wizard will not do it.

#### The final page — save it now

- The admin password is shown **once** and disappears on refresh (change it any
  time from your profile).
- A recap sheet lists the database details and the admin login; use
  **Print / save as PDF** before leaving.
- Frontend setup: open `{frontend_url}/setup` and enter **only the backend
  address** — the installer writes the rest.

#### Cheat-sheet: what to type, per environment

| You are… | Host | Port | DB | User | Frontend URL |
|---|---|---|---|---|---|
| on your own PC | `127.0.0.1` | `5432` | `pishdad` | `pishdad` | `http://localhost:3100` |
| using the project’s Docker | `db` | `5432` | `cms` | `cms` | `http://localhost:3100` |
| on a VPS with a local DB | `127.0.0.1` | `5432` | your DB | its owner | `https://your-domain.com` |
| on shared hosting | panel’s DB host | `5432` | full prefixed name | full prefixed user | `https://your-domain.com` |
| on a managed cloud DB | provider host | from the string | from the string | from the string | `https://your-domain.com` |

---

### 3C. After the install: deploy script and cron

The demo content (8 published pages, header/footer, themes, a working form) is
seeded automatically, so your first login lands in a finished site rather than
an empty one.

Server deploy from the repo root (after `.env` is ready):

```bash
bash deploy/deploy.sh        # build → migrate → seal → outbox → doctor --strict → frontend build → verify
```

Add this cron line (queues, digests, scheduled publishing all tick through it):

```cron
* * * * * cd <root>/pishdad-core/backend && php artisan schedule:run
```

---

## 5. Updating (no re-download)

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

## 6. Shared hosting (cPanel): requirements and install

Possible — **if** the host gives you all five. If any one is missing, you need
a VPS instead; there is no workaround (see “why not” below).

| # | Requirement in cPanel | Where to check |
|---|---|---|
| 1 | PHP **8.3+** in “Select PHP Version”, with extensions `pdo_pgsql`, `intl`, `mbstring`, `zip`, `gd`, `sodium`, `bcmath`, `curl`, `fileinfo`, `openssl` | Select PHP Version → check every box |
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

## 7. FAQ

**Do I need Redis/MinIO/Supervisor?**
No. Defaults are database queue, file sessions, local disk. Add them only when
you outgrow one server.

**I only have MySQL. Will it work?**
No — see §6. PostgreSQL is mandatory.

**How big is the download?**
~8 MB source (ZIP ~2 MB). Dependencies (`vendor/` + `node_modules/`, ~500 MB)
are built on your machine, never downloaded as a blob.

**How do I know an update is safe?**
`php artisan pishdad:doctor --strict` before and after. Backups:
`php artisan backup:run` (database + uploads + manifest).
