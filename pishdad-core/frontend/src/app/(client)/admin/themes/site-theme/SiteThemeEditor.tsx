"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { authed, authedEnvelope } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, Skeleton } from "@/components/ui/primitives";
import { contrastCheck, formatRatio, parseColor, type WcagLevel } from "@/lib/contrast";

/**
 * F4.1.F — ویرایشگرِ قالبِ سایتِ عمومی.
 *
 * ## این صفحه با `/admin/appearance` فرق دارد
 *
 * `appearance` ظاهرِ **پنل** را عوض می‌کند (تم، شعاع، تراکم — همه روی
 * `:root`). این صفحه ظاهرِ **سایتِ عمومی** را عوض می‌کند (پوسته، رنگ‌بندی،
 * override — همه در scopeِ `.site`). این دو پیش از `F0.8` عملاً یکی بودند چون
 * سایت `:root` پنل را ارث می‌برد؛ حالا که جدا شده‌اند، یکی شدنشان یعنی برگشتِ
 * همان باگ.
 *
 * ## ⭐ چرا `PUT /overrides` و نه `PATCH`
 *
 * چون **کل** نقشهٔ override جایگذاری می‌شود: کلیدی که نیامد حذف است. با
 * `PATCH` وضعیتِ «کلیدی که فکر می‌کنم هست و نیست» تولید می‌شد — کاربر override
 * را پاک می‌کند، ذخیره می‌زند، و بک‌اند نادیده‌اش می‌گیرد.
 *
 * ## چرا هر نوشتن کلِ وضعیت را برمی‌گرداند
 *
 * هر پاسخ، `selection` + `tokens` تازه دارد و همان‌ها state می‌شوند. یعنی UI
 * بعد از ذخیره **همان چیزی** را نشان می‌دهد که سرور واقعاً حل کرده — نه
 * حدسِ خوش‌بینانهٔ ما. اگر بک‌اند override را رد کند (۴۲۲)، صفحه هم همان را
 * نشان می‌دهد.
 */

type ThemeRow = { id: number; key: string; name: string; slug: string; layout: string; is_builtin: number | boolean; source: string | null; version: string | null };
type PresetRow = { id: number; key: string; name: string; slug: string; is_builtin: number | boolean };
type Selection = { theme_slug: string; preset_slug: string; mode: "light" | "dark" | "system"; overrides: Record<string, string> };
type Tokens = Record<string, string>;

type State = { themes: ThemeRow[]; presets: PresetRow[]; selection: Selection | null; tokens: Tokens; modes: string[] };

const EMPTY: State = { themes: [], presets: [], selection: null, tokens: {}, modes: ["light", "dark", "system"] };

/**
 * نقش‌های قابلِ override، دقیقاً به همان ترتیبی که `SiteThemeResolver` تعریف
 * می‌کند. این فهرست **نمایشی** است — اعتبارسنجی سمت سرور است و
 * `site-theme-vocabulary.test.ts` تضمین می‌کند که این فهرست و فهرستِ resolver
 * هیچ‌وقت بی‌سروصدا از هم جدا نشوند.
 */
const COLOR_ROLES = [
  { key: "primary", fa: "رنگ اصلی" },
  { key: "primary-hover", fa: "رنگ اصلی (هاور)" },
  { key: "primary-soft", fa: "رنگ اصلی (ملایم)" },
  { key: "accent", fa: "رنگ تأکید" },
  { key: "accent-soft", fa: "رنگ تأکید (ملایم)" },
  { key: "bg", fa: "پس‌زمینه" },
  { key: "surface", fa: "سطح" },
  { key: "surface-2", fa: "سطح دوم" },
  { key: "text", fa: "متن" },
  { key: "text-muted", fa: "متن کم‌رنگ" },
  { key: "border", fa: "خط مرزی" },
] as const;

const LAYOUT_ROLES = [
  { key: "radius-md", fa: "شعاع گوشه (میانی)" },
  { key: "radius-lg", fa: "شعاع گوشه (بزرگ)" },
  { key: "fs-body", fa: "اندازهٔ متن" },
  { key: "fs-h", fa: "اندازهٔ عنوان" },
] as const;

const MODE_FA: Record<string, string> = { light: "روشن", dark: "تیره", system: "سیستم" };

