import { mediaUrl, type BlockValue } from "@/lib/domain";
// WF-L1 — تبدیل نقطهٔ کانونی (0..1) به `object-position`.
import { focalPosition } from "@/lib/focal-point";
// F0.2: sanitizerها به `lib/sanitize.ts` منتقل شدند چون ویجت‌های کروم و
// پیش‌نمایش ادیتور هم به آن‌ها نیاز دارند — نگه‌داشتنشان اینجا یعنی وابستگی
// معکوس (پنل → کامپوننتِ سایتِ عمومی).
import { safeHref, safeSrc, safeHtml } from "@/lib/sanitize";
// K6.3: واژگانِ بلوک‌های هسته و متنِ لاگ/اعلان، در `lib/block-type.ts` زندگی
// می‌کنند تا لاگ و رفتار و تست یک روایت داشته باشند (و آزمون بتواند بدون
// رندرر React آن‌ها را بخواند).
import {
  blockDiagnosticsEnabled,
  describeUnknownType,
  isBuiltinBlockType,
  unknownBlockNotice,
  unknownBlockWarning,
} from "@/lib/block-type";
import { publicT, type PublicLocale, type PublicMessageKey } from "@/lib/i18n/public";
// K6.12 — انتخابِ الگوی عمومیِ رندر برای بلوک‌های اعلانی (افزونه). افزونه هیچ
// React/JS نمی‌فرستد؛ هسته با ~۱۱ الگو رندر می‌کند. نگاشتِ خالص در
// `lib/block-pattern.ts` است تا آزمون‌پذیر باشد و اینجا فقط `switch` بماند.
import { selectBlockPattern } from "@/lib/block-pattern";
import { DeclaredBlock } from "./DeclaredBlock";
import { FormBlock } from "./FormBlock";
import { ResponsiveImage, normalizeSources, normalizeSrcset, type PictureSource } from "./ResponsiveImage";

/**
 * F3 — رندر بلوک‌های صفحه عمومی به کامپوننت.
 * hero/text/image/cta/gallery از رجیستری بک‌اند (config/blocks.php) +
 * quote/video/contact-form برای سازگاری با بلوک‌های پلاگینی آینده.
 * بلوک `_enabled === false` رد می‌شود (تغییر فعال/غیرفعال صفحه ۱.۱۲).
 *
 * K6.3 — `type` ناشناس (شاخهٔ `default` پایین همین فایل). تصمیم‌ها و دلیلش در
 * `lib/block-type.ts` است؛ اینجا فقط ویوِ همان تصمیم.
 */

export { safeHref, safeSrc, safeHtml };

function str(v: unknown): string {
  return typeof v === "string" ? v : "";
}

function mediaSrc(d: Record<string, unknown>): string | null {
  for (const k of ["url", "src", "image_url"]) {
    // `src` هم دادهٔ کاربر است (بلوک سازندهٔ صفحه) ⇒ همان allowlist، بدون استثنا.
    const v = safeSrc(d[k]);
    if (v) return v;
  }
  // media_id بدون endpoint عمومی قابل resolve نیست (TODO بک‌اند: URL آماده در payload صفحه).
  // اگر path مستقیم داده شده بود، از بیس MinIO بساز:
  const path = d.path;
  if (typeof path === "string" && path) return safeSrc(mediaUrl({ path }));
  return null;
}

/**
 * WF-C4 — فرادادهٔ نسخه‌های واکنش‌گرا را از `data` بیرون می‌کشد.
 * پیشوندِ خالی = تصویر اصلی (`srcset`/`sources`)؛ `_0`/`_1` = آیتم گالری.
 */
function mediaVariants(
  d: Record<string, unknown>,
  suffix = "",
): { srcset?: string; sources?: PictureSource[] } {
  return {
    srcset: normalizeSrcset(d[`srcset${suffix}`]),
    sources: normalizeSources(d[`sources${suffix}`]),
  };
}

