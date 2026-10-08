import type { CSSProperties } from "react";
import { safeSrc } from "@/lib/sanitize";

/**
 * WF-C4 — تصویر واکنش‌گرا: `<picture>` + `srcset`/`sizes` وقتی نسخه‌های
 * WebP/AVIF و چند-عرض از بک‌اند آمده باشند؛ وگرنه دقیقاً همان `<img src>`
 * قبلی (fail-soft).
 *
 * کلیدهای فراداده از `Site\PageController::withMediaUrls()` می‌آیند:
 * `srcset` (قالب اصلی)، `sources` (`[{type:'image/avif', srcset}, …]`).
 */

export type PictureSource = { type?: string; srcset?: string };

/** اعتبارسنجی «srcset» — کل رشته از allowlist عبور می‌کند. */
export function normalizeSrcset(raw: unknown): string | undefined {
  return safeSrc(raw) ?? undefined;
}

/** اعتبارسنجی فهرست `sources`؛ فقط نوع‌های `image/*` با srcset معتبر می‌مانند. */
export function normalizeSources(raw: unknown): PictureSource[] | undefined {
  if (!Array.isArray(raw)) return undefined;
  const out = raw
    .map((item) => {
      const o = (item ?? {}) as Record<string, unknown>;
      return {
        type: typeof o.type === "string" ? o.type : "",
        srcset: safeSrc(o.srcset) ?? "",
      };
    })
    .filter((s) => s.type.startsWith("image/") && s.srcset);
  return out.length ? out : undefined;
}

export function ResponsiveImage({
  src,
  srcset,
  sources,
  alt,
  sizes,
  loading = "lazy",
  style,
  objectPosition,
  className,
}: {
  src: string;
  srcset?: string | null;
  sources?: PictureSource[] | null;
  alt?: string;
  sizes?: string;
  loading?: "lazy" | "eager";
  style?: CSSProperties;
  /**
   * WF-L1 — نقطهٔ کانونیِ تصویر به‌صورت `object-position` (مثلاً «30% 70%»).
   * نبودش یعنی همان «وسط» پیش‌فرض؛ روی `style` غلبه می‌کند چون از داده می‌آید.
   */
  objectPosition?: string;
  className?: string;
}) {
  const safeSet = safeSrc(srcset) ?? undefined;
  const safeSources = (sources ?? []).filter(
    (s) => typeof s.type === "string" && s.type.startsWith("image/") && typeof s.srcset === "string" && s.srcset.length > 0,
  );

  const imgStyle = objectPosition ? { ...style, objectPosition } : style;

  const img = (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      srcSet={safeSet}
      sizes={safeSet ? sizes : undefined}
      alt={alt}
      loading={loading}
      style={imgStyle}
      className={className}
    />
  );

  if (safeSources.length === 0) return img;

  return (
    <picture>
      {safeSources.map((s) => (
        <source key={s.type} type={s.type} srcSet={s.srcset} sizes={sizes} />
      ))}
      {img}
    </picture>
  );
}
