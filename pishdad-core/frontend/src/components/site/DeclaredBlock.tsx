// K6.12 — الگوهای عمومیِ رندر برای بلوکِ اعلانیِ افزونه.
//
// چرا این فایل جدا از `BlockRenderer.tsx` است:
//  ۱. نگهبان‌های `block-type.test.ts` *متنِ* `BlockRenderer.tsx` را اسکن می‌کنند
//     (برچسب‌های `case` و «دقیقاً یک `default`»). اگر الگوهای عمومی همان‌جا
//     بودند، آن آزمون‌ها دو منبع حقیقت را با هم قاطی می‌کردند و ضعیف می‌شدند.
//  ۲. الگوی عمومی یک قراردادِ رندرِ **افزونه** است، نه بلوکِ هسته؛ زندگی‌اش در
//     فایل خودش مرزِ مسئولیت را روشن نگه می‌دارد.
//
// افزونه فقط schema/داده می‌دهد؛ این‌جا هسته با ۱۱ الگو رندر می‌کند. هیچ `case`
// کد افزونه اجرا نمی‌کند — همه از `data` اسکالر می‌خوانند و از sanitizerهای
// مشترک می‌گذرند. هیچ‌وقت throw نمی‌کند.
import { safeHref, safeSrc, safeHtml } from "@/lib/sanitize";
import type { BlockPattern } from "@/lib/block-pattern";
import { ContactForm } from "./ContactForm";

function str(v: unknown): string {
  return typeof v === "string" ? v : "";
}

function mediaSrc(d: Record<string, unknown>): string | null {
  for (const k of ["url", "src", "image_url"]) {
    const v = safeSrc(d[k]);
    if (v) return v;
  }
  const path = d.path;
  if (typeof path === "string" && path) return safeSrc(path);
  return null;
}

function asRecords(v: unknown): Array<Record<string, unknown>> {
  return Array.isArray(v)
    ? (v.filter((x) => x && typeof x === "object" && !Array.isArray(x)) as Array<Record<string, unknown>>)
    : [];
}

