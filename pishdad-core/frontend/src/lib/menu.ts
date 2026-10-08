/** رجیستری منوی شل مشترک (پورت مفهومی از دمو layout.js) — F3 کامل. */

// پسوند `.ts` عمدی است: `npm test` با `node --test` فایل‌های TS را بدون
// resolver اجرا می‌کند و specifier نسبیِ بدون پسوند resolve نمی‌شود —
// همان محدودیتی که `block-type.ts` و `widget-type.ts` را بی‌import نگه داشته.
// `tsconfig.json:22` هم `allowImportingTsExtensions` را روشن کرده است.
import { isPluginAdminPath } from "./plugin-admin-path.ts";
import type { MessageKey } from "./i18n/fa.ts";

// I1-b — `import type` عمداً است: `npm test` فایل‌های TS را با `node --test`
// بدون resolver اجرا می‌کند و یک import مقداری (نه نوعی) اینجا لازم می‌شد.
// نوع‌ها کاملاً پاک می‌شوند، پس چیزی برای resolve کردن باقی نمی‌ماند.

export type MenuItem = {
  href: string;
  /** فارسیِ ثابت. برای افزونه‌ها تنها راه است و fallback همیشه هست. */
  label: string;
  icon: string;
  key: string;
  /**
   * I1-b — کلیدِ ترجمه برای آیتم‌های **هسته**.
   *
   * چرا کنار `label` و نه جای آن: `label` رشتهٔ نمایشی است و در سه جای دیگر هم
   * مصرف می‌شود (ad-hoc، بدون context زبان). حذفش یعنی هر مصرف‌کنندهٔ بی‌خبر
   * `undefined` می‌گیرد و `t()` هم کلید خام را نشان می‌دهد. پس `label` می‌ماند و
   * `labelKey` فقط می‌گوید «اگر زبان دیکشنری دارد، از ترجمه بخوان».
   *
   * آیتم‌های افزونه `labelKey` ندارند و **نباید** داشته باشند: برچسب، زبانِ
   * خودِ افزونه‌نویس است و ترجمه‌کردنش یعنی ادعای مالکیت روی متنِ او.
   */
  labelKey?: MessageKey;
  /**
   * ⭐ اسلاگِ افزونه‌ای که این آیتم **متعلق به اوست**.
   *
   * ## چرا این فیلد هست
   *
   * یک آیتمی که در `ADMIN_MENU` **هاردکد** است، از نگاه فیلتر «عضو هسته» است
   * — و هیچ کش یا پاک‌سازی‌ای نمی‌تواند آن را بردارد، حتی وقتی افزونه‌ای که
   * صفحه‌اش را اداره می‌کند غیرفعال یا حذف شده باشد.
   *
   * منوی ثابت نمی‌تواند خودش وضعیت افزونه‌ها را بداند (هسته نباید به جدول
   * پلاگین‌ها وابسته باشد)، پس فقط **اعلام** می‌کند و `filterMenuByPlugins`
   * تصمیم می‌گیرد.
   *
   * نبودِ این فیلد یعنی «آیتمِ خودِ هسته» — که همیشه نمایش داده می‌شود.
   */
  requiresPlugin?: string;
  /** آیتم‌های تودرتو (زیرمنو). یک سطح — نه درخت بازگشتی. */
  children?: MenuItem[];
};
/**
 * `pluginsAfter` یک نشانهٔ **ترتیب** است، نه هویت: یعنی «گروه افزونه باید بعد
 * از این گروه بنشیند».
 *
 * از این به بعد کاملاً عمومی است و از اول هم منطقِ خاصی نداشت — فقط همین یک
 * جای‌گذاری را رمز می‌کرد. نام عمومی یعنی هسته از وجود افزونهٔ خاصی چیزی
 * نمی‌داند.
 */
export type MenuGroup = { title?: string; titleKey?: MessageKey; pluginsAfter?: boolean; items: MenuItem[] };

