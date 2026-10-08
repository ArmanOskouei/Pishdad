/**
 * K6.13 — آماده‌سازی دادهٔ بلوک برای پیش‌نمایش زنده.
 *
 * `BlockRenderer` نشانیِ تصویر را از `url`/`src`/`image_url` یا `path` می‌خواند
 * (بک‌اند در مسیر عمومی `url` را از `media_id` تزریق می‌کند). در ادیتور اما
 * داده خام است و فقط `media_id`/`image_id`/`media_ids` دارد و نگاشتِ id→URL در
 * `mediaCache` نشسته. پس پیش از رندر، همان کلیدهای نشانی را تزریق می‌کنیم.
 *
 * این تابع **خالص** و بدون React است تا آزمون‌پذیر باشد.
 */

/** ایندکس‌های مدیای یک بلوک، از سه شکلِ ممکن (`media_id`/`image_id`/`media_ids`). */
export function mediaIdsOf(data: Record<string, unknown>): number[] {
  const out: number[] = [];
  for (const k of ["media_id", "image_id"]) {
    const v = data[k];
    if (typeof v === "number" && Number.isFinite(v) && v > 0) out.push(v);
  }
  if (Array.isArray(data.media_ids)) {
    for (const x of data.media_ids) {
      if (typeof x === "number" && Number.isFinite(x) && x > 0) out.push(x);
    }
  }
  return out;
}

/**
 * دادهٔ بلوک را با نشانی‌های resolve‌شدهٔ رسانه غنی می‌کند بدون دست‌زدن به
 * کلیدهای اصلی. اگر نگاشتی نبود، داده دست‌نخورده برمی‌گردد.
 */
export function withResolvedMedia(
  data: Record<string, unknown>,
  cache: Record<number, string>,
): Record<string, unknown> {
  const out: Record<string, unknown> = { ...data };

  if (typeof data.media_id === "number" && cache[data.media_id]) {
    out.url = out.url ?? cache[data.media_id];
  }
  if (typeof data.image_id === "number" && cache[data.image_id]) {
    out.url = out.url ?? cache[data.image_id];
  }
  if (Array.isArray(data.media_ids)) {
    data.media_ids.forEach((id, i) => {
      if (typeof id === "number" && cache[id] && out[`url_${i}`] === undefined) {
        out[`url_${i}`] = cache[id];
      }
    });
  }

  return out;
}
