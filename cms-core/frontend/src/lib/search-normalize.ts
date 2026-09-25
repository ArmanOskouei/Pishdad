/**
 * نرمال‌سازی فارسی برای جستجو (مشترک لایو‌سرچ + صفحه نتایج):
 * - یکسان‌سازی ي/ك/ة عربی → ی/ک/ه
 * - حذف اعراب، تشدید، کشیده (ـ) و نیم‌فاصله/‌ برای تطبیق
 * - یکدست‌سازی ارقام عربی/انگلیسی → فارسی
 * تحمل غلط املایی ساده = includes + تطبیق شروع‌کلمه (matchScore در search-registry).
 */

export function normalizeFa(s: string): string {
  if (!s) return "";
  return s
    .replace(/ي/g, "ی")
    .replace(/ك/g, "ک")
    .replace(/ة/g, "ه")
    .replace(/[ً-ٰٟـ]/g, "")
    .replace(/[\u200C\u200D]/g, "")
    .replace(/[٠-٩]/g, (d) => "۰۱۲۳۴۵۶۷۸۹"["٠١٢٣٤٥٦٧٨٩".indexOf(d)])
    .replace(/[0-9]/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[Number(d)])
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

export function queryTokens(q: string): string[] {
  return normalizeFa(q).split(" ").filter((t) => t.length > 0);
}

/** نرمال‌سازی کاراکتربه‌کاراکتر با نگاشت ایندکس → متن اصلی (برای هایلایت دقیق). */
export function normalizeWithMap(s: string): { text: string; map: number[] } {
  let text = "";
  const map: number[] = [];
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (/[ً-ٰٟـ]/.test(ch) || ch === "‌" || ch === "‍") continue; // حذف بدون جایگزین
    let out = ch;
    if (ch === "ي") out = "ی";
    else if (ch === "ك") out = "ک";
    else if (ch === "ة") out = "ه";
    else if (ch >= "٠" && ch <= "٩") out = "۰۱۲۳۴۵۶۷۸۹"[ch.charCodeAt(0) - 0x660];
    else if (ch >= "0" && ch <= "9") out = "۰۱۲۳۴۵۶۷۸۹"[Number(ch)];
    else out = ch.toLowerCase();
    text += out;
    map.push(i);
  }
  return { text, map };
}

/** بازه‌های [شروع، پایان) در متن اصلی که توکن‌های کوئری را پوشش می‌دهند. */
export function findRanges(original: string, rawQuery: string): Array<[number, number]> {
  const tokens = queryTokens(rawQuery);
  if (tokens.length === 0 || !original) return [];
  const { text, map } = normalizeWithMap(original);
  const ranges: Array<[number, number]> = [];
  for (const t of tokens) {
    let from = 0;
    for (let guard = 0; guard < 8; guard++) {
      const idx = text.indexOf(t, from);
      if (idx < 0) break;
      const start = map[idx] ?? idx;
      const endIdx = idx + t.length - 1;
      const end = (map[endIdx] ?? endIdx) + 1;
      ranges.push([start, Math.min(end, original.length)]);
      from = idx + Math.max(1, t.length);
    }
  }
  return ranges.sort((a, b) => a[0] - b[0]);
}