export const ADMIN_MENU: MenuGroup[] = [
  {
    items: [
      { href: "/admin/dashboard", label: "داشبورد", icon: "▦", key: "dashboard", labelKey: "menu.dashboard" },
      { href: "/admin/analytics", label: "تحلیل سایت", icon: "◔", key: "analytics", labelKey: "menu.analytics" },
      { href: "/admin/search-report", label: "گزارش جستجو", icon: "⌕", key: "search-report", labelKey: "menu.searchReport" },
      { href: "/admin/pages", label: "صفحات", icon: "▤", key: "pages", labelKey: "menu.pages" },
      { href: "/admin/forms", label: "فرم‌ساز", icon: "⌸", key: "forms", labelKey: "menu.forms" },
      { href: "/admin/media", label: "فایل‌ها", icon: "🗎", key: "media", labelKey: "menu.media" },
      { href: "/admin/tickets", label: "تیکت‌ها", icon: "✉", key: "tickets", labelKey: "menu.tickets" },
    ],
  },
  {
    title: "مدیریت",
    titleKey: "menu.group.manage",
    items: [
      { href: "/admin/managers", label: "مدیران و نقش‌ها", icon: "◍", key: "managers", labelKey: "menu.managers" },
      // F4.2.F — صندوق به‌عنوان آیتمِ منو، نه فقط زنگِ هدر. زنگ ۸ ردیف آخر را
      // نشان می‌دهد؛ اگر آیتمِ منو نبود، رسیدن به صندوقِ کامل و ترجیحاتش فقط با
      // تایپِ آدرس ممکن بود.
      { href: "/admin/notifications", label: "اعلان‌ها", icon: "◔", key: "notifications", labelKey: "menu.notifications" },
      { href: "/admin/profile", label: "پروفایل من", icon: "◌", key: "profile", labelKey: "menu.profile" },
    ],
  },
  {
    title: "ظاهر و محتوا",
    titleKey: "menu.group.appearance",
    items: [
      { href: "/admin/header-footer", label: "هدر و فوتر", icon: "☰", key: "header-footer", labelKey: "menu.headerFooter" },
      { href: "/admin/blocks", label: "بلوک‌های سایت", icon: "▦", key: "blocks", labelKey: "menu.blocks" },
      { href: "/admin/themes", label: "قالب سایت", icon: "◫", key: "themes", labelKey: "menu.themes" },
      { href: "/admin/appearance", label: "قالب پنل", icon: "◐", key: "appearance", labelKey: "menu.appearance" },
      {
        href: "/admin/plugins",
        label: "پلاگین‌ها",
        icon: "⬡",
        key: "plugins",
        labelKey: "menu.plugins",
        children: [
          {
            href: "/admin/plugins/developers",
            label: "مستندات توسعه‌دهندگان",
            icon: "❯",
            key: "plugin-developers",
            labelKey: "devdocs.title",
          },
          {
            href: "/admin/store",
            label: "فروشگاه",
            icon: "🛒",
            key: "store",
            labelKey: "menu.store",
          },
        ],
      },
    ],
  },
  {
    title: "تنظیمات",
    titleKey: "menu.group.settings",
    items: [
      { href: "/admin/settings", label: "تنظیمات سایت", icon: "⚙", key: "settings", labelKey: "menu.settings" },
      { href: "/admin/socials", label: "شبکه‌های اجتماعی", icon: "🔗", key: "socials", labelKey: "menu.socials" },
    ],
  },
  // ⚠️ گروه `مرکزی` قبلاً اینجا سخت‌کد بود و L-B12 آن را برد.
  //
  // نکتهٔ مهم: «هسته» گروهِ **افزونه‌ای** را نمی‌سازد — خودِ افزونه از رجیستری
  // می‌آید. ولی هسته می‌تواند آیتم‌هایی داشته باشد که *متعلق به* یک افزونه‌اند؛
  // آن‌ها با `requiresPlugin` علامت می‌خورند تا با غیرفعال‌شدنِ افزونه، از منو
  // بروند (به‌جای اینکه به صفحه‌ای لینک بدهند که دیگر وجود ندارد).
];

