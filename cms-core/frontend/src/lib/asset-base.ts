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
 */
export function applyAssetBase<T extends string | null | undefined>(
  base: string | null,
  url: T,
): T {
  if (!base || typeof url !== "string" || !url.startsWith("/")) return url;
  return `${base}${url}` as T;
}
