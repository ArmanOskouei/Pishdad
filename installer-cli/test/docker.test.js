'use strict';

// Tests for the E85 Docker-mode fixes: container composer counts, mount
// identity is confirmed, unusable containers fail loudly. Fully stubbed.
const { describe, it } = require('node:test');
const assert = require('node:assert/strict');

const cli = require('../lib/cli.js');

const GOOD_MODULES = 'Core\npdo_pgsql\nmbstring\nopenssl\nintl\nfileinfo\n';

/** Stub exec: only cms-app is a usable Pishdad container. */
function stubExec(calls) {
  return (cmd, args, opts) => {
    void opts;
    calls.push(`${cmd} ${(args || []).join(' ')}`);
    const a = (args || []).join(' ');
    if (cmd === 'docker' && a.includes('php') && a.includes('PHP_VERSION') && a.includes('cms-app')) {
      return { ok: true, out: 'PHP 8.5.11 (cli)' };
    }
    if (cmd === 'docker' && a.includes('cms-app') && a.includes(' -m')) return { ok: true, out: GOOD_MODULES };
    if (cmd === 'docker' && a.includes('artisan --version') && a.includes('cms-app')) {
      return { ok: true, out: 'Laravel Framework 13.x' };
    }
    if (cmd === 'docker' && a.includes('container inspect') && a.includes('cms-app')) {
      return { ok: true, out: JSON.stringify([{ Destination: '/var/www/html', Source: 'E:/srv/Pishdad/pishdad-core/backend/..' }]) };
    }
    return { ok: false, out: '' };
  };
}

describe('resolvePhpChoice --docker-container', () => {
  it('refuses a container without working PHP, loudly', async () => {
    const calls = [];
    const out = [];
    const real = process.stdout.write.bind(process.stdout);
    process.stdout.write = (s) => {
      out.push(s);
      return true;
    };
    try {
      const r = await cli.resolvePhpChoice(
        { dockerContainer: 'nope' },
        { exec: stubExec(calls) },
        'E:/srv/Pishdad',
      );
      assert.equal(r, false);
      assert.match(out.join(''), /no working PHP/);
    } finally {
      process.stdout.write = real;
    }
  });

  it('accepts a proven container after the human confirms the mount', async () => {
    const calls = [];
    const r = await cli.resolvePhpChoice(
      { dockerContainer: 'cms-app' },
      { exec: stubExec(calls), ask: () => true },
      'E:/srv/Pishdad',
    );
    assert.equal(r.kind, 'docker');
    assert.equal(r.ref, 'cms-app');
    assert.ok(calls.some((c) => c.includes('artisan --version')), 'must prove artisan answers');
    assert.ok(calls.some((c) => c.includes('container inspect')), 'must read mounts');
  });

  it('stops when the human says the mount is not their project', async () => {
    const calls = [];
    const r = await cli.resolvePhpChoice(
      { dockerContainer: 'cms-app' },
      { exec: stubExec(calls), ask: () => false },
      'E:/srv/Pishdad',
    );
    assert.equal(r, false);
    assert.ok(calls.some((c) => c.includes('container inspect')), 'must have asked about a real mount');
  });
});

describe('makeBeRunner', () => {
  it('prefixes docker exec in docker mode', () => {
    const calls = [];
    const r = cli.makeBeRunner(
      { dir: 'X', phpChoice: { kind: 'docker', ref: 'cms-app' } },
      { exec: stubExec(calls) },
    );
    assert.equal(r.mode, 'docker');
    assert.match(r.describe('composer install'), /docker exec .* cms-app composer install/);
    r.run('php', ['artisan', 'about']);
    assert.ok(calls.some((c) => c.includes('docker exec -w /var/www/html cms-app php artisan about')));
  });

  it('runs binaries directly in native mode', () => {
    const r = cli.makeBeRunner({ dir: 'X', phpChoice: null }, { exec: () => ({ ok: true }) });
    assert.equal(r.mode, 'native');
    assert.equal(r.describe('composer install'), '$ composer install');
  });
});
