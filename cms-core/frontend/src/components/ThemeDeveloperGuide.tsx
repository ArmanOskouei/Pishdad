"use client";

import { useEffect, useState } from "react";

/**
 * راهنمای کامل توسعهٔ قالب (دوزبانه، با خروجی PDF).
 *
 * محتوا **از فایل‌های Markdown سروشده** می‌آید (`/theme-guide.fa.md` و
 * `/theme-guide.en.md` در `public/`)، نه هاردکد در کامپوننت؛ پس به‌روزرسانی سند
 * یعنی ویرایش همان فایل — نه بازسازی فرانت.
 *
 * PDF: پنجرهٔ چاپِ مستقل با RTL+LTR و فونت محلی Vazirmatn ساخته می‌شود (بدون CDN،
 * طبق قانون پروژه). «Save as PDF» مرورگر فایل واقعی می‌دهد.
 *
 * امنیت: خروجی `markdownToHtml` **قبل** از هر تبدیل، HTML را escape می‌کند، پس
 * `dangerouslySetInnerHTML` اینجا روی محتوای خودمان و بدون HTML خام است.
 */

function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function inline(s: string): string {
  let t = esc(s);
  t = t.replace(/`([^`]+)`/g, "<code>$1</code>");
  t = t.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
  t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>');
  return t;
}

/** تفکیک سلولِ جدول با احترام به `\|` (پایپِ escapeشده). */
function splitCells(row: string): string[] {
  return row.replace(/^\||\|$/g, "").split(/(?<!\\)\|/).map((c) => c.trim().replace(/\\\|/g, "|"));
}

/** تبدیل Markdown امن (خروجی HTML) — بلوک کد، سرتیتر، جدول، لیست، نقل‌قول، افقی. */
export function markdownToHtml(md: string): string {
  const lines = md.split(/\r?\n/);
  const out: string[] = [];
  let inCode = false;
  let code: string[] = [];
  let list: "ul" | "ol" | null = null;
  let table: string[] = [];

  const flushList = () => { if (list) { out.push(`</${list}>`); list = null; } };
  const flushTable = () => {
    if (!table.length) return;
    const rows = table.map(splitCells);
    const head = rows[0] ?? [];
    const body = rows.slice(2);
    out.push(
      "<table><thead><tr>" + head.map((h) => `<th>${inline(h)}</th>`).join("") + "</tr></thead><tbody>" +
      body.map((r) => "<tr>" + r.map((c) => `<td>${inline(c)}</td>`).join("") + "</tr>").join("") +
      "</tbody></table>",
    );
    table = [];
  };

  for (const raw of lines) {
    if (raw.trimStart().startsWith("```")) {
      if (!inCode) { flushList(); flushTable(); inCode = true; code = []; }
      else { out.push("<pre><code>" + esc(code.join("\n")) + "</code></pre>"); inCode = false; }
      continue;
    }
    if (inCode) { code.push(raw); continue; }

    if (/^\s*\|.*\|\s*$/.test(raw)) { flushList(); table.push(raw.trim()); continue; }
    flushTable();

    const h = /^(#{1,6})\s+(.*)$/.exec(raw);
    if (h) { flushList(); const n = h[1]!.length; out.push(`<h${n}>${inline(h[2]!)}</h${n}>`); continue; }

    const q = /^>\s?(.*)$/.exec(raw);
    if (q) { flushList(); out.push(`<blockquote>${inline(q[1]!)}</blockquote>`); continue; }

    if (/^(-|\*)\s+/.test(raw)) { if (list !== "ul") { flushList(); out.push("<ul>"); list = "ul"; } out.push(`<li>${inline(raw.replace(/^(-|\*)\s+/, ""))}</li>`); continue; }
    if (/^\d+\.\s+/.test(raw)) { if (list !== "ol") { flushList(); out.push("<ol>"); list = "ol"; } out.push(`<li>${inline(raw.replace(/^\d+\.\s+/, ""))}</li>`); continue; }
    if (/^-{3,}$/.test(raw.trim())) { flushList(); out.push("<hr/>"); continue; }
    if (raw.trim() === "") { flushList(); continue; }

    flushList();
    out.push(`<p>${inline(raw)}</p>`);
  }
  flushList();
  flushTable();
  if (inCode) out.push("<pre><code>" + esc(code.join("\n")) + "</code></pre>");
  return out.join("\n");
}

type State =
  | { status: "loading" }
  | { status: "ready"; html: string }
  | { status: "error"; message: string };

export function ThemeDeveloperGuide() {
  const [lang, setLang] = useState<"fa" | "en">("fa");
  const [state, setState] = useState<State>({ status: "loading" });

  useEffect(() => {
    let alive = true;
    setState({ status: "loading" });
    fetch(`/theme-guide.${lang}.md`, { cache: "no-store" })
      .then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
      .then((md) => { if (alive) setState({ status: "ready", html: markdownToHtml(md) }); })
      .catch((e) => { if (alive) setState({ status: "error", message: e instanceof Error ? e.message : "?" }); });
    return () => { alive = false; };
  }, [lang]);

  const html = state.status === "ready" ? state.html : "";

  const downloadPdf = () => {
    if (state.status !== "ready") return;
    const w = window.open("", "_blank");
    if (!w) return;
    const dir = lang === "en" ? "ltr" : "rtl";
    const origin = window.location.origin;
    w.document.write(
      `<!doctype html><html dir="${dir}" lang="${lang}"><head><meta charset="utf-8">` +
      `<title>Pishdad Theme Developer Guide</title><style>` +
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
