# pishdad â€” one-command installer

```bash
npx pishdad install [dir] [options]
```

Installs [Pishdad](https://github.com/ArmanOskouei/Pishdad) (Ù¾ÛŒØ´Ø¯Ø§Ø¯) â€”
backend + frontend â€” and opens the easy 6-step web installer in your browser.
Windows, macOS and Linux. Zero dependencies (Node.js builtins only).

## What one command does

1. **Finds PHP â€” everywhere, not just PATH.** Native `PATH`, well-known
   install folders (XAMPP/Laragon/winget dirs), then running Docker
   containers. Several found? You pick (Enter = default). Nothing usable?
   Only then is a download offered â€” and only with your explicit Yes.
2. **Other prerequisites** — Composer, PostgreSQL client, Node.js, git: offered one by one with the official command, installed on Yes, re-verified afterwards. No to a critical tool stops loudly; No to Node continues with backend only.
3. **Code** — `git clone --depth 1` (or `git pull --ff-only` if the folder already is a checkout).
4. **Backend** — `composer install --no-dev`, `.env` from the example (never overwritten), app key. With `--docker-container` (or a picked container) the same commands run via `docker exec -w /var/www/html` — after proving the checkout is mounted there.
5. **Frontend** -- 
5. **Frontend** — `npm ci` (skip with `--no-frontend`).
6. **Serve + open** — native mode starts the backend and opens `http://127.0.0.1:8080/install`; Docker mode starts nothing new and opens the same URL. The 6-step web installer finishes the job (database, migrate, superadmin, demo).

## Options

| Flag | Meaning |
|---|---|
| `--check` | only report what is missing, install nothing (safe to run anywhere) |
| (default) | ask **Yes/No per missing item**: shows the official install command, installs on Yes, re-verifies that exact tool; saying No to PHP/Composer/PostgreSQL/git stops with a clear message, saying No to Node continues with backend only |
| `--yes, -y` | install every missing prerequisite without asking |
| `--dir <path>` | install into `<path>` (default `./Pishdad`) |
| `--no-frontend` | backend only |
| `--no-serve` | stop after installing, don't start servers |
| `--no-open` | print the URL instead of opening a browser (servers/headless) |

```bash
npx pishdad install --check        # is this machine ready?
npx pishdad install ./mysite --yes # full auto install into ./mysite
```

## Notes

- PostgreSQL reachability is reported, not required up front: the web
  installer accepts any host (local server, Docker, remote).
- On a headless server there is no browser to open â€” the URL is printed and
  the installer is opened from your own machine (`http://<server>:8080/install`).
- Shared cPanel hosting without root cannot auto-install system packages; use
  the [cPanel guide](https://github.com/ArmanOskouei/Pishdad/blob/main/INSTALL.md)
  instead.
- Tests: `npm test` (pure logic, no network, no sudo).

## Publish a new version (maintainers)

```bash
npm login
npm publish --access public
```
