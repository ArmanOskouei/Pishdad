"use client";

/**
 * قلب تسک ۰.۲ — سیستم تم.
 * - توکن‌ها/اتریبیوت‌ها دقیقاً از DESIGN-DIRECTIONS.md + دمو (data-theme/accent/radius/density/font-size/direction روی <html>)
 * - تغییر زنده بدون رندر مجدد: فقط dataset روی documentElement عوض می‌شود (CSS Variables واکنش نشان می‌دهد)
 * - ذخیره در localStorage + PUT /api/v1/ui-settings هنگام لاگین (syncToServer)
 */

import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { api, type UiSettings } from "@/lib/api";

export type ThemeState = Omit<UiSettings, "accent"> & { accent: string };

const LS_KEY = "cms-ui-f1";

export const DEFAULT_THEME: ThemeState = {
  dir: "rtl",
  preset: "amaliyat",
  accent: "indigo",
  mode: "dark",
  radius: "sharp",
  density: "compact",
  font: "md",
};

const PRESET_ACCENT: Record<UiSettings["preset"], string> = {
  sahar: "teal",
  amaliyat: "indigo",
  arya: "violet",
  narm: "clay",
  divan: "blue",
};

export function applyTheme(t: ThemeState) {
  if (typeof document === "undefined") return;
  const h = document.documentElement;
  h.dataset.direction = t.preset; // direction در دمو = نام جهت (sahar/amaliyat/...)
  h.dataset.theme = t.mode === "system" ? (window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark") : t.mode;
  h.dataset.accent = t.accent;
  h.dataset.radius = t.radius;
  h.dataset.density = t.density;
  h.dataset.fontSize = t.font;
  /**
   * ECO2 — روی سایتِ عمومی جهت/زبان مالکش سرور است (از لوکالِ مسیر)، نه تمِ
   * پنل. بدون این شرط، هر بازدیدِ صفحه (حتی `/en`) پس از hydration با تمِ
   * ذخیره‌شدهٔ پنل به `rtl/fa` می‌پرید.
   *
   * F4.4 — `lang` کنارِ `dir`، در همین تابع.
   *
   * نه یک `useEffect` جدا: اگر `dir` از یک جا بیاید و `lang` از جای دیگر، یک روز
   * ناهمگام می‌شوند (`dir=ltr` + `lang=fa` ⇒ صفحه‌خوان انگلیسی را فارسی می‌خواند)
   * و هیچ خطایی هم نمی‌دهد. جهت و زبان یک تصمیم‌اند.
   */
  if (h.dataset.cmsSurface !== "site") {
    h.dir = t.dir;
    h.lang = t.dir === "ltr" ? "en" : "fa";
  }
}

function load(): ThemeState {
  if (typeof window === "undefined") return DEFAULT_THEME;
  try {
    const raw = localStorage.getItem(LS_KEY);
    if (raw) return { ...DEFAULT_THEME, ...JSON.parse(raw) };
  } catch { /* ignore */ }
  return DEFAULT_THEME;
}

type Ctx = {
  theme: ThemeState;
  /** persist:false برای پیش‌نمایش (صفحه قالب) و pull — هرگز PUT نمی‌زند و
   * تایمر معلق را لغو می‌کند؛ ذخیره واقعی فقط با تأیید (PUT مستقیم). */
  set: (p: Partial<ThemeState>, opts?: { persist?: boolean }) => void;
  toggleMode: () => void;
  syncToServer: (token: string) => Promise<void>;
  pullFromServer: (token: string) => Promise<void>;
  markLoggedOut: () => void;
};

const ThemeCtx = createContext<Ctx | null>(null);

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<ThemeState>(DEFAULT_THEME);
  const loggedInRef = useRef(false);
  const putTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  /** آینه همگام theme — تا set/schedule بدون side-effect داخل updater کار کنند
   * (updater در StrictMode دو بار صدا زده می‌شود → double-PUT). */
  const themeRef = useRef<ThemeState>(DEFAULT_THEME);

  const clearPending = useCallback(() => {
    if (putTimer.current) {
      clearTimeout(putTimer.current);
      putTimer.current = null;
    }
  }, []);

  /** ذخیره دبونس‌شده در DB — فقط وقتی نشست فعال است (بعد از pull موفق). */
  const schedulePersist = useCallback((t: ThemeState) => {
    if (!loggedInRef.current) return;
    clearPending();
    putTimer.current = setTimeout(() => {
      putTimer.current = null;
      fetch("/api/proxy/v1/ui-settings", {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ dir: t.dir, preset: t.preset, accent: t.accent, mode: t.mode, radius: t.radius, density: t.density, font: t.font }),
        cache: "no-store",
      }).catch(() => undefined);
    }, 600);
  }, [clearPending]);

  useEffect(() => {
    const t = load();
    themeRef.current = t;
    setTheme(t);
    applyTheme(t);
    let cancelled = false;
    // DB مرجع نهایی است: در هر بارگذاری، اگر نشست فعال باشد تم سرور اعمال می‌شود
    // (مرورگر/سیستم جدید هم همان تم ذخیره‌شده را می‌گیرد).
    (async () => {
      try {
        const res = await fetch("/api/proxy/v1/ui-settings", { cache: "no-store" });
        if (!res.ok || cancelled) return;
        const json = await res.json().catch(() => ({}));
        const s = (json?.data ?? json) as UiSettings;
        if (!s || !s.preset) return;
        loggedInRef.current = true;
        const merged: ThemeState = { ...DEFAULT_THEME, ...s, accent: s.accent ?? PRESET_ACCENT[s.preset] ?? "indigo" };
        themeRef.current = merged;
        setTheme(merged);
        try { localStorage.setItem(LS_KEY, JSON.stringify(merged)); } catch { /* ignore */ }
        applyTheme(merged);
      } catch { /* آفلاین/مهمان → تم محلی می‌ماند */ }
    })();
    return () => { cancelled = true; };
  }, []);

  const apply = useCallback((next: ThemeState, opts?: { persist?: boolean }) => {
    themeRef.current = next;
    setTheme(next);
    applyTheme(next);
    // persist:false = پیش‌نمایش: نه در localStorage می‌ماند نه به DB می‌رود —
    // تا همان اکانت در تب/مرورگر دیگر، تم ذخیره‌شده واقعی را ببیند.
    if (opts?.persist === false) { clearPending(); return; }
    try { localStorage.setItem(LS_KEY, JSON.stringify(next)); } catch { /* ignore */ }
    schedulePersist(next);
  }, [schedulePersist, clearPending]);

  const set = useCallback((p: Partial<ThemeState>, opts?: { persist?: boolean }) => {
    const prev = themeRef.current;
    const next = { ...prev, ...p };
    if (p.preset && !p.accent) next.accent = PRESET_ACCENT[p.preset] ?? next.accent;
    apply(next, opts);
  }, [apply]);

  const toggleMode = useCallback(() => {
    const prev = themeRef.current;
    const mode: ThemeState["mode"] = prev.mode === "dark" ? "light" : "dark";
    apply({ ...prev, mode });
  }, [apply]);

  const syncToServer = useCallback(async (token: string) => {
    const t = load();
    await api("/v1/ui-settings", {
      method: "PUT", token,
      body: { dir: t.dir, preset: t.preset, accent: t.accent, mode: t.mode, radius: t.radius, density: t.density, font: t.font },
    }).catch(() => undefined);
  }, []);

  const pullFromServer = useCallback(async (token: string) => {
    const s = await api<UiSettings>("/v1/ui-settings", { token }).catch(() => null);
    if (s) {
      loggedInRef.current = true;
      set({ ...s, accent: s.accent ?? PRESET_ACCENT[s.preset] ?? "indigo" }, { persist: false });
    }
  }, [set]);

  /** خروج: نشست بسته شد — دیگر هیچ PUT دبونسی نباید برود. */
  const markLoggedOut = useCallback(() => {
    loggedInRef.current = false;
    clearPending();
  }, [clearPending]);

  return <ThemeCtx.Provider value={{ theme, set, toggleMode, syncToServer, pullFromServer, markLoggedOut }}>{children}</ThemeCtx.Provider>;
}

export function useTheme(): Ctx {
  const c = useContext(ThemeCtx);
  if (!c) throw new Error("useTheme بیرون از ThemeProvider");
  return c;
}
