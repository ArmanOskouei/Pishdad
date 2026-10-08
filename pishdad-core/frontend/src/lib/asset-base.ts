/**
 * WF-M13 — کمک‌تابع‌های خالصِ «دامنهٔ دارایی/CDN».
 *
 * همراستا با `docs/CDN-ASSESSMENT.md`: CDN فقط برای فایل‌های ایستا معنا دارد؛
 * این ماژول نشانی‌های **محلی** (شروع با `/`) را به دامنهٔ دارایی می‌چسباند و
 * نشانی‌های مطلق (مثل MinIO/S3) را دست‌نخورده می‌گذارد. عمداً هیچ وابستگی‌ای
 * ندارد تا در آزمون‌های `node --test` بدون alias قابل import باشد.
 */

/** نرمال‌سازی دامنه: trim + حذف اسلش‌های پایانی؛ خالی ⇒ null. */
export function normalizeAssetBase(raw: unknown): string | null {
  if (typeof raw !== "string") return null;
  const trimmed = raw.trim().replace(/\/+$/, "");
  return trimmed === "" ? null : trimmed;
}

/** دامنهٔ دارایی از کروم عمومی (`asset_domain`) یا null. */
export function resolveAssetBase(
  chrome: { asset_domain?: string | null } | null | undefined,
): string | null {
  return normalizeAssetBase(chrome?.asset_domain);
}

/**
 * اگر دامنه تنظیم شده باشد و نشانی محلی باشد، آن را با دامنه می‌سازد.
 * نشانی مطلق (منبع دیگری) یا خالی دست‌نخورده برمی‌گردد.
 *
 * `//host` عمداً دست‌نخورده می‌ماند: نشانیِ «protocol-relative» منبعِ دیگری
 * است و چسباندنِ پایه به آن یک میزبانِ ناشناس می‌سازد.
 */
export function applyAssetBase<T extends string | null | undefined>(
  base: string | null,
  url: T,
): T {
  if (!base || typeof url !== "string" || !url.startsWith("/") || url.startsWith("//")) return url;
  return `${base}${url}` as T;
}

/**
 * E71 — فقط نشانیِ رسانهٔ **بک‌اند** (`/storage/…`) با پایه ساخته می‌شود.
 *
 * درسِ E64 که زیادی تعمیم داده شده بود: `applyAssetBase` هر نشانیِ محلی را
 * می‌ساخت، از جمله دارایی‌های خودِ فرانت (`/pishdad-logo.png`،
 * `/favicon.ico`). آن‌ها روی فرانت سرو می‌شوند و بردن‌شان به میزبانِ بک‌اند
 * ۴۰۴ می‌داد — لوگوی سایت دقیقاً همین‌طور شکست. بک‌اند برای رسانهٔ محلی فقط
 * مسیرِ `/storage/…` می‌فرستد (`Storage::url()` روی دیسکِ `public`)، پس همان
 * پیشوند تنها چیزی است که به پایهٔ رسانه نیاز دارد؛ بقیهٔ مسیرهای محلی متعلق
 * به فرانت‌اند و دست‌نخورده می‌مانند.
 */
export function applyMediaBase<T extends string | null | undefined>(
  base: string | null,
  url: T,
): T {
  if (!base || typeof url !== "string" || !url.startsWith("/storage/")) return url;
  return `${base}${url}` as T;
}

/**
 * E64 — همان کارِ `applyAssetBase` روی یک `srcset`.
 *
 * `srcset` یک رشته است با چند نشانیِ جداشده با کاما که هرکدام اختیاراً یک
 * توصیف‌گرِ عرض/چگالی دارد (`/a.webp 640w, /b.webp 1280w`). پس نمی‌شود کل رشته
 * را به‌عنوان یک نشانی چسباند؛ هر بخش جدا ساخته می‌شود و توصیف‌گرها حفظ
 * می‌شوند. ورودیِ غیرِرشته یا خالی دست‌نخورده برمی‌گردد.
 *
 * E71 — هر بخش با `applyMediaBase` ساخته می‌شود، نه `applyAssetBase`.
 */
