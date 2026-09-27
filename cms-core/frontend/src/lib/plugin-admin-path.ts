/**
 * K6.6 — تنها جایی که مسیر صفحهٔ ادمینِ پلاگین ساخته می‌شود.
 *
 * قید ساختاری: مسیر صفحهٔ پلاگین **باید** زیر `/admin/` باشد. تا پیش از این
 * فایل، این قید فقط در متن قرارداد بود (`docs/TASKS.md:477`،
 * `docs/PLUGIN-GUIDE.md:352`) و هیچ کدی آن را اجبار نمی‌کرد؛ مانیفست
 * `admin.page_registry` هم در بک‌اند `deferred` است و `fields => []` دارد
 * (`PluginPackageContract.php:462`) ⇒ نه `path` اعلام می‌شد، نه بررسی.
 *
 * چرا جدا از `proxy.ts`: App Router از فایل proxy/route فقط هندلر و `config`
 * صادر می‌کند، و منطق امنیتی باید مستقل و قابل تست باشد — همان دلیلی که
 * `lib/proxy-allowlist.ts` از `route.ts` جدا شده است.
 *
 * قاعده عمداً **همان** قاعدهٔ `admin.menu` در بک‌اند است
 * (`PluginPackageContract.php:178`) تا دو لایه یکی حرف بزنند و اختلافی که
 * «اینجا رد شد ولی آنجا قبول شد» تولید نکند:
 *   - الگو: `^/admin(/[a-z0-9._-]+)*$` — با لازم‌بودن `/` اول، `http://`،
 *     `javascript:` و `//evil.com` (protocol-relative) یک‌جا می‌میرند.
 *   - `is_path` (`:1208`): `[a-z0-9._-]` نقطه را می‌پذیرد، پس `..` از الگو رد
 *     نمی‌شود و باید **صریح** گرفته شود؛ وگرنه `/admin/../x` از پیشوند بیرون می‌زد.
 */

/** ریشهٔ اجباری صفحات ادمین. همین ثابت هم‌زمان در `proxy.ts` برای matcher استفاده می‌شود. */
export const PLUGIN_ADMIN_ROOT = "/admin";

/** سقف طول، هم‌تراز با `maxLength => 200` در `PluginPackageContract.php:178`. */
export const PLUGIN_ADMIN_PATH_MAX_LENGTH = 200;

/** همان الگوی مصوب بک‌اند برای `admin.menu.href` و `admin.page_registry.path`. */
export const PLUGIN_ADMIN_PATH_PATTERN = /^\/admin(\/[a-z0-9._-]+)*$/;

/** همان قاعدهٔ `is_path` در `PluginPackageContract.php:1208`. */
const TRAVERSAL_PATTERN = /(^|\/)\.\.(\/|$)/;

/** طرح‌وارهٔ اجرایی؛ پیشوند `/` آن را می‌کُتد ولی صریح گرفته می‌شود تا پیام خطا دقیق بماند. */
const EXECUTABLE_SCHEME_PATTERN = /^\s*(javascript|data|vbscript)\s*:/i;

/** خطای اعتبارسنجی مسیر. عمداً `Error` ساده است تا در بیلد و در تست یکسان رفتار کند. */
export class PluginAdminPathError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "PluginAdminPathError";
  }
}

/**
 * آیا این رشته **دقیقاً** یک مسیر صفحهٔ ادمینِ پلاگین معتبر است؟
 *
 * این تابع دروازهٔ قید K6.6 است. هر مسیری که از این بگذرد، هم زیر `/admin/`
 * است و هم در matcher پروکسی می‌افتد ⇒ چون `proxy.ts` matcher را از
 * `PLUGIN_ADMIN_ROOT` می‌سازد، هر دو از یک ریشه می‌آیند و نمی‌توانند جدا بیفتند.
 */
export function isPluginAdminPath(value: unknown): value is string {
  if (typeof value !== "string") return false;
  if (value.length === 0 || value.length > PLUGIN_ADMIN_PATH_MAX_LENGTH) return false;
  if (EXECUTABLE_SCHEME_PATTERN.test(value)) return false;
  if (TRAVERSAL_PATTERN.test(value)) return false;
  return PLUGIN_ADMIN_PATH_PATTERN.test(value);
}