export const BOTTOM_NAV_DEFAULTS = [
  "/admin/dashboard",
  "/admin/pages",
  "/admin/media",
  "/admin/tickets",
  "/admin/header-footer",
  "/admin/settings",
] as const;

export const BOTTOM_NAV_OPTIONS: MenuItem[] = ADMIN_MENU
  .flatMap((group) => group.items)
  .flatMap((item) => [item, ...(item.children ?? [])])
  .filter((item) => item.href.startsWith("/admin/"));

/* ═══════════════════════════════════════════════════════════════════════
 * I1-b — ترجمهٔ منو، در همان جایی که منو ساخته می‌شود
 * ═══════════════════════════════════════════════════════════════════════
 *
 * چرا این‌جا و نه در `Sidebar`/`Topbar`: هر دو، `groups` را از props می‌گیرند
 * و **عمداً** هیچ دانشی از زبان ندارند. اگر ترجمه در آن‌ها بود، breadcrumb
 * (`crumbsForPath`) همچنان فارسی می‌ماند، چون crumb را از همان `label`
 * می‌سازد — یعنی نیمی از پنل انگلیسی و نیمی فارسی.
 *
 * پس یک بار، درست بعد از ورود داده: `localizeMenuGroups` همان گروه‌ها را با
 * `label`/`title` ترجمه‌شده برمی‌گرداند و **بقیهٔ پشته هیچ تغییری نمی‌بیند**
 * (`Sidebar`، `Topbar`، `BottomNav` و `crumbs.ts` دست‌نخورده).
 *
 * توابع **خالص‌اند** و کلیدِ ناشناخته هرگز مدخل نمی‌سازد: نبودِ `labelKey` یعنی
 * «متنِ خودش را نشان بده» (افزونه‌ها، و هر آیتمِ افزودهٔ دستی).
 */

/** همان امضای `t`، بدون `values` — منو هیچ پارامتری ندارد. */
export type Translate = (key: MessageKey) => string;

/** برچسبِ نهاییِ یک آیتم: ترجمه اگر کلید دارد، وگرنه متنِ خودش. */
export function menuLabel(item: MenuItem, t: Translate): string {
  return item.labelKey ? t(item.labelKey) : item.label;
}

/** عنوانِ نهاییِ یک گروه. `undefined` یعنی گروه بی‌عنوان (بخش اول منو). */
export function menuGroupTitle(group: MenuGroup, t: Translate): string | undefined {
  return group.titleKey ? t(group.titleKey) : group.title;
}

/**
 * کلِ منو را به زبانِ جاری می‌برد. آیتم‌های افزونه `labelKey` ندارند و دست‌نخورده
 * می‌مانند — برچسبِ افزونه زبانِ خودِ افزونه‌نویس است.
 */
export function localizeMenuGroups(groups: MenuGroup[], t: Translate): MenuGroup[] {
  return groups.map((g) => {
    const next: MenuGroup = {
      ...g,
      items: g.items.map((it) => {
        const item: MenuItem = it.labelKey ? { ...it, label: menuLabel(it, t) } : it;
        if (it.children) {
          item.children = it.children.map((c) => (c.labelKey ? { ...c, label: menuLabel(c, t) } : c));
        }
        return item;
      }),
    };
    const title = menuGroupTitle(g, t);
    if (title !== undefined) next.title = title;
    return next;
  });
}

