import type { MenuGroup } from "./menu";

/**
 * ساخت breadcrumb از روی مسیر جاری و رجیستری منو.
 *
 * ## چرا این‌جا و نه در `Shell`
 *
 * این منطق قبلاً داخل `Shell.tsx` بود و چون `Shell` یک کامپوننت است، هیچ راهی
 * نبود بدون رندر کردن کل شل تستش. K6.9 خواسته «crumb از page descriptor بیاید»
 * بود، ولی مشکل اصلی یک چیز دیگر بود: این تابع — که تمام breadcrumb پنل را
 * می‌سازد — **هیچ تستی نداشت**. یعنی هر تغییر در منوی ادمین می‌توانست مسیر
 * ناوبری را بی‌سروصدا خراب کند.
 *
 * ## قاعدهٔ برچسب
 *
 * برچسب از رجیستری منو خوانده می‌شود، **نه از URL خام**. URL برای ماشین است و
 * `slug` قابل‌خواندن نیست؛ اگر از URL ساخته شود، کاربر `page_registry` را می‌بیند
 * به‌جای «فهرست صفحات».
 */

/** آیتم منویی که مسیر جاری زیرمجموعه‌اش است. */
export type CrumbHit = { href: string; label: string; group?: string };

/**
 * عمیق‌ترین آیتمی که مسیر جاری زیرمجموعه‌اش است.
 *
 * «عمیق‌ترین» یعنی بلندترین `href` تطبیق‌یافته — وگرنه `/admin` زیرمجموعهٔ
 * `/admin/plugins` هم نیست ولی برعکسش هست، و بدون این قاعده مسیر
 * `/admin/plugins/42` برچسب «افزونه‌ها» می‌گرفت نه «افزونه‌ها › ۴۲».
 *
 * تطبیق باید روی مرز مسیر باشد: `/admin/plug` نباید زیرمجموعهٔ
 * `/admin/plugins` شمرده شود، وگرنه crumb غلط می‌سازد.
 */
export function findCrumbHit(pathname: string, groups: MenuGroup[]): CrumbHit | null {
  let hit: CrumbHit | null = null;

  for (const g of groups) {
    for (const item of g.items) {
      // آیتم و فرزندانش هر دو کاندید crumb هستند؛ فرزند بلندتر است پس در
      // مسیرهای زیرمنو برنده می‌شود (مثلاً /admin/plugins/developers).
      for (const candidate of [item, ...(item.children ?? [])]) {
        if (pathname !== candidate.href && !pathname.startsWith(`${candidate.href}/`)) continue;
        if (!hit || candidate.href.length > hit.href.length) {
          hit = { href: candidate.href, label: candidate.label, group: g.title };
        }
      }
    }
  }

  return hit;
}

/**
 * breadcrumb نهایی: ریشه + گروه + برچسب آیتم منطبق.
 *
 * تکرارهای مجاور حذف می‌شوند چون ریشه و گروه می‌توانند یکی باشند — مثلاً گروهی با
 * عنوان «پنل» زیر ریشهٔ «پنل». بدون این فیلتر، crumb دو بار همان واژه را نشان
 * می‌داد.
 *
 * اگر هیچ آیتمی منطبق نبود، فقط ریشه برمی‌گردد — که درست است: مسیری که در منو
 * نیست نباید برچسبی اختراعی بگیرد.
 */
export function crumbsForPath(pathname: string, groups: MenuGroup[], root: string[]): string[] {
  const hit = findCrumbHit(pathname, groups);
  const parts = (hit ? [root, hit.group, hit.label] : [root])
    .flat()
    .filter((p): p is string => Boolean(p));

  return parts.filter((p, i) => i === 0 || p !== parts[i - 1]);
}