/**
 * WF-M16 — جفت‌های پُرمصرفِ پیش‌زمینه/پس‌زمینهٔ سایت.
 *
 * هر جفت یک سنجه است، نه یک قانون: صفحه فقط هشدار می‌دهد و **ذخیره را مسدود
 * نمی‌کند**. آستانه‌ها طبق WCAG 2.2 — متن عادی ۴.۵:۱ (AA) و متن بزرگ ۳:۱.
 * `fgColor` برای رنگ‌های ثابتی است که نقشِ قابل‌override نیستند (مثل متنِ دکمه
 * که `--site-primary-text` است و همیشه سفید می‌ماند).
 */
type ContrastPair = {
  id: string;
  fa: string;
  fgRole?: string;
  fgColor?: string;
  bgRole: string;
  large?: boolean;
};

const CONTRAST_PAIRS: ContrastPair[] = [
  { id: "text-bg", fa: "متن روی پس‌زمینه", fgRole: "text", bgRole: "bg" },
  { id: "text-surface", fa: "متن روی سطح", fgRole: "text", bgRole: "surface" },
  { id: "text-surface2", fa: "متن روی سطح دوم", fgRole: "text", bgRole: "surface-2" },
  { id: "muted-bg", fa: "متن کم‌رنگ روی پس‌زمینه", fgRole: "text-muted", bgRole: "bg" },
  { id: "muted-surface", fa: "متن کم‌رنگ روی سطح", fgRole: "text-muted", bgRole: "surface" },
  { id: "primary-bg", fa: "رنگ اصلی روی پس‌زمینه", fgRole: "primary", bgRole: "bg" },
  { id: "primary-surface", fa: "رنگ اصلی روی سطح", fgRole: "primary", bgRole: "surface" },
  { id: "accent-bg", fa: "رنگ تأکید روی پس‌زمینه", fgRole: "accent", bgRole: "bg" },
  { id: "button-primary", fa: "متن دکمه روی رنگ اصلی", fgColor: "#ffffff", bgRole: "primary" },
  { id: "button-primary-hover", fa: "متن دکمه روی رنگ اصلی (هاور)", fgColor: "#ffffff", bgRole: "primary-hover" },
  { id: "border-surface", fa: "خط مرزی روی سطح (مؤلفهٔ رابط)", fgRole: "border", bgRole: "surface", large: true },
];

const LEVEL_FA: Record<WcagLevel, string> = { passAAA: "AAA", passAA: "AA", fail: "ناموفق" };
const LEVEL_RANK: Record<WcagLevel, number> = { fail: 0, passAA: 1, passAAA: 2 };

/** نقش‌های رنگی از توکن‌های حل‌شده گرفته می‌شوند تا پیش‌نمایش با ذخیره یکی باشد. */
const tokenOf = (tokens: Tokens, role: string) => tokens[`--theme-${role}`] ?? "";