/* ═══════════════════════════════════════════════════════════════════════
 * ساختارِ منو فقط از هسته و افزونه‌ها می‌آید
 * ═══════════════════════════════════════════════════════════════════════
 *
 * هیچ بخشی از ساختارِ پنل‌های بیرونی نباید در بایندِ بیلدِ هسته بنشیند؛ وگرنه
 * حتی وقتی آن صفحه‌ها build نمی‌شوند، مسیرهایشان در خروجی دیده می‌شوند و
 * ساختارِ چیزی که منتشر نشده افشا می‌شود.
 *
 * نشانهٔ `pluginsAfter` روی `MenuGroup` هم عمومی است: از اول هم منطقِ خاصی
 * نبود، فقط یک نشانهٔ ترتیب بود («گروه افزونه پیش از این گروه بنشیند»).
 */

/* ═══════════════════════════════════════════════════════════════════════
 * K6.1 — ادغام منوی افزونه‌ها (`admin.menu`) روی منوی هسته
 * ═══════════════════════════════════════════════════════════════════════
 *
 * چرا این‌جا و نه فقط در بک‌اند: منوی پنل یک `const` هاردکد بود
 * (`menu.ts:6`، و `docs/TASKS.md:296` همین را ثبت کرده) ⇒ افزونه‌ای که
 * دقیقاً طبق قرارداد `panel.extensions: [{ point: "admin.menu" }]` می‌نوشت
 * بسته‌اش سبز می‌گرفت و **هیچ اتفاقی نمی‌افتاد** (`docs/TASKS.md:288`).
 *
 * سه قیدی که این ادغام باید نگه دارد و قبلاً هیچ کدی نگه نمی‌داشت:
 *
 *  ۱) **برخورد `key` قطعی حل شود، نه last-write-wins بی‌صدا.**
 *     `admin.menu.key` عمداً قاعدهٔ `no_core_name` ندارد
 *     (`PluginPackageContract.php:208`: «key اینجا کلید آیتم منوست، نه نوع
 *     ویجت») ⇒ یک افزونه می‌تواند `key: "dashboard"` اعلام کند و با آیتم
 *     هسته (`menu.ts:15`) برخورد کند. هسته برنده است — همان اولویتی که
 *     `ManifestRegistry::permissionModules()` (`:132-136`) و
 *     `widgetSchemas()` (`:190-194`) برای هسته در برابر افزونه دارند.
 *     مهم‌تر: برنده باید **قطعی** باشد. ترتیب ردیف‌هایی که بک‌اند می‌فرستد
 *     ترتیب تضمین‌شده‌ای نیست (`ManifestRegistry::activeManifests():345-349`
 *     یک `pluck` بدون `orderBy` است) ⇒ اگر «هرکس آخر آمد» مبنا باشد، دو
 *     مدیر در دو روز منوی متفاوت می‌بینند. پس ترتیب کلّی را خودمان از روی
 *     داده می‌سازیم (تابع `compareDeclarations`).
 *
 *  ۲) **آیتمی که `permission` مدیر جاری را ندارد دیده نشود** — و
 *     fail-closed: فهرست پرمیشن خالی یعنی هیچ آیتمِ پرمیشن‌داری نباید
 *     دیده شود، نه اینکه «احتمالاً همه را داریم» فرض شود.
 *
 *  ۳) **`href` بیرون از `/admin/` حذف شود.** این قید از قبل در الگوی
 *     قرارداد (`PluginPackageContract.php:217`) و در `is_path` (`:1208`)
 *     نوشته شده بود و K6.6 آن را به کد آورد — `plugin-admin-path.ts`.
 *     اینجا **همان تابع** صدا زده می‌شود، نه نسخهٔ سوم. اگر روزی قاعدهٔ
 *     `/admin/` عوض شود، یک جا عوض می‌شود و این ادغام هم‌زمان عوض می‌شود.
 *
 * قاعدهٔ چهارمی که خودمان اضافه کردیم و در قرارداد نیامده: **برخورد
 * `href`** هم باید برخورد بدهد. `Shell.tsx:13-15` مسیر جاری را با تطبیق
 * `href` به crumb تبدیل می‌کند، پس دو آیتم با `href` یکسان یعنی crumb
 * مبهم — و یک افزونه می‌توانست crumb یک صفحهٔ هسته را بدزدد. هسته در
 * `seenHrefs` کاشته می‌شود پس `/admin/pages` برای افزونه هم قابل اعلان
 * نیست.
 */

