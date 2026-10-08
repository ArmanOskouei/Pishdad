"use client";

import { useCallback, useEffect, useState } from "react";
import { publicT, type PublicLocale } from "@/lib/i18n/public";
import {
  SITE_MODE_STORAGE_KEY,
  resolveSiteMode,
  toggleSiteMode,
  type SiteMode,
} from "@/lib/site-mode";

/**
 * WF-L3 — سوییچِ روشن/تیرهٔ سایتِ عمومی (برای بازدیدکننده، نه فقط ادمین).
 *
 * حالت روی `.site[data-mode]` می‌نشیند (همان کلیدی که `globals.css` می‌خواند)
 * و در `localStorage` ذخیره می‌شود — بدون کوکی. مقدارِ نخستینِ سرور از
 * تنظیمِ سایت/قالب می‌آید و اسکریپتِ ضد-FOUC (در `ThemeShell`) پیش از رنگ‌آمیزی
 * مقدارِ ذخیره‌شده را اعمال می‌کند. وضعیتِ نمایشی فقط در `useEffect` از
 * `localStorage` همگام می‌شود تا ناهماهنگیِ hydration رخ ندهد.
 */
export function SiteModeToggle({
  locale = "fa",
  defaultMode = "dark",
}: {
  locale?: PublicLocale;
  defaultMode?: SiteMode;
}) {
  const [mode, setMode] = useState<SiteMode>(defaultMode);

  const apply = useCallback((next: SiteMode) => {
    document.querySelectorAll<HTMLElement>(".site").forEach((el) => {
      el.dataset.mode = next;
    });
  }, []);

  useEffect(() => {
    let stored: string | null = null;
    try {
      stored = window.localStorage.getItem(SITE_MODE_STORAGE_KEY);
    } catch {
      stored = null;
    }
    const prefersLight =
      typeof window.matchMedia === "function" && window.matchMedia("(prefers-color-scheme: light)").matches;
    const effective = resolveSiteMode(stored, defaultMode, prefersLight);
    setMode(effective);
    apply(effective);
  }, [apply, defaultMode]);

  const onClick = () => {
    const next = toggleSiteMode(mode);
    setMode(next);
    try {
      window.localStorage.setItem(SITE_MODE_STORAGE_KEY, next);
    } catch {
      /* ذخیره‌سازیِ مسدود — فقط این نشست عوض می‌شود. */
    }
    apply(next);
  };

  const action = mode === "dark" ? publicT(locale, "mode.light") : publicT(locale, "mode.dark");

  return (
    <button
      type="button"
      className="btn btn-ghost btn-sm site-mode-toggle"
      onClick={onClick}
      aria-label={publicT(locale, "mode.toggle")}
      aria-pressed={mode === "dark"}
      title={action}
    >
      {mode === "dark" ? (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="12" cy="12" r="4.2" fill="currentColor" />
          <g stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4L17 7M7 17l-1.6 1.6" />
          </g>
        </svg>
      ) : (
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path
            d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5a8.5 8.5 0 1 0 10.8 10.8Z"
            fill="currentColor"
          />
        </svg>
      )}
    </button>
  );
}
