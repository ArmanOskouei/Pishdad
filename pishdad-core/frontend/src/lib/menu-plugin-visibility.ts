import type { MenuGroup, MenuItem } from "./menu.ts";

/**
 * حذفِ آیتم‌هایی که افزونه‌شان **فعال نیست**.
 *
 * ⭐ این قانون از یک گزارشِ واقعی آمد:
 *
 * «پلاگین را غیرفعال کردم، ولی آیتمش هنوز در منوی پنل بود.»
 *
 * ریشه‌اش این بود که آیتم «اشتراک من» در `ADMIN_MENU` هاردکد شده بود، پس
 * هیچ ربطی به وضعیت افزونه نداشت و هیچ کشی هم نمی‌توانست آن را بردارد.
 *
 * ## چرا فیلتر سمت فرانت و نه بک‌اند
 *
 * چون آیتم در منوی **هسته** تعریف شده، نه در رجیستری افزونه‌ها. رجیستری
 * فقط چیزی را برمی‌گرداند که افزونه *اعلام* کرده، و این آیتم اعلام نشده.
 *
 * جای دیگری هم لازم بود: منو روی سرور رندر می‌شود و هر رندر یک درخواست
 * می‌زند — ولی اگر بک‌اند فیلتر می‌کرد، منوی کش‌شدهٔ صفحه باید بی‌اعتبار
 * می‌شد. فیلتر سمت فرانت یعنی وضعیت همیشه تازه است، حتی وقتی بک‌اند کش
 * دارد.
 *
 * ## fail-closed یعنی چه
 *
 * اگر فهرست افزونه‌های فعال **در دسترس نباشد** (خطای شبکه، endpoint قطع)،
 * همهٔ آیتم‌هایِ افزونه‌ای حذف می‌شوند. دلیل: نشان‌دادنِ آیتمی که شاید
 * کار نکند بدتر از نیامدنش است — کاربر کلیک می‌کند و به صفحهٔ خطا می‌خورد.
 * نبودن یک آیتمِ اشتراک، کم‌ضررترین حالتِ ممکن است.
 */
export function filterMenuByPlugins(
  groups: readonly MenuGroup[],
  activePluginSlugs: ReadonlySet<string> | null,
): MenuGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items
        .filter((item) => itemIsAvailable(item, activePluginSlugs))
        // آیتمِ باقی‌مانده که فرزند دارد: فرزندانش هم همان دروازه را رد می‌کنند.
        .map((item) =>
          item.children
            ? { ...item, children: item.children.filter((c) => itemIsAvailable(c, activePluginSlugs)) }
            : item,
        ),
    }))
    // گروهی که همهٔ آیتم‌هایش رفت نباید بماند: «مالی» با تنها آیتمِ
    // اشتراک، بعد از غیرفعال‌کردنِ افزونه یک عنوانِ خالی می‌شد.
    .filter((group) => group.items.length > 0);
}

function itemIsAvailable(
  item: MenuItem,
  activePluginSlugs: ReadonlySet<string> | null,
): boolean {
  // آیتمِ خودِ هسته: همیشه هست.
  if (item.requiresPlugin === undefined) {
    return true;
  }

  // «نمی‌دانیم» ⇒ نمی‌نمایان. fail-closed.
  if (activePluginSlugs === null) {
    return false;
  }

  return activePluginSlugs.has(item.requiresPlugin);
}
