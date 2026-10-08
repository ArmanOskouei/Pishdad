'use strict';

// Exhaustive PHP discovery: PATH is only the first place to look.
// Order: (1) PATH → (2) well-known install locations → (3) Docker containers.
// The installer must NEVER download PHP while a usable one exists anywhere.
//
// All machine access goes through `io` so tests stub it:
//   io = { exists(p)->bool, exec(cmd,args,opts)->{ok,out}, ps()->[{name,image}] }

const { parseVersion, missingExtensions, cmpVersion } = require('./pure.js');

const DOCKER_WORKDIR = '/var/www/html';

/** Absolute file candidates per platform (fast `exists` check first). */
function candidatePaths(platform, env) {
  const home = (env && (env.ProgramFiles || env['ProgramFiles(x86)'])) || '';
  if (platform === 'win32') {
    return [
      'C:\\php\\php.exe',
      'C:\\tools\\php\\php.exe',
      'C:\\xampp\\php\\php.exe',
      'C:\\laragon\\bin\\php\\php.exe',
      home ? `${home}\\PHP\\php.exe` : null,
    ].filter(Boolean);
  }
  if (platform === 'darwin') {
    return [
      '/opt/homebrew/bin/php',
      '/usr/local/bin/php',
      '/Applications/XAMPP/bin/php',
      '/opt/php/bin/php',
    ];
  }
  return [
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/php/bin/php',
    '/snap/bin/php',
  ];
}

function probePhp(io, bin) {
  const v = io.exec(bin, ['-r', 'echo PHP_VERSION;'], {});
  const ver = parseVersion((v && v.out) || '');
  if (!v || !v.ok || !ver) return null;
  const mods = io.exec(bin, ['-m'], {});
  return { bin, version: ver, missing: missingExtensions((mods && mods.out) || '') };
}

function probeDockerPhp(io, container) {
  const v = io.exec('docker', ['exec', container, 'php', '-r', 'echo PHP_VERSION;'], {});
  const ver = parseVersion((v && v.out) || '');
  if (!v || !v.ok || !ver) return null;
  const mods = io.exec('docker', ['exec', container, 'php', '-m'], {});
  return { container, version: ver, missing: missingExtensions((mods && mods.out) || '') };
}

/**
 * Find every usable PHP on the machine, best first.
 * A PHP is "usable" when version >= 8.2 AND zero required extensions missing.
 */
function discoverPhp(io, platform, env) {
  const found = [];

  const p = probePhp(io, 'php');
  if (p) found.push({ kind: 'path', ref: 'php', ...p });

  for (const c of candidatePaths(platform, env)) {
    if (!io.exists(c)) continue;
    const r = probePhp(io, c);
    if (r) found.push({ kind: 'file', ref: c, ...r });
  }

  // Docker last: containers whose php probes cleanly.
  const dinfo = io.exec('docker', ['info'], {});
  if (dinfo && dinfo.ok) {
    const hits = [];
    for (const c of io.ps()) {
      const r = probeDockerPhp(io, c.name);
      if (r) hits.push({ kind: 'docker', ref: c.name, image: c.image, ...r });
    }
    // Default order matters (Enter = first): an `app`/`web` container serves
    // the project, a `scheduler`/`worker`/`db` one usually does not.
    hits.sort((a, b) => scoreDocker(a.ref) - scoreDocker(b.ref));
    found.push(...hits);
  }

  return found.filter((f) => cmpVersion(f.version, '8.2') >= 0 && f.missing.length === 0);
}

function scoreDocker(name) {
  const n = String(name).toLowerCase();
  if (/(^|[-_])app([-_]|$)/.test(n)) return 0;
  if (/(^|[-_])(web|www|php|backend)([-_]|$)/.test(n)) return 1;
  return 2;
}

function cmpMin(have, want) {
  const pa = String(have).split('.').map(Number);
  const pb = String(want).split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const a = pa[i] || 0;
    const b = pb[i] || 0;
    if (a !== b) return a > b;
  }
  return true;
}

/**
 * Choose one. Exactly one usable → it wins silently. Several → the caller
 * asks the user (never guess between native and Docker).
 */
function pickPhp(found) {
  if (found.length === 0) return null;
  if (found.length === 1) return { choice: found[0], alternatives: [] };
  return { choice: null, alternatives: found };
}

module.exports = { DOCKER_WORKDIR, candidatePaths, probePhp, probeDockerPhp, discoverPhp, pickPhp };
