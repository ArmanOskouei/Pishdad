"use client";

/**
 * F4.4 — لایهٔ زبانِ رابط کاربری.
 *
 * ## ⭐ چرا زبان روی `theme.dir` سوار است و نه یک کانالِ ذخیره‌سازیِ تازه
 *
 * جهتِ متنِ پنل از قبل یک تنظیمِ **سروری** بود: `ui-settings.dir` که در
 * `ThemeProvider` به DB می‌رود و در `localStorage` هم می‌ماند. اگر زبان را
 * کانالِ دومی می‌ساختیم، دو چیز مستقل می‌داشتیم که هرگز با هم عوض نمی‌شدند:
 * `dir=ltr` با متنِ فارسی، یا `lang=en` با چیدمانِ راست‌به‌چپ. هر دو حالت
 * شکسته‌اند و هیچ‌کدام خطا نمی‌دهند.
 *
 * پس یک منبعِ حقیقت: **جهتِ متن = زبانِ رابط.** `ltr` ⇔ `en`، `rtl` ⇔ `fa`.
 * ذخیره‌سازی، همگام‌سازی و ضد- FOUC همگی را همان `ThemeProvider` قبلی انجام
 * می‌دهد و این فایل چیزی برای نگه‌داشتن اضافه نمی‌کند.
 *
 * ## چرا `t` همیشه رشته برمی‌گرداند
 *
 * کلیدِ ناشناخته به‌جای `undefined` (که در React یعنی «هیچ رندر نکن» و کل
 * عنصر ناپدید می‌شود) به **کلید خودش** برمی‌گردد. یعنی یک ترجمهٔ جاافتاده در UI
 * دیده می‌شود، نه اینکه سطر را بی‌سروصدا حذف کند.
 */

import { createContext, useCallback, useContext, useMemo, type ReactNode } from "react";
import { useTheme } from "@/lib/theme";
import { en } from "./en";
import { fa, type MessageKey } from "./fa";

export type Lang = "fa" | "en";
export type { MessageKey } from "./fa";

/** `dir` ⇔ زبان. تنها جایی که این تبدیل زندگی می‌کند. */
export const langFromDir = (dir: string | undefined): Lang => (dir === "ltr" ? "en" : "fa");
export const dirFromLang = (lang: Lang): "ltr" | "rtl" => (lang === "en" ? "ltr" : "rtl");

const DICT: Record<Lang, Record<string, string>> = { fa, en };

/** جای‌گذاریِ `{n}` — تنها پارامترِ لازم. الگوی مسطح، بدون i18n سنگین. */
export function format(template: string, values?: Record<string, string | number>): string {
  if (!values) return template;
  return template.replace(/\{(\w+)\}/g, (whole, k: string) =>
    k in values ? String(values[k]) : whole,
  );
}

type Ctx = {
  lang: Lang;
  dir: "ltr" | "rtl";
  setLang: (l: Lang) => void;
  /** `t("bell.unread", { n: 3 })` */
  t: (key: MessageKey, values?: Record<string, string | number>) => string;
  /** کلیدِ گروهِ کاتالوگ که در بک‌اند است، نه متنِ گروه. */
  tGroup: (groupKey: string, fallback: string) => string;
};

const LangCtx = createContext<Ctx | null>(null);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const { theme, set } = useTheme();
  const lang = langFromDir(theme.dir);
  const dir = dirFromLang(lang);

  const setLang = useCallback(
    (l: Lang) => {
      // `set` هم `localStorage` را می‌نویسد و هم PUT دبونسی `ui-settings` را
      // می‌زند ⇒ زبان بین دستگاه‌ها هم می‌رود، بدون کانالِ تازه.
      set({ dir: dirFromLang(l) });
    },
    [set],
  );

  const t = useCallback(
    (key: MessageKey, values?: Record<string, string | number>) =>
      format(DICT[lang][key] ?? fa[key] ?? key, values),
    [lang],
  );

  /**
   * برچسبِ گروه از بک‌اند می‌آید (فارسی، hard-coded در `Catalog::GROUPS`). اول
   * کلیدِ گروه را امتحان می‌کنیم و اگر نبود **عنوانِ بک‌اند** را نشان می‌دهیم —
   * نه `inbox.group.<key>` خام، چون آن رشته برای کاربر بی‌معنی است.
   */
  const tGroup = useCallback(
    (groupKey: string, fallback: string) => DICT[lang][`inbox.group.${groupKey}`] ?? fallback,
    [lang],
  );

  const value = useMemo<Ctx>(() => ({ lang, dir, setLang, t, tGroup }), [lang, dir, setLang, t, tGroup]);

  return <LangCtx.Provider value={value}>{children}</LangCtx.Provider>;
}

export function useLang(): Ctx {
  const c = useContext(LangCtx);
  if (!c) throw new Error("useLang بیرون از LanguageProvider");
  return c;
}
