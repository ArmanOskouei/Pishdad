import type { ReactNode } from "react";
import type { SitePage } from "@/lib/domain";
import type { BlogPostItem, SiteBlockSchemas, SiteChrome } from "@/lib/site";
import type { PublicLocale } from "@/lib/i18n/public";
import type { ThemeManifest } from "./theme-manifest";

export type { ThemeManifest, ThemeMode, ThemeSlot } from "./theme-manifest";

/**
 * ECO1 — قرارداد کامپوننتِ قالب.
 *
 * قالب یک کامپوننت React است که **خودش** صفحه را می‌سازد: هدر/فوتر، محتوا،
 * و ستون‌های کناری. تفاوتِ قالب‌ها در چیدمان و پوسته است، نه در دادهٔ صفحه.
 *
 * داده‌ای که دریافت می‌کند:
 *  - `page`  : صفحهٔ منتشرشده (بلوک‌ها + ستون‌های کناری + متا).
 *  - `chrome`: کروم عمومی سایت (هدر/فوتر/شبکه‌ها + `theme.globals`).
 *  - `schemas`: رجیستری schema بلوک‌ها (`chrome.blocks`) برای `DeclaredBlock`.
 *
 * و دو شکافِ اختیاری که مسیر (route) می‌سازد چون به قالب ربطی ندارند:
 *  - `banner`: بنر opt-in اعلان (فقط صفحهٔ خانه).
 *  - `jsonLd` : اسکریپت JSON-LD (فقط صفحه‌های عمیق).
 */
export interface ThemeProps {
  page: SitePage;
  chrome: SiteChrome | null;
  schemas?: SiteBlockSchemas;
  banner?: ReactNode;
  jsonLd?: ReactNode;
  /**
   * WF-M18 — محتوایِ جایگزینِ `<main>` به‌جای رندرِ بلوک‌های `page`.
   *
   * برای صفحه‌های سیستمیِ قالب‌دار که «صفحهٔ منتشرشده» نیستند و بلوکی برای
   * رندر ندارند (۴۰۴). وقتی داده شود، قالب همان‌طور که صفحات عادی را می‌پیچد
   * این را داخل `<main>` می‌گذارد — پس هدر/فوتر/توکن‌های قالب حفظ می‌شوند.
   */
  content?: ReactNode;
  /**
   * ECO2 — زبانِ صفحه و تنظیمِ دو‌زبانه. قالب لازم نیست چیزی از آن بسازد:
   * `ThemeShell` دکمهٔ تغییر زبان را در هدر می‌گذارد.
   */
  locale?: PublicLocale;
  switcher?: { locales: PublicLocale[]; primary: PublicLocale };
  /**
   * WF-L3 — صفحهٔ خانه: مسیر راهنما رندر نمی‌شود چون خانه خودش ریشهٔ مسیر است.
   */
  isHome?: boolean;
}

/** چیدمانِ ساختاریِ قالب — تفاوتِ واقعیِ پوسته‌ها (بدون دست‌زدن به بلوک‌ها). */
export interface ThemeLayoutConfig {
  /** `columns`: ستون‌ها کنار محتوا · `stacked`: ستون‌ها زیر محتوا. */
  sidebarPlacement: "columns" | "stacked";
  /** بیشینهٔ عرض ستون محتوا (px)؛ نامشخص = تمام‌عرض. */
  mainMaxInlineSize?: number;
}

/**
 * WF-C7 — بایگانی بلاگ به‌عنوان «اسلاتِ قالب».
 *
 * قالب می‌تواند `Blog` خودش را بدهد تا بایگانی/تک‌نوشته را با markup ویژهٔ
 * خودش رندر کند؛ نبودش یعنی پیاده‌سازیِ پیش‌فرض (`themes/blog.tsx`) با همان
 * پوسته/کلاس‌های `theme-{variant}` رندر می‌شود. قراردادِ قبلی (Component)
 * دست‌نخورده است — افزودنیِ صرف.
 */
export type ThemeBlogKind = "index" | "post";

export interface BlogArchiveData {
  posts: BlogPostItem[];
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  category?: string | null;
  tag?: string | null;
}

export interface ThemeBlogProps {
  kind: ThemeBlogKind;
  locale: PublicLocale;
  chrome: SiteChrome | null;
  schemas?: SiteBlockSchemas;
  switcher?: { locales: PublicLocale[]; primary: PublicLocale };
  jsonLd?: ReactNode;
  /** فقط `kind === "index"`. */
  archive?: BlogArchiveData;
  /** فقط `kind === "post"`. */
  page?: SitePage;
  author?: string | null;
  category?: string | null;
  tags?: string[];
  imageUrl?: string | null;
}

export type ThemeBlogComponent = (props: ThemeBlogProps) => ReactNode;

export interface ThemeDefinition {
  manifest: ThemeManifest;
  Component: (props: ThemeProps) => ReactNode;
  /** WF-C7 — اسلاتِ بلاگ؛ اختیاری، با fallback ایمن. */
  Blog?: ThemeBlogComponent;
}
