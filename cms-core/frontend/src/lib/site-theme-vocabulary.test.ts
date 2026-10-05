import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

/**
 * F4.1.C — نگهبان رابطهٔ CSS و دادهٔ قالب.
 *
 * ## چرا این تست اینجاست و نه در بک‌اند
 *
 * بک‌اند `SiteThemeResolver` نقش‌هایی مثل `primary` را به `--theme-primary`
 * تبدیل می‌کند، و `globals.css` باید آن‌ها را به `--primary` نگاشت کند. اگر این
 * نگاشت نباشد، قالب **بی‌سروصدا** رنگش را اعمال نمی‌کند — نه خطایی، نه لاگی،
 * نه چیزی در پنل.
 *
 * تستی که این را بپرسد باید **هر دو فایل** را ببیند. کانتینر بک‌اند درخت فرانت را
 * نمی‌بیند، پس چنین تستی فقط روی ماشین توسعه سبز می‌شود و در CI قرمز — یعنی
 * دقیقاً جایی که نباید باشد. اینجا هر دو در یک ریشه‌اند.
 *
 * ⚠️ منبعِ نقش‌ها، فایلِ PHP است — نه یک فهرستِ تکراری در فرانت. اگر اینجا
 * دوباره فهرست را بنویسیم، دو منبعِ حقیقت داریم و یکی از آن‌ها کهنه می‌شود.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const CSS = resolve(HERE, "../app/globals.css");
const PHP = resolve(HERE, "../../../backend/app/Services/Themes/SiteThemeResolver.php");

/**
 * کامنت‌ها را برمی‌دارد. بدون این کار، مستنداتِ CSS — که خودش نمونه‌هایی مثل
 * `var(--theme-x, var(--x))` می‌نویسد — به‌عنوان «نقشِ ناشناخته» شمرده می‌شوند
 * و تست دربارهٔ چیزی حرف می‌زند که اصلاً اجرا نمی‌شود.
 */
const readCss = () => readFileSync(CSS, "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
const readPhp = () => readFileSync(PHP, "utf8");

/** نقش‌ها را از خودِ PHP بیرون می‌کشد — تک‌منبعِ حقیقت. */
function rolesFromResolver(): string[] {
  const php = readPhp();
  const out: string[] = [];
  const re = /public const (?:COLOR_ROLES|LAYOUT_ROLES) = \[([\s\S]*?)\];/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(php)) !== null) {
    for (const r of m[1].matchAll(/'([a-z0-9-]+)'/g)) out.push(r[1]);
  }
  return out;
}

// ── منبعِ نقش‌ها قابل‌خواندن است؟ ────────────────────────────────────
test("the resolver's role lists are parseable", () => {
  const roles = rolesFromResolver();
  assert.ok(roles.length > 0, "هیچ نقشی از SiteThemeResolver خوانده نشد — الگو عوض شده؟");
});

// ── ⭐ هر نقشِ resolver باید در CSS نگاشت شده باشد ────────────────────
test("every resolver role is mapped in globals.css", () => {
  const css = readCss();
  const missing: string[] = [];

  for (const role of rolesFromResolver()) {
    // نگاشت در CSS به شکل `var(--theme-x, var(--x))` است، نه `--theme-x:` —
    // چون نگاشت باید *پیش‌فرض* باشد و مقدار ذخیره‌شده آن را بازنویسی کند.
    if (!css.includes(`var(--theme-${role},`)) missing.push(role);
  }

  assert.deepEqual(
    missing,
    [],
    `این نقش‌ها در globals.css نگاشت نشده‌اند و بی‌سروصدا بی‌اثر می‌مانند: ${missing.join("، ")}`,
  );
});

