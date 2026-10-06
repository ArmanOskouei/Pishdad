/**
 * WF-M16 — سنجش کنتراست WCAG.
 *
 * این ماژول **خالص** است: بدون DOM، بدون React، بدون CSS. فقط سه چیز:
 * تجزیهٔ رنگ، محاسبهٔ درخشندگیِ نسبی، و نسبتِ کنتراست. همین خالص‌بودن باعث
 * می‌شود که `node --test` بتواند بدونِ هیچ رندرری صداش بزند.
 *
 * مرجع: WCAG 2.2 — 1.4.3 (کنتراست متن) و 1.4.11 (کنتراست غیرمتن).
 */

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

/** `passAAA` سخت‌گیرانه‌ترین، `fail` یعنی زیر آستانهٔ AA. */
export type WcagLevel = "passAAA" | "passAA" | "fail";

/** آستانه‌های AA و AAA برای متن عادی. */
export const AA_NORMAL = 4.5;
export const AAA_NORMAL = 7;
/** آستانه‌های AA و AAA برای متن بزرگ (یا مؤلفهٔ رابط ۱.۴.۱۱). */
export const AA_LARGE = 3;
export const AAA_LARGE = 4.5;

const HEX = /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
const RGB_FN = /^rgba?\(([^)]+)\)$/i;

const clampByte = (n: number): number => Math.min(255, Math.max(0, n));

function parseChannel(raw: string): number | null {
  const t = raw.trim();
  if (t === "") return null;
  if (t.endsWith("%")) {
    const p = Number(t.slice(0, -1));
    if (!Number.isFinite(p)) return null;
    return clampByte(Math.round((p / 100) * 255));
  }
  const n = Number(t);
  if (!Number.isFinite(n)) return null;
  return clampByte(Math.round(n));
}

/**
 * `#rgb`، `#rgba`، `#rrggbb`، `#rrggbbaa`، `rgb(...)` و `rgba(...)` را
 * می‌فهمد (با کاما یا فاصله، و کانالِ درصدی). آلفا **نادیده** گرفته می‌شود —
 * کنتراست روی کانال‌های رنگی سنجیده می‌شود، نه شفافیت. هر چیزِ دیگری
 * (مثل `color-mix(...)` یا `transparent`) `null` می‌دهد تا حلقهٔ فراخوان
 * بتواند به‌جای هشدارِ کاذب، آن جفت را رد کند.
 */
export function parseColor(input: string): Rgb | null {
  if (typeof input !== "string") return null;
  const value = input.trim();
  if (value === "") return null;

  if (value.startsWith("#")) {
    if (!HEX.test(value)) return null;
    let hex = value.slice(1);
    if (hex.length === 3 || hex.length === 4) {
      hex = hex
        .split("")
        .map((c) => c + c)
        .join("");
    }
    return {
      r: parseInt(hex.slice(0, 2), 16),
      g: parseInt(hex.slice(2, 4), 16),
      b: parseInt(hex.slice(4, 6), 16),
    };
  }

  const fn = RGB_FN.exec(value);
  if (fn) {
    const parts = fn[1].split(/[\s,/]+/).filter(Boolean);
    if (parts.length < 3) return null;
    const r = parseChannel(parts[0]);
    const g = parseChannel(parts[1]);
    const b = parseChannel(parts[2]);
    if (r === null || g === null || b === null) return null;
    return { r, g, b };
  }

  return null;
}

/** درخشندگیِ نسبیِ WCAG؛ برای ورودیِ نامعتبر `NaN`. */
export function relativeLuminance(color: Rgb | string): number {
  const rgb = typeof color === "string" ? parseColor(color) : color;
  if (!rgb) return NaN;

  const linear = (channel: number): number => {
    const s = channel / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };

  return 0.2126 * linear(rgb.r) + 0.7152 * linear(rgb.g) + 0.0722 * linear(rgb.b);
}

/** نسبتِ کنتراست (۱ تا ۲۱). برای ورودیِ نامعتبر `NaN`. */
export function contrastRatio(a: Rgb | string, b: Rgb | string): number {
  const la = relativeLuminance(a);
  const lb = relativeLuminance(b);
  if (!Number.isFinite(la) || !Number.isFinite(lb)) return NaN;
  const light = Math.max(la, lb);
  const dark = Math.min(la, lb);
  return (light + 0.05) / (dark + 0.05);
}

/** دستهٔ نسبت نسبت به آستانه‌ها. `large` یعنی متن بزرگ (سطح ۳:۱). */
export function classifyContrast(ratio: number, large = false): WcagLevel {
  if (!Number.isFinite(ratio)) return "fail";
  const aa = large ? AA_LARGE : AA_NORMAL;
  const aaa = large ? AAA_LARGE : AAA_NORMAL;
  if (ratio >= aaa) return "passAAA";
  if (ratio >= aa) return "passAA";
  return "fail";
}

export interface ContrastResult {
  /** نسبتِ کامل (رُندنشده) — نمایش با `toFixed` کارِ UI است. */
  ratio: number;
  level: WcagLevel;
  passAA: boolean;
  passAAA: boolean;
}

/** یک‌جا نسبت + دسته + بولین‌ها؛ همان چیزی که UI لازم دارد. */
export function contrastCheck(
  fg: Rgb | string,
  bg: Rgb | string,
  opts: { large?: boolean } = {},
): ContrastResult {
  const ratio = contrastRatio(fg, bg);
  const level = classifyContrast(ratio, opts.large ?? false);
  return { ratio, level, passAA: level !== "fail", passAAA: level === "passAAA" };
}

/** نسبت را برای نمایش آماده می‌کند؛ نامعتبر → `—`. */
export function formatRatio(ratio: number): string {
  return Number.isFinite(ratio) ? ratio.toFixed(2) : "—";
}
