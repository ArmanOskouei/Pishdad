/**
 * WF-L3 — حالتِ روشن/تیرهٔ سایتِ عمومی.
 *
 * منبعِ حقیقتِ «حالتِ مؤثر» یک تابعِ خالص است تا هم سوییچِ کلاینتی، هم
 * اسکریپتِ ضد-FOUC و هم تستِ `node --test` یک روایت داشته باشند. انتخاب
 * کاربر در `localStorage` ذخیره می‌شود (نه کوکی) و سایت را به تمِ پنل گره
 * نمی‌زند. حالت روی `.site[data-mode]` می‌نشیند — همان چیزی که
 * `globals.css` برای توکن‌های روشن می‌خواند.
 */

export const SITE_MODE_STORAGE_KEY = "cms-site-mode";

export type SiteMode = "light" | "dark";

export function isSiteMode(v: unknown): v is SiteMode {
  return v === "light" || v === "dark";
}

/** مقدارِ ذخیره‌شدهٔ `localStorage` را به حالتِ معتبر تبدیل می‌کند (وگرنه null). */
export function parseStoredSiteMode(raw: unknown): SiteMode | null {
  return isSiteMode(raw) ? raw : null;
}

/**
 * حالتِ مؤثر: اول انتخابِ ذخیره‌شدهٔ کاربر، بعد تنظیمِ سایت/قالب
 * (`chrome.mode`)، و در نهایت تیره. `setting === "system"` فقط وقتی به
 * روشن می‌رود که مرورگر روشن را ترجیح داده باشد (`prefersLight`).
 */
export function resolveSiteMode(stored: unknown, setting: unknown, prefersLight = false): SiteMode {
  const chosen = parseStoredSiteMode(stored);
  if (chosen) return chosen;
  if (isSiteMode(setting)) return setting;
  if (setting === "system") return prefersLight ? "light" : "dark";
  return "dark";
}

export function toggleSiteMode(mode: SiteMode): SiteMode {
  return mode === "dark" ? "light" : "dark";
}

/**
 * اسکریپتِ درون‌خطیِ ضد-FOUC.
 *
 * داخلِ خودِ عنصرِ `.site` رندر می‌شود تا هنگامِ پارسِ HTML و **پیش از**
 * رنگ‌آمیزیِ محتوا اجرا شود و `data-mode` را از `localStorage` بگذارد. عمداً
 * فقط مقدارِ معتبر را بازنویسی می‌کند تا پیش‌فرضِ سرور (تنظیمِ سایت/قالب) در
 * نبودِ انتخابِ کاربر دست‌نخورده بماند. خطا هرگز به صفحه سرریز نمی‌کند.
 */
export function siteModeBootstrapJs(): string {
  const key = JSON.stringify(SITE_MODE_STORAGE_KEY);
  return `(function(){try{var e=document.currentScript&&document.currentScript.parentElement;if(!e||!e.setAttribute)return;var m=localStorage.getItem(${key});if(m==="light"||m==="dark")e.setAttribute("data-mode",m);}catch(_){}})();`;
}
