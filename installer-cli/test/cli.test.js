'use strict';

// Unit tests for the installer CLI. Pure logic only — no network, no sudo,
// no processes. Run: npm test   (node --test test/)
const { describe, it } = require('node:test');
const assert = require('node:assert/strict');

const pure = require('../lib/pure.js');
const { run } = require('../lib/cli.js');

describe('cmpVersion', () => {
  it('compares dotted versions', () => {
    assert.equal(pure.cmpVersion('8.3.1', '8.2'), 1);
    assert.equal(pure.cmpVersion('8.2', '8.2.0'), 0);
    assert.equal(pure.cmpVersion('8.1.9', '8.2'), -1);
    assert.equal(pure.cmpVersion('22.1', '20'), 1);
  });

  it('ignores non-numeric tails (8.3.0-nts, v22)', () => {
    assert.equal(pure.cmpVersion('8.3.0-nts', '8.3'), 0);
    assert.equal(pure.cmpVersion('v22.14.0', '20'), 1);
  });
});

describe('parseVersion', () => {
  it('finds the first X.Y[.Z] in tool output', () => {
    assert.equal(pure.parseVersion('PHP 8.3.12 (cli)'), '8.3.12');
    assert.equal(pure.parseVersion('v22.14.0'), '22.14.0');
    assert.equal(pure.parseVersion('composer 2.8 (x64)'), '2.8');
    assert.equal(pure.parseVersion('no digits here'), null);
  });
});

describe('missingExtensions', () => {
  it('reports exactly what php -m lacks (case-insensitive)', () => {
    const out = 'Core\nPDO\npdo_pgsql\nSODIUM\nintl\n';
    assert.deepEqual(pure.missingExtensions(out, ['pdo_pgsql', 'sodium', 'gd']), ['gd']);
  });

  it('empty output means everything is missing', () => {
    assert.deepEqual(pure.missingExtensions('', ['zip']), ['zip']);
  });
});

describe('parseArgs', () => {
  it('defaults to installing ./Pishdad with everything on', () => {
    assert.deepEqual(pure.parseArgs(['install']), {
      command: 'install',
      dir: null,
      check: false,
      yes: false,
      frontend: true,
      serve: true,
      open: true,
      dockerContainer: null,
      native: false,
    });
  });

  it('understands flags and a positional dir', () => {
    const o = pure.parseArgs(['install', './mysite', '--check', '--no-frontend', '--no-open']);
    assert.equal(o.dir, './mysite');
    assert.equal(o.check, true);
    assert.equal(o.frontend, false);
    assert.equal(o.open, false);
    assert.equal(o.serve, true);
  });

  it('rejects unknown options instead of silently ignoring them', () => {
    assert.throws(() => pure.parseArgs(['install', '--frobnicate']), /unknown option/);
  });
});

describe('interpretYesNo', () => {
  it('understands fa/en yes/no and defaults', () => {
    assert.equal(pure.interpretYesNo('', true), true);
    assert.equal(pure.interpretYesNo('', false), false);
    for (const y of ['y', 'Y', 'yes', 'YES', 'بله', 'آره', '1']) assert.equal(pure.interpretYesNo(y), true, y);
    for (const n of ['n', 'N', 'no', 'NO', 'نه', 'خیر', '0']) assert.equal(pure.interpretYesNo(n), false, n);
  });

  it('returns null on garbage so the caller asks again', () => {
    assert.equal(pure.interpretYesNo('maybe'), null);
    assert.equal(pure.interpretYesNo('شاید'), null);
  });
});

describe('planForDeclined', () => {
  it('aborts on critical tools with a clear message', () => {
    for (const t of ['php', 'composer', 'postgres', 'git']) {
      const p = pure.planForDeclined(t);
      assert.equal(p.action, 'abort', t);
      assert.match(p.message, /cannot continue/);
    }
  });

  it('drops only the frontend when Node is declined', () => {
    const p = pure.planForDeclined('node');
    assert.equal(p.action, 'skip-frontend');
    assert.match(p.message, /BACKEND ONLY/);
  });
});

describe('cli wiring', () => {
  it('--help prints usage and exits 0 without touching the machine', async () => {
    let out = '';
    const real = process.stdout.write.bind(process.stdout);
    process.stdout.write = (s) => {
      out += s;
      return true;
    };
    try {
      const code = await run(['--help'], { execSteps: () => assert.fail('must not run anything') });
      assert.equal(code, 0);
      assert.match(out, /npx pishdad install/);
    } finally {
      process.stdout.write = real;
    }
  });

  it('an unknown command fails loudly, not silently', async () => {
    const code = await run(['frobnicate'], { execSteps: () => ({ ok: true }) });
    assert.equal(code, 2);
  });
});
