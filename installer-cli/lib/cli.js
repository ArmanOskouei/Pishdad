'use strict';

// Orchestration: parse args → check → fetch → install → serve → open.
// Every destructive/network step prints what it runs before running it.

const fs = require('node:fs');
const path = require('node:path');
const readline = require('node:readline');
const { REPO_URL, INSTALL_URL, parseArgs, helpText, interpretYesNo, planForDeclined } = require('./pure.js');
const { run: execRun, audit, installCommand, openBrowser, startDetached, waitHttp } = require('./steps.js');

const say = (s) => process.stdout.write(s + '\n');

function printAudit(rows) {
  for (const r of rows) {
    say(`  ${r.ok ? 'ok  ' : 'MISS'}  ${r.name}${r.detail ? ` — ${r.detail}` : ''}`);
  }
  return rows.every((r) => r.ok);
}

/** One Yes/No question. `asker` is injectable so tests never touch stdin. */
function askYesNo(question, def, asker) {
  if (asker) return Promise.resolve(asker(question, def));
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise((resolve) => {
    const loop = () => {
      rl.question(`${question} [${def ? 'Y/n' : 'y/N'}] `, (answer) => {
        const v = interpretYesNo(answer, def);
        if (v === null) {
          say('Please answer y/yes or n/no (بله/نه).');
          loop();
        } else {
          rl.close();
          resolve(v);
        }
      });
    };
    loop();
  });
}
const { DOCKER_WORKDIR, discoverPhp, pickPhp, probeDockerPhp } = require('./detect.js');

function describePhp(p) {
  if (p.kind === 'path') return `native PHP on PATH (php ${p.version})`;
  if (p.kind === 'file') return `native PHP at ${p.ref} (php ${p.version})`;
  return `Docker container '${p.ref}' (php ${p.version}${p.image ? `, image ${p.image}` : ''})`;
}

/** `docker ps` → [{name, image}]. Empty list when Docker is absent. */
function listContainers(execFn) {
  const run = execFn || execRun;
  const r = run('docker', ['ps', '--format', '{{.Names}}\t{{.Image}}'], { silent: true });
  if (!r.ok) return [];
  return r.out
    .split(/[\r\n]+/)
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => {
      const [name, ...rest] = l.split('\t');
      return { name, image: rest.join('\t') };
    })
    .filter((c) => c.name);
}

/**
 * E84 — resolve which PHP runs the backend, without ever guessing.
 *
 * Returns the choice ({kind, ref, version, ...}), null when none exists
 * (caller falls through to the download offer), or false when the user
 * declined / the forced container is unusable (message already printed).
 */
async function resolvePhpChoice(opts, deps, dir) {
  const X = (deps && deps.exec) || execRun;
  const io = {
    exists: fs.existsSync,
    exec: (cmd, args) => X(cmd, args, { silent: true }),
    ps: () => listContainers(X),
  };

  // Forced container: prove it, or fail loudly — never fall back silently.
  if (opts.dockerContainer) {
    const r = probeDockerPhp(io, opts.dockerContainer);
    if (!r) {
      say(`Container '${opts.dockerContainer}' has no working PHP 8.2+. Check the name with: docker ps`);
      return false;
    }
    const proven = await proveContainerCheckout(opts.dockerContainer, dir, deps);
    if (!proven) return false;
    return { kind: 'docker', ref: opts.dockerContainer, ...r };
  }

  let found = discoverPhp(io, process.platform, process.env);
  if (opts.native) found = found.filter((f) => f.kind !== 'docker');

  const picked = pickPhp(found);
  if (!picked) return null;
  if (picked.choice) {
    if (picked.choice.kind !== 'docker') return picked.choice;
    if (!(await proveContainerCheckout(picked.choice.ref, dir, deps))) return false;
    return picked.choice;
  }

  // Several usable PHPs: the user picks, default = first. Never guess
  // between native and Docker — they are different machines, really.
  say('\nSeveral working PHPs found:');
  picked.alternatives.forEach((f, i) => say(`  [${i + 1}] ${describePhp(f)}`));
  const idx = await askNumber(`Use [1-${picked.alternatives.length}]?`, 1, deps.askNumber);
  const sel = picked.alternatives[idx - 1] || picked.alternatives[0];
  say(`Using ${describePhp(sel)}.`);
  if (sel.kind === 'docker' && !(await proveContainerCheckout(sel.ref, dir, deps))) return false;
  return sel;
}

