"use client";

import { useEffect, useState } from "react";
import { markdownToHtml } from "@/lib/markdown";

/**
 * راهنمای کامل توسعهٔ **قالب یا افزونه** (دوزبانه، با خروجی PDF).
 *
 * محتوا **از فایل‌های Markdown سروشده** می‌آید (`/theme-guide.*.md` و
 * `/plugin-guide.*.md` در `public/`)، نه هاردکد در کامپوننت؛ پس به‌روزرسانی سند
 * یعنی ویرایش همان فایل — نه بازسازی فرانت.
 *
 * E65 — چرا نوع‌آگاه: راهنمای افزونه باید **دقیقاً به جامعیِ** راهنمای قالب
 * باشد و همان تجربهٔ کاربری (تغییرِ زبان + دانلودِ PDF) را بدهد. به‌جای دو
 * کامپوننتِ موازی که دیر یا زود از هم واگرا می‌شوند، یک کامپوننت با `kind`
 * همین ساختار را برای هر دو فراهم می‌کند و تنها تفاوت، نامِ سند و عنوانِ PDF است.
 *
 * PDF: پنجرهٔ چاپِ مستقل با RTL+LTR و فونت محلی Vazirmatn ساخته می‌شود (بدون CDN،
 * طبق قانون پروژه). «Save as PDF» مرورگر فایل واقعی می‌دهد.
 *
 * امنیت: تبدیلِ Markdown در `lib/markdown.ts` **قبل** از هر تبدیل، HTML را escape
 * می‌کند، پس `dangerouslySetInnerHTML` اینجا روی محتوای خودمان و بدون HTML
 * خام است.
 *
 * چرا مبدل اینجا نیست: آن توابع خالص‌اند و در `lib/markdown.ts` زندگی می‌کنند تا
 * با `node --test` سنجیده شوند (این فایل JSX دارد و مستقیم آزمون‌پذیر نیست).
 */
export { markdownToHtml };

type State =
  | { status: "loading" }
  | { status: "ready"; html: string }
  | { status: "error"; message: string };

/** E65 — هر نوع یک سند و یک عنوانِ PDF دارد؛ بقیهٔ رفتار مشترک است. */
const DOCS = {
  theme: { path: "/theme-guide", pdfTitle: "Pishdad Theme Developer Guide" },
  plugin: { path: "/plugin-guide", pdfTitle: "Pishdad Plugin Developer Guide" },
} as const;

export type DeveloperGuideKind = keyof typeof DOCS;

export function MarkdownDeveloperGuide({ kind = "theme" }: { kind?: DeveloperGuideKind }) {
  const doc = DOCS[kind];
  const [lang, setLang] = useState<"fa" | "en">("fa");
  const [state, setState] = useState<State>({ status: "loading" });

  useEffect(() => {
    let alive = true;
    setState({ status: "loading" });
    fetch(`${doc.path}.${lang}.md`, { cache: "no-store" })
      .then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
      .then((md) => { if (alive) setState({ status: "ready", html: markdownToHtml(md) }); })
      .catch((e) => { if (alive) setState({ status: "error", message: e instanceof Error ? e.message : "?" }); });
    return () => { alive = false; };
  }, [lang, doc.path]);

  const html = state.status === "ready" ? state.html : "";

  const downloadPdf = () => {
    if (state.status !== "ready") return;
    const w = window.open("", "_blank");
    if (!w) return;
    const dir = lang === "en" ? "ltr" : "rtl";
    const origin = window.location.origin;
    w.document.write(
      `<!doctype html><html dir="${dir}" lang="${lang}"><head><meta charset="utf-8">` +
      `<title>${doc.pdfTitle}</title><style>` +
      `@font-face{font-family:Vazirmatn;src:url('${origin}/fonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;}` +
      `@font-face{font-family:Vazirmatn;src:url('${origin}/fonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;}` +
      `body{font-family:Vazirmatn,system-ui,sans-serif;line-height:1.95;color:#111;max-width:900px;margin:26px auto;padding:0 26px;}` +
      `h1{font-size:26px;} h2{font-size:20px;margin-top:30px;border-bottom:1px solid #ccc;padding-bottom:4px;} h3{font-size:16px;margin-top:20px;}` +
      `table{border-collapse:collapse;width:100%;margin:10px 0;font-size:12.5px;} th,td{border:1px solid #ccc;padding:6px 8px;text-align:start;vertical-align:top;}` +
      `pre{background:#f5f5f5;padding:10px;border-radius:6px;overflow:auto;font-size:11.5px;} code{font-family:ui-monospace,monospace;background:#f0f0f0;padding:0 4px;border-radius:3px;} pre code{background:none;padding:0;}` +
      `blockquote{border-inline-start:3px solid #888;padding-inline-start:10px;color:#444;margin:10px 0;} a{color:#1d4ed8;}` +
      `</style></head><body>${html}</body></html>`,
    );
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 500);
  };

  return (
    <div className="card card-pad" style={{ marginBlock: 12 }}>
      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", marginBlockEnd: 12 }}>
        <div className="seg" style={{ maxInlineSize: 220 }}>
          <button type="button" className={lang === "fa" ? "on" : ""} onClick={() => setLang("fa")}>فارسی</button>
          <button type="button" className={lang === "en" ? "on" : ""} onClick={() => setLang("en")}>English</button>
        </div>
        <div style={{ flex: 1 }} />
        <button type="button" className="btn btn-primary" onClick={downloadPdf} disabled={state.status !== "ready"}>
          ⤓ دانلود PDF · Download PDF
        </button>
      </div>

      {state.status === "loading" ? <p style={{ color: "var(--text-muted)" }}>در حال بارگذاری راهنما…</p> : null}
      {state.status === "error" ? (
        <p style={{ color: "var(--danger)" }}>بارگذاری راهنما ناموفق بود ({state.message}).</p>
      ) : null}
      {state.status === "ready" ? (
        <div className="theme-guide-doc" dir={lang === "en" ? "ltr" : "rtl"} dangerouslySetInnerHTML={{ __html: html }} />
      ) : null}
    </div>
  );
}
