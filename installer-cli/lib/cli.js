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

  const missing = rows.filter((r) => !r.ok && toolFor(r.name));
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

  say('\n== 3/5 backend ==');
  say('$ composer install --no-dev --optimize-autoloader');
  if (!execSteps('composer', () => execRun('composer', ['install', '--no-dev', '--optimize-autoloader'], { cwd: be })).ok) return 1;

  const envFile = path.join(be, '.env');
  if (!fs.existsSync(envFile)) {
    fs.copyFileSync(path.join(be, '.env.example'), envFile);
    say('created .env from .env.example (edit DB_* before /install if Postgres is not local defaults)');
  } else {
    say('.env exists — kept as is (never overwritten)');
  }
  say('$ php artisan key:generate (only if empty)');
  execSteps('keygen', () => execRun('php', ['artisan', 'key:generate'], { cwd: be }));

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
  const logDir = path.join(dir, '.local', 'logs');
  say('$ php artisan serve --host=127.0.0.1 --port=8080  (background, log: .local/logs/)');
  startDetached('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8080'], be, path.join(logDir, 'backend.log'));

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

module.exports = { run };
