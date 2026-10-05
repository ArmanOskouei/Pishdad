import type { ReactNode } from "react";
import type { SitePage } from "@/lib/domain";
import type { SiteBlockSchemas, SiteChrome } from "@/lib/site";
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
   * ECO2 — زبانِ صفحه و تنظیمِ دو‌زبانه. قالب لازم نیست چیزی از آن بسازد:
   * `ThemeShell` دکمهٔ تغییر زبان را در هدر می‌گذارد.
   */
  locale?: PublicLocale;
  switcher?: { locales: PublicLocale[]; primary: PublicLocale };
}

/** چیدمانِ ساختاریِ قالب — تفاوتِ واقعیِ پوسته‌ها (بدون دست‌زدن به بلوک‌ها). */
export interface ThemeLayoutConfig {
  /** `columns`: ستون‌ها کنار محتوا · `stacked`: ستون‌ها زیر محتوا. */
  sidebarPlacement: "columns" | "stacked";
  /** بیشینهٔ عرض ستون محتوا (px)؛ نامشخص = تمام‌عرض. */
  mainMaxInlineSize?: number;
}

export interface ThemeDefinition {
  manifest: ThemeManifest;
  Component: (props: ThemeProps) => ReactNode;
}