/**
 * WF-L1 — مقدار `object-position` از مختصات نرمال‌شدهٔ بک‌اند.
 * پیشوندِ خالی = تصویر اصلی؛ `_0`/`_1` = آیتم گالری.
 */
function mediaFocal(d: Record<string, unknown>, suffix = ""): string | undefined {
  return focalPosition(d[`focal_x${suffix}`], d[`focal_y${suffix}`]);
}

/**
 * `diagnostics` یعنی «این رندر در دیباگ است» — پیش‌فرض از محیط می‌آید
 * (`lib/block-type.ts:blockDiagnosticsEnabled`).
 *
 * پارامتر صریح برای وقتی لازم است که **ادمین/پیش‌نمایش** بلوک‌ها را رندر کند
 * (`PageEditor`): همان کامپوننت، ولی با اعلانِ دیداری، تا ادیتور بلوکِ راندر‌نشده
 * را ببیند. مصرف‌کننده‌های فعلی (`app/page.tsx`، `app/[...path]/page.tsx`) این
 * را پاس نمی‌دهند و رفتارشان همان پیش‌فرض محیط است.
 */
/**
 * K6.12 — رجیستریِ اعلانیِ بلوک‌ها برای انتخابِ الگو.
 *
 * کلید = `type`، مقدار = `schema` بلوک (همان `BlockDef["schema"]`). فقط
 * `properties` مصرف می‌شود (`x-pattern`)، پس نوع عمداً سبک نگه داشته شده و
 * `domain.ts` این‌جا import نمی‌شود تا جهتِ وابستگی معکوس نشود.
 */
export type BlockSchemaRegistry = Record<string, { properties?: Record<string, { default?: unknown; enum?: unknown }> | null }>;

export function BlockRenderer({
  blocks,
  diagnostics,
  schemas,
  locale = "fa",
  imageLoading = "lazy",
}: {
  blocks: BlockValue[];
  diagnostics?: boolean;
  /**
   * K6.12 — رجیستریِ schema برای مسیرِ «schema-only». اگر بدهید، افزونه‌ای که
   * `x-pattern` اعلام کرده به همان الگو نگاشت می‌شود. نبودش رفتار قبلی را
   * می‌دهد (نگاشت بر اساس نوع) — یعنی سایت عمومی بدون تغییر کار می‌کند.
   */
  schemas?: BlockSchemaRegistry;
  /** ECO2 — زبانِ سایت. پیش‌فرض `fa` یعنی پنل/پیش‌نمایش بدون تغییر. */
  locale?: PublicLocale;
  /**
   * WF-M13 — رفتار بارگذاری تصاویر. پیش‌فرض `lazy` (بهینه). سایتِ عمومی وقتی
   * تنظیم کارایی خاموش باشد `eager` می‌فرستد. پنل/پیش‌نمایش دست‌نخورده‌اند.
   */
  imageLoading?: "lazy" | "eager";
}) {
  const visible = blocks.filter((b) => (b.data as Record<string, unknown>)?._enabled !== false);
  if (visible.length === 0) {
    return <p style={{ color: "var(--text-muted)" }}>{publicT(locale, "block.empty")}</p>;
  }
  const showUnknown = diagnostics ?? blockDiagnosticsEnabled();
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 28 }}>
      {visible.map((b, i) => (
        <Block key={i} type={b.type} data={(b.data ?? {}) as Record<string, unknown>} schemas={schemas} diagnostics={showUnknown} locale={locale} imageLoading={imageLoading} />
      ))}
    </div>
  );
}

