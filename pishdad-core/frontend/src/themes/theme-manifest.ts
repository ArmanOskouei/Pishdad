/**
 * ECO1 — قرارداد داده‌ای قالب کد‌محور.
 *
 * هر قالب یک `theme.json` دارد که **اعلان** است: نام/اسلاگ/توضیح، حالت‌های
 * رنگیِ پشتیبانی‌شده، شکاف‌های صفحه (`slots`)، و توکن‌های پیش‌فرضِ پوسته.
 *
 * فایلِ JSON عمداً از React جدا است: هم رجیستری آن را بدون وابستگی به
 * رندرر می‌خواند، هم تست‌های `node --test` می‌توانند منطقِ رزولو را بدون
 * کشیدنِ درختِ کامپوننت بسنجند.
 */

/** شکاف‌هایی که یک قالب می‌تواند رندر کند. */
export type ThemeSlot = "header" | "footer" | "main" | "left" | "right" | "banner" | "blog";

export type ThemeMode = "light" | "dark" | "system";

export interface ThemeManifest {
  /** اسلاگ یکتا — همان که در `settings.theme` ذخیره می‌شود. */
  slug: string;
  name: string;
  description: string;
  version: string;
  /** حالت‌های رنگی‌ای که این قالب می‌پذیرد (COLOR_ROLES از preset می‌آید). */
  modes: ThemeMode[];
  /** شکاف‌های صفحه‌ای که این قالب رندر می‌کند. */
  slots: ThemeSlot[];
  /** توکن‌های پیش‌فرضِ پوسته (نقش‌های LAYOUT_ROLES، نه رنگ). */
  tokens: Record<string, string>;
}
