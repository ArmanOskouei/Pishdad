import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

import { ADMIN_MENU, mergePluginMenu, localizeMenuGroups, menuLabel, menuGroupTitle, type MenuGroup, type MenuItem } from "../menu.ts";
import { fa } from "./fa.ts";
import { en } from "./en.ts";

/**
 * I1-b — رشته‌های فارسیِ پوستهٔ پنل باید از دیکشنری بیایند.
 *
 * این یکی از آن دسته‌ای نیست که «کار می‌کند ولی برمی‌گردد» — این یکی **نیمه**
 * برمی‌گردد: کسی یک رشتهٔ فارسی را در JSX اضافه می‌کند، فارسی‌ها درست دیده
 * می‌شوند و هیچ تستی قرمز نمی‌شود، تا وقتی که یک مدیر انگلیسی پنل را باز کند و
 * یک دکمهٔ تنها فارسی میان انگلیسی بایستد. پس نگهبان باید **خودِ متن** را
 * بسنجد، نه فقط وجودِ `useLang` را.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const read = (p: string) => readFileSync(resolve(HERE, p), "utf8");

/**
 * کامنت‌ها را **کامل** حذف کن، بعد خط‌به‌خط بسنج.
 *
 * چرا اول حذف و بعد خط‌بندی: `{/* … *\/}` در JSX چندخطی است و فقط خطِ اولش با
 * الگوی «خطی که با `/*` شروع می‌شود» شناخته می‌شود. بدون حذفِ کامل، نگهبان روی
 * متنِ فارسیِ خودِ مستنداتِ کد قرمز می‌شد — یعنی یک تست که به‌جای کد، توضیحِ
 * کد را نقد می‌کند و بعد از چند ماه کسی خاموشش می‌کند.
 */
const codeLines = (src: string): string[] =>
  src
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/^\s*\/\/.*$/gm, "")
    .split("\n");

