/**
 * E66 — چیدمانِ **فوتر** در سه ناحیه: بالاتر از ستون‌ها، خودِ ستون‌ها، پایین‌تر از ستون‌ها.
 *
 * ## چرا یک ماژولِ مشترک
 *
 * پیش از این، ستون‌بندیِ فوتر دو جا **جدا** پیاده شده بود: بومِ پنل
 * (`LayoutsClient`) و رندرِ سایتِ عمومی (`Chrome::SiteFooter`). هر تغییرِ ترتیب
 * یعنی دو ویرایشِ هم‌زمان که دیر یا زود واگرا می‌شوند — و دقیقاً همان چیزی است که
 * هشدارِ E66 می‌گفت («وگرنه فقط در پنل دیده می‌شود»). این ماژول خالص و بدونِ
 * وابستگی است تا **هر دو** از یک حقیقت بخوانند و با `node --test` سنجیده شود.
 *
 * ## مدلِ داده
 *
 * جای هر ویجت با فیلدِ اختیاریِ `place` روی خودِ ویجت ذخیره می‌شود:
 *   • غایب/`"grid"` ⇒ در ستون‌ها (رفتارِ قبلی، سازگارِ عقب‌رو)
 *   • `"above"`      ⇒ تمام‌عرض، بالای ستون‌ها
 *   • `"below"`      ⇒ تمام‌عرض، پایینِ ستون‌ها
 * ویجتِ `copyright` همیشه «پایین‌تر» است — همان رفتارِ قبلی، بدون نیاز به مهاجرت.
 *
 * ## چرا `flatten` ستون‌ها را دوباره درهم می‌بافد
 *
 * آرایهٔ ذخیره‌شده باید با ترتیبِ **DOM** یکی باشد، چون لایهٔ ویرایشِ
 * پیش‌نمایشِ زنده بر اساسِ ایندکسِ آرایه جابه‌جا می‌کند. پس ستون‌ها سطربه‌سطر
 * (`i % cols`) درهم‌بافته می‌شوند تا رفت‌وبرگشتِ `distribute ∘ flatten` اتحاد باشد.
 */

export type FooterPlace = "above" | "grid" | "below";

export type FooterWidget = { type: string; place?: unknown };

export type FooterZones<T> = {
  /** تمام‌عرض، بالای ستون‌ها */
  above: T[];
  /** ستون‌ها (هر عضو یک ستون، به ترتیبِ راست‌به‌چپ) */
  columns: T[][];
  /** تمام‌عرض، پایینِ ستون‌ها (شاملِ `copyright`) */
  below: T[];
};

/** ورودیِ خامِ `place` را به یکی از سه ناحیه نگاشت می‌کند (ناشناس ⇒ `grid`). */
export function normalizeFooterPlace(raw: unknown): FooterPlace {
  return raw === "above" || raw === "below" ? raw : "grid";
}

/**
 * ستون مؤثر فوتر — **همان قاعدهٔ بک‌اند** (`LinkItems::effectiveFooterColumns`):
 * `layout.columns` معتبر (۱ تا ۴) وگرنه تعداد واقعیِ ویجت‌های `links` (۱ تا ۴).
 */
export function footerColumnCount(raw: unknown, linksCount: number): number {
  if (typeof raw === "number" && Number.isInteger(raw) && raw >= 1 && raw <= 4) return raw;

  return Math.max(1, Math.min(4, linksCount === 0 ? 1 : linksCount));
}

/** ویجت‌هایی که در **ستون‌ها** می‌نشینند (نه تمام‌عرض). */
export function footerGridWidgets<T extends FooterWidget>(widgets: readonly T[]): T[] {
  return widgets.filter((w) => w.type !== "copyright" && normalizeFooterPlace(w.place) === "grid");
}

/** توزیعِ ویجت‌ها به سه ناحیه؛ ستون‌ها سطربه‌سطر (`index % cols`) پر می‌شوند. */
export function distributeFooter<T extends FooterWidget>(
  widgets: readonly T[],
  columns: number,
): FooterZones<T> {
  const cols = Math.max(1, Math.floor(columns) || 1);
  const above: T[] = [];
  const below: T[] = [];
  const grid: T[] = [];

  for (const w of widgets) {
    // `copyright` همیشه پایین‌تر است — رفتارِ قبلی، بدون مهاجرتِ داده.
    if (w.type === "copyright") {
      below.push(w);
      continue;
    }
    const place = normalizeFooterPlace(w.place);
    if (place === "above") above.push(w);
    else if (place === "below") below.push(w);
    else grid.push(w);
  }

  const out: T[][] = Array.from({ length: cols }, () => []);
  grid.forEach((w, i) => out[i % cols]!.push(w));

  return { above, columns: out, below };
}

/** ستون‌ها را سطربه‌سطر درهم می‌بافد تا آرایهٔ صاف = ترتیبِ DOM. */
export function interleaveColumns<T>(columns: readonly (readonly T[])[]): T[] {
  const out: T[] = [];
  const depth = columns.reduce((max, c) => Math.max(max, c.length), 0);

  for (let i = 0; i < depth; i += 1) {
    for (const column of columns) {
      const item = column[i];
      if (item !== undefined) out.push(item);
    }
  }

  return out;
}

/** نواحی → آرایهٔ صاف با ترتیبِ DOM (بالا، ستون‌ها سطربه‌سطر، پایین). */
export function flattenFooter<T extends FooterWidget>(zones: FooterZones<T>): T[] {
  return [...zones.above, ...interleaveColumns(zones.columns), ...zones.below];
}

/** یکی از سه ناحیهٔ غیرِستونی + ستونِ هدف؛ برای پنجرهٔ «کجا اضافه شود؟» */
export type FooterDropTarget = { zone: "above" } | { zone: "below" } | { zone: "column"; column: number };