/** Pick a number (1-based). Injectable like askYesNo. */
function askNumber(question, def, asker) {
  if (asker) return Promise.resolve(asker(question, def));
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise((resolve) => {
    const loop = () => {
      rl.question(`${question} [${def}] `, (answer) => {
        const t = String(answer || '').trim();
        if (t === '') {
          rl.close();
          resolve(def);
          return;
        }
        const n = parseInt(t, 10);
        if (!Number.isInteger(n) || n < 1) {
          say('Please type a number from the list (or Enter for the default).');
          loop();
        } else {
          rl.close();
          resolve(n);
        }
      });
    };
    loop();
  });
}

/**
 * A Docker PHP is only usable when THIS checkout is mounted inside it.
 *
 * Two proofs, in order:
 *  1. `php artisan --version` answers from the backend workdir (it is a
 *     Laravel checkout at all).
 *  2. The human confirms the mount: we print the container's host source
 *     path for that workdir and ask "is this your project?". Host paths
 *     (E:\...) and VM paths (/run/desktop/mnt/...) can never be compared
 *     reliably by code, so this one stays a question — asked once.
 */
function proveContainerCheckout(container, dir, deps) {
  const X = (deps && deps.exec) || execRun;
  const r = X('docker', ['exec', '-w', DOCKER_WORKDIR, container, 'php', 'artisan', '--version'], { silent: true });
  if (!r.ok) {
    say(`Container '${container}' has PHP, but this project is not mounted in it (php artisan did not answer from ${DOCKER_WORKDIR}).`);
    say('Mount the checkout into the container (or run natively), then re-run.');
    return Promise.resolve(false);
  }
  let source = null;
  const ins = X('docker', ['container', 'inspect', '--format', '{{json .Mounts}}', container], { silent: true });
  if (ins.ok) {
    try {
      const mounts = JSON.parse(String(ins.out));
      const hit = (Array.isArray(mounts) ? mounts : []).find(
        (m) => m && (m.Destination === DOCKER_WORKDIR || String(m.Destination || '').startsWith(DOCKER_WORKDIR + '/')),
      );
      if (hit) source = hit.Source;
    } catch {
      source = null;
    }
  }
  say(`Container '${container}' serves a checkout mounted from: ${source || '(mount source unreadable)'}.`);
  say(`This install targets: ${dir}`);
  return askYesNo('Is that YOUR project (same code)?', true, deps && deps.ask).then((yes) => {
    if (!yes) {
      say('Stopped — point --docker-container at the container that mounts THIS project, then re-run.');
      return false;
    }
    return true;
  });
}

/**
 * Backend command runner. Native: direct binaries in the checkout dir.
 * Docker: same commands prefixed with `docker exec -w <workdir> <container>`.
 */
function makeBeRunner(opts, deps) {
  const X = (deps && deps.exec) || execRun;
  const be = path.resolve(opts.dir || 'Pishdad', 'pishdad-core', 'backend');
  if (opts.phpChoice && opts.phpChoice.kind === 'docker') {
    const c = opts.phpChoice.ref;
    return {
      mode: 'docker',
      describe: (s) => `$ docker exec -w ${DOCKER_WORKDIR} ${c} ${s}`,
      run: (tool, args) => X('docker', ['exec', '-w', DOCKER_WORKDIR, c, tool, ...args]),
    };
  }
  return {
    mode: 'native',
    describe: (s) => `$ ${s}`,
    run: (tool, args) => X(tool, args, { cwd: be }),
  };
}

/** Map a failed audit row to the tool key installCommand() understands. */
function toolFor(rowName) {
  if (rowName.startsWith('PHP')) return 'php';
  if (rowName.startsWith('Composer')) return 'composer';
  if (rowName.startsWith('PostgreSQL')) return 'postgres';
  if (rowName.startsWith('Node')) return 'node';
  if (rowName === 'git') return 'git';
  return null;
}

