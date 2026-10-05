import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

import {
  DEFAULT_THEME_SLUG,
  THEME_MANIFESTS,
  isKnownTheme,
  resolveThemeManifest,
  themeSlugs,
} from "./manifests.ts";

/**
 * ECO1 — رجیستریِ قالب‌های کد‌محور.
 *
 * مهم‌ترین ادعا این است: **اسلاگِ ناشناخته هرگز صفحه را نمی‌شکند** و به قالبِ
 * پیش‌فرض می‌افتد. بدون این، یک مقدارِ کهنه در `settings.theme` (قالبِ حذف‌شده)
 * سایت را به صفحهٔ سفید می‌برد.
 */

const HERE = dirname(fileURLToPath(import.meta.url));

test("ECO1 — the three built-in themes are registered", () => {
  assert.deepEqual(themeSlugs().sort(), ["commerce", "editorial", "minimal"]);
  assert.ok(THEME_MANIFESTS.every((m) => m.slug && m.name && m.version));
  assert.ok(THEME_MANIFESTS.every((m) => m.slots.includes("main")));
  assert.ok(THEME_MANIFESTS.every((m) => m.modes.includes("light") && m.modes.includes("dark")));
});

test("ECO1 — a known slug resolves to itself", () => {
  for (const slug of themeSlugs()) {
    assert.equal(resolveThemeManifest(slug).slug, slug);
    assert.equal(isKnownTheme(slug), true);
  }
});

/**
 * ⭐ تستِ جهش‌یاب — اگر `resolveThemeManifest` به `BY_SLUG[slug] ?? default`
 * ساده شود، این تست قرمز می‌ماند.
 *
 * `"constructor"` روی یک آبجکتِ ساده `Object.prototype.constructor` (یک تابعِ
 * truthy) برمی‌گرداند، پس `??` آن را «پیدا شد» می‌بیند و fallback را دور می‌زند.
 * آن‌وقت مانیفستِ برگشتی `.slug` ندارد و سایت با خطای runtime می‌افتد.
 */
test("ECO1 — an unknown slug falls back to the default theme (prototype-safe)", () => {
  for (const bogus of ["nope", "constructor", "__proto__", "toString", ""]) {
    assert.equal(
      resolveThemeManifest(bogus).slug,
      DEFAULT_THEME_SLUG,
      `اسلاگِ «${bogus}» باید به قالب پیش‌فرض بیفتد.`,
    );
  }
  assert.equal(resolveThemeManifest(undefined).slug, DEFAULT_THEME_SLUG);
  assert.equal(resolveThemeManifest(null).slug, DEFAULT_THEME_SLUG);
  assert.equal(isKnownTheme("constructor"), false);
});

test("ECO1 — every theme.json matches the seeded layout tokens", () => {
  // منبعِ دادهٔ زندهٔ سایت `SiteThemeSeeder::THEMES.layout_tokens` است. اگر
  // theme.json از آن جدا شود، فرانت می‌گوید یک قالب است و بک‌اند چیز دیگری
  // می‌فرستد — بی‌صدا.
  const php = readFileSync(
    resolve(HERE, "../../../backend/database/seeders/SiteThemeSeeder.php"),
    "utf8",
  );

  for (const manifest of THEME_MANIFESTS) {
    const block = php.slice(php.indexOf(`'key' => '${manifest.slug}'`));
    assert.ok(block.length > 0, `قالب «${manifest.slug}» در seed پیدا نشد.`);

    for (const [role, value] of Object.entries(manifest.tokens)) {
      assert.ok(
        block.includes(`'${role}' => '${value}'`),
        `نقش «${role}» با مقدار «${value}» در seed قالب «${manifest.slug}» نیست.`,
      );
    }
  }
});