/** عنوان گروهی که آیتم‌های افزونه در آن می‌نشینند. */
export const PLUGIN_MENU_GROUP_TITLE = "افزونه‌ها";

/** آیکون پیش‌فرض وقتی افزونه `icon` نداده (`icon` در قرارداد اختیاری است). */
const PLUGIN_MENU_DEFAULT_ICON = "◆";

/**
 * جایگاه آیتمی که `order` نداده — عمداً بالای سقف قرارداد
 * (`PluginPackageContract.php:219`، بیشینهٔ 999) است تا «بی‌ترتیب» از
 * «بیان‌شده» قابل تشخیص بماند و این دو یکی نشوند.
 */
const PLUGIN_MENU_UNORDERED = 9999;

/** یک آیتم منوی اعلام‌شده از سوی یک افزونه. */
export type PluginMenuDeclaration = {
  /** اسلاگ افزونه — فضای‌نام برخوردها و جزء ترتیب قطعی. */
  slug: string;
  key: string;
  label: string;
  href: string;
  icon?: string;
  order?: number;
  permission?: string;
};

/** دروازه‌ای که آیتم را رد کرد. ترتیب مقادیر = ترتیب اجرای دروازه‌هاست. */
export type PluginMenuRejectReason =
  | "shape"
  | "href"
  | "permission"
  | "key"
  | "href_duplicate";

export type PluginMenuRejection = {
  slug: string;
  key: string;
  reason: PluginMenuRejectReason;
  detail: string;
};

export type PluginMenuMerge = {
  /** منوی نهایی. اگر هیچ آیتمی پذیرفته نشود، همان `groups` ورودی برمی‌گردد. */
  groups: MenuGroup[];
  accepted: MenuItem[];
  /**
   * هر آیتمِ رد‌شده با دلیلش — دقیقاً همان کاری که
   * `ManifestRegistry::collisions()` (`:50-65`) می‌کند. بدون این، «رد شدن»
   * فقط یک `continue` بی‌صدا بود و افزونه نصب می‌شد ولی هرگز دیده نمی‌شد.
   */
  rejected: PluginMenuRejection[];
};

type Normalized = { decl: PluginMenuDeclaration; index: number };

function isFilledString(value: unknown): value is string {
  return typeof value === "string" && value.trim() !== "";
}

/**
 * نرمال‌سازی یک اعلان خام. دادهٔ این تابع از شبکه می‌آید و هیچ فرضی
 * دربارهٔ شکلش نباید داشت: اعلان ناقص باید **رد** شود، نه اینکه رندر را
 * بترکاند یا با `undefined` یک آیتم نیم‌بند بسازد.
 */
function normalizeDeclaration(raw: unknown, index: number): Normalized | PluginMenuRejection {
  const source = (raw === null || typeof raw !== "object" ? {} : raw) as Record<string, unknown>;
  const bail = (detail: string): PluginMenuRejection => ({
    slug: isFilledString(source.slug) ? source.slug.trim() : "",
    key: isFilledString(source.key) ? source.key.trim() : "",
    reason: "shape",
    detail,
  });

  if (raw === null || typeof raw !== "object" || Array.isArray(raw)) {
    return bail("اعلان باید یک آبجکت باشد.");
  }
  for (const field of ["slug", "key", "label", "href"] as const) {
    if (!isFilledString(source[field])) return bail(`فیلد «${field}» رشتهٔ غیرخالی نیست.`);
  }
  if (source.icon !== undefined && typeof source.icon !== "string") {
    return bail("فیلد «icon» باید رشته باشد.");
  }
  if (source.order !== undefined && (typeof source.order !== "number" || !Number.isInteger(source.order))) {
    return bail("فیلد «order» باید عدد صحیح باشد.");
  }
  if (source.permission !== undefined && !isFilledString(source.permission)) {
    return bail("فیلد «permission» باید رشتهٔ غیرخالی باشد.");
  }

  // narrowingِ حلقهٔ بالا به این نقطه منتقل نمی‌شود (نوعِ `unknown` باقی می‌ماند)،
  // پس مقدارهای تأییدشده صریح بیرون کشیده می‌شوند — بدون `as unknown as string`.
  const slug = source.slug as string;
  const key = source.key as string;
  const label = source.label as string;
  const href = source.href as string;

  const decl: PluginMenuDeclaration = {
    slug: slug.trim(),
    key: key.trim(),
    label: label.trim(),
    href: href.trim(),
  };
  if (typeof source.icon === "string") decl.icon = source.icon;
  if (typeof source.order === "number") decl.order = source.order;
  if (isFilledString(source.permission)) decl.permission = source.permission.trim();

  return { decl, index };
}

