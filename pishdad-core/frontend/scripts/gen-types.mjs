/**
 * Regenerate src/types/api.d.ts from the backend OpenAPI spec.
 *
 * Why this exists instead of a plain `openapi-typescript` call:
 *
 * `openapi-typescript@7.13.0` builds its output through TypeScript's compiler
 * API — `ts.factory.createKeywordTypeNode` and friends. TypeScript 7 is the Go
 * port (microsoft/typescript-go) and does not ship that JS API, so the tool
 * dies with:
 *
 *   TypeError: Cannot read properties of undefined (reading 'createKeywordTypeNode')
 *
 * Its peer range is still `typescript: ^5.x` and 7.13.0 is the newest release
 * that exists, so there is no version of the tool to upgrade to. Waiting is the
 * only real fix.
 *
 * Meanwhile the project itself is perfectly happy on TypeScript 7 — verified
 * here rather than assumed: `tsc --noEmit` and `next build` both pass with
 * zero errors, because the app only consumes the generated file and never
 * touches the compiler API.
 *
 * So this script runs the generator against a pinned TypeScript 5 without
 * disturbing the one the project builds with. The pinned copy is resolved into
 * a temp directory and removed afterwards, so it never lands in
 * node_modules or package-lock.json and cannot drift.
 */

import { execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

// Last 5.x line. The generator needs the JS compiler API that 7.x dropped.
const GENERATOR_TYPESCRIPT = "typescript@5.9.3";

const projectRoot = resolve(import.meta.dirname, "..");
const specPath = resolve(projectRoot, "../backend/openapi/openapi.yaml");
const outPath = resolve(projectRoot, "src/types/api.d.ts");

if (!existsSync(specPath)) {
  console.error(`gen:types — spec not found: ${specPath}`);
  process.exit(1);
}

const scratch = mkdtempSync(join(tmpdir(), "cms-gen-types-"));

// On Windows `npm` is a .cmd shim, and spawnSync without a shell cannot exec
// it — it fails with ENOENT. Point at the right binary per platform.
const npmBin = process.platform === "win32" ? "npm.cmd" : "npm";

try {
  console.log(`gen:types — installing ${GENERATOR_TYPESCRIPT} in a scratch dir...`);
  execFileSync(
    npmBin,
    [
      "install",
      "--no-save",
      "--no-audit",
      "--no-fund",
      "--prefix",
      scratch,
      GENERATOR_TYPESCRIPT,
      "openapi-typescript@7.13.0",
    ],
    { stdio: "inherit", shell: process.platform === "win32" },
  );

  // Run the generator from the scratch prefix so it resolves the pinned
  // TypeScript, while the project keeps its own on 7.x.
  const runner = join(scratch, "node_modules", "openapi-typescript", "bin", "cli.js");
  if (!existsSync(runner)) {
    throw new Error(`generator not found at ${runner}`);
  }

  console.log("gen:types — generating src/types/api.d.ts");
  execFileSync(
    process.execPath,
    [runner, specPath, "-o", outPath],
    { stdio: "inherit", cwd: projectRoot },
  );

  console.log("gen:types — done");
} finally {
  rmSync(scratch, { recursive: true, force: true });
}
