"use client";

/**
 * قالب پنل مدیریت (تسک ۲.۴ / صفحه ۱.۱۴):
 * گرید جهت‌ها + پیش‌نمایش زنده + تأیید → PUT ui-settings.
 * State Machine: saved → previewing(dirty) → confirmed | canceled (پورت از دمو).
 */

import { useEffect, useMemo, useState } from "react";
import { authed } from "@/lib/auth";
import type { UiSettings } from "@/lib/api";
import { useTheme } from "@/lib/theme";
import { Alert, SaveBar, Skeleton, Badge } from "@/components/ui/primitives";
import { DragDropList } from "@/components/ui/DragDropList";
import { useToast } from "@/components/ui/Toast";
import { BOTTOM_NAV_UPDATED_EVENT } from "@/components/layout/BottomNav";
import { BOTTOM_NAV_DEFAULTS, BOTTOM_NAV_OPTIONS, localizeMenuGroups, type MenuItem } from "@/lib/menu";
import { useLang, type MessageKey } from "@/lib/i18n";
import { faNum } from "@/lib/fa";

type Dir = { id: UiSettings["preset"]; name: string; desc: string; pv: { bg: string; side: string; card: string; line: string; pri: string }; presets: { label: string; accent: string }[] };

/**
 * I1-b — نام و توضیح و رنگ‌ها کلیدِ ترجمه دارند و متنِ فارسی می‌ماند به‌عنوانِ
 * fallback. چرا `pv` (پیش‌نمایش رنگ) ترجمه نمی‌شود: رنگ، زبان ندارد.
 */
const DIRS: Dir[] = [
  { id: "sahar", name: "سپیده", desc: "مینیمال روشن — پیش‌فرض مشتری", pv: { bg: "#f7f7f8", side: "#ffffff", card: "#ffffff", line: "#e5e7eb", pri: "#0d9488" }, presets: [{ label: "فیروزه‌ای", accent: "teal" }, { label: "نیلی", accent: "indigo" }, { label: "یاقوتی", accent: "rose" }] },
  { id: "amaliyat", name: "عملیات", desc: "دارک کنسول — پیش‌فرض مرکزی", pv: { bg: "#0b0f17", side: "#121826", card: "#1a2233", line: "#232c40", pri: "#6366f1" }, presets: [{ label: "ایندیگو", accent: "indigo" }, { label: "ماتریس", accent: "teal" }, { label: "امنیت", accent: "amber" }] },
  { id: "arya", name: "آریا", desc: "رنگارنگ گرادیانی", pv: { bg: "#faf5ff", side: "#7c3aed", card: "#ffffff", line: "#e9d5ff", pri: "#a855f7" }, presets: [{ label: "بنفش-صورتی", accent: "violet" }, { label: "آبی", accent: "blue" }, { label: "غروب", accent: "rose" }] },
  { id: "narm", name: "نرم", desc: "سافت دوستانه", pv: { bg: "#fdf6ef", side: "#f5e6d5", card: "#ffffff", line: "#ecdcc8", pri: "#c2703d" }, presets: [{ label: "سفالی", accent: "clay" }, { label: "آسمانی", accent: "blue" }, { label: "لیمویی", accent: "teal" }] },
  { id: "divan", name: "دیوان", desc: "کلاسیک", pv: { bg: "#eef2f7", side: "#1e3a8a", card: "#ffffff", line: "#dbe3ef", pri: "#1d4ed8" }, presets: [{ label: "آبی کلاسیک", accent: "blue" }, { label: "سبز دولتی", accent: "teal" }, { label: "سرمه‌ای", accent: "indigo" }] },
];

/**
 * ترجمهٔ نامِ رنگِ هر preset. کلید از خودِ `dir` + `accent` ساخته می‌شود تا یک
 * جدولِ موازی و قابلِ فراموشی در کنار `DIRS` لازم نباشد — وگرنه افزودنِ یک
 * رنگ یعنی ویرایشِ دو جا که هیچ‌کدام همدیگر را خبر نمی‌کنند.
 */
const ACCENT_KEYS: Record<string, Record<string, MessageKey>> = {
  sahar: { teal: "dir.sahar.accentTeal", indigo: "dir.sahar.accentIndigo", rose: "dir.sahar.accentRose" },
  amaliyat: { indigo: "dir.amaliyat.accentIndigo", teal: "dir.amaliyat.accentTeal", amber: "dir.amaliyat.accentAmber" },
  arya: { violet: "dir.arya.accentViolet", blue: "dir.arya.accentBlue", rose: "dir.arya.accentRose" },
  narm: { clay: "dir.narm.accentClay", blue: "dir.narm.accentBlue", teal: "dir.narm.accentTeal" },
  divan: { blue: "dir.divan.accentBlue", teal: "dir.divan.accentTeal", indigo: "dir.divan.accentIndigo" },
};

