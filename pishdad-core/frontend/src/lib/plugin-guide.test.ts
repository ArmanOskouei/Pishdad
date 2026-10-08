import { strict as assert } from "node:assert";
import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, "..", "..", "..", "..");

const PUBLIC = join(REPO, "pishdad-core", "frontend", "public");
const DOCS = join(REPO, "docs");
const COMPONENTS = join(REPO, "pishdad-core", "frontend", "src", "components");

const md5 = (buf: Buffer) => createHash("md5").update(buf).digest("hex");

/**
 * E65 — راهنمای توسعهٔ افزونه در `/admin/plugins` از فایل‌های Markdown **سروشده**
 * می‌آید (`public/plugin-guide.{fa,en}.md`)، و منبعِ حقیقتِ محتوا در `docs/` است.
 *
 * دو نسخه داشتن یک حقیقت یعنی امکانِ واگرایی: کسی سندِ `docs/` را به‌روز می‌کند و
 * فراموش می‌کند کپیِ سروشده را بزند ⇒ کاربرِ پنل راهنمای کهنه می‌بیند. هیچ‌جای
 * دیگری این را نمی‌بیند (بستهٔ فرانت `docs/` را ندارد)، پس همان‌جای `content-images`
 * اینجا هم بررسی می‌شود.
 */
const PAIRS = [
  { served: "plugin-guide.fa.md", source: "PLUGIN-GUIDE.md", lang: "fa" },
  { served: "plugin-guide.en.md", source: "PLUGIN-GUIDE.en.md", lang: "en" },
] as const;

test("نسخهٔ سروشدهٔ راهنمای افزونه با سندِ docs بایت‌به‌بایت یکی است", () => {
  for (const { served, source } of PAIRS) {
    const servedPath = join(PUBLIC, served);
    const sourcePath = join(DOCS, source);

    assert.ok(existsSync(servedPath), `فایلِ سروشده گم است: ${served} — از ${source} کپی کن`);
    assert.ok(existsSync(sourcePath), `سندِ مرجع گم است: ${source}`);

    assert.equal(
      md5(readFileSync(servedPath)),
      md5(readFileSync(sourcePath)),
      `${served} با ${source} یکی نیست — دوباره کپی کن`,
    );
  }
});

/**
 * کمینهٔ محتوایی که کاربر برای E65 خواسته: مانیفست، امضای Ed25519،
 * schema-per-plugin، چرخهٔ بازبینی، و یک مثالِ کاملِ کد.
 */
test("راهنما موضوع‌های لازم را پوشش می‌دهد (هر دو زبان)", () => {
  const required: Record<string, string[]> = {
    fa: ["manifest.json", "Ed25519", "schema", "بازبینی", "امضا"],
    en: ["manifest.json", "Ed25519", "schema", "review", "signature"],
  };

  for (const { served, lang } of PAIRS) {
    const text = readFileSync(join(PUBLIC, served), "utf8");
    for (const token of required[lang]!) {
      assert.ok(text.includes(token), `«${token}» در ${served} نیست`);
    }
    // مثالِ کاملِ کد (بلوکِ \`\`\` جفت‌شده) — دست‌کم چند بلوک.
    const fences = text.match(/^```/gm)?.length ?? 0;
    assert.ok(fences >= 4 && fences % 2 === 0, `${served} بلوکِ کدِ کاملِ جفت‌شده ندارد (${fences} حصار)`);
  }
});

/** سیم‌کشیِ UI: کامپوننت باید هر دو سند را بشناسد و صفحهٔ افزونه‌ها نوعِ plugin را بدهد. */
test("کامپوننت راهنما نوعِ plugin را می‌شناسد و صفحهٔ افزونه‌ها آن را می‌سازد", () => {
  const component = readFileSync(join(COMPONENTS, "ThemeDeveloperGuide.tsx"), "utf8");
  assert.match(component, /plugin:\s*\{\s*path:\s*"\/plugin-guide"/);

  const guide = readFileSync(join(COMPONENTS, "DeveloperGuide.tsx"), "utf8");
  assert.match(guide, /<MarkdownDeveloperGuide\s+kind="plugin"\s*\/>/);
});