function Block({
  type,
  data,
  schemas,
  diagnostics,
  locale,
  imageLoading,
}: {
  type: string;
  data: Record<string, unknown>;
  schemas?: BlockSchemaRegistry;
  diagnostics: boolean;
  locale: PublicLocale;
  imageLoading: "lazy" | "eager";
}) {
  const t = (k: PublicMessageKey, values?: Record<string, string | number>) => publicT(locale, k, values);
  // K6.12 — بلوکِ **اعلانیِ افزونه** (نه هسته) با الگوی عمومی رندر می‌شود.
  //
  // نکتهٔ امنیتی: این شاخه فقط برای `type`های بیرون از `BUILTIN_BLOCK_TYPES`
  // اجرا می‌شود، پس **هرگز نمی‌تواند بلوک هسته را بپوشاند** — همان گارانتیِ
  // شاخهٔ `default`. اگر `x-pattern` صریح در schema اعلان آمده باشد، همان
  // برنده است؛ وگرنه نگاشتِ نوع. الگوی `default` یعنی نوع ناشناس ⇒ سیاستِ
  // K6.3 پایین‌تر (لاگ + اعلان).
  if (!isBuiltinBlockType(type)) {
    // schema از رجیستری می‌آید؛ `data.__schema` فقط fallbackِ سازگاری است.
    const schema = schemas?.[type] ?? (data.__schema as never);
    const pattern = selectBlockPattern(type, schema);
    if (pattern !== "default") return <DeclaredBlock pattern={pattern} data={data} />;
  }

  switch (type) {
    case "hero": {
      const align = data.align === "center" ? "center" : data.align === "left" ? "left" : "right";
      const bg = mediaSrc(data);
      // WF-L1 — نقطهٔ کانونی هیرو روی `background-position` می‌نشیند.
      const heroPosition = mediaFocal(data) ?? "center";
      return (
        <section
          style={{
            textAlign: align as "left" | "center" | "right",
            padding: "56px 24px",
            borderRadius: 16,
            background: bg ? `url(${bg}) ${heroPosition}/cover` : "var(--primary-soft)",
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
            {t("block.imagePending", { id: String(data.media_id ?? "—") })}
            {str(data.caption) ? <figcaption>{str(data.caption)}</figcaption> : null}
          </figure>
        );
      }
      const variants = mediaVariants(data);
      return (
        <figure style={{ margin: 0 }}>
          <ResponsiveImage
            src={src}
            srcset={variants.srcset}
            sources={variants.sources}
            alt={str(data.alt) || t("block.imageAlt")}
            loading={imageLoading}
            sizes="(max-width: 768px) 100vw, 800px"
            objectPosition={mediaFocal(data)}
            style={{ maxInlineSize: "100%", borderRadius: 12 }}
          />
          {str(data.caption) ? <figcaption style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockStart: 6 }}>{str(data.caption)}</figcaption> : null}
        </figure>
      );
    }
    case "cta": {
      const style = data.style === "secondary" ? "btn-ghost" : data.style === "ghost" ? "btn-ghost" : "btn-primary";
      return (
        <div>
          <a className={`btn ${style}`} href={safeHref(data.href) ?? "#"}>{str(data.label) || t("block.ctaDefault")}</a>
        </div>
      );
    }
    case "gallery": {
      const ids = Array.isArray(data.media_ids) ? data.media_ids : [];
      const cols = Math.min(6, Math.max(1, Number(data.columns) || 3));
      const items = ids
        .map((_, idx) => {
          const url = safeSrc(data[`url_${idx}`]);
          if (!url) return null;
          return { url, objectPosition: mediaFocal(data, `_${idx}`), ...mediaVariants(data, `_${idx}`) };
        })
        .filter((x): x is { url: string; objectPosition: string | undefined; srcset?: string; sources?: PictureSource[] } => x !== null);
      if (items.length === 0) {
        return <p style={{ color: "var(--text-muted)", fontSize: 13 }}>{t("block.galleryPending", { n: ids.length })}</p>;
      }
      const sizes = `(max-width: 768px) 50vw, ${Math.max(1, Math.round(100 / cols))}vw`;
      return (
        <div style={{ display: "grid", gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 10 }}>
          {items.map((item, idx) => (
            <ResponsiveImage
              key={idx}
              src={item.url}
              srcset={item.srcset}
              sources={item.sources}
              alt={t("block.galleryAlt")}
              loading={imageLoading}
              sizes={sizes}
              objectPosition={item.objectPosition}
              style={{ inlineSize: "100%", borderRadius: 10 }}
            />
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
      // لینک بیرونی هم دادهٔ کاربر است ⇒ `javascript:` اینجا هم باید بمیرد (B24).
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
            <b style={{ display: "block", fontSize: 14 }}>{caption || t("block.watchVideo")}</b>
            <small dir="ltr" style={{ display: "block", textAlign: "end", color: "var(--text-muted)", fontSize: 11.5 }}>{href.slice(0, 80)}</small>
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
    case "form": {
      const slug = str(data.slug);
      if (!slug) return null;
      return <FormBlock slug={slug} title={str(data.title) || undefined} locale={locale} />;
    }
    case "contact-form":
    case "contact": {
      // WF-H10 — فرم تماس حالا از فرم‌ساز می‌خواند (اسلاگ پیش‌فرض contact)؛
      // اگر فرم seed نشده باشد، به فرم تماس قدیمی برمی‌گردد.
      return (
        <FormBlock
          slug={str(data.form_slug) || "contact"}
          title={str(data.title) || t("block.contactForm")}
          locale={locale}
          contactFallback
          showPhone={data.show_phone !== false}
        />
      );
    }
    default: {
      // K6.3 — بلوکِ ناشناس. سه چیز، عمداً و جدا از هم:
      //
      //  ۱. **هرگز throw نمی‌کنیم.** یک بلوکِ خراب نباید کل صفحهٔ عمومی را
      //     بیندازد؛ `throw` اینجا یعنی یک محتوای اشتباه، دموی کل سایت را
      //     می‌گیرد.
      //  ۲. **همیشه لاگ سمتِ سرور.** این تنها کانالی است که در SSR به اپراتور
      //     می‌رسد و از بین نمی‌رود. اگر `type` در `BUILTIN_BLOCK_TYPES` باشد
      //     ولی `switch` نخورده، لغزشِ **خودِ ماست** نه خطای محتوا ⇒ یک پله
      //     بلندتر: `error`.
      //  ۳. **اعلانِ دیداری فقط وقتی تشخیص روشن است** (`diagnostics`) — یعنی
      //     نه production، یا production با `BLOCK_RENDER_DIAGNOSTICS=1`. در
      //     production برای بازدیدکننده چیزی رندر نمی‌شود (نه داخلِ سایت لو
      //     می‌رود، نه صفحه بد به‌نظر می‌رسد) ولی لاگ حتماً رفته.
      //
      // در dev، ادیتور بالاخره می‌بیند که بلوکش رندر نشده — به‌جای اینکه صفحه
      // سالم به نظر برسد و محتوا غایب باشد. `role="note"` است چون یادداشتِ
      // محتوایی است، نه خطای قابل‌انجام‌کار برای بازدیدکننده.
      //
      // توجه: این شاخه فقط وقتی اجرا می‌شود که هیچ `case`ای نخورده باشد،
      // یعنی **نمی‌تواند بلوکِ هسته را بپوشاند** — امضای `switch` در
      // `block-type.test.ts` قفل شده است.
      if (isBuiltinBlockType(type)) {
        console.error(unknownBlockWarning(type, "registry-drift"));
      } else {
        console.warn(unknownBlockWarning(type));
      }
      if (!diagnostics) return null;
      const named = describeUnknownType(type) || "(خالی)";
      return (
        <div
          role="note"
          data-block-state="unknown"
          data-block-type={named}
          style={{ margin: 0, padding: 16, border: "1px dashed var(--border)", borderRadius: 12, color: "var(--text-muted)", fontSize: 13 }}
        >
          {unknownBlockNotice(locale, type)}
        </div>
      );
    }
  }
}
