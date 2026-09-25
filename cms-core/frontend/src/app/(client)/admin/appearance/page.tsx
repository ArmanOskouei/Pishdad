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
import { BOTTOM_NAV_DEFAULTS, BOTTOM_NAV_OPTIONS, type MenuItem } from "@/lib/menu";
import { faNum } from "@/lib/fa";

type Dir = { id: UiSettings["preset"]; name: string; desc: string; pv: { bg: string; side: string; card: string; line: string; pri: string }; presets: { label: string; accent: string }[] };

const DIRS: Dir[] = [
  { id: "sahar", name: "سپیده", desc: "مینیمال روشن — پیش‌فرض مشتری", pv: { bg: "#f7f7f8", side: "#ffffff", card: "#ffffff", line: "#e5e7eb", pri: "#0d9488" }, presets: [{ label: "فیروزه‌ای", accent: "teal" }, { label: "نیلی", accent: "indigo" }, { label: "یاقوتی", accent: "rose" }] },
  { id: "amaliyat", name: "عملیات", desc: "دارک کنسول — پیش‌فرض مرکزی", pv: { bg: "#0b0f17", side: "#121826", card: "#1a2233", line: "#232c40", pri: "#6366f1" }, presets: [{ label: "ایندیگو", accent: "indigo" }, { label: "ماتریس", accent: "teal" }, { label: "امنیت", accent: "amber" }] },
  { id: "arya", name: "آریا", desc: "رنگارنگ گرادیانی", pv: { bg: "#faf5ff", side: "#7c3aed", card: "#ffffff", line: "#e9d5ff", pri: "#a855f7" }, presets: [{ label: "بنفش-صورتی", accent: "violet" }, { label: "آبی", accent: "blue" }, { label: "غروب", accent: "rose" }] },
  { id: "narm", name: "نرم", desc: "سافت دوستانه", pv: { bg: "#fdf6ef", side: "#f5e6d5", card: "#ffffff", line: "#ecdcc8", pri: "#c2703d" }, presets: [{ label: "سفالی", accent: "clay" }, { label: "آسمانی", accent: "blue" }, { label: "لیمویی", accent: "teal" }] },
  { id: "divan", name: "دیوان", desc: "کلاسیک", pv: { bg: "#eef2f7", side: "#1e3a8a", card: "#ffffff", line: "#dbe3ef", pri: "#1d4ed8" }, presets: [{ label: "آبی کلاسیک", accent: "blue" }, { label: "سبز دولتی", accent: "teal" }, { label: "سرمه‌ای", accent: "indigo" }] },
];

function normalizeBottomNav(value: unknown): string[] {
  if (!Array.isArray(value)) return [...BOTTOM_NAV_DEFAULTS];
  const allowed = new Set(BOTTOM_NAV_OPTIONS.map((item) => item.href));
  return value
    .filter((path): path is string => typeof path === "string" && allowed.has(path))
    .filter((path, index, paths) => paths.indexOf(path) === index)
    .slice(0, 6);
}

function orderedBottomNavOptions(paths: string[]): MenuItem[] {
  const selected = paths.flatMap((path) => {
    const item = BOTTOM_NAV_OPTIONS.find((option) => option.href === path);
    return item ? [item] : [];
  });
  const selectedPaths = new Set(selected.map((item) => item.href));
  return [...selected, ...BOTTOM_NAV_OPTIONS.filter((item) => !selectedPaths.has(item.href))];
}

type Draft = UiSettings & { accent: string; pi: number };

