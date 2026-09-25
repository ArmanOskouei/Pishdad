/** رجیستری منوی شل مشترک (پورت مفهومی از دمو layout.js) — F3 کامل. */

export type MenuItem = { href: string; label: string; icon: string; key: string };
export type MenuGroup = { title?: string; central?: boolean; items: MenuItem[] };

export const ADMIN_MENU: MenuGroup[] = [
  {
    items: [
      { href: "/admin/dashboard", label: "داشبورد", icon: "▦", key: "dashboard" },
      { href: "/admin/pages", label: "صفحات", icon: "▤", key: "pages" },
      { href: "/admin/media", label: "فایل‌ها", icon: "🗎", key: "media" },
      { href: "/admin/tickets", label: "تیکت‌ها", icon: "✉", key: "tickets" },
    ],
  },
  {
    title: "مدیریت",
    items: [
      { href: "/admin/managers", label: "مدیران و نقش‌ها", icon: "◍", key: "managers" },
      { href: "/admin/profile", label: "پروفایل من", icon: "◌", key: "profile" },
    ],
  },
  {
    title: "مالی",
    items: [{ href: "/admin/subscription", label: "اشتراک من", icon: "◈", key: "subscription" }],
  },
  {
    title: "ظاهر و محتوا",
    items: [
      { href: "/admin/header-footer", label: "هدر و فوتر", icon: "☰", key: "header-footer" },
      { href: "/admin/blocks", label: "بلوک‌های سایت", icon: "▦", key: "blocks" },
      { href: "/admin/themes", label: "قالب سایت", icon: "◫", key: "themes" },
      { href: "/admin/appearance", label: "قالب پنل", icon: "◐", key: "appearance" },
      { href: "/admin/plugins", label: "پلاگین‌ها", icon: "⬡", key: "plugins" },
    ],
  },
  {
    title: "تنظیمات",
    items: [
      { href: "/admin/settings", label: "تنظیمات سایت", icon: "⚙", key: "settings" },
      { href: "/admin/socials", label: "شبکه‌های اجتماعی", icon: "🔗", key: "socials" },
    ],
  },
  {
    title: "مرکزی",
    central: true,
    items: [{ href: "/central/dashboard", label: "پنل مرکزی", icon: "⬢", key: "central" }],
  },
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
  .filter((item) => item.href.startsWith("/admin/"));

/** منوی محدود حالت تعلیق: فقط صفحه اشتراک (پرداخت) فعال است. */
export const ADMIN_MENU_LOCKED: MenuGroup[] = [
  {
    title: "تعلیق — فقط پرداخت",
    items: [{ href: "/admin/subscription", label: "اشتراک من (پرداخت)", icon: "◈", key: "subscription" }],
  },
];

export const CENTRAL_MENU: MenuGroup[] = [
  {
    title: "مرکزی",
    central: true,
    items: [
      { href: "/central/dashboard", label: "داشبورد مرکزی", icon: "⬢", key: "central" },
      { href: "/central/clients", label: "مشتریان", icon: "◍", key: "clients" },
      { href: "/central/billing", label: "صورتحساب", icon: "⬣", key: "billing" },
      { href: "/central/chabokan", label: "چابکان", icon: "⬢", key: "chabokan" },
      { href: "/central/monitoring", label: "مانیتورینگ", icon: "◉", key: "monitoring" },
      { href: "/central/updates", label: "آپدیت‌ها", icon: "⟳", key: "updates" },
      { href: "/central/review", label: "صف بازبینی", icon: "☑", key: "review" },
      { href: "/central/operator", label: "اپراتور", icon: "⚠", key: "operator" },
    ],
  },
  {
    title: "پنل مشتری",
    items: [{ href: "/admin/dashboard", label: "بازگشت به پنل", icon: "▦", key: "dashboard" }],
  },
];
