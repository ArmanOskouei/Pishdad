/**
 * تبدیل Markdown به HTML برای راهنماهای توسعه‌دهنده (قالب/افزونه).
 *
 * چرا ماژولِ جدا و نه داخلِ کامپوننت: این توابع **خالص**اند و هیچ وابستگی‌ای به
 * React ندارند، پس با `node --test` بدون JSX قابل سنجش‌اند. راهنمای افزونه (E65)
 * یک سندِ ~۴۸KB است و اگر مبدل جایی کم بیاورد، کاربر یک راهنمای به‌هم‌ریخته
 * می‌بیند؛ این‌جا می‌شود همان را واقعاً آزمود.
 *
 * ⚠️ امنیت: `inline` **قبل** از هر تبدیل، HTML را escape می‌کند، پس خروجی برای
 * `dangerouslySetInnerHTML` روی محتوای خودمان بی‌خطر است.
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

/** تفکیک سلولِ جدول با احترام به `\|` (پایپِ escape‌شده). */
export function splitCells(row: string): string[] {
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
