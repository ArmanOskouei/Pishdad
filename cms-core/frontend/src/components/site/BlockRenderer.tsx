import { mediaUrl, type BlockValue } from "@/lib/domain";
import { ContactForm } from "./ContactForm";

/**
 * F3 — رندر بلوک‌های صفحه عمومی به کامپوننت.
 * hero/text/image/cta/gallery از رجیستری بک‌اند (config/blocks.php) +
 * quote/video/contact-form برای سازگاری با بلوک‌های پلاگینی آینده.
 * بلوک `_enabled === false` رد می‌شود (تغییر فعال/غیرفعال صفحه ۱.۱۲).
 */

/** متن ریچ‌تکست Jodit با allowlist ساده sanitize می‌شود (بدون lib سنگین). */
export function safeHtml(html: string): string {
  const ALLOWED = new Set(["p", "br", "b", "strong", "i", "em", "u", "s", "a", "ul", "ol", "li", "h1", "h2", "h3", "h4", "blockquote", "code", "pre", "hr", "span", "div"]);
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, "")
    .replace(/<style[\s\S]*?<\/style>/gi, "")
    .replace(/<iframe[\s\S]*?<\/iframe>/gi, "")
    .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, "")
    .replace(/(href|src)\s*=\s*"(javascript|data|vbscript):[^"]*"/gi, '$1="#"')
    .replace(/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/g, (m, tag: string) => {
      const t = tag.toLowerCase();
      if (!ALLOWED.has(t)) return "";
      if (t === "a") {
        const href = /href\s*=\s*"([^"]*)"/i.exec(m)?.[1] ?? "#";
        const safe = /^(\/|#|https?:\/\/|mailto:)/i.test(href) ? href : "#";
        return `<a href="${safe}">`;
      }
      return t === "br" || t === "hr" ? `<${t}>` : m.startsWith("</") ? `</${t}>` : `<${t}>`;
    });
}

function str(v: unknown): string {
  return typeof v === "string" ? v : "";
}

function mediaSrc(d: Record<string, unknown>): string | null {
  for (const k of ["url", "src", "image_url"]) {
    const v = d[k];
    if (typeof v === "string" && v) return v;
  }
  // media_id بدون endpoint عمومی قابل resolve نیست (TODO بک‌اند: URL آماده در payload صفحه).
  // اگر path مستقیم داده شده بود، از بیس MinIO بساز:
  const path = d.path;
  if (typeof path === "string" && path) return mediaUrl({ path });
  return null;
}

export function BlockRenderer({ blocks }: { blocks: BlockValue[] }) {
  const visible = blocks.filter((b) => (b.data as Record<string, unknown>)?._enabled !== false);
  if (visible.length === 0) {
    return <p style={{ color: "var(--text-muted)" }}>محتوایی برای این صفحه ثبت نشده است.</p>;
  }
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 28 }}>
      {visible.map((b, i) => (
        <Block key={i} type={b.type} data={(b.data ?? {}) as Record<string, unknown>} />
      ))}
    </div>
  );
}

