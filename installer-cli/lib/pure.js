'use strict';

// Pure helpers: no I/O, no platform calls — fully unit-tested.
// Everything that touches the machine lives in steps.js.

const REPO_URL = 'https://github.com/ArmanOskouei/Pishdad.git';

const INSTALL_URL = 'http://127.0.0.1:8080/install';

const REQUIRED_PHP_EXTENSIONS = [
  'pdo_pgsql',
  'sodium',
  'intl',
  'bcmath',
  'mbstring',
  'zip',
  'gd',
  'fileinfo',
  'curl',
  'openssl',
];

/**
 * Compare dotted versions: cmpVersion('8.2.1', '8.2') >= 0.
 * Non-numeric tails ('8.3.0-nts') are ignored.
 */
function cmpVersion(have, want) {
  const norm = (v) =>
    String(v)
      .replace(/^[^0-9]+/, '')
      .split(/[^0-9.]/)[0]
      .split('.')
      .map((n) => parseInt(n, 10) || 0);
  const a = norm(have);
  const b = norm(want);
  const n = Math.max(a.length, b.length);
  for (let i = 0; i < n; i++) {
    const x = a[i] || 0;
    const y = b[i] || 0;
    if (x !== y) return x < y ? -1 : 1;
  }
  return 0;
}

/** First `X.Y[.Z]` inside `php -v` / `node --version` output. */
function parseVersion(output) {
  const m = String(output).match(/(\d+)\.(\d+)(?:\.(\d+))?/);
  if (!m) return null;
  return m[3] !== undefined ? `${m[1]}.${m[2]}.${m[3]}` : `${m[1]}.${m[2]}`;
}

/** Missing extensions from a `php -m` listing. */
function missingExtensions(phpModulesOutput, required = REQUIRED_PHP_EXTENSIONS) {
  const have = new Set(
    String(phpModulesOutput)
      .split(/[\r\n]+/)
      .map((s) => s.trim().toLowerCase())
      .filter(Boolean),
  );
  return required.filter((ext) => !have.has(ext.toLowerCase()));
}

function parseArgs(argv) {
  const opts = {
    command: null,
    dir: null,
    check: false,
    yes: false,
    frontend: true,
    serve: true,
    open: true,
  };
  const rest = [...argv];
  if (rest[0] && !rest[0].startsWith('-')) opts.command = rest.shift();
  for (let i = 0; i < rest.length; i++) {
    const a = rest[i];
    if (a === '--check') opts.check = true;
    else if (a === '--help' || a === '-h') opts.command = 'help';
    else if (a === '--yes' || a === '-y') opts.yes = true;
    else if (a === '--no-frontend') opts.frontend = false;
    else if (a === '--no-serve') opts.serve = false;
    else if (a === '--no-open') opts.open = false;
    else if (a === '--dir' && rest[i + 1]) opts.dir = rest[++i];
    else if (!a.startsWith('-') && !opts.dir) opts.dir = a;
    else throw new Error(`unknown option: ${a}\nSee: pishdad install --help`);
  }
  return opts;
}

/**
 * Interprets one Yes/No answer. Empty answer means the default.
 * Accepts fa/en variants; anything else returns null (ask again).
 */
function interpretYesNo(answer, defaultYes = true) {
  const a = String(answer || '').trim().toLowerCase();
  if (a === '') return defaultYes;
  if (['y', 'yes', 'بله', 'بلی', 'آره', '1', 'true'].includes(a)) return true;
  if (['n', 'no', 'نه', 'خیر', '0', 'false'].includes(a)) return false;
  return null;
}

/**
 * What happens when the user declines a prerequisite.
 * Critical tools stop the install (the backend cannot be built without
 * them); Node only drops the frontend, everything else continues.
 */
function planForDeclined(tool) {
  if (tool === 'node') {
    return {
      action: 'skip-frontend',
      message:
        'Without Node.js the frontend cannot be installed. Continuing with BACKEND ONLY — the site will not run until you install Node and re-run.',
    };
  }
  const names = { php: 'PHP', composer: 'Composer', postgres: 'PostgreSQL', git: 'git' };
  return {
    action: 'abort',
    message: `Without ${names[tool] || tool} the install cannot continue. Install it and re-run (see INSTALL.md).`,
  };
}

function helpText() {
  return `pishdad — one-command installer for Pishdad (پیشداد)

Usage:
  npx pishdad install [dir] [options]

What it does:
  1. checks prerequisites (PHP, Composer, PostgreSQL client, Node, git)
     — missing ones are offered one by one: show the official install
     command, ask Yes/No, install, and re-verify that exact tool.
  2. clones ${REPO_URL} (or updates it)
  3. composer install + .env + app key        (backend)
  4. npm ci                                   (frontend, unless --no-frontend)
  5. serves the backend and opens ${INSTALL_URL}
     where the 6-step web installer finishes the job
     (database, migrate, superadmin, demo content).

Options:
  --check          only report what is missing, install nothing
  --yes, -y        install every missing prerequisite without asking
                   (default without --yes: ask Yes/No per item in a terminal;
                   declining PHP/Composer/PostgreSQL/git stops the install,
                   declining Node continues with backend only)
  --dir <path>     install into <path> (default: ./Pishdad)
  --no-frontend    skip the frontend entirely
  --no-serve       do not start servers at the end
  --no-open        do not open the browser (prints the URL instead)

Examples:
  npx pishdad install
  npx pishdad install --check
  npx pishdad install ./mysite --yes
`;
}

module.exports = {
  REPO_URL,
  INSTALL_URL,
  REQUIRED_PHP_EXTENSIONS,
  cmpVersion,
  parseVersion,
  missingExtensions,
  parseArgs,
  helpText,
  interpretYesNo,
  planForDeclined,
};