export function SiteThemeEditor() {
  const toast = useToast();
  const [state, setState] = useState<State>(EMPTY);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  /** پیش‌نویسِ override؛ ذخیره تا «ذخیره تغییرات» زده نشود اعمال نمی‌شود. */
  const [draft, setDraft] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    try {
      const json = await authed<State>("/v1/admin/site-theme");
      setState({
        themes: Array.isArray(json?.themes) ? json.themes : [],
        presets: Array.isArray(json?.presets) ? json.presets : [],
        selection: json?.selection ?? null,
        tokens: json?.tokens ?? {},
        modes: Array.isArray(json?.modes) ? json.modes : EMPTY.modes,
      });
      setDraft(json?.selection?.overrides ?? {});
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : "بارگذاری قالب سایت ناموفق بود.");
    } finally {
      setLoading(false);
    }
  }, []);

  // ⭐ فقط در `useEffect`. فراخوانی در بدنهٔ رندر = React #301.
  useEffect(() => {
    void load();
  }, [load]);

  /** یک نوشتنِ مشترک: پاسخ، وضعیتِ تازه را برمی‌گرداند و ما همان را می‌پذیریم. */
  const write = useCallback(
    async (path: string, method: "POST" | "PUT", body: unknown, okMessage: string) => {
      setBusy(true);
      try {
        const env = await authedEnvelope<{ selection: Selection; tokens: Tokens }>(path, { method, body });
        setState((s) => ({ ...s, selection: env.data.selection, tokens: env.data.tokens }));
        setDraft(env.data.selection?.overrides ?? {});
        setError(null);
        toast(env.message ?? okMessage, "ok");
        // بازخوانی کامل، چون فهرستِ قالب/رنگ‌بندی هم می‌تواند از راه reset
        // عوض شود و `state` کهنه یعنی انتخابِ ناموجود.
        void load();
      } catch (e) {
        toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
      } finally {
        setBusy(false);
      }
    },
    [load, toast],
  );

  const sel = state.selection;

  if (loading) return <Skeleton lines={6} />;
  if (error && !sel) return <Alert tone="red">{error}</Alert>;
  if (!sel) return <Alert tone="amber">پوسته و رنگ‌بندیِ سایت هنوز قابل خواندن نیست.</Alert>;

  const dirty = JSON.stringify(draft) !== JSON.stringify(sel.overrides ?? {});

  /** مقدارِ نهاییِ یک نقش: پیش‌نویس بر توکنِ حل‌شده مقدم است. */
  const resolveToken = (role: string) => draft[role] ?? tokenOf(state.tokens, role) ?? "";

  /**
   * سنجش کاملاً مشتق‌شده است — نه state، نه افکت. در بدنهٔ رندر فقط محاسبه
   * می‌شود و هیچ `setState`/`fetch`ی ندارد، پس React #301 ممکن نیست.
   */
  const audit = CONTRAST_PAIRS.map((pair) => {
    const fg = pair.fgColor ?? resolveToken(pair.fgRole ?? "");
    const bg = resolveToken(pair.bgRole);
    const valid = parseColor(fg) !== null && parseColor(bg) !== null;
    const check = contrastCheck(fg, bg, { large: pair.large });
    return { pair, fg, bg, valid, ...check };
  });

  /** بدترین دستهٔ نقش در همهٔ جفت‌های درگیر؛ `null` یعنی هیچ جفتی درگیرش نبود. */
  const roleLevel = (role: string): WcagLevel | null => {
    let worst: WcagLevel | null = null;
    for (const row of audit) {
      if (!row.valid || (row.pair.fgRole !== role && row.pair.bgRole !== role)) continue;
      if (worst === null || LEVEL_RANK[row.level] < LEVEL_RANK[worst]) worst = row.level;
    }
    return worst;
  };

  return (
    <div>
      <div className="grid c2">
        {/* ── پوسته ── */}
        <div className="card card-pad">
          <div className="card-title">پوستهٔ سایت</div>
          <p className="muted" style={{ fontSize: 12, margin: "0 0 10px" }}>
            چیدمان و بلوک‌های آمادهٔ صفحات. تغییرش کشِ سایت را باطل می‌کند.
          </p>
          <div style={{ display: "grid", gap: 6 }}>
            {state.themes.map((t) => (
              <label key={t.id} className={`mobile-nav-option${t.slug === sel.theme_slug ? " selected" : ""}`} style={{ cursor: "pointer" }}>
                <input
                  type="radio"
                  name="site-theme"
                  checked={t.slug === sel.theme_slug}
                  onChange={() => void write("/v1/admin/site-theme/select", "POST", { theme_slug: t.slug, preset_slug: sel.preset_slug, mode: sel.mode }, "قالب سایت ذخیره شد.")}
                />
                <span className="mobile-nav-option-label" style={{ minBlockSize: 0 }}>
                  <b>{t.name}</b>
                  <small dir="ltr" style={{ color: "var(--text-muted)" }}>{t.slug}{t.version ? ` · ${t.version}` : ""}</small>
                </span>
                {t.is_builtin ? <Badge tone="gray">درون‌ساخت</Badge> : null}
              </label>
            ))}
          </div>
        </div>

        {/* ── رنگ‌بندی + حالت ── */}
        <div className="card card-pad">
          <div className="card-title">رنگ‌بندی</div>
          <p className="muted" style={{ fontSize: 12, margin: "0 0 10px" }}>
            پالتِ رنگِ سایت. جدا از تمِ پنل است: عوض‌کردنش رنگِ این پنل را تکان نمی‌دهد.
          </p>
          <div style={{ display: "grid", gap: 6 }}>
            {state.presets.map((p) => (
              <label key={p.id} className={`mobile-nav-option${p.slug === sel.preset_slug ? " selected" : ""}`} style={{ cursor: "pointer" }}>
                <input
                  type="radio"
                  name="site-preset"
                  checked={p.slug === sel.preset_slug}
                  onChange={() => void write("/v1/admin/site-theme/preset", "PUT", { preset_slug: p.slug }, "رنگ‌بندی ذخیره شد.")}
                />
                <span className="mobile-nav-option-label" style={{ minBlockSize: 0 }}>
                  {/* نمونه از خودِ توکن‌های حل‌شده — همیشه همان چیزی که سایت می‌بیند. */}
                  <span className="dot on" style={{ background: `var(--primary)` }} aria-hidden />
                  <b>{p.name}</b>
                </span>
                <small dir="ltr" style={{ color: "var(--text-muted)" }}>{p.slug}</small>
              </label>
            ))}
          </div>

          <div className="field" style={{ marginBlockStart: 12 }}>
            <label>حالت</label>
            <div className="seg" role="group" aria-label="حالت سایت">
              {state.modes.map((m) => (
                <button
                  key={m}
                  type="button"
                  className={sel.mode === m ? "on" : ""}
                  aria-pressed={sel.mode === m}
                  onClick={() => void write("/v1/admin/site-theme/preset", "PUT", { preset_slug: sel.preset_slug, mode: m }, "حالت ذخیره شد.")}
                >
                  {MODE_FA[m] ?? m}
                </button>
              ))}
            </div>
            <small style={{ color: "var(--text-muted)" }}>
              «سیستم» یعنی روشن/تیره‌بودن از تنظیمِ دستگاهِ بازدیدکننده می‌آید.
            </small>
          </div>
        </div>
      </div>

      {/* ── override‌های موردی ── */}
      <div className="card card-pad" style={{ marginBlockStart: 14 }}>
        <div className="card-title">تنظیم موردی</div>
        <p className="muted" style={{ fontSize: 12, margin: "0 0 10px" }}>
          روی رنگ‌بندیِ انتخابی سوار می‌شود. خالی گذاشتن یعنی «از رنگ‌بندی پیروی کن».
        </p>
        <div className="grid c2">
          {COLOR_ROLES.map((r) => {
            const value = draft[r.key] ?? "";
            const worst = roleLevel(r.key);
            return (
              <div className="field" key={r.key}>
                <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
                  <span>{r.fa}</span>
                  {worst === "fail" ? <Badge tone="red">کم‌کنتراست</Badge> : null}
                </label>
                <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
                  <input
                    type="color"
                    aria-label={r.fa}
                    value={value || tokenOf(state.tokens, r.key) || "#6366f1"}
                    onChange={(e) => setDraft((d) => ({ ...d, [r.key]: e.target.value }))}
                    style={{ inlineSize: 40, blockSize: 32, padding: 0, border: "1px solid var(--border)", borderRadius: "var(--radius-md)", background: "none" }}
                  />
                  <input
                    className="input"
                    dir="ltr"
                    style={{ textAlign: "left" }}
                    value={value}
                    placeholder={tokenOf(state.tokens, r.key) || "#rrggbb"}
                    onChange={(e) => setDraft((d) => ({ ...d, [r.key]: e.target.value }))}
                  />
                  <span className={`dot ${value ? "on" : "mute"}`} aria-hidden title={value ? "دستی" : "از رنگ‌بندی"} />
                </div>
              </div>
            );
          })}
          {LAYOUT_ROLES.map((r) => (
            <div className="field" key={r.key}>
              <label>{r.fa}</label>
              <input
                className="input"
                dir="ltr"
                style={{ textAlign: "left" }}
                value={draft[r.key] ?? ""}
                placeholder={tokenOf(state.tokens, r.key) || "—"}
                onChange={(e) => setDraft((d) => ({ ...d, [r.key]: e.target.value }))}
              />
            </div>
          ))}
        </div>

        <div style={{ display: "flex", gap: 8, marginBlockStart: 12, flexWrap: "wrap" }}>
          <button
            className="btn btn-primary"
            disabled={!dirty || busy}
            onClick={() => void write("/v1/admin/site-theme/overrides", "PUT", { overrides: draft }, "تنظیمات ذخیره شد.")}
          >
            ذخیرهٔ تنظیم موردی
          </button>
          <button className="btn btn-ghost" disabled={!dirty} onClick={() => setDraft(sel.overrides ?? {})}>
            بازگرداندن پیش‌نویس
          </button>
          <div style={{ flex: 1 }} />
          {/*
            * `reset` نه `DELETE`: قالب یک رکوردِ نصب‌شده است که نمی‌میرد؛ چیزی که
            * پاک می‌شود **انتخابِ نصب** است. نامی که این تفاوت را پنهان کند، بعداً
            * یکی `site_themes` را می‌ترکاند.
            */}
          <button
            className="btn btn-ghost"
            disabled={busy}
            onClick={() => void write("/v1/admin/site-theme/reset", "POST", {}, "به پیش‌فرض برگشت.")}
          >
            بازگشت به پیش‌فرض
          </button>
        </div>
      </div>

      {/* ── WF-M16 — سنجش کنتراست (هشدار، نه مسدودکننده) ── */}
      <div className="card card-pad" style={{ marginBlockStart: 14 }}>
        <div className="card-title">سنجش کنتراست (WCAG AA)</div>
        <p className="muted" style={{ fontSize: 12, margin: "0 0 10px" }}>
          نسبتِ کنتراستِ جفت‌های پُرمصرف، بر پایهٔ پیش‌نویسِ فعلی. آستانهٔ متن عادی ۴.۵:۱ و
          متن بزرگ/مؤلفهٔ رابط ۳:۱ است. این سنجش فقط <b>هشدار</b> می‌دهد و ذخیره را مسدود نمی‌کند.
        </p>
        <div>
          {audit.map(({ pair, fg, bg, ratio, level, valid }) => (
            <div
              key={pair.id}
              style={{
                display: "flex",
                alignItems: "center",
                gap: 8,
                padding: "6px 0",
                borderBlockEnd: "1px solid var(--border)",
              }}
            >
              <span
                aria-hidden
                style={{
                  inlineSize: 46,
                  blockSize: 28,
                  flex: "0 0 auto",
                  display: "inline-flex",
                  alignItems: "center",
                  justifyContent: "center",
                  borderRadius: "var(--radius-md)",
                  border: "1px solid var(--border)",
                  background: valid ? bg : "transparent",
                  color: valid ? fg : "var(--text-muted)",
                  fontSize: 12,
                  fontWeight: 700,
                }}
              >
                آ
              </span>
              <span style={{ flex: 1, fontSize: 12.5 }}>
                {pair.fa}
                {pair.large ? <small className="muted"> · آستانهٔ ۳:۱</small> : null}
              </span>
              <span dir="ltr" style={{ fontSize: 12, fontVariantNumeric: "tabular-nums", color: "var(--text-muted)" }}>
                {formatRatio(ratio)}
              </span>
              <Badge tone={!valid ? "gray" : level === "passAAA" ? "green" : level === "passAA" ? "gray" : "red"}>
                {!valid ? "نامعتبر" : LEVEL_FA[level]}
              </Badge>
            </div>
          ))}
        </div>
      </div>

      {/*
       * ⭐ پیش‌نمایش **سراسری** (`/preview`)، نه modal.
       *
       * modal داخل `Shell` رندر می‌شود، پس `:root` پنل را می‌ارث‌برد و آن‌وقت
       * دقیقاً همان چیزی را نشان می‌دهد که می‌خواستیم ثابت کنیم اشتباه است: سایت
       * با توکن‌های پنل. مسیرِ `/preview` بیرون از هر گروهِ مسیر است، پس
       * `Shell` ندارد و scopeِ سایت واقعاً مستقل است.
       */}
      <div className="card card-pad" style={{ marginBlockStart: 14 }}>
        <div className="card-title">پیش‌نمایش زنده</div>
        <p className="muted" style={{ fontSize: 12, margin: "0 0 10px" }}>
          صفحه‌ای جدا از پنل — بدون هدر و بدون توکن‌های پنل. همان چیزی که بازدیدکننده
          می‌بیند، نه آنچه داخل این پنل رندر می‌شود.
        </p>
        <a className="btn btn-primary" href="/preview" target="_blank" rel="noreferrer">
          باز کردن پیش‌نمایش در تب تازه ←
        </a>
        <div style={{ marginBlockStart: 6 }}>
          <Link className="muted" style={{ fontSize: 12 }} href="/">
            مشاهدهٔ سایتِ واقعی
          </Link>
        </div>
      </div>
    </div>
  );
}