export function DeclaredBlock({ pattern, data }: { pattern: BlockPattern | "default"; data: Record<string, unknown> }) {
  // if-chain عمداً نه `switch`: نگهبانِ «برچسب‌های case = واژگان هسته» در
  // `block-type.test.ts` نباید الگوهای عمومی را به‌عنوان بلوکِ هسته ببیند.
  if (pattern === "hero") {
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

  if (pattern === "rich-text") {
    const body = str(data.body ?? data.html ?? data.text);
    if (!body) return null;
    return <div dangerouslySetInnerHTML={{ __html: safeHtml(body) }} style={{ lineHeight: 2, fontSize: 15 }} />;
  }

  if (pattern === "image") {
    const src = mediaSrc(data);
    if (!src) {
      return (
        <figure style={{ margin: 0, padding: 16, border: "1px dashed var(--border)", borderRadius: 12, color: "var(--text-muted)", fontSize: 13 }}>
          تصویر در دسترس نیست.
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

  if (pattern === "gallery") {
    let urls = asRecords(data.items)
      .map((it) => safeSrc(it.url ?? it.src ?? it.image_url))
      .filter((u): u is string => Boolean(u));
    if (urls.length === 0) {
      const ids = Array.isArray(data.media_ids) ? data.media_ids : [];
      urls = ids
        .map((_, idx) => safeSrc(data[`url_${idx}`] ?? null))
        .filter((u): u is string => Boolean(u));
    }
    if (urls.length === 0) {
      return <p style={{ color: "var(--text-muted)", fontSize: 13 }}>تصویرهای این گالری در دسترس نیستند.</p>;
    }
    return <GalleryGrid urls={urls} cols={data.columns} />;
  }

  if (pattern === "grid") {
    const items = asRecords(data.items).slice(0, 24);
    if (items.length === 0) return null;
    const cols = Math.min(4, Math.max(1, Number(data.columns) || 3));
    return (
      <div style={{ display: "grid", gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 12 }}>
        {items.map((it, i) => (
          <div key={i} className="card card-pad" style={{ display: "flex", flexDirection: "column", gap: 6 }}>
            {str(it.title) ? <b>{str(it.title)}</b> : null}
            {str(it.text) || str(it.body) ? <span style={{ fontSize: 13.5, color: "var(--text-muted)" }}>{str(it.text ?? it.body)}</span> : null}
            {str(it.href) ? <a className="btn btn-ghost btn-sm" href={safeHref(it.href) ?? "#"}>{str(it.label) || "بیشتر"}</a> : null}
          </div>
        ))}
      </div>
    );
  }

  if (pattern === "list") {
    const items = asRecords(data.items).slice(0, 50);
    if (items.length === 0) {
      const text = str(data.body ?? data.text);
      if (!text) return null;
      return <div dangerouslySetInnerHTML={{ __html: safeHtml(text) }} style={{ lineHeight: 2 }} />;
    }
    return (
      <ul style={{ margin: 0, paddingInlineStart: 20, lineHeight: 2 }}>
        {items.map((it, i) => {
          const label = str(it.label ?? it.title ?? it.text) || "—";
          return <li key={i}>{str(it.href) ? <a href={safeHref(it.href) ?? "#"}>{label}</a> : label}</li>;
        })}
      </ul>
    );
  }

  if (pattern === "faq") {
    const rows = asRecords(data.items)
      .map((it) => ({ q: str(it.q ?? it.question), a: str(it.a ?? it.answer) }))
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

  if (pattern === "quote") {
    const text = str(data.quote ?? data.text ?? data.body);
    if (!text) return null;
    return (
      <blockquote style={{ borderInlineStart: "3px solid var(--primary)", paddingInlineStart: 14, color: "var(--text)", fontSize: 16 }}>
        {text}
        {str(data.author ?? data.cite) ? <footer style={{ fontSize: 13, color: "var(--text-muted)" }}>— {str(data.author ?? data.cite)}</footer> : null}
      </blockquote>
    );
  }

  if (pattern === "cta") {
    const style = data.style === "secondary" || data.style === "ghost" ? "btn-ghost" : "btn-primary";
    return (
      <div>
        <a className={`btn ${style}`} href={safeHref(data.href) ?? "#"}>{str(data.label) || "ادامه"}</a>
      </div>
    );
  }

  if (pattern === "form") {
    // الگوی عمومیِ فرم = فرم تماس هسته. هیچ فیلدِ دینامیکِ افزونه رندر نمی‌شود:
    // گرفتنِ «schema فرم» یعنی اجرای منطقِ افزونه، که قید ممنوع است.
    return (
      <ContactForm
        title={str(data.title) || "فرم تماس"}
        showPhone={data.show_phone !== false}
        destinationEmail={str(data.email) || undefined}
      />
    );
  }

  if (pattern === "embed") {
    const raw = str(data.url ?? data.src);
    const caption = str(data.caption);
    if (!raw) return null;
    if (/\.mp4($|\?|#)/i.test(raw)) {
      const src = safeSrc(raw);
      if (!src) return null;
      return (
        <figure style={{ margin: 0 }}>
          <video src={src} controls style={{ inlineSize: "100%", borderRadius: 12 }} />
          {caption ? <figcaption style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockStart: 6 }}>{caption}</figcaption> : null}
        </figure>
      );
    }
    const href = safeHref(raw);
    if (!href) return null;
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className="card card-pad"
        style={{ display: "flex", gap: 10, alignItems: "center", textDecoration: "none", color: "var(--text)" }}
      >
        <span aria-hidden style={{ fontSize: 22 }}>▶</span>
        <span>
          <b style={{ display: "block", fontSize: 14 }}>{caption || "مشاهده"}</b>
          <small dir="ltr" style={{ display: "block", textAlign: "end", color: "var(--text-muted)", fontSize: 11.5 }}>{href.slice(0, 80)}</small>
        </span>
      </a>
    );
  }

  // pattern === "default" — متن ساده؛ اگر بدنه‌ای نبود، چیزی رندر نمی‌شود.
  const body = str(data.body ?? data.text ?? data.title);
  if (!body) return null;
  return <p style={{ lineHeight: 2, fontSize: 15 }}>{body}</p>;
}

function GalleryGrid({ urls, cols: rawCols }: { urls: string[]; cols: unknown }) {
  const cols = Math.min(6, Math.max(1, Number(rawCols) || 3));
  return (
    <div
      style={{
        display: "grid",
        gridTemplateColumns: `repeat(${cols}, 1fr)`,
        gap: 10,
        padding: 12,
        border: "1px solid var(--border)",
        borderRadius: 12,
      }}
    >
      {urls.map((u) => (
        // eslint-disable-next-line @next/next/no-img-element
        <img key={u} src={u} alt="گالری" loading="lazy" style={{ inlineSize: "100%", borderRadius: 8, display: "block" }} />
      ))}
    </div>
  );
}
