"use client";

import { useLang } from "@/lib/i18n";
import { useToast } from "@/components/ui/Toast";

/**
 * F4.4 — سوییچِ زبانِ رابط کاربری.
 *
 * ## چرا `seg` (دکمهٔ رادیوییِ گروهی) و نه `select`
 *
 * دو گزینهٔ هم‌وزن باید همیشه دیده شوند: اگر فقط «زبان فعلی» را نشان بدهیم،
 * کاربر نمی‌داند گزینهٔ دیگری وجود دارد. و با `role="group"` +
 * `aria-pressed` هر دو دکمه برای صفحه‌خوان واقعاً «فشردنی» خوانده می‌شوند.
 *
 * ## بدون state
 *
 * زبان از `theme.dir` می‌آید و همین `ThemeProvider` آن را در DB
 * (`ui-settings`) و `localStorage` ذخیره می‌کند. پس این کامپوننت هیچ state
 * خودش ندارد ⇒ نمی‌تواند با سرور اختلاف پیدا کند. لازم نیست `busy` هم باشد:
 * `PUT` دبونسی است و خطایش باید **پنل را قفل نکند**.
 */
export function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
  const { lang, setLang, t } = useLang();
  const toast = useToast();

  const pick = (next: "fa" | "en") => {
    if (next === lang) return;
    setLang(next);
    // تنها جایی که تغییرِ زبان بی‌صدا نباشد. خودِ متن‌ها بلافاصله عوض می‌شوند
    // (context)، ولی تا کاربر برگردد ممکن است ندیده باشد.
    toast(next === "en" ? "Interface language: English" : "زبان رابط: فارسی", "ok");
  };

  return (
    <div className="seg" role="group" aria-label={t("settings.language")}>
      <button
        type="button"
        className={lang === "fa" ? "on" : ""}
        aria-pressed={lang === "fa"}
        lang="fa"
        onClick={() => pick("fa")}
      >
        فارسی
      </button>
      <button
        type="button"
        className={lang === "en" ? "on" : ""}
        aria-pressed={lang === "en"}
        lang="en"
        dir="ltr"
        onClick={() => pick("en")}
      >
        {compact ? "EN" : "English"}
      </button>
    </div>
  );
}