function normalizeBottomNav(value: unknown): string[] {
  if (!Array.isArray(value)) return [...BOTTOM_NAV_DEFAULTS];
  const allowed = new Set(BOTTOM_NAV_OPTIONS.map((item) => item.href));
  return value
    .filter((path): path is string => typeof path === "string" && allowed.has(path))
    .filter((path, index, paths) => paths.indexOf(path) === index)
    .slice(0, 6);
}

function orderedBottomNavOptions(paths: string[], options: MenuItem[] = BOTTOM_NAV_OPTIONS): MenuItem[] {
  const selected = paths.flatMap((path) => {
    const item = options.find((option) => option.href === path);
    return item ? [item] : [];
  });
  const selectedPaths = new Set(selected.map((item) => item.href));
  return [...selected, ...options.filter((item) => !selectedPaths.has(item.href))];
}

type Draft = Omit<UiSettings, "accent"> & { accent: string; pi: number };

export default function AppearancePage() {
  const { theme, set } = useTheme();
  const toast = useToast();
  const { t } = useLang();
  const [saved, setSaved] = useState<Draft | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedBottomNav, setSavedBottomNav] = useState<string[]>(() => [...BOTTOM_NAV_DEFAULTS]);
  const [draftBottomNav, setDraftBottomNav] = useState<string[]>(() => [...BOTTOM_NAV_DEFAULTS]);
  const [savingBottomNav, setSavingBottomNav] = useState(false);

  // I1-b — نام و رنگِ هر جهت بصری از دیکشنری می‌آید؛ `DIRS[].name` فارسیِ ثابت
  // می‌ماند به‌عنوان fallback و برای `id` که ترجمه نمی‌شود.
  const dirName = (id: UiSettings["preset"]): string => t(`dir.${id}` as const);
  const dirDesc = (id: UiSettings["preset"]): string => t(`dir.${id}Desc` as const);
  const accentLabel = (dir: UiSettings["preset"], accent: string): string => {
    const key = ACCENT_KEYS[dir]?.[accent];
    return key ? t(key) : accent;
  };
  // برچسبِ آیتم‌های نوار پایین هم از منوی هسته می‌آید، پس همان `labelKey` را
  // ترجمه می‌کنیم — به‌جای اینکه یک جدولِ موازی برای شش مسیر بسازیم. `useMemo`
  // چون خروجی به‌عنوان `items` به `DragDropList` می‌رود و آن `useMemo` خودش
  // روی همین هویت کلید می‌زند.
  const navOptions = useMemo(
    () => localizeMenuGroups([{ items: BOTTOM_NAV_OPTIONS }], t)[0]!.items,
    [t],
  );

  useEffect(() => {
    (async () => {
      try {
        const s = await authed<UiSettings>("/v1/ui-settings");
        // رنگِ ذخیره‌شده را بازیابی کن؛ اگر برای این جهت معتبر نبود، پیش‌فرضِ جهت.
        const dirPresets = DIRS.find((x) => x.id === s.preset)?.presets ?? [];
        const accent = s.accent && dirPresets.some((p) => p.accent === s.accent)
          ? s.accent
          : (dirPresets[0]?.accent ?? "indigo");
        const pi = Math.max(0, dirPresets.findIndex((p) => p.accent === accent));
        const d: Draft = { ...s, accent, pi };
        const bottomNav = normalizeBottomNav(s.bottom_nav);
        setSaved(d); setDraft(d);
         setSavedBottomNav(bottomNav); setDraftBottomNav(bottomNav);
         set({ ...s, accent: d.accent }, { persist: false });
      } catch (e) {
        setError(e instanceof Error ? e.message : t("appearance.errLoad"));
      } finally { setLoading(false); }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const dirty = useMemo(
    () => !!saved && !!draft && JSON.stringify({ ...draft, pi: undefined }) !== JSON.stringify({ ...saved, pi: undefined }),
    [saved, draft],
  );

  const bottomNavDirty = useMemo(
    () => JSON.stringify(draftBottomNav) !== JSON.stringify(savedBottomNav),
    [draftBottomNav, savedBottomNav],
  );

  // دسته UIUX (افزودنی): محافظ ترک صفحه با پیش‌نمایش ذخیره‌نشده.
  useEffect(() => {
    if (!dirty) return;
    const onBefore = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener("beforeunload", onBefore);
    return () => window.removeEventListener("beforeunload", onBefore);
  }, [dirty]);

  function mutate(p: Partial<Draft>) {
    if (!draft) return;
    const next = { ...draft, ...p };
    if (p.preset) {
      const dir = DIRS.find((x) => x.id === p.preset)!;
      next.accent = dir.presets[p.pi ?? 0]?.accent ?? dir.presets[0].accent;
    }
    setDraft(next);
    // پیش‌نمایش زنده بدون ذخیره در DB — ذخیره واقعی فقط با «تأیید» (PUT مستقیم)
    set({ preset: next.preset, mode: next.mode, radius: next.radius, density: next.density, font: next.font, accent: next.accent, dir: next.dir }, { persist: false });
  }

  function toggleBottomNav(href: string, enabled: boolean) {
    setDraftBottomNav((current) => {
      if (enabled) {
        return current.includes(href) || current.length >= 6 ? current : [...current, href];
      }
      return current.filter((path) => path !== href);
    });
  }

  function moveBottomNav(href: string, direction: -1 | 1) {
    setDraftBottomNav((current) => {
      const index = current.indexOf(href);
      const nextIndex = index + direction;
      if (index < 0 || nextIndex < 0 || nextIndex >= current.length) return current;
      const next = [...current];
      [next[index], next[nextIndex]] = [next[nextIndex], next[index]];
      return next;
    });
  }

  function reorderBottomNav(from: number, to: number) {
    setDraftBottomNav((current) => {
      if (from < 0 || to < 0 || from >= current.length || to >= current.length || from === to) return current;
      const next = [...current];
      const [moved] = next.splice(from, 1);
      if (!moved) return current;
      next.splice(to, 0, moved);
      return next;
    });
  }

  async function saveBottomNav() {
    if (!bottomNavDirty) return;
    setSavingBottomNav(true);
    try {
      const data = await authed<UiSettings>("/v1/ui-settings", {
        method: "PUT",
        body: { bottom_nav: draftBottomNav },
      });
      const savedPaths = normalizeBottomNav(data.bottom_nav);
      setSavedBottomNav(savedPaths);
      setDraftBottomNav(savedPaths);
window.dispatchEvent(new Event(BOTTOM_NAV_UPDATED_EVENT));
      toast(t("appearance.bottomNavSaved"), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : t("appearance.bottomNavSaveFailed"), "err");
    } finally {
      setSavingBottomNav(false);
    }
  }

  async function confirmAll() {
    if (!draft || !dirty) return;
    setSaving(true);
    try {
      const data = await authed<UiSettings>("/v1/ui-settings", {
        method: "PUT",
        body: { dir: draft.dir, preset: draft.preset, accent: draft.accent, mode: draft.mode, radius: draft.radius, density: draft.density, font: draft.font },
      });
      const d: Draft = { ...data, accent: draft.accent, pi: draft.pi };
      setSaved(d); setDraft(d);
      toast(t("appearance.presetSaved", { name: dirName(d.preset) }), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : t("appearance.errSave"), "err");
    } finally { setSaving(false); }
  }

  function cancelAll() {
    if (!saved) return;
    if (dirty && !window.confirm(t("appearance.discardConfirm"))) return;
    setDraft({ ...saved });
    // بازگشت به مقدار ذخیره‌شده سرور — PUT اضافه لازم نیست (با DB یکسان است).
    set({ preset: saved.preset, mode: saved.mode, radius: saved.radius, density: saved.density, font: saved.font, accent: saved.accent, dir: saved.dir }, { persist: false });
    toast(t("appearance.previewCancelled"));
  }

  if (loading || !draft || !saved) {
    return (
      <div>
        <div className="page-head"><div><h1>{t("appearance.title")}</h1><p>{t("appearance.loading")}</p></div></div>
        <Skeleton lines={5} />
      </div>
    );
  }
  if (error) return <div><Alert tone="red">{error}</Alert></div>;

  const activeDir = DIRS.find((x) => x.id === draft.preset)!;
  const bottomNavOptions = orderedBottomNavOptions(draftBottomNav, navOptions);
  const selectedBottomNavItems = draftBottomNav.flatMap((path) => {
    const item = navOptions.find((option) => option.href === path);
    return item ? [{ ...item, id: item.href }] : [];
  });
  const availableBottomNavItems = bottomNavOptions.filter((item) => !draftBottomNav.includes(item.href));

  const renderNavOption = (
    item: MenuItem,
    selectedIndex: number,
    moveUp?: () => void,
    moveDown?: () => void,
  ) => {
    const selected = selectedIndex >= 0;
    return (
      <div className={`mobile-nav-option${selected ? " selected" : ""}`}>
        <label className="mobile-nav-option-label">
          {selected ? <span aria-hidden style={{ cursor: "grab", fontSize: 17 }}>⠿</span> : null}
          <input
            type="checkbox"
            checked={selected}
            disabled={!selected && draftBottomNav.length >= 6}
            onChange={(event) => toggleBottomNav(item.href, event.target.checked)}
            aria-label={t("appearance.showInBottomNav", { label: item.label })}
          />
          <span aria-hidden className="bn-icon">{item.icon}</span>
          <span>{item.label}</span>
        </label>
        <span className="mobile-nav-path" dir="ltr">{item.href}</span>
        {selected ? (
          <div className="mobile-nav-order">
            <span className="mobile-nav-position" aria-label={t("appearance.position", { n: faNum(selectedIndex + 1) })}>{faNum(selectedIndex + 1)}</span>
            <button
              type="button"
              className="icon-btn"
              disabled={selectedIndex === 0}
              onClick={moveUp ?? (() => moveBottomNav(item.href, -1))}
              aria-label={t("appearance.moveUp", { label: item.label })}
              style={{ minInlineSize: 44, minBlockSize: 44 }}
            >
              ↑
            </button>
            <button
              type="button"
              className="icon-btn"
              disabled={selectedIndex === draftBottomNav.length - 1}
              onClick={moveDown ?? (() => moveBottomNav(item.href, 1))}
              aria-label={t("appearance.moveDown", { label: item.label })}
              style={{ minInlineSize: 44, minBlockSize: 44 }}
            >
              ↓
            </button>
          </div>
        ) : null}
      </div>
    );
  };

  return (
    <div>
      <div className="page-head"><div><h1>{t("appearance.title")}</h1><p>{t("appearance.subtitle")}</p></div></div>
      <Alert tone="blue">{t("appearance.activePreset", { name: dirName(saved.preset) })}</Alert>

      <div className="tgrid" role="radiogroup" aria-label={t("appearance.visualDirection")}>
        {DIRS.map((d) => {
          const isSaved = saved.preset === d.id;
          const isPrev = draft.preset === d.id && dirty;
          return (
            <div
              key={d.id} role="radio" tabIndex={0} aria-checked={draft.preset === d.id}
              onClick={() => mutate({ preset: d.id, pi: 0 })}
              onKeyDown={(e) => { if (e.key === "Enter") mutate({ preset: d.id, pi: 0 }); }}
              className={`theme-card${draft.preset === d.id ? " selected" : ""}${isPrev ? " st-prev" : ""}${isSaved && !dirty ? " st-active" : ""}`}
            >
              <div className="mini" style={{ "--pv-bg": d.pv.bg, "--pv-side": d.pv.side, "--pv-card": d.pv.card, "--pv-line": d.pv.line, "--pv-pri": d.pv.pri } as React.CSSProperties}>
                <div className="ms"><i /><i /><i /><i /></div>
                <div className="mb">
                  <div style={{ blockSize: 11, borderRadius: 99, background: "var(--pv-side)", opacity: 0.7, inlineSize: "60%" }} />
                  <div style={{ display: "flex", gap: 5 }}>
                    <i style={{ flex: 1, blockSize: 26, borderRadius: 6, background: "var(--pv-card)", border: "1px solid var(--pv-line)" }} />
                    <i style={{ flex: 1, blockSize: 26, borderRadius: 6, background: "var(--pv-card)", border: "1px solid var(--pv-line)" }} />
                  </div>
                </div>
                {isSaved ? <Badge tone="green">{t("appearance.badgeActive")}</Badge> : null}
                {isPrev ? <Badge tone="amber">{t("appearance.badgePreview")}</Badge> : null}
              </div>
              <div className="theme-meta">
                <b>{dirName(d.id)}</b>
                <div className="card-sub">{dirDesc(d.id)}</div>
                <div style={{ display: "flex", gap: 8, marginBlockStart: 8 }} onClick={(e) => e.stopPropagation()}>
                  {d.presets.map((p, i) => (
                    <button
                      key={p.accent}
                      type="button"
                      title={accentLabel(d.id, p.accent)}
                      aria-label={accentLabel(d.id, p.accent)}
                      aria-pressed={draft.preset === d.id && draft.pi === i}
                      data-accent={p.accent}
                      onClick={() => mutate({ preset: d.id, pi: i })}
                      className={`theme-swatch${draft.preset === d.id && draft.pi === i ? " selected" : ""}`}
                    />
                  ))}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("appearance.advanced")}</div>
        <div className="grid c3" style={{ marginBlockStart: 10 }}>
          <div className="field"><label>{t("appearance.radius")}</label>
            <div className="seg">{([["sharp", t("appearance.radiusSharp")], ["default", t("appearance.radiusDefault")], ["rounded", t("appearance.radiusRounded")]] as const).map(([v, l]) => (
              <button key={v} className={draft.radius === v ? "on" : ""} onClick={() => mutate({ radius: v })}>{l}</button>
            ))}</div></div>
          <div className="field"><label>{t("appearance.density")}</label>
            <div className="seg">{([["compact", t("appearance.densityCompact")], ["comfortable", t("appearance.densityComfortable")], ["loose", t("appearance.densityLoose")]] as const).map(([v, l]) => (
              <button key={v} className={draft.density === v ? "on" : ""} onClick={() => mutate({ density: v })}>{l}</button>
            ))}</div></div>
          <div className="field"><label>{t("appearance.fontSize")}</label>
            <div className="seg">{([["sm", t("appearance.fontSm")], ["md", t("appearance.fontMd")], ["lg", t("appearance.fontLg")]] as const).map(([v, l]) => (
              <button key={v} className={draft.font === v ? "on" : ""} onClick={() => mutate({ font: v })}>{l}</button>
            ))}</div></div>
        </div>
        <div className="seg" style={{ maxInlineSize: 220, marginBlockStart: 8 }}>
          <button className={draft.mode === "light" ? "on" : ""} onClick={() => mutate({ mode: "light" })}>{t("appearance.light")}</button>
          <button className={draft.mode === "dark" ? "on" : ""} onClick={() => mutate({ mode: "dark" })}>{t("appearance.dark")}</button>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("appearance.bottomNav")}</div>
        <p className="card-sub" style={{ marginBlock: "6px 12px" }}>
          {t("appearance.bottomNavHint")}
        </p>
        <div className="mobile-nav-options" role="group" aria-label={t("appearance.bottomNavGroup")}>
          <p className="card-sub" style={{ margin: 0 }}>
            {t("appearance.bottomNavDragHint")}
          </p>
          {selectedBottomNavItems.length > 0 ? (
            <DragDropList
              items={selectedBottomNavItems}
              onMove={reorderBottomNav}
              render={(item, _index, helpers) =>
                renderNavOption(item, draftBottomNav.indexOf(item.href), helpers.moveUp, helpers.moveDown)
              }
            />
          ) : (
            <p style={{ color: "var(--text-muted)", fontSize: 13, margin: 0 }}>{t("appearance.bottomNavEmpty")}</p>
          )}
          {availableBottomNavItems.length > 0 ? (
            <div role="list" aria-label={t("appearance.bottomNavUnselected")} style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {availableBottomNavItems.map((item) => (
                <div key={item.href} role="listitem">
                  {renderNavOption(item, -1)}
                </div>
              ))}
            </div>
          ) : null}
        </div>
        <div className="mobile-nav-actions">
          <span className="card-sub" role="status">{t("appearance.bottomNavCount", { n: draftBottomNav.length })}</span>
          <button
            type="button"
            className="btn btn-primary"
            disabled={!bottomNavDirty || savingBottomNav}
            onClick={() => void saveBottomNav()}
          >
            {savingBottomNav ? t("appearance.saving") : t("appearance.bottomNavSave")}
          </button>
        </div>
      </div>

      {dirty ? (
        <SaveBar
          dirtyText={`${dirName(activeDir.id)} — ${accentLabel(activeDir.id, activeDir.presets[draft.pi]?.accent ?? "")}`}
          onCancel={cancelAll}
          onSave={() => void confirmAll()}
          onReset={() => mutate({ preset: "amaliyat", pi: 0, mode: "dark", radius: "sharp", density: "compact", font: "md" })}
        />
      ) : null}
      {saving ? <Skeleton lines={2} /> : null}
    </div>
  );
}
