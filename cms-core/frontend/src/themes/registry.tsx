import { resolveThemeManifest } from "./manifests";
import { DefaultBlogTheme } from "./blog";
import { CommerceTheme } from "./commerce";
import { EditorialTheme } from "./editorial";
import { MinimalTheme } from "./minimal";
import type { ThemeBlogComponent, ThemeDefinition } from "./types";

/**
 * ECO1 — رجیستریِ رندرِ قالب‌های کد‌محور.
 *
 * `resolveTheme(slug)` همیشه یک قالب برمی‌گرداند: اسلاگِ ناشناخته به
 * `DEFAULT_THEME_SLUG` می‌افتد (منطقش در `manifests.ts` و تست‌شده). قرارداد
 * این است که مسیرِ عمومی با هیچ اسلاگی کرش نکند — دادهٔ قدیمی نباید سایت را
 * به صفحهٔ سفید ببرد.
 */
const DEFINITIONS: ThemeDefinition[] = [
  { manifest: resolveThemeManifest("minimal"), Component: MinimalTheme },
  { manifest: resolveThemeManifest("editorial"), Component: EditorialTheme },
  { manifest: resolveThemeManifest("commerce"), Component: CommerceTheme },
];

const BY_SLUG: Record<string, ThemeDefinition> = Object.fromEntries(
  DEFINITIONS.map((d) => [d.manifest.slug, d]),
);

export { DEFAULT_THEME_SLUG, themeSlugs } from "./manifests";

/** اسلاگِ فعال → تعریفِ قالب، با fallback ایمن به پیش‌فرض. */
export function resolveTheme(slug?: string | null): ThemeDefinition {
  const manifest = resolveThemeManifest(slug);
  return BY_SLUG[manifest.slug];
}

/**
 * WF-C7 — رندرکنندهٔ اسلاتِ بلاگ. اگر قالب `Blog` خودش را داده باشد همان،
 * وگرنه پیاده‌سازیِ پیش‌فرض با `variant` همان قالب (رنگ/شعاع/عرض حفظ می‌شود).
 */
export function resolveBlogTheme(slug?: string | null): ThemeBlogComponent {
  const definition = resolveTheme(slug);
  if (definition.Blog) return definition.Blog;
  const variant = definition.manifest.slug;
  return (props) => <DefaultBlogTheme {...props} variant={variant} />;
}
