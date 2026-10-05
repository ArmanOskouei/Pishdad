import assert from "node:assert/strict";
import { test } from "node:test";

/**
 * J3 — تشخیصِ بیلدِ مشتری از بیلدِ مرکزی.
 *
 * ⚠️ این تست محیط را دستکاری می‌کند، پس هر تستی که این ماژول را import کند
 * وضعیتِ `CMS_CLIENT_ONLY_BUILD` را به‌ارث می‌برد. برای همین هر تست قبل و بعدِ
 * دستکاری، مقدار را **برمی‌گرداند** — وگرنه تست‌های دیگر را می‌شکند.
 *
 * دلیلِ اینکه تست مهم است: بدون آن، یک روز کسی شرط را برعکس می‌نویسد و
 * پنلِ مرکزی به مشتری نشان داده می‌شود — یا برعکس، اپراتور فکر می‌کند نسخه‌اش خراب
 * است. هر دو فقط از طریق تست دیده می‌شوند.
 */

const KEY = "CMS_CLIENT_ONLY_BUILD";

const withEnv = <T>(value: string | undefined, fn: () => T): T => {
  const previous = process.env[KEY];
  if (value === undefined) delete process.env[KEY];
  else process.env[KEY] = value;
  try {
    return fn();
  } finally {
    if (previous === undefined) delete process.env[KEY];
    else process.env[KEY] = previous;
  }
};

// Dynamic import so the module reads `process.env` at call time, not load time.
const load = async () => await import("./build-flavour.ts");

test("a build without the flag is the central build", async () => {
  const { isClientOnlyBuild, buildFlavour } = await load();
  await withEnv(undefined, () => {
    assert.equal(isClientOnlyBuild(), false);
    assert.equal(buildFlavour(), "central");
  });
});

test("the flag is a string '1', not a truthy value", async () => {
  const { isClientOnlyBuild, buildFlavour } = await load();

  // Why this matters: in Docker the flag arrives as the STRING "1". A loose
  // check like `Boolean(process.env.X)` would also accept "0" and "false" —
  // both of which are strings Docker is perfectly happy to pass. So a
  // `--build-arg CLIENT_ONLY=0` build would silently claim to be client-only.
  await withEnv("1", () => {
    assert.equal(isClientOnlyBuild(), true);
    assert.equal(buildFlavour(), "client");
  });

  for (const falsyString of ["0", "false", "", "no", "  "]) {
    await withEnv(falsyString, () => {
      assert.equal(
        isClientOnlyBuild(),
        false,
        `"${falsyString}" نباید بیلدِ مشتری را فعال کند`,
      );
    });
  }
});

test("a client-only build explains why /central is missing", async () => {
  const { explainMissingCentralPanel } = await load();

  await withEnv("1", () => {
    const note = explainMissingCentralPanel();
    assert.ok(note, "بیلدِ مشتری باید دلیل را توضیح بدهد");
    assert.equal(note.code, "central_panel_not_in_this_build");
    assert.ok(note.title.length > 0);
    assert.ok(note.body.length > 0);
  });
});

test("a central build has nothing to explain — it owns /central", async () => {
  const { explainMissingCentralPanel } = await load();

  await withEnv(undefined, () => {
    // Fail-closed the other way: if this returned a note, a *central* build
    // would tell operators the panel "is not in this build" when it is. That is
    // the confusing inverse of the bug above.
    assert.equal(explainMissingCentralPanel(), null);
  });
});