/**
 * ترتیب کلّی: `order` سپس `slug` سپس `key` سپس `href` سپس جایگاه اولیه.
 *
 * `slug + key` معمولاً یکتاست ولی قرارداد تکرار `key` در یک مانیفست را
 * ممنوع نکرده، پس دو مؤلفهٔ آخر لازم‌اند تا comparator یک **ترتیب کلّی**
 * باشد و نتیجه به ترتیب ورودیِ فید وابسته نشود.
 */
function compareDeclarations(a: Normalized, b: Normalized): number {
  const ao = a.decl.order ?? PLUGIN_MENU_UNORDERED;
  const bo = b.decl.order ?? PLUGIN_MENU_UNORDERED;
  if (ao !== bo) return ao - bo;
  if (a.decl.slug !== b.decl.slug) return a.decl.slug < b.decl.slug ? -1 : 1;
  if (a.decl.key !== b.decl.key) return a.decl.key < b.decl.key ? -1 : 1;
  if (a.decl.href !== b.decl.href) return a.decl.href < b.decl.href ? -1 : 1;
  return a.index - b.index;
}

/**
 * K6.1 — منوی هسته + آیتم‌های اعلام‌شدهٔ `admin.menu`.
 *
 * تابع **خالص** است: خروجی فقط تابعِ *مجموعهٔ* اعلان‌هاست، نه ترتیب آن‌ها.
 * برای همین فهرست ردّها در هر مرحله هم قطعی می‌ماند.
 *
 * @param groups            منوی هسته (بدون تغییرِ محلی برگردانده می‌شود).
 * @param declarations      آیتم‌های اعلام‌شده. خالی یعنی «هیچ» — نه «همه».
 * @param managerPermissions پرمیشن‌های مدیر جاری. **خالی یعنی هیچ**؛ دروازهٔ
 *                           `permission` عمداً fail-closed است.
 */