/**
 * مسیر اعلام‌شده در مانیفست را راستی‌آزمایی می‌کند و **همان را** برمی‌گرداند.
 *
 * عمداً مقدار برنمی‌گرداند و نرمال‌سازی نمی‌کند: اگر رشتهٔ اعلام‌شده معتبر نبود
 * باید خطا بخورد، نه اینکه بی‌صدا به چیز دیگری تبدیل شود — همان اصلی که در
 * `ManifestRegistry.php` دنبال می‌شود (برخورد بلند = نصب بی‌دلیل نادیده گرفته
 * شدن، بدترین حالت).
 *
 * @throws {PluginAdminPathError} اگر مسیر زیر `/admin/` نباشد یا شکل ناامنی داشته باشد.
 */
export function assertPluginAdminPath(declared: unknown): string {
  if (typeof declared !== "string") {
    throw new PluginAdminPathError("مسیر صفحهٔ پلاگین باید رشته باشد.");
  }
  if (!isPluginAdminPath(declared)) {
    throw new PluginAdminPathError(
      `مسیر صفحهٔ پلاگین «${declared}» معتبر نیست: باید زیر ${PLUGIN_ADMIN_ROOT}/ باشد و فقط از حروف کوچک انگلیسی، عدد، نقطه، خط تیره و زیرخط ساخته شود.`,
    );
  }
  return declared;
}

/**
 * مسیر صفحهٔ پلاگین را از segmentها می‌سازد — و همیشه زیر `/admin/` می‌ماند.
 *
 * برخلاف `assertPluginAdminPath` که رشتهٔ ازپیش‌نوشته را می‌سنجد، این یکی مسیر
 * را **می‌سازد** و ریشه را خودش تحمیل می‌کند، پس renderer آیندهٔ K6.5
 * (`app/(client)/admin/[...slug]/page.tsx`) نمی‌تواند اشتباهی بیرون از ریشه بنویسد.
 *
 * @throws {PluginAdminPathError} اگر هر segment خالی/نقطه‌دار/خارج از الگو باشد.
 */
export function buildPluginAdminPath(...segments: string[]): string {
  if (segments.length === 0) {
    throw new PluginAdminPathError("مسیر صفحهٔ پلاگین بدون segment معتبر نیست.");
  }
  for (const segment of segments) {
    if (typeof segment !== "string" || !/^[a-z0-9][a-z0-9._-]*$/.test(segment)) {
      throw new PluginAdminPathError(
        `بخش مسیر «${String(segment)}» معتبر نیست: باید با حرف کوچک انگلیسی شروع شود و فقط از حروف کوچک انگلیسی، عدد، نقطه، خط تیره و زیرخط ساخته شود.`,
      );
    }
    // الگوی segment نقطه را می‌پذیرد، پس `..` (و `.`) جداگانه باید رد شود.
    if (segment === "." || segment === "..") {
      throw new PluginAdminPathError(`بخش مسیر «${segment}» مجاز نیست.`);
    }
  }
  const built = `${PLUGIN_ADMIN_ROOT}/${segments.join("/")}`;
  if (built.length > PLUGIN_ADMIN_PATH_MAX_LENGTH) {
    throw new PluginAdminPathError("مسیر صفحهٔ پلاگین بیش از حد بلند است.");
  }
  return built;
}

/**
 * آیا این pathname زیر ریشهٔ محافظت‌شدهٔ پروکسی می‌افتد؟
 *
 * تابع عمداً export است: تست K6.6 باید ثابت کند که **هر** مسیری که
 * `buildPluginAdminPath` می‌سازد، اینجا `true` است — یعنی سازنده و نگهبان
 * یک فضا دارند و هیچ مسیر ساخته‌شده‌ای بیرون از matcher جا نمی‌ماند.
 */
export function isGuardedAdminPath(pathname: string): boolean {
  return pathname === PLUGIN_ADMIN_ROOT || pathname.startsWith(`${PLUGIN_ADMIN_ROOT}/`);
}