function Block({ type, data }: { type: string; data: Record<string, unknown> }) {
  switch (type) {
    case "hero": {
      const align = data.align === "center" ? "center" : data.align === "left" ? "left" : "right";
      const bg = mediaSrc(data);
      return (
        <section
          style={{
            textAlign: align as "left" | "center" | "right",
            padding: "56px 24px",
            borderRadius: 16,
            background: bg ? `url(${bg}) center/cover` : "var(--primary-soft)",
            border: "1px solid var(--border)",
          }}
        >
          <h1 style={{ fontSize: 30, margin: 0 }}>{str(data.title) || "—"}</h1>
          {str(data.subtitle) ? <p style={{ fontSize: 16, color: "var(--text-muted)" }}>{str(data.subtitle)}</p> : null}
        </section>
      );
    }
    case "text": {
      const body = str(data.body ?? data.html);
      if (!body) return null;
      return <div dangerouslySetInnerHTML={{ __html: safeHtml(body) }} style={{ lineHeight: 2, fontSize: 15 }} />;
    }
    case "image": {
      const src = mediaSrc(data);
      if (!src) {
        return (
          <figure style={{ margin: 0, padding: 16, border: "1px dashed var(--border)", borderRadius: 12, color: "var(--text-muted)", fontSize: 13 }}>
            تصویر #{String(data.media_id ?? "—")} — resolve عمومی مدیا در بک‌اند نیست (TODO).
            {str(data.caption) ? <figcaption>{str(data.caption)}</figcaption> : null}
          </figure>
        );
      }
      return (
        <figure style={{ margin: 0 }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={src} alt={str(data.alt) || "تصویر"} loading="lazy" style={{ maxInlineSize: "100%", borderRadius: 12 }} />
          {str(data.caption) ? <figcaption style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockStart: 6 }}>{str(data.caption)}</figcaption> : null}
        </figure>
      );
    }
    case "cta": {
      const style = data.style === "secondary" ? "btn-ghost" : data.style === "ghost" ? "btn-ghost" : "btn-primary";
      return (
        <div>
          <a className={`btn ${style}`} href={str(data.href) || "#"}>{str(data.label) || "ادامه"}</a>
        </div>
      );
    }
    case "gallery": {
      const ids = Array.isArray(data.media_ids) ? data.media_ids : [];
      const cols = Math.min(6, Math.max(1, Number(data.columns) || 3));
      const urls = ids.map((_, idx) => (data[`url_${idx}`] ?? null) as string | null).filter(Boolean) as string[];
      if (urls.length === 0) {
        return <p style={{ color: "var(--text-muted)", fontSize: 13 }}>گالری {ids.length} تصویر دارد — resolve عمومی مدیا در بک‌اند نیست (TODO).</p>;
      }
      return (
        <div style={{ display: "grid", gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 10 }}>
          {urls.map((u) => (
            // eslint-disable-next-line @next/next/no-img-element
            <img key={u} src={u} alt="گالری" loading="lazy" style={{ inlineSize: "100%", borderRadius: 10 }} />
          ))}
        </div>
      );
    }
    case "quote": {
      const text = str(data.quote ?? data.text ?? data.body);
      if (!text) return null;
      return (
        <blockquote style={{ borderInlineStart: "3px solid var(--primary)", paddingInlineStart: 14, color: "var(--text)", fontSize: 16 }}>
          {text}
          {str(data.author ?? data.cite) ? <footer style={{ fontSize: 13, color: "var(--text-muted)" }}>— {str(data.author ?? data.cite)}</footer> : null}
        </blockquote>
      );
    }
    case "video": {
      const src = str(data.url ?? data.src);
      const caption = str(data.caption);
      if (!src) return null;
      if (/\.mp4($|\?|#)/i.test(src)) {
        return (
          <figure style={{ margin: 0 }}>
            <video src={src} controls style={{ inlineSize: "100%", borderRadius: 12 }} />
            {caption ? <figcaption style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockStart: 6 }}>{caption}</figcaption> : null}
          </figure>
        );
      }
      return (
        <a
          href={src}
          target="_blank"
          rel="noopener noreferrer"
          className="card card-pad"
          style={{ display: "flex", gap: 10, alignItems: "center", textDecoration: "none", color: "var(--text)" }}
        >
          <span aria-hidden style={{ fontSize: 22 }}>▶</span>
          <span>
            <b style={{ display: "block", fontSize: 14 }}>{caption || "مشاهده ویدیو"}</b>
            <small dir="ltr" style={{ display: "block", textAlign: "end", color: "var(--text-muted)", fontSize: 11.5 }}>{src.slice(0, 80)}</small>
          </span>
        </a>
      );
    }
    case "faq": {
      const items = Array.isArray(data.items) ? (data.items as Array<Record<string, unknown>>) : [];
      const rows = items
        .map((it) => ({ q: str(it.q), a: str(it.a) }))
        .filter((r) => r.q && r.a)
        .slice(0, 20);
      if (rows.length === 0) return null;
      return (
        <div className="site-faq" style={{ display: "flex", flexDirection: "column", gap: 10 }}>
          {rows.map((r, idx) => (
            <details key={idx} className="site-faq-item">
              <summary>{r.q}</summary>
              <div className="site-faq-answer">{r.a}</div>
            </details>
          ))}
        </div>
      );
    }
    case "contact-form":
    case "contact": {
      return (
        <ContactForm
          title={str(data.title) || "فرم تماس"}
          showPhone={data.show_phone !== false}
          destinationEmail={str(data.email) || undefined}
        />
      );
    }
    default: {
      // بلوک ناشناس (پلاگینی): fail-soft — چیزی نشکن، فقط لاگ کنسول سرور.
      console.warn(`[site] unknown block type: ${type}`);
      return null;
    }
  }
}
