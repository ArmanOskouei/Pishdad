# pishdad — one-command installer

```bash
npx pishdad install [dir] [options]
```

Installs [Pishdad](https://github.com/ArmanOskouei/Pishdad) (پیشداد) —
backend + frontend — and opens the easy 6-step web installer in your browser.
Windows, macOS and Linux. Zero dependencies (Node.js builtins only).

## What one command does

1. **Prerequisites** — audits PHP (+10 extensions), Composer, PostgreSQL,
   Node.js and git. Missing something? Re-run with `--yes` to install it
   automatically (needs sudo/admin), or install by hand — the exact command
   is printed, never guessed.
2. **Code** — `git clone --depth 1` (or `git pull --ff-only` if the folder
   already is a checkout).
3. **Backend** — `composer install --no-dev`, `.env` from the example
   (never overwritten), app key.
4. **Frontend** — `npm ci` (skip with `--no-frontend`).
5. **Serve + open** — starts the backend and opens
   `http://127.0.0.1:8000/install`, where the web installer finishes the job
   (database, migrate, superadmin, demo content).

## Options

| Flag | Meaning |
|---|---|
| `--check` | only report what is missing, install nothing (safe to run anywhere) |
| `--yes, -y` | auto-install missing prerequisites |
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
- On a headless server there is no browser to open — the URL is printed and
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
