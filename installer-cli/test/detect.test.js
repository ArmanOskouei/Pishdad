'use strict';

// Tests for exhaustive PHP discovery (E84). The machine is fully stubbed:
// io = { exists(), exec(), ps() }. Run: npm test
const { describe, it } = require('node:test');
const assert = require('node:assert/strict');

const detect = require('../lib/detect.js');
const pure = require('../lib/pure.js');

const FULL_MODULES = 'Core\npdo_pgsql\nsodium\nintl\nbcmath\nmbstring\nzip\ngd\nfileinfo\ncurl\nopenssl\n';

/** Stub io where `php` on PATH is old and broken, XAMPP is good. */
function stubIO() {
  return {
    exists: (p) => p === 'C:\\xampp\\php\\php.exe',
    exec: (cmd, args) => {
      const a = (args || []).join(' ');
      if (cmd === 'php' && a.includes('PHP_VERSION')) return { ok: true, out: 'PHP 8.1.9 (cli)' };
      if (cmd === 'php') return { ok: true, out: 'Core\n' };
      if (cmd === 'C:\\xampp\\php\\php.exe' && a.includes('PHP_VERSION')) return { ok: true, out: 'PHP 8.3.12 (cli)' };
      if (cmd === 'C:\\xampp\\php\\php.exe') return { ok: true, out: FULL_MODULES };
      if (cmd === 'docker' && a.includes('info')) return { ok: false, out: '' };
      return { ok: false, out: '' };
    },
    ps: () => [],
  };
}

describe('candidatePaths', () => {
  it('lists absolute paths per platform, never relative', () => {
    for (const p of ['win32', 'darwin', 'linux']) {
      const list = detect.candidatePaths(p, {});
      assert.ok(list.length > 0, p);
      for (const c of list) assert.match(c, /^([A-Z]:\\|\/)/, `${p}: ${c}`);
    }
  });
});

describe('discoverPhp', () => {
  it('skips the PATH php when too old and finds XAMPP instead', () => {
    const found = detect.discoverPhp(stubIO(), 'win32', {});
    assert.equal(found.length, 1);
    assert.equal(found[0].kind, 'file');
    assert.equal(found[0].ref, 'C:\\xampp\\php\\php.exe');
    assert.equal(found[0].version, '8.3.12');
    assert.deepEqual(found[0].missing, []);
  });

  it('returns nothing usable when extensions are missing', () => {
    const io = stubIO();
    io.exec = (cmd, args) => {
      if (String((args || []).join(' ')).includes('PHP_VERSION')) return { ok: true, out: 'PHP 8.3.0' };
      return { ok: true, out: 'Core\n' }; // no extensions at all
    };
    io.exists = () => true;
    io.ps = () => [];
    assert.deepEqual(detect.discoverPhp(io, 'linux', {}), []);
  });

  it('prefers an app container over a scheduler one as the default', () => {
    const io = {
      exists: () => false,
      exec: (cmd, args) => {
        const a = (args || []).join(' ');
        if (cmd === 'php') return { ok: false, out: '' };
        if (cmd === 'docker' && a === 'info') return { ok: true, out: 'Server Version: 25' };
        if (cmd === 'docker' && a.includes('PHP_VERSION')) return { ok: true, out: 'PHP 8.5.11 (cli)' };
        if (cmd === 'docker') return { ok: true, out: 'Core\npdo_pgsql\nmbstring\nopenssl\nintl\nfileinfo\n' };
        return { ok: false, out: '' };
      },
      ps: () => [{ name: 'cms-scheduler', image: 'x' }, { name: 'cms-app', image: 'x' }],
    };
    const found = detect.discoverPhp(io, 'win32', {});
    assert.deepEqual(found.map((f) => f.ref), ['cms-app', 'cms-scheduler']);
  });

  it('finds a working PHP inside a Docker container', () => {
    const io = {
      exists: () => false,
      exec: (cmd, args) => {
        const a = (args || []).join(' ');
        if (cmd === 'php') return { ok: false, out: '' };
        if (cmd === 'docker' && a === 'info') return { ok: true, out: 'Server Version: 25' };
        if (cmd === 'docker' && a.includes('cms-app') && a.includes('PHP_VERSION')) return { ok: true, out: 'PHP 8.5.11 (cli)' };
        if (cmd === 'docker' && a.includes('cms-app')) return { ok: true, out: FULL_MODULES };
        return { ok: false, out: '' };
      },
      ps: () => [{ name: 'cms-app', image: 'app:latest' }, { name: 'cms-db', image: 'postgres:16' }],
    };
    const found = detect.discoverPhp(io, 'win32', {});
    assert.equal(found.length, 1);
    assert.equal(found[0].kind, 'docker');
    assert.equal(found[0].ref, 'cms-app');
  });
});

describe('pickPhp', () => {
  it('empty means no usable PHP anywhere', () => {
    assert.equal(detect.pickPhp([]), null);
  });

  it('a single hit wins silently', () => {
    const only = { kind: 'file', ref: 'x', version: '8.3.0', missing: [] };
    assert.deepEqual(detect.pickPhp([only]), { choice: only, alternatives: [] });
  });

  it('several hits must go to the user, never auto-picked', () => {
    const a = { kind: 'path', ref: 'php', version: '8.3.0', missing: [] };
    const b = { kind: 'docker', ref: 'cms-app', version: '8.5.0', missing: [] };
    assert.deepEqual(detect.pickPhp([a, b]), { choice: null, alternatives: [a, b] });
  });
});

describe('parseArgs docker flags', () => {
  it('understands --docker-container and --native', () => {
    const o = pure.parseArgs(['install', '--docker-container', 'cms-app']);
    assert.equal(o.dockerContainer, 'cms-app');
    assert.equal(pure.parseArgs(['install', '--native']).native, true);
    assert.equal(pure.parseArgs(['install']).dockerContainer, null);
  });
});
