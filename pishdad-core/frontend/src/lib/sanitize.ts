/**
 * F0.2 — sanitizers مشترک فرانت.
 *
 * چون اینجا و نه داخل `BlockRenderer.tsx`:
 *  - ویجت `cta` در `Chrome.tsx` و آیتم‌های `links`/`nav`/`socials` در `Chrome.tsx`
 *    و `SiteNav.tsx` هم `href` می‌دهند و تا امروز هیچ sanitize‌ای نداشتند.
 *  - `PageEditor.tsx` امروز مصرف‌کنندهٔ `safeHtml` نیست، ولی اگر روزی پیش‌نمایش
 *    بلوک ریچ‌تکست اضافه کند، مسیر درست از همین فایل است نه از کامپوننتِ سایتِ
 *    عمومی (که جهت وابستگیِ معکوس می‌شد).
 *
 * ⚠️ این لایه **دفاع عمقی** است، نه منبع حقیقت. منبع حقیقت `App\Validation\SafeUrl`
 * در بک‌اند است که روی نوشتن اجرا می‌شود و ویجت‌های پلاگینی را هم پوشش می‌دهد.
 * چیزی که اینجا می‌ماند فقط دادهٔ ازپیش‌موجود در DB را پوشش می‌دهد.
 *
 * قرارداد باید با `App\Validation\SafeUrl` هم‌تراز بماند:
 *   - lookahead `(?!\/)` برای رد `//evil.com`
 *   - رد `\…` بعد از `/` چون مرورگر بک‌اسلش را مثل اسلش می‌خواند ⇒ open-redirect
 *   - حذف نویسه‌های کنترلی *فقط برای آزمون* (وگرنه `java\tscript:` زنده است)
 *   - `?` و `tel:` که بک‌اند می‌پذیرد و این نسخهٔ قبلی نداشت
 */

/**
 * نشانی امن برای `href`.
 *
 * `data.href` بلوک CTA و ویجت CTA کروم دادهٔ کاربر است و `safeHtml` فقط متن
 * ریچ‌تکست را می‌شوید، پس `javascript:alert(1)` بدون این تابع مستقیم به `<a>`
 * می‌رسد و زنده است.
 */
const HREF_OK = /^(?:\/(?![/\\])|#|\?|https?:\/\/|mailto:|tel:)/i;

/**
 * `src` امن — همان allowlist بدون `mailto:`/`tel:`/`#` که برای منبع فایل بی‌معنی‌اند.
 * `data:` عمداً نیست: `data:image/svg+xml` می‌تواند `<script>` در خود داشته باشد.
 */
const SRC_OK = /^(?:\/(?![/\\])|https?:\/\/)/i;

/** پروتکل‌هایی که هرگز مجاز نیستند — دفاع در برابر دور زدن الگو. */
const DENY_PREFIXES = ["javascript:", "data:", "vbscript:", "file:"];

function safeUrl(raw: unknown, ok: RegExp): string | null {
  if (typeof raw !== "string") return null;
  const v = raw.trim();
  if (!v) return null;

  // `java\tscript:` / `java\nscript:` — مرورگر TAB/LF را از URL حذف می‌کند،
  // پس بدون این مرحله الگوی بالا دور زده می‌شود. فقط برای مقایسه پاک می‌شود؛
  // خودِ نشانی دست‌نخورده برمی‌گردد.
  const probe = v.replace(/[\u0000-\u0020\u007f]+/g, "").toLowerCase();
  if (DENY_PREFIXES.some((p) => probe.startsWith(p))) return null;

  if (!ok.test(v.replace(/[\u0000-\u001f\u007f]/g, ""))) return null;
  return v;
}

export function safeHref(raw: unknown): string | null {
  return safeUrl(raw, HREF_OK);
}

export function safeSrc(raw: unknown): string | null {
  return safeUrl(raw, SRC_OK);
}

/** متن ریچ‌تکست Jodit با allowlist ساده sanitize می‌شود (بدون lib سنگین). */
export function safeHtml(html: string): string {
  const ALLOWED = new Set(["p", "br", "b", "strong", "i", "em", "u", "s", "a", "ul", "ol", "li", "h1", "h2", "h3", "h4", "blockquote", "code", "pre", "hr", "span", "div"]);
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, "")
    .replace(/<style[\s\S]*?<\/style>/gi, "")
    .replace(/<iframe[\s\S]*?<\/iframe>/gi, "")
    .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, "")
    .replace(/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/g, (m, tag: string) => {
      const t = tag.toLowerCase();
      if (!ALLOWED.has(t)) return "";
      if (t === "a") {
        const href = /href\s*=\s*"([^"]*)"/i.exec(m)?.[1] ?? "#";
        return `<a href="${(safeHref(href) ?? "#").replace(/"/g, "&quot;")}">`;
      }
      return t === "br" || t === "hr" ? `<${t}>` : m.startsWith("</") ? `</${t}>` : `<${t}>`;
    });
}