export function applySrcsetBase(base: string | null, raw: unknown): unknown {
  if (!base || typeof raw !== "string" || raw.trim() === "") return raw;

  return raw
    .split(",")
    .map((part) => {
      const trimmed = part.trim();
      if (trimmed === "") return "";
      const space = trimmed.search(/\s/);
      const url = space === -1 ? trimmed : trimmed.slice(0, space);
      const descriptor = space === -1 ? "" : ` ${trimmed.slice(space).trim()}`;

      return `${applyMediaBase(base, url)}${descriptor}`;
    })
    .filter((part) => part !== "")
    .join(", ");
}

/** کلیدهای تکردیزیِ نشانیِ رسانه در `data` بلوک‌ها (قراردادِ `BlockRenderer::mediaSrc`). */
const MEDIA_URL_KEY = /^(?:url|image_url|src|poster_url)(?:_\d+)?$/;
/** کلیدهای رشتهٔ `srcset` (`srcset`، `srcset_0`، `poster_srcset`، …). */
const MEDIA_SRCSET_KEY = /^(?:srcset|poster_srcset)(?:_\d+)?$/;
/** کلیدهای فهرستِ `<source>` (`sources`، `sources_0`، `poster_sources`، …). */
const MEDIA_SOURCES_KEY = /^(?:sources|poster_sources)(?:_\d+)?$/;

/** E64 — `srcset` درونِ هر عضوِ فهرستِ `sources` را بازنویسی می‌کند. */
function rebaseSources(base: string, sources: readonly unknown[]): unknown[] {
  return sources.map((item) => {
    if (!item || typeof item !== "object" || Array.isArray(item)) return item;
    const source = item as Record<string, unknown>;
    if (typeof source.srcset !== "string") return item;

    return { ...source, srcset: applySrcsetBase(base, source.srcset) };
  });
}

/**
 * E64 — نشانی‌های **محلیِ** رسانه را در بلوک‌های یک صفحه با پایه مطلق می‌کند.
 *
 * ## چرا لازم است
 *
 * بک‌اند برای دیسکِ محلی (`public`) فقط مسیرِ `/storage/…` می‌فرستد (پایهٔ
 * S3/MinIO را دارد، پایهٔ سرورِ لاراول را ندارد). اگر پایه اینجا اعمال نشود،
 * مرورگر `/storage/…` را نسبت به میزبانِ **فرانت** باز می‌کند و ۴۰۴ می‌گیرد —
 * همان باگِ E64.
 *
 * ## چرا فقط کلیدهای شناخته‌شده
 *
 * `data` بلوک هم رسانه دارد و هم چیزهای غیرِرسانه (`href`، `slug`، `form_slug`).
 * بازنویسیِ کورکورانهٔ هر رشتهٔ محلی، لینک‌ها را به میزبانِ بک‌اند می‌برد.
 * پس عمداً فقط کلیدهای قراردادیِ رسانه لمس می‌شوند و ورودی دست‌نخورده می‌ماند
 * (کپی برمی‌گردد).
 */
export function rebaseBlockMedia<T>(base: string | null, blocks: T): T {
  if (!base || !Array.isArray(blocks)) return blocks;

  const rebased = (blocks as unknown[]).map((raw) => {
    if (!raw || typeof raw !== "object") return raw;
    const block = raw as { data?: unknown };
    const data = block.data;
    if (!data || typeof data !== "object" || Array.isArray(data)) return raw;

    const next: Record<string, unknown> = { ...(data as Record<string, unknown>) };
    let changed = false;

    for (const [key, value] of Object.entries(next)) {
      if (typeof value === "string" && MEDIA_URL_KEY.test(key)) {
        const fixed = applyMediaBase(base, value);
        if (fixed !== value) {
          next[key] = fixed;
          changed = true;
        }
      } else if (typeof value === "string" && MEDIA_SRCSET_KEY.test(key)) {
        const fixed = applySrcsetBase(base, value);
        if (fixed !== value) {
          next[key] = fixed;
          changed = true;
        }
      } else if (Array.isArray(value) && MEDIA_SOURCES_KEY.test(key)) {
        next[key] = rebaseSources(base, value);
        changed = true;
      }
    }

    return changed ? { ...(raw as Record<string, unknown>), data: next } : raw;
  });

  return rebased as T;
}