async function cmdInstall(opts, deps) {
  const { execSteps } = deps;
  const dir = path.resolve(opts.dir || 'Pishdad');

  say('== 1/5 prerequisites ==');
  let rows = audit();
  printAudit(rows);

  // E84 — exhaustive PHP resolution BEFORE anyone offers a download.
  // PATH is only the first place; a usable PHP in XAMPP/Laragon/winget-dir
  // or inside a Docker container must win over downloading a new one.
  const php = await resolvePhpChoice(opts, deps, dir);
  if (php === false) return 2; // user declined / unusable container, already told why
  const missing = rows.filter((r) => !r.ok && toolFor(r.name) && !(php && r.name.startsWith('PHP')));
  if (php) {
    opts.phpChoice = php;
    say(`\nPHP: using ${describePhp(php)} (verified: version + all extensions).`);
  }

  // E85 — in Docker mode the container is the machine: its composer counts,
  // and no host psql client is needed (migrations run via artisan inside,
  // the DB host itself is chosen later in the web installer).
  if (php && php.kind === 'docker') {
    const X = (deps && deps.exec) || execRun;
    const cr = X('docker', ['exec', '-w', DOCKER_WORKDIR, php.ref, 'composer', '--version'], { silent: true });
    if (cr.ok) {
      say(`Composer: using the container's composer (${String(cr.out).trim().split('\n')[0]}).`);
      const ci = missing.findIndex((r) => toolFor(r.name) === 'composer');
      if (ci > -1) missing.splice(ci, 1);
    } else {
      say(`Container '${php.ref}' has PHP but no composer — the host one will be used if present, else offered.`);
    }
    const pi = missing.findIndex((r) => toolFor(r.name) === 'postgres');
    if (pi > -1) {
      missing.splice(pi, 1);
      say('PostgreSQL client: not needed on the host in Docker mode (database host is set in the web installer).');
    }
  }
  if (opts.check) {
    say(missing.length === 0 ? '\nReady. Nothing to install.' : `\nMissing: ${missing.length} item(s). Re-run with --yes to install them (needs sudo/admin).`);
    return missing.length === 0 ? 0 : 2;
  }

  if (missing.length > 0) {
    const auto = opts.yes || false;
    const interactive = !auto && process.stdin.isTTY && !deps.noPrompt;
    if (!auto && !interactive) {
      say('\nMissing prerequisites (not a terminal — cannot ask). Install them by hand:');
      for (const m of missing) {
        const c = installCommand(toolFor(m.name));
        say(`  ${m.name}: ${c || '(no automatic installer for this OS — see INSTALL.md)'}`);
      }
      return 2;
    }
    for (const m of missing) {
      const tool = toolFor(m.name);
      const c = installCommand(tool);
      if (!c) {
        say(`No automatic installer for ${m.name} on this OS — install it by hand, then re-run.`);
        return 2;
      }
      let installIt = auto;
      if (!auto) {
        say(`\n${m.name} is missing (${m.detail || 'not found'}).`);
        say(`Official install command:\n  ${c}`);
        installIt = await askYesNo('Install it now?', true, deps.ask);
      }
      if (!installIt) {
        const plan = planForDeclined(tool);
        say(`\n${plan.message}`);
        if (plan.action === 'abort') return 2;
        opts.frontend = false; // Node declined: backend only, said loudly.
        continue;
      }
      say(`\n$ ${c}`);
      const r = execSteps(`sh:${c}`, () =>
        execRun(process.platform === 'win32' ? 'powershell' : 'sh', process.platform === 'win32' ? ['-NoProfile', '-Command', c] : ['-c', c]),
      );
      if (!r.ok) {
        say(`Failed to install ${m.name}. Fix it by hand, then re-run.`);
        return 2;
      }
      // The installer must prove its own work: re-check this exact tool now.
      const again = audit().find((x) => toolFor(x.name) === tool);
      if (!again || !again.ok) {
        say(`${m.name} still not usable after install (${(again && again.detail) || 'unknown'}). Fix it by hand, then re-run.`);
        return 2;
      }
      say(`${m.name}: installed and verified.`);
    }
    say('\n== re-checking everything ==');
    rows = audit();
    printAudit(rows);
    if (rows.some((r) => !r.ok && toolFor(r.name))) {
      say('Still missing prerequisites. Install the rest by hand, then re-run.');
      return 2;
    }
  }

  say('\n== 2/5 code ==');
  if (fs.existsSync(path.join(dir, '.git'))) {
    say(`$ git -C ${dir} pull --ff-only`);
    if (!execSteps('git-pull', () => execRun('git', ['-C', dir, 'pull', '--ff-only'])).ok) return 1;
  } else {
    fs.mkdirSync(dir, { recursive: true });
    say(`$ git clone --depth 1 ${REPO_URL} ${dir}`);
    if (!execSteps('git-clone', () => execRun('git', ['clone', '--depth', '1', REPO_URL, dir])).ok) return 1;
  }

  const be = path.join(dir, 'pishdad-core', 'backend');
  const fe = path.join(dir, 'pishdad-core', 'frontend');
  if (!fs.existsSync(path.join(be, 'artisan'))) {
    say(`Expected a Pishdad checkout at ${dir} (no pishdad-core/backend/artisan). Aborting.`);
    return 1;
  }

  // E84 — the backend runner: native binaries, or a Docker container that
  // already has this checkout mounted (proven below, never assumed).
  // `runBe('composer', [...])` / `runBe('php', [...])` hide the difference.
  const beRunner = makeBeRunner(opts, deps);
  say('\n== 3/5 backend ==');
  say(beRunner.describe('composer install --no-dev --optimize-autoloader'));
  if (!execSteps('composer', () => beRunner.run('composer', ['install', '--no-dev', '--optimize-autoloader'])).ok) return 1;

  const envFile = path.join(be, '.env');
  if (!fs.existsSync(envFile)) {
    fs.copyFileSync(path.join(be, '.env.example'), envFile);
    say('created .env from .env.example (edit DB_* before /install if Postgres is not local defaults)');
  } else {
    say('.env exists — kept as is (never overwritten)');
  }
  say('backend key step (only if empty)');
  execSteps('keygen', () => beRunner.run('php', ['artisan', 'key:generate']));

  if (opts.frontend) {
    say('\n== 4/5 frontend ==');
    say('$ npm ci');
    if (!execSteps('npm-ci', () => execRun('npm', ['ci'], { cwd: fe })).ok) return 1;
  } else {
    say('\n== 4/5 frontend skipped (--no-frontend) ==');
  }

  if (!opts.serve) {
    say('\nDone (servers not started: --no-serve).');
    say(`Start the backend yourself and open ${INSTALL_URL}`);
    return 0;
  }

  say('\n== 5/5 serve + open ==');
  const dockerMode = !!(opts.phpChoice && opts.phpChoice.kind === 'docker');
  if (!dockerMode) {
    const logDir = path.join(dir, '.local', 'logs');
    say('$ php artisan serve --host=127.0.0.1 --port=8080  (background, log: .local/logs/)');
    startDetached('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8080'], be, path.join(logDir, 'backend.log'));
  } else {
    // The container already serves the backend (that is why it exists);
    // starting a second server would only fight over the port.
    say(`Docker mode: container '${opts.phpChoice.ref}' already serves the backend — no new server started.`);
  }

  say('waiting for the API (up to 3 min)…');
  if (!(await waitHttp('http://127.0.0.1:8080/api/v1/site/status', 180))) {
    say('The backend did not answer. See .local/logs/backend.log, then open the URL by hand.');
    say(INSTALL_URL);
    return 1;
  }
  say('Backend is up.');

  if (opts.open) {
    say(`opening ${INSTALL_URL}`);
    if (!openBrowser(INSTALL_URL)) say(`Could not open a browser (headless?). Open it yourself:\n${INSTALL_URL}`);
  } else {
    say(`Open it yourself:\n${INSTALL_URL}`);
  }

  say('\nThe 6-step web installer finishes the job: database → migrate → superadmin → done.');
  say('Your data and demo content are seeded automatically at the end.');
  return 0;
}

async function run(argv, deps = {}) {
  const execSteps = deps.execSteps || ((_label, fn) => fn());
  let opts;
  try {
    opts = parseArgs(argv);
  } catch (e) {
    say(e.message);
    return 2;
  }
  if (!opts.command || opts.command === 'install') return cmdInstall(opts, { execSteps });
  if (opts.command === 'help') {
    say(helpText());
    return 0;
  }
  say(`unknown command: ${opts.command}\n\n${helpText()}`);
  return 2;
}

module.exports = { run, resolvePhpChoice, makeBeRunner, proveContainerCheckout };