/** رشتهٔ داخل یک attribute — جایی که فارسی یعنی «به صفحه‌خوان انگلیسی توضیح فارسی». */
const PERSIAN_ATTRIBUTE = /(?:aria-label|title|placeholder|alt)="[^"]*[\u0600-\u06FF][^"]*"/g;

const CHROME_FILES = [
  "../../components/layout/Shell.tsx",
  "../../components/layout/Sidebar.tsx",
  "../../components/layout/Topbar.tsx",
  "../../components/layout/BottomNav.tsx",
  "../../app/(client)/admin/settings/SettingsClient.tsx",
  "../../app/(client)/admin/appearance/page.tsx",
];

test("هیچ رشتهٔ فارسی در attributeهای دسترس‌پذیریِ پوستهٔ پنل نمانده", () => {
  // `aria-label`/`title`/`alt` بدترین جا برای رشتهٔ هاردکد است: متنِ قابل‌ترجمه
  // نیست و کاربر آن را نمی‌بیند، پس هیچ‌کس متوجهش نمی‌شود که ترجمه نشده.
  // همین تست یک رشتهٔ واقعی در `BottomNav.tsx` پیدا کرد.
  const offenders: string[] = [];
  for (const f of CHROME_FILES) {
    codeLines(read(f)).forEach((line, i) => {
      for (const m of line.matchAll(PERSIAN_ATTRIBUTE)) offenders.push(`${f}:${i + 1}  ${m[0]}`);
    });
  }
  assert.deepEqual(offenders, [], "این‌ها رشتهٔ فارسیِ هاردکد در attribute هستند — از `t()` بیاید:\n  " + offenders.join("\n  "));
});

test("هیچ متنِ نمایشیِ فارسی در این فایل‌ها بیرون از fallbackها نمانده", () => {
  // `DIRS` در صفحهٔ نمایش **عمداً** فارسی می‌ماند (نامِ جهت و برچسبِ رنگ هر دو
  // کلیدِ ترجمه دارند و فارسی‌شان fallback است). بقیهٔ خطوط نباید فارسی داشته باشند.
  const offenders: string[] = [];
  for (const f of CHROME_FILES) {
    codeLines(read(f)).forEach((line, i) => {
      if (!/[\u0600-\u06FF]/.test(line)) return;
      if (/id: "(sahar|amaliyat|arya|narm|divan)"/.test(line)) return; // ردیف‌های DIRS
      offenders.push(`${f}:${i + 1}  ${line.trim()}`);
    });
  }
  assert.deepEqual(offenders, [], "متنِ نمایشیِ فارسیِ هاردکد — از `t()` بیاید:\n  " + offenders.join("\n  "));
});

test("هر آیتمِ منوی هسته کلیدِ ترجمه دارد", () => {
  const missing: string[] = [];
  for (const g of ADMIN_MENU) {
    if (g.title && !g.titleKey) missing.push(`عنوانِ گروه «${g.title}»`);
    for (const it of g.items) if (!it.labelKey) missing.push(`آیتم «${it.label}» (${it.href})`);
  }
  assert.deepEqual(missing, [], "بدون `labelKey` این متن در زبانِ انگلیسی فارسی می‌ماند: " + missing.join("، "));
});

test("برچسبِ افزونه‌ها ترجمه نمی‌شود — زبانِ افزونه‌نویس است", () => {
  // عکسِ تست بالا. اگر روزی `labelKey` به اعلانِ افزونه تزریق شد، یعنی پروژه
  // دارد متنِ یک پلاگینِ شخص ثالث را ادعا می‌کند — و هر مدیری که فارسی
  // انتخاب کند، برچسبِ انگلیسیِ آن افزونه را می‌بیند.
  const out = mergePluginMenu(ADMIN_MENU, [
    { slug: "acme", key: "acme.blog", label: "Acme Blog", href: "/admin/acme" },
  ], ["perm:acme.view"]);
  const pluginGroup = out.groups.find((g) => g.items.some((i) => i.key === "acme.blog"))!;
  assert.ok(pluginGroup, "گروه افزونه باید ساخته شود.");
  for (const it of pluginGroup.items) assert.equal(it.labelKey, undefined, `آیتمِ افزونه «${it.key}» نباید labelKey داشته باشد.`);
  // عنوانِ گروه، برعکس، متعلق به هسته است.
  assert.equal(pluginGroup.titleKey, "menu.group.plugins");
});

test("ترجمه، فقط رشته را عوض می‌کند — ساختار و مسیر دست‌نخورده", () => {
  const faT = (k: keyof typeof fa) => fa[k];
  const enT = (k: keyof typeof fa) => en[k];

  const enOut = localizeMenuGroups(ADMIN_MENU, enT);
  assert.equal(enOut.length, ADMIN_MENU.length);
  assert.deepEqual(enOut.map((g) => g.items.map((i) => i.href)), ADMIN_MENU.map((g) => g.items.map((i) => i.href)));
  assert.deepEqual(enOut.map((g) => g.items.map((i) => i.key)), ADMIN_MENU.map((g) => g.items.map((i) => i.key)));
  assert.deepEqual(enOut.map((g) => g.items.map((i) => i.icon)), ADMIN_MENU.map((g) => g.items.map((i) => i.icon)));
  // و عنوانِ گروه هم ترجمه شده، نه فقط آیتم‌ها.
  assert.deepEqual(
    enOut.map((g) => g.title),
    ADMIN_MENU.map((g) => (g.titleKey ? en[g.titleKey] : g.title)),
  );

  // با ترجمهٔ `fa` خروجی باید **عیناً** متنِ اصلی باشد — یعنی فارسی، خودِ فارسی.
  // اگر یک روز کلیدی اشتباه نگاشت شود، همین‌جا دیده می‌شود.
  const faOut = localizeMenuGroups(ADMIN_MENU, faT);
  assert.deepEqual(
    faOut.flatMap((g) => g.items.map((i) => i.label)),
    ADMIN_MENU.flatMap((g) => g.items.map((i) => i.label)),
  );
  assert.deepEqual(
    faOut.map((g) => g.title),
    ADMIN_MENU.map((g) => g.title),
  );

  const item = ADMIN_MENU[0]!.items[0]!;
  assert.equal(menuLabel(item, enT), en[item.labelKey as keyof typeof en]);
  assert.equal(menuGroupTitle(ADMIN_MENU[1]!, enT), en["menu.group.manage"]);
});

test("آیتمِ بدون کلید، متنِ خودش را نگه می‌دارد (افزونه و هر مورد دستی)", () => {
  const custom: MenuItem = { href: "/admin/x", label: "X", icon: "▪", key: "x" };
  const group: MenuGroup = { title: "گروه", items: [custom] };
  const [out] = localizeMenuGroups([group], (() => "ترجمه") as never);
  assert.equal(out!.items[0]!.label, "X", "نباید دست بخورد.");
  assert.equal(out!.title, "گروه", "نباید دست بخورد.");
});