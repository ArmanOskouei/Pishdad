'use strict';

// Machine layer: processes, package managers, browser. Kept separate from
// pure.js so the logic stays unit-testable and every OS branch is explicit.

const { execFileSync, spawn } = require('node:child_process');
const os = require('node:os');
const fs = require('node:fs');
const path = require('node:path');
const { cmpVersion, parseVersion, missingExtensions } = require('./pure.js');

function run(cmd, args, opts = {}) {
  try {
    const out = execFileSync(cmd, args, {
      encoding: 'utf8',
      stdio: opts.silent ? 'pipe' : 'inherit',
      cwd: opts.cwd,
      timeout: opts.timeout || 10 * 60 * 1000,
    });
    return { ok: true, out: typeof out === 'string' ? out : '' };
  } catch (e) {
    const out = (e.stdout || '') + (e.stderr || '');
    return { ok: false, out: typeof out === 'string' ? out : String(e.message || e) };
  }
}

function which(cmd) {
  const r = run(process.platform === 'win32' ? 'where' : 'which', [cmd], { silent: true });
  return r.ok;
}

/** One-shot prerequisite installers per OS. Returns null when unsupported. */
function installCommand(tool) {
  const p = process.platform;
  const apt = {
    php: 'sudo apt update && sudo apt install -y php8.3 php8.3-fpm php8.3-pgsql php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath postgresql-16',
    composer:
      "php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php --install-dir=/usr/local/bin --filename=composer",
    node: 'curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs',
    git: 'sudo apt install -y git',
    postgres: 'sudo apt install -y postgresql-16',
  };
  const winget = {
    php: 'winget install PHP.PHP.8.3',
    composer: 'winget install Composer.Composer',
    node: 'winget install OpenJS.NodeJS.LTS',
    git: 'winget install Git.Git',
    postgres: 'winget install PostgreSQL.PostgreSQL.16',
  };
  const brew = {
    php: 'brew install php',
    composer: 'brew install composer',
    node: 'brew install node@22',
    git: 'brew install git',
    postgres: 'brew install postgresql@16 && brew services start postgresql@16',
  };
  // Only Debian/Ubuntu `apt` is automated: anything else prints the command.
  if (p === 'linux') return apt[tool] || null;
  if (p === 'win32') return winget[tool] || null;
  if (p === 'darwin') return brew[tool] || null;
  return null;
}

/** Full prerequisite audit. Never throws; every row is {name, ok, detail}. */
function audit() {
  const rows = [];
  const push = (name, ok, detail) => rows.push({ name, ok, detail });

  // PHP + extensions
  if (!which('php')) {
    push('PHP 8.2+', false, 'not found on PATH');
  } else {
    const v = run('php', ['-r', 'echo PHP_VERSION;'], { silent: true });
    const ver = parseVersion(v.out || '');
    if (!ver || cmpVersion(ver, '8.2') < 0) {
      push('PHP 8.2+', false, `found ${ver || 'unknown'} — 8.2+ required`);
    } else {
      const mods = run('php', ['-m'], { silent: true });
      const missing = missingExtensions(mods.out || '');
      push(
        'PHP 8.2+',
        missing.length === 0,
        missing.length === 0 ? `php ${ver}` : `php ${ver}, missing extensions: ${missing.join(', ')}`,
      );
    }
  }

  // Composer
  push('Composer 2', which('composer'), which('composer') ? '' : 'not found on PATH');

  // PostgreSQL server reachability (a client + a live server, not just the binary)
  if (!which('psql')) {
    push('PostgreSQL 14+', false, 'psql not found on PATH');
  } else {
    const r = run('psql', ['-h', 'localhost', '-U', 'postgres', '-c', 'SELECT 1'], { silent: true });
    push('PostgreSQL 14+', r.ok, r.ok ? 'reachable on localhost' : 'client present, no server on localhost (the web installer accepts any host)');
  }

  // Node (only needed to build/serve the frontend)
  if (!which('node')) {
    push('Node.js 22', false, 'not found on PATH');
  } else {
    const v = run('node', ['--version'], { silent: true });
    const ver = parseVersion(v.out || '');
    push('Node.js 22', !!ver && cmpVersion(ver, '20') >= 0, ver ? `node ${ver}` : 'unreadable version');
  }

  push('git', which('git'), which('git') ? '' : 'not found on PATH (needed to fetch the code)');

  return rows;
}

function openBrowser(url) {
  const p = process.platform;
  const cmd = p === 'win32' ? 'cmd' : p === 'darwin' ? 'open' : 'xdg-open';
  const args = p === 'win32' ? ['/c', 'start', '', url] : [url];
  try {
    const child = spawn(cmd, args, { detached: true, stdio: 'ignore' });
    child.unref();
    return true;
  } catch {
    return false;
  }
}

/** Start `proc` detached; returns the child. Caller owns its lifetime. */
function startDetached(cmd, args, cwd, logFile) {
  fs.mkdirSync(path.dirname(logFile), { recursive: true });
  const log = fs.openSync(logFile, 'a');
  const child = spawn(cmd, args, { cwd, detached: true, stdio: ['ignore', log, log] });
  child.unref();
  return child;
}

function waitHttp(url, seconds, fetchFn) {
  const get = fetchFn || ((u) => fetch(u, { signal: AbortSignal.timeout(4000) }));
  const tries = Math.max(1, Math.floor(seconds / 3));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  return (async () => {
    for (let i = 0; i < tries; i++) {
      try {
        const res = await get(url);
        if (res && (res.status === 200 || res.ok)) return true;
      } catch {
        /* not up yet */
      }
      await sleep(3000);
    }
    try {
      const res = await get(url);
      return !!(res && (res.status === 200 || res.ok));
    } catch {
      return false;
    }
  })();
}

module.exports = { run, which, installCommand, audit, openBrowser, startDetached, waitHttp };