// ── و برعکس: CSS نباید نقشِ ناشناخته بخواند ─────────────────────────
test("globals.css reads no theme role the resolver does not know", () => {
  const known = new Set(rolesFromResolver());
  const read = new Set<string>();

  for (const m of readCss().matchAll(/var\(--theme-([a-z0-9-]+)/g)) read.add(m[1]);

  const unknown = [...read].filter((r) => !known.has(r));
  assert.deepEqual(unknown, [], `globals.css نقشِ ناشناخته می‌خواند: ${unknown.join("، ")}`);
});

// ── ⭐ `-soft`ها باید صریح باشند، نه محاسبه‌شده ───────────────────────
/**
 * همان تله‌ای که `F4.1.U` ثبت کرد: `--primary-soft` روی `:root` با
 * `color-mix()` از روی `--primary` حساب می‌شود. `color-mix` نمی‌تواند به یک
 * متغیرِ پویا ارجاع دهد، پس اگر قالب `--primary` را عوض کند ولی
 * `--primary-soft` را نه، رنگِ نرمِ دکمه‌ها با رنگِ خودِ دکمه ناهماهنگ می‌شود.
 */
test("the -soft tokens are explicitly remapped, never left to color-mix", () => {
  const css = readCss();

  for (const role of ["primary-soft", "accent-soft"]) {
    assert.match(
      css,
      new RegExp(`var\\(--theme-${role},\\s*var\\(`),
      `«${role}» باید صریح نگاشت شود — بازاعلامش را حذف نکنید.`,
    );
  }
});

// ── نگاشت باید داخل scope سایت باشد، نه سراسری ───────────────────────
/**
 * اگر نگاشت روی `:root` باشد، رنگِ قالبِ سایت روی **کل اپ** می‌افتد — یعنی
 * پنلِ ادمین هم رنگِ مشتری را می‌گیرد. `F4.1.W` این دو را جدا می‌خواهد.
 */
test("the mapping is scoped to the site, not applied globally", () => {
  const css = readCss();

  const rootBlock = css.slice(css.indexOf(":root"), css.indexOf("}", css.indexOf(":root")));
  assert.ok(
    !rootBlock.includes("--theme-primary"),
    "نگاشت نباید داخل `:root` باشد — رنگِ سایت نباید به کل اپ سرایت کند.",
  );

  assert.match(css, /\.site\s*\{[\s\S]*var\(--theme-primary,/, "نگاشت باید داخل scope سایت باشد.");
});

// ── ⭐ F0.8 — سایت نباید توکنِ خودش را از `:root` پنل ارث ببرد ──────────
/**
 * نسخهٔ قبلی `var(--theme-primary, var(--primary))` بود. آرگومانِ دوم همان
 * `--primary`ای بود که **روی همین عنصر هم** بازاعلام می‌شد، پس ارجاع **چرخه‌ای**
 * بود و مرورگر آن را «نامعتبر در زمان محاسبه» می‌گرفت و به ارث‌بری از `:root`
 * می‌افتاد. نتیجه: ظاهرِ سایتِ عمومی به تمِ پنلِ مدیریت قفل می‌شد.
 */
test("the site scope never falls back to a token of the same name", () => {
  const site = readCss().slice(readCss().indexOf(".site {"));
  // `--primary: var(--theme-primary, var(--primary))` ⇒ چرخه.
  const cyclic = /(--[a-z0-9-]+):\s*var\(--theme-\1\s*,/g;
  const bad: string[] = [];
  let m: RegExpExecArray | null;
  while ((m = cyclic.exec(site)) !== null) bad.push(m[1]!);
  assert.deepEqual(
    bad,
    [],
    `این نگاشت‌ها چرخه‌ای‌اند و به ارث‌بری از \`:root\` می‌افتند: ${bad.join("، ")}`,
  );
});

test("the site scope carries its own baseline instead of inheriting the panel's", () => {
  const css = readCss();
  const block = css.slice(css.indexOf(".site {"), css.indexOf("}", css.indexOf(".site {")));

  // پایه باید داخل خودِ scope سایت باشد، نه روی `:root`.
  assert.match(
    block,
    /--site-primary:\s*[^;]+;/,
    "سایت باید خطِ پایهٔ خودش را داشته باشد (`--site-*`).",
  );

  // و هر نقشِ نگاشت‌شده باید آرگومانِ دومش از خانوادهٔ `--site-` بیاید.
  for (const role of rolesFromResolver()) {
    const re = new RegExp(`var\\(--theme-${role},\\s*var\\((--site-[a-z0-9-]+)\\)`);
    const hit = re.exec(css);
    if (hit) {
      assert.ok(
        css.includes(`${hit[1]}:`),
        `«${hit[1]}» آرگومانِ دومِ نگاشت است ولی هیچ‌جا تعریف نشده — سایت از \`:root\` ارث می‌برد.`,
      );
    }
  }
});