export default function AppearancePage() {
  const { theme, set } = useTheme();
  const toast = useToast();
  const [saved, setSaved] = useState<Draft | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [savedBottomNav, setSavedBottomNav] = useState<string[]>(() => [...BOTTOM_NAV_DEFAULTS]);
  const [draftBottomNav, setDraftBottomNav] = useState<string[]>(() => [...BOTTOM_NAV_DEFAULTS]);
  const [savingBottomNav, setSavingBottomNav] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const s = await authed<UiSettings>("/v1/ui-settings");
        const pi = 0;
        const d: Draft = { ...s, accent: DIRS.find((x) => x.id === s.preset)?.presets[0].accent ?? "indigo", pi };
        const bottomNav = normalizeBottomNav(s.bottom_nav);
        setSaved(d); setDraft(d);
         setSavedBottomNav(bottomNav); setDraftBottomNav(bottomNav);
         set({ ...s, accent: d.accent }, { persist: false });
      } catch (e) {
        setError(e instanceof Error ? e.message : "خطا در بارگذاری تنظیمات.");
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
      toast("نوار پایین موبایل ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره نوار پایین ناموفق بود.", "err");
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
        body: { dir: draft.dir, preset: draft.preset, mode: draft.mode, radius: draft.radius, density: draft.density, font: draft.font },
      });
      const d: Draft = { ...data, accent: draft.accent, pi: draft.pi };
      setSaved(d); setDraft(d);
      toast(`قالب «${DIRS.find((x) => x.id === d.preset)?.name}» ذخیره شد.`, "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا در ذخیره.", "err");
    } finally { setSaving(false); }
  }

  function cancelAll() {
    if (!saved) return;
    if (dirty && !window.confirm("تغییرات ذخیره‌نشده از بین می‌رود. انصراف می‌دهید؟")) return;
    setDraft({ ...saved });
    // بازگشت به مقدار ذخیره‌شده سرور — PUT اضافه لازم نیست (با DB یکسان است).
    set({ preset: saved.preset, mode: saved.mode, radius: saved.radius, density: saved.density, font: saved.font, accent: saved.accent, dir: saved.dir }, { persist: false });
    toast("پیش‌نمایش لغو شد.");
  }

  if (loading || !draft || !saved) {
    return (
      <div>
        <div className="page-head"><div><h1>قالب پنل مدیریت</h1><p>در حال بارگذاری…</p></div></div>
        <Skeleton lines={5} />
      </div>
    );
  }
  if (error) return <div><Alert tone="red">{error}</Alert></div>;

  const activeDir = DIRS.find((x) => x.id === draft.preset)!;
  const bottomNavOptions = orderedBottomNavOptions(draftBottomNav);
  const selectedBottomNavItems = draftBottomNav.flatMap((path) => {
    const item = BOTTOM_NAV_OPTIONS.find((option) => option.href === path);
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
            aria-label={`نمایش ${item.label} در نوار پایین موبایل`}
          />
          <span aria-hidden className="bn-icon">{item.icon}</span>
          <span>{item.label}</span>
        </label>
        <span className="mobile-nav-path" dir="ltr">{item.href}</span>
        {selected ? (
          <div className="mobile-nav-order">
            <span className="mobile-nav-position" aria-label={`ترتیب ${faNum(selectedIndex + 1)}`}>{faNum(selectedIndex + 1)}</span>
            <button
              type="button"
              className="icon-btn"
              disabled={selectedIndex === 0}
              onClick={moveUp ?? (() => moveBottomNav(item.href, -1))}
              aria-label={`انتقال ${item.label} به بالا`}
              style={{ minInlineSize: 44, minBlockSize: 44 }}
            >
              ↑
            </button>
            <button
              type="button"
              className="icon-btn"
              disabled={selectedIndex === draftBottomNav.length - 1}
              onClick={moveDown ?? (() => moveBottomNav(item.href, 1))}
              aria-label={`انتقال ${item.label} به پایین`}
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
      <div className="page-head"><div><h1>قالب پنل مدیریت</h1><p>ظاهر خود پنل — انتخاب → پیش‌نمایش زنده → تأیید</p></div></div>
      <Alert tone="blue">قالب فعال: <b>{DIRS.find((x) => x.id === saved.preset)?.name}</b> — ظاهر خود پنل است (نه سایت) و برای <b>همه مدیران</b> اعمال می‌شود.</Alert>

      <div className="tgrid" role="radiogroup" aria-label="جهت بصری">
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
                {isSaved ? <Badge tone="green">✔ فعال</Badge> : null}
                {isPrev ? <Badge tone="amber">◉ پیش‌نمایش</Badge> : null}
              </div>
              <div className="theme-meta">
                <b>{d.name}</b>
                <div className="card-sub">{d.desc}</div>
                <div style={{ display: "flex", gap: 8, marginBlockStart: 8 }} onClick={(e) => e.stopPropagation()}>
                  {d.presets.map((p, i) => (
                    <button
                      key={p.accent}
                      type="button"
                      title={p.label}
                      aria-label={p.label}
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
        <div className="card-title">تنظیمات پیشرفته (اختیاری)</div>
        <div className="grid c3" style={{ marginBlockStart: 10 }}>
          <div className="field"><label>شعاع گوشه‌ها</label>
            <div className="seg">{([["sharp", "تیز"], ["default", "پیش‌فرض"], ["rounded", "گرد"]] as const).map(([v, l]) => (
              <button key={v} className={draft.radius === v ? "on" : ""} onClick={() => mutate({ radius: v })}>{l}</button>
            ))}</div></div>
          <div className="field"><label>تراکم</label>
            <div className="seg">{([["compact", "فشرده"], ["comfortable", "متوسط"], ["loose", "باز"]] as const).map(([v, l]) => (
              <button key={v} className={draft.density === v ? "on" : ""} onClick={() => mutate({ density: v })}>{l}</button>
            ))}</div></div>
          <div className="field"><label>اندازه فونت</label>
            <div className="seg">{([["sm", "کوچک"], ["md", "متوسط"], ["lg", "بزرگ"]] as const).map(([v, l]) => (
              <button key={v} className={draft.font === v ? "on" : ""} onClick={() => mutate({ font: v })}>{l}</button>
            ))}</div></div>
        </div>
        <div className="seg" style={{ maxInlineSize: 220, marginBlockStart: 8 }}>
          <button className={draft.mode === "light" ? "on" : ""} onClick={() => mutate({ mode: "light" })}>روشن</button>
          <button className={draft.mode === "dark" ? "on" : ""} onClick={() => mutate({ mode: "dark" })}>تیره</button>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">نوار پایین موبایل</div>
        <p className="card-sub" style={{ marginBlock: "6px 12px" }}>
          تنظیمات موبایل — مسیرهای مجاز پنل را انتخاب و ترتیب نمایش آن‌ها را تعیین کنید.
        </p>
        <div className="mobile-nav-options" role="group" aria-label="مسیرهای مجاز نوار پایین موبایل">
          <p className="card-sub" style={{ margin: 0 }}>
            آیتم‌های انتخاب‌شده را با ماوس یا لمس بکشید؛ دکمه‌های بالا/پایین نیز برای دسترس‌پذیری فعال هستند.
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
            <p style={{ color: "var(--text-muted)", fontSize: 13, margin: 0 }}>هنوز آیتمی برای نوار پایین انتخاب نشده است.</p>
          )}
          {availableBottomNavItems.length > 0 ? (
            <div role="list" aria-label="آیتم‌های انتخاب‌نشده نوار پایین" style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {availableBottomNavItems.map((item) => (
                <div key={item.href} role="listitem">
                  {renderNavOption(item, -1)}
                </div>
              ))}
            </div>
          ) : null}
        </div>
        <div className="mobile-nav-actions">
          <span className="card-sub" role="status">{draftBottomNav.length} از ۶ آیتم انتخاب شده است.</span>
          <button
            type="button"
            className="btn btn-primary"
            disabled={!bottomNavDirty || savingBottomNav}
            onClick={() => void saveBottomNav()}
          >
            {savingBottomNav ? "در حال ذخیره…" : "ذخیره نوار پایین"}
          </button>
        </div>
      </div>

      {dirty ? (
        <SaveBar
          dirtyText={`${activeDir.name} — ${activeDir.presets[draft.pi]?.label ?? ""}`}
          onCancel={cancelAll}
          onSave={() => void confirmAll()}
          onReset={() => mutate({ preset: "amaliyat", pi: 0, mode: "dark", radius: "sharp", density: "compact", font: "md" })}
        />
      ) : null}
      {saving ? <Skeleton lines={2} /> : null}
    </div>
  );
}