export function mergePluginMenu(
  groups: MenuGroup[],
  declarations: readonly PluginMenuDeclaration[] = [],
  managerPermissions: readonly string[] = [],
): PluginMenuMerge {
  const rejected: PluginMenuRejection[] = [];

  // ۱) دروازهٔ شکل — تنها مرحله‌ای که به ترتیب فید وابسته است، چون هنوز
  //    چیزی برای مرتب‌سازی نداریم. بقیهٔ مراحل زیر روی فهرست مرتب‌شده اجرا
  //    می‌شوند و در نتیجه ردّهایشان هم قطعی است.
  const shaped: Normalized[] = [];
  const raw: unknown[] = Array.isArray(declarations) ? (declarations as unknown[]) : [];
  for (const [i, entry] of raw.entries()) {
    const n = normalizeDeclaration(entry, i);
    if ("reason" in n) rejected.push(n);
    else shaped.push(n);
  }

  // ۲) ترتیب قطعی — پیش از دروازه‌ها، تا «برندهٔ برخورد» و «ترتیب نمایش»
  //    یک قاعده باشند: هرکس اول است هم می‌برد هم اول دیده می‌شود.
  shaped.sort(compareDeclarations);

  // ۳) دروازهٔ `/admin/` — تنها منبع حقیقت، `plugin-admin-path.ts` (K6.6).
  // ۴) دروازهٔ پرمیشن — fail-closed.
  const granted = new Set(managerPermissions);
  const kept: Normalized[] = [];
  for (const n of shaped) {
    if (!isPluginAdminPath(n.decl.href)) {
      rejected.push({
        slug: n.decl.slug,
        key: n.decl.key,
        reason: "href",
        detail: `مسیر «${n.decl.href}» زیر /admin/ نیست یا شکل ناامنی دارد.`,
      });
      continue;
    }
    if (n.decl.permission !== undefined && !granted.has(n.decl.permission)) {
      rejected.push({
        slug: n.decl.slug,
        key: n.decl.key,
        reason: "permission",
        detail: `پرمیشن «${n.decl.permission}» به مدیر جاری داده نشده است.`,
      });
      continue;
    }
    kept.push(n);
  }

  // ۵) برخورد `key`  ۶) برخورد `href` — هسته اول از همه مالک است.
  const seenKeys = new Set<string>();
  const seenHrefs = new Set<string>();
  for (const group of groups) {
    for (const item of group.items) {
      seenKeys.add(item.key);
      seenHrefs.add(item.href);
      // فرزندان هم فضای کلید/href را اشغال می‌کنند: یک افزونه نباید
      // بتواند مسیر زیرمنوی هسته را دوباره تعریف کند.
      for (const child of item.children ?? []) {
        seenKeys.add(child.key);
        seenHrefs.add(child.href);
      }
    }
  }
  const accepted: MenuItem[] = [];
  for (const n of kept) {
    if (seenKeys.has(n.decl.key)) {
      rejected.push({
        slug: n.decl.slug,
        key: n.decl.key,
        reason: "key",
        detail: `کلید «${n.decl.key}» قبلاً گرفته شده (هسته، یا افزونه‌ای با ترتیب قطعی مقدم)؛ این یکی اعمال نشد.`,
      });
      continue;
    }
    if (seenHrefs.has(n.decl.href)) {
      rejected.push({
        slug: n.decl.slug,
        key: n.decl.key,
        reason: "href_duplicate",
        detail: `مسیر «${n.decl.href}» قبلاً در منو هست؛ افزونه نمی‌تواند صفحهٔ موجود را دوباره تعریف کند.`,
      });
      continue;
    }
    seenKeys.add(n.decl.key);
    seenHrefs.add(n.decl.href);
    accepted.push({
      href: n.decl.href,
      label: n.decl.label,
      icon: n.decl.icon ?? PLUGIN_MENU_DEFAULT_ICON,
      key: n.decl.key,
    });
  }

  // بدون آیتمِ پذیرفته‌شده، منو **دقیقاً** همان منوی هستهٔ امروز است — نه یک
  // گروه خالی با عنوان «افزونه‌ها» که فقط فضا می‌گیرد.
  if (accepted.length === 0) return { groups, accepted, rejected };

  const pluginGroup: MenuGroup = { title: PLUGIN_MENU_GROUP_TITLE, titleKey: "menu.group.plugins", items: accepted };
  // گروهی که `pluginsAfter` دارد لنگرِ جای‌گذاری است: آیتم‌های افزونه پیش از
  // آن می‌نشینند. اگر هیچ لنگری نبود، ته لیست. (این لنگر عمومی است و هیچ اسمی
  // از افزونه‌ای خاص در هسته نیست.)
  const anchorAt = groups.findIndex((g) => g.pluginsAfter === true);
  const at = anchorAt === -1 ? groups.length : anchorAt;
  return {
    groups: [...groups.slice(0, at), pluginGroup, ...groups.slice(at)],
    accepted,
    rejected,
  };
}
