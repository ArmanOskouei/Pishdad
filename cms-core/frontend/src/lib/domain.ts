/** تایپ‌های دامنه F2 — شکل واقعی پاسخ‌های بک‌اند (OpenAPI 1.7.0 + کنترلرها). */

import { publicConfig } from "./runtime-config.ts";

export type Paginator<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

const isPaginator = (x: unknown): x is Paginator<never> =>
  !!x && typeof x === "object" &&
  Array.isArray((x as { data?: unknown }).data) &&
  typeof (x as { current_page?: unknown }).current_page === "number";

/**
 * نرمال‌سازی پاسخ لیست: بک‌اند گاهی paginator خام برمی‌گرداند و گاهی
 * `{data: paginator}`.
 */
export function asPaginator<T>(json: unknown): Paginator<T> {
  if (isPaginator(json)) return json as Paginator<T>;
  const d = (json as { data?: unknown } | null)?.data;
  if (isPaginator(d)) return d as Paginator<T>;
  const arr = Array.isArray(d) ? (d as T[]) : Array.isArray(json) ? (json as T[]) : [];
  return { data: arr, current_page: 1, last_page: 1, per_page: arr.length, total: arr.length };
}

export function asList<T>(json: unknown): T[] {
  if (Array.isArray(json)) return json as T[];
  const d = (json as { data?: unknown } | null)?.data;
  if (Array.isArray(d)) return d as T[];
  if (isPaginator(d)) return (d as Paginator<T>).data;
  return [];
}

/* ── صفحات (۱.۵) ── */

export type PageItem = {
  id: number;
  title: string;
  slug: string;
  status: "draft" | "published";
  is_single?: boolean;
  /** دسته ۱: شمارش بازبینی‌ها برای ستون «نسخه‌ها» (افزودنی، ممکن است غایب باشد). */
  revisions_count?: number;
  user_id?: number;
  blocks?: BlockValue[];
  meta?: Record<string, unknown> | null;
  published_revision_id?: number | null;
  published_at?: string | null;
  /** WF-C3: موعدِ انتشار زمان‌بندی‌شده (ISO) — فقط برای صفحهٔ پیش‌نویس معنا دارد. */
  scheduled_at?: string | null;
  left_preset_id?: number | null;
  right_preset_id?: number | null;
  left_enabled?: boolean;
  right_enabled?: boolean;
  leftPreset?: SidePreset | null;
  rightPreset?: SidePreset | null;
  created_at?: string;
  updated_at?: string;
};

export type BlockValue = { type: string; data: Record<string, unknown> };

export type Revision = {
  id: number;
  page_id: number;
  version: number;
  blocks?: BlockValue[];
  meta?: Record<string, unknown> | null;
  created_by?: number | null;
  note?: string | null;
  created_at?: string;
};

export type BlockPropSchema = {
  type?: string;
  enum?: (string | number)[];
  default?: unknown;
  minLength?: number;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  minItems?: number;
  maxItems?: number;
  items?: { type?: string; minimum?: number };
  description?: string;
};

export type BlockSchema = {
  type: "object";
  required?: string[];
  properties?: Record<string, BlockPropSchema>;
};

export type BlockDef = {
  type: string;
  title: string;
  description?: string | null;
  schema: BlockSchema;
};

/* ── فرم‌ساز (WF-H10) ── */

export type FormFieldItem = {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  placeholder?: string;
  options?: string[];
  max_length?: number;
};

export type FormItem = {
  id: number;
  name: string;
  slug: string;
  fields: FormFieldItem[];
  destination: "email" | "ticket";
  recipients?: string[] | null;
  success_message?: string | null;
  active: boolean;
  submissions_count?: number;
  created_at?: string;
  updated_at?: string;
};

export type FormSubmissionItem = {
  id: number;
  form_id: number;
  payload: Record<string, unknown>;
  ip?: string | null;
  created_at?: string;
};

/* ── مدیا (۱.۶) ── */

export type MediaItem = {
  id: number;
  original_name: string;
  mime: string;
  size: number;
  path: string;
  /** دیسکِ ذخیره (`s3` | `public` | `local`) — سرور می‌دهد. */
  disk?: string;
  /** نشانیِ عمومیِ محاسبه‌شده دقیق (دیسک-aware) — اگر باشد، بر پایهٔ S3 مقدم است. */
  url?: string | null;
  alt?: string | null;
  /** WF-H6 — پوشهٔ حاوی فایل (nullable؛ «بدون پوشه» = null). */
  folder_id?: number | null;
  folder?: MediaFolder | null;
  /** WF-H6 — برچسب‌های فایل. */
  tags?: MediaTag[];
  /** WF-L1 — نقطهٔ کانونی نرمال‌شده (0..1) برای برشِ واکنش‌گرا. */
  focal_x?: number | null;
  focal_y?: number | null;
  created_at?: string;
  deleted_at?: string | null;
};

/** WF-H6 — پوشهٔ درختی رسانه. */
export type MediaFolder = {
  id: number;
  name: string;
  parent_id?: number | null;
  media_count?: number;
};

/** WF-H6 — برچسب رسانه. */
export type MediaTag = {
  id: number;
  name: string;
  color?: string | null;
  media_count?: number;
};

/**
 * نشانی نمایشی/کپی فایل.
 *
 * اولویت با `m.url` است — بک‌اند آن را بر پایهٔ **دیسکِ خودِ فایل** می‌سازد
 * (`s3` ⇒ MinIO، `public` ⇒ `/storage`). بدون آن، همه با پایهٔ S3 آدرس
 * می‌گرفتند و فایل‌های دیسکِ public (تصاویرِ SVG محتوا) ۴۰۴/ORB می‌شدند.
 *
 * E71 — ولی `url` نسبی (`/storage/…` از دیسکِ `public`) همان‌طور که هست
 * برنمی‌گردد: مرورگرِ پنل آن را نسبت به میزبانِ **فرانت** باز می‌کند و ۴۰۴
 * می‌گیرد (پیش‌نمایشِ `/admin/media` و آدرسِ جزئیات دقیقاً همین‌طور شکستند).
 * پس نشانیِ نسبی با پایهٔ رسانهٔ زمانِ اجرا مطلق می‌شود؛ مطلق (MinIO/S3) و
 * `//host` دست‌نخورده می‌مانند. پایهٔ خالی یعنی رفتارِ قبلی.
 */
export function mediaUrl(m: Pick<MediaItem, "path"> & { url?: string | null }): string {
  const url = typeof m.url === "string" && m.url ? m.url : null;
  if (url) {
    if (!url.startsWith("/") || url.startsWith("//")) return url;
    const base = publicConfig().mediaUrl;
    return base ? `${base}${url}` : url;
  }
  const base = publicConfig().mediaUrl;
  return `${base}/${m.path.replace(/^\/+/, "")}`;
}


/* ── سهمیهٔ فضا و وضعیت سایت ── */

export type MediaUsage = {
  used_bytes: number;
  quota_bytes: number;
  tier: string;
  percent: number;
};

export type SiteStatus = { status: "active"; message: string };

/* ── مدیران + نقش‌ها (۱.۳) ── */

export type Manager = {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  /** دسته UIUX (افزودنی): آواتار per-row در تب مدیران. */
  avatar_media_id?: number | null;
  avatar_url?: string | null;
  created_at?: string;
};

export type RoleItem = {
  id: number;
  name: string;
  system: boolean;
  permissions: string[];
  managers_count: number;
  /** WF-M8 — آیا ورود کاربران این نقش مستلزم تأیید دومرحله‌ای است؟ */
  requires_2fa: boolean;
};

export type PermModule = { name: string; title_fa: string; source: string };
export type PermAction = { name: string; title_fa: string };

/** ماتریس دسترسی تسک ۴: ماژول‌ها با عنوان فارسی (قرارداد API). */
export type PermMatrix = { modules: PermModule[]; actions: PermAction[] };

/** نرمال‌سازی پاسخ ماتریس (شکل جدید آبجکتی + تحمل شکل قدیمی رشته‌ای). */
export function asPermMatrix(json: unknown): PermMatrix {
  const d = (json as { data?: unknown } | null)?.data ?? json;
  const m = (d as { modules?: unknown })?.modules;
  const a = (d as { actions?: unknown })?.actions;
  const modules: PermModule[] = Array.isArray(m)
    ? m.map((x) =>
        typeof x === "string"
          ? { name: x, title_fa: MODULE_FA[x] ?? x, source: "core" }
          : { name: String((x as PermModule).name ?? ""), title_fa: String((x as PermModule).title_fa ?? (x as PermModule).name ?? ""), source: String((x as PermModule).source ?? "core") },
      )
    : [];
  const actions: PermAction[] = Array.isArray(a)
    ? a.map((x) =>
        typeof x === "string"
          ? { name: x, title_fa: ACTION_FA[x] ?? x }
          : { name: String((x as PermAction).name ?? ""), title_fa: String((x as PermAction).title_fa ?? (x as PermAction).name ?? "") },
      )
    : [];
  return { modules, actions };
}

/** نگاشت فارسی ماژول‌ها/اکشن‌های هسته (fallback وقتی API title_fa ندهد). */
export const MODULE_FA: Record<string, string> = {
  pages: "صفحات",
  media: "فایل‌ها",
  settings: "تنظیمات",
  tickets: "تیکت‌ها",
  plugins: "پلاگین‌ها",
  themes: "قالب‌ها",
  layouts: "چیدمان",
  users: "مدیران و نقش‌ها",
};

export const ACTION_FA: Record<string, string> = {
  view: "مشاهده",
  edit: "ویرایش",
  delete: "حذف",
};

export const ROLE_FA: Record<string, string> = {
  owner: "مالک",
  admin: "مدیر",
  editor: "ویراستار",
  viewer: "بیننده",
};

/* ── گزارش فعالیت محتوا (WF-H9) ── */

export type ActivityItem = {
  id: number;
  user_id?: number | null;
  user?: { id: number; name: string } | null;
  subject_type?: string | null;
  subject_id?: number | null;
  action: string;
  summary: string;
  diff?: Record<string, unknown> | null;
  /** شناسهٔ نسخهٔ قابل بازگردانی (فقط رویدادهای صفحه که نسخهٔ قبلی دارند). */
  restorable_revision_id?: number | null;
  created_at?: string | null;
};

export const ACTIONS_PAGE = ["page.create", "page.update", "page.publish", "page.unpublish", "page.delete", "page.restore", "page.force_delete"] as const;

export const ACTIVITY_ACTION_FA: Record<string, string> = {
  "page.create": "ساخت صفحه",
  "page.update": "ویرایش صفحه",
  "page.publish": "انتشار صفحه",
  "page.unpublish": "لغو انتشار صفحه",
  "page.delete": "حذف صفحه",
  "page.restore": "بازگردانی صفحه",
  "page.force_delete": "حذف دائم صفحه",
  "media.upload": "آپلود فایل",
  "media.replace": "جایگزینی فایل",
  "media.delete": "حذف فایل",
};

/** فیلدهای diff با برچسب فارسی برای نمایش در جدول. */
export const ACTIVITY_FIELD_FA: Record<string, string> = {
  title: "عنوان",
  slug: "نشانی",
  locale: "زبان",
  status: "وضعیت",
  is_single: "تک‌صفحه‌ای",
  blocks: "بلوک‌ها",
  meta: "متا",
  left_preset_id: "پریست چپ",
  right_preset_id: "پریست راست",
  left_enabled: "ستون چپ",
  right_enabled: "ستون راست",
};

/* ── پروفایل (۱.۴) ── */

export type ProfileData = {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  /** تسک ۵: آواتار + نام/نام‌خانوادگی + درباره من. */
  avatar_media_id?: number | null;
  avatar_url?: string | null;
  first_name?: string | null;
  last_name?: string | null;
  bio?: string | null;
  google2fa_enabled: boolean;
  roles: string[];
  /**
   * K6.1 — پرمیشن‌های مؤثر *خودِ* مدیر جاری به شکل `module.action`.
   *
   * خالی یعنی «هیچ» و دروازهٔ `mergePluginMenu` روی ورودی خالی fail-closed
   * است. نقشِ `owner`/`admin` همهٔ ماژول‌ها × اکشن‌ها را دارد، پس فهرستش کامل است.
   */
  permissions?: string[];
};

export type TwoFactorMethods = {
  totp: { enabled: boolean };
  recovery_codes_remaining: number;
  /** دسته UIUX (افزودنی): وضعیت تأیید پیامکی. */
  sms?: { enabled: boolean; phone?: string | null } | null;
};

/* ── تیکت‌ها (۱.۷) ── */

export type TicketMessage = {
  id: number;
  user_id?: number | null;
  author_type: string;
  body: string;
  attachment_media_id?: number | null;
  created_at?: string;
  user?: { id: number; name: string } | null;
};

export type Ticket = {
  id: number;
  subject: string;
  status: "open" | "pending" | "closed";
  priority: "low" | "normal" | "high" | "urgent";
  labels?: string[] | null;
  source?: string;
  messages_count?: number;
  messages?: TicketMessage[];
  closed_at?: string | null;
  created_at?: string;
  updated_at?: string;
};

export const TICKET_STATUS_FA: Record<string, string> = { open: "باز", pending: "در انتظار", closed: "بسته" };
export const TICKET_PRIORITY_FA: Record<string, string> = { low: "کم", normal: "عادی", high: "زیاد", urgent: "فوری" };

/* ── تنظیمات سایت + شبکه‌ها (۱.۸ / ۱.۹) ── */

export type SiteSettings = {
  title: string;
  description?: string | null;
  logo_media_id?: number | null;
  favicon_media_id?: number | null;
  /** نشانی نمایشی — سرور غنی می‌کند (حل path با AWS_URL، وگرنه null). */
  logo_url?: string | null;
  favicon_url?: string | null;
  phone?: string | null;
  email?: string | null;
  /** سئو/GEO: نشانی پایه سایت (canonical/OG) — بدون اسلش پایانی. */
  site_url?: string | null;
  og_image_media_id?: number | null;
  /** نشانی نمایشی تصویر OG — سرور غنی می‌کند. */
  og_image_url?: string | null;
  /** خلاصه ۱-۲ جمله‌ای سایت برای llms.txt و AI (حداکثر ۱۰۰۰ نویسه). */
  ai_summary?: string | null;
  /** اجازه ایندکس کل سایت (false = noindex سراسری). */
  robots_index?: boolean;
  /** WF-H1 — متن سفارشی robots.txt (خالی = پیش‌فرض ساخته‌شده). */
  robots_txt?: string | null;
  /** WF-H1 — کد تأیید مالکیت Google Search Console. */
  google_site_verification?: string | null;
  /** نشانی فیزیکی — JSON-LD سازمان. */
  address?: string | null;
  /** WF-M20 — متن سیاست حریم خصوصی (صفحهٔ /privacy). بدون HTML. */
  privacy_policy?: string | null;
  /** WF-M17 — متن بیانیهٔ دسترس‌پذیری (صفحهٔ /accessibility). بدون HTML. */
  accessibility_statement?: string | null;
  /** دسته UIUX (افزودنی): زبان پیش‌فرض سایت. */
  locale?: string | null;
  /** ECO2 — حالت زبان: `single` (فقط زبان اصلی) یا `dual` (اصلی + دوم، مدل a). */
  language_mode?: "single" | "dual" | null;
  /** ECO2 — زبان دوم؛ فقط در حالت `dual` معنا دارد. */
  secondary_locale?: string | null;
  /** ECO2 — فهرست زبان‌ها (زبان اصلی اول). سرور محاسبه می‌کند. */
  locales?: string[] | null;
  /** ECO2 — زبانِ ریشهٔ سایت (مدل a). */
  primary_locale?: string | null;
  /** دسته UIUX (افزودنی): منطقه زمانی سایت. */
  timezone?: string | null;
  /** کار دوم (افزودنی): صفحه خانه تنظیم‌شده. */
  homepage_page_id?: number | null;
  /** «کش سایت»: خاموش ⇒ تنظیمات بی‌درنگ روی سایت؛ روشن ⇒ سرعت/مصرف بهینه. پیش‌فرض روشن. */
  cache_enabled?: boolean;
};

export type SocialItem = {
  key: string;
  url: string;
  active: boolean;
  /** برچسب نمایشی شبکه سفارشی (nullable) — اگر نباشد از SOCIAL_FA یا خود key استفاده می‌شود. */
  label?: string | null;
  /** شناسه لوگوی سفارشی در کتابخانه (nullable). */
  icon_media_id?: number | null;
  /** نشانی نمایشی لوگو — سرور غنی می‌کند (حل path با AWS_URL، وگرنه null). */
  icon_url?: string | null;
};

export const SOCIAL_FA: Record<string, string> = {
  instagram: "اینستاگرام",
  telegram: "تلگرام",
  x: "ایکس",
  linkedin: "لینکدین",
  aparat: "آپارات",
  youtube: "یوتیوب",
  facebook: "فیسبوک",
  whatsapp: "واتساپ",
  website: "وب‌سایت",
};

/* ── پلاگین‌ها (۱.۱۰ + گردش تایید مورد ۶) ── */

export type ReviewStatus = "pending" | "approved" | "rejected";

export type PluginItem = {
  id: number;
  name: string;
  /**
   * B35 — توضیح اختیاری از مانیفست.
   *
   * `null` یعنی نویسنده چیزی ننوشته؛ رشتهٔ خالی یعنی نوشته ولی خالی است. بک‌اند
   * عمداً این دو را یکی نمی‌کند، پس اینجا هم نباید با `?? ""` یکی شوند.
   */
  description?: string | null;
  slug: string;
  version: string;
  previous_version?: string | null;
  active: boolean;
  system: boolean;
  signature_valid: boolean;
  review_status?: ReviewStatus;
  review_note?: string | null;
  submitted_at?: string | null;
  reviewed_at?: string | null;
  checksum?: string;
  manifest?: Record<string, unknown> | null;
  created_at?: string;
  /**
   * WF-H17 — آخرین نسخهٔ بازارِ افزونه (یا آخرین نسخهٔ اعلام‌شده در مانیفست).
   * `null` یعنی «اطلاعات انتشار موجود نیست»، نه «نسخهٔ ۰».
   */
  latest_version?: string | null;
  /** WF-H17 — آیا نسخهٔ نصب‌شده از نسخهٔ بازار عقب‌تر است؟ */
  update_available?: boolean;
  /** WF-H17 — متن تغییرات نسخهٔ جدید، برای نمایش پیش از ارتقا. */
  changelog?: string | null;
};

export const REVIEW_FA: Record<ReviewStatus, string> = {
  pending: "در انتظار تأیید",
  approved: "تأییدشده",
  rejected: "ردشده",
};

/* ── هدر/فوتر + بلوک‌ها (۱.۱۱ / ۱.۱۲) ── */

export type WidgetValue = {
  type: string;
  settings: Record<string, unknown>;
  /**
   * E66 — جای ویجت در فوتر: غایب/`"grid"` = در ستون‌ها (رفتارِ قبلی)،
   * `"above"` = تمام‌عرض بالای ستون‌ها، `"below"` = تمام‌عرض پایینِ ستون‌ها.
   * در هدر بی‌معناست و بک‌اند هم فقط برای فوتر ذخیره‌اش می‌کند.
   */
  place?: "above" | "grid" | "below" | null;
};

export type LayoutData = { widgets: WidgetValue[]; layout: Record<string, unknown> };

/**
 * آیتم لینک nav هدر / links فوتر — قرارداد بک‌اند (LinkItems):
 * page = صفحه منتشرشده (رزولو زنده title/url در کروم عمومی)،
 * custom = لینک دستی. شکل قدیمی {label, href} یعنی custom.
 */
export type ChromeLinkItem = {
  kind?: "page" | "custom";
  page_id?: number | null;
  label?: string;
  href?: string;
  /** زیرمنو (تودرتو تا عمق ۲ طبق LinkItems بک‌اند: MAX_DEPTH=2، هر سطح ≤۸). */
  children?: ChromeLinkItem[];
  /** فقط kind=page در خروجی عمومی — عنوان/نشانی زنده صفحه. */
  title?: string | null;
  url?: string | null;
};

/** سقف‌های هم‌خط بک‌اند (LinkItems::MAX_*). */
export const LINK_LIMITS = { MAX_ITEMS: 12, MAX_CHILDREN: 8, MAX_DEPTH: 2 } as const;

const HREF_RE = /^(https?:\/\/|\/|#|\?|mailto:|tel:)/u;

/** اعتبارسنجی سمت کلاینت هم‌خط بک‌اند — خروجی: خطا به‌ازای path (مثل «2 / زیرمنو 1»). */
export function validateLinkTree(links: ChromeLinkItem[]): string[] {
  const errors: string[] = [];
  const pos = (path: number[]): string => {
    const parts = path.map((idx, lvl) =>
      lvl === 0 ? `پیوند ${idx + 1}` : `زیرمنو ${idx + 1}`,
    );
    return parts.join(" / ");
  };
  const walk = (raw: unknown, path: number[], depth: number): void => {
    const at = pos(path);
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
      errors.push(`${at}: ورودی معتبر نیست.`);
      return;
    }
    const item = raw as ChromeLinkItem;
    const kind = item.kind === "page" || item.kind === "custom" ? item.kind : item.page_id ? "page" : "custom";
    const label = typeof item.label === "string" ? item.label.trim() : "";
    const href = typeof item.href === "string" ? item.href.trim() : "";
    const rawChildren = (raw as { children?: unknown }).children;
    const hasChildren = rawChildren !== undefined && rawChildren !== null;
    if (kind === "page") {
      if (!item.page_id || item.page_id <= 0) errors.push(`${at}: صفحه انتخاب نشده است.`);
      if (label.length > 80) errors.push(`${at}: عنوان حداکثر ۸۰ نویسه است.`);
    } else {
      if (!label) errors.push(`${at}: عنوان الزامی است.`);
      else if (label.length > 80) errors.push(`${at}: عنوان حداکثر ۸۰ نویسه است.`);
      if (hasChildren && href === "") {
        // مجاز: والدِ دارای زیرمنو بدون نشانی (هم‌خط validateCustomItem بک‌اند).
      } else if (!href || href.length > 2048 || !HREF_RE.test(href)) {
        errors.push(`${at}: نشانی معتبر نیست (با / یا https:// شروع شود).`);
      }
    }
    if (!hasChildren) return;
    if (!Array.isArray(rawChildren) || (rawChildren.length > 0 && !rawChildren.every((_, i) => i in rawChildren))) {
      errors.push(`${at}: زیرمنوها باید لیست باشند.`);
      return;
    }
    const children = rawChildren as unknown[];
    if (depth >= LINK_LIMITS.MAX_DEPTH) {
      errors.push(`${at}: عمق تودرتو حداکثر ${LINK_LIMITS.MAX_DEPTH} سطح است.`);
      return;
    }
    if (children.length > LINK_LIMITS.MAX_CHILDREN) {
      errors.push(`${at}: هر پیوند حداکثر ${LINK_LIMITS.MAX_CHILDREN} زیرمنو دارد.`);
    }
    children.forEach((c, j) => walk(c, [...path, j], depth + 1));
  };
  if (!Array.isArray(links)) {
    return ["لیست پیوندها معتبر نیست."];
  }
  if (links.length > LINK_LIMITS.MAX_ITEMS) {
    errors.push(`حداکثر ${LINK_LIMITS.MAX_ITEMS} پیوند مجاز است.`);
  }
  links.forEach((l, i) => walk(l, [i], 0));
  return errors;
}

/** گزینه سبک صفحه منتشرشده برای انتخاب‌گر «صفحه سایت». */
export type PageOption = { id: number; title: string; slug: string };

/** تسک ۶: schema یک ویجت از GET /api/v1/admin/widgets/schema. */
export type WidgetSchema = {
  area: "header" | "footer";
  type: string;
  title: string;
  description?: string | null;
  schema: BlockSchema;
  ui?: { labels?: Record<string, string>; media_field?: string };
  source: string;
};

export type PageTypeItem = {
  type: string;
  title: string;
  description?: string;
  enabled?: boolean;
  source?: string;
  blocks: BlockValue[];
  customized: boolean;
};

/* ── ستون‌های کناری — پریست‌های مشترک با ارجاع زنده ── */

export type SideName = "left" | "right";

export type SidePreset = {
  id: number;
  name: string;
  side: SideName;
  blocks: BlockValue[];
  created_at?: string;
  updated_at?: string;
};

export type SidebarData = {
  enabled: boolean;
  preset_id?: number | null;
  blocks: BlockValue[];
};

/* ── قالب‌های سایت (۱.۱۳ + گردش تایید مورد ۶) ── */

export type ThemeItem = {
  id: number;
  name: string;
  slug: string;
  version: string;
  active: boolean;
  signature_valid: boolean;
  review_status?: ReviewStatus;
  review_note?: string | null;
  submitted_at?: string | null;
  reviewed_at?: string | null;
  manifest?: Record<string, unknown> | null;
  created_at?: string;
};

/* ── صف بازبینی مرکزی (مورد ۶) ── */

export type ReviewQueueItem = {
  type: "plugin" | "theme";
  id: number;
  name: string;
  slug: string;
  version: string;
  signature_valid: boolean;
  submitted_at?: string | null;
  manifest?: Record<string, unknown> | null;
  owner?: { id: number; name: string; email: string } | null;
};

/** WF-H18 — یک نظرِ بازار در صف بازبینی مرکزی. */
export type MarketReviewQueueItem = {
  id: number;
  plugin_id: number;
  user_id: number;
  rating: number;
  comment: string | null;
  status: "pending" | "approved" | "rejected";
  created_at?: string | null;
  plugin?: { id: number; name: string; slug: string; version: string } | null;
  user?: { id: number; name: string; email: string } | null;
};

/** دسته UIUX (افزودنی): نشست فعال کاربر — فراداده parseشده (مسئله ۲). */
export type UserSession = {
  id: number;
  name: string;
  current: boolean;
  os?: string | null;
  os_version?: string | null;
  browser?: string | null;
  browser_version?: string | null;
  device?: string | null;
  device_type?: string | null;
  ip?: string | null;
  last_used_at?: string | null;
  created_at?: string;
  expires_at?: string | null;
};

/** WF-H8 — یک تلاشِ ورود (موفق/ناموفق) برای کارت «تاریخچهٔ ورود». */
export type LoginEvent = {
  id: number;
  successful: boolean;
  ip?: string | null;
  os?: string | null;
  os_version?: string | null;
  browser?: string | null;
  browser_version?: string | null;
  device?: string | null;
  device_type?: string | null;
  created_at?: string | null;
};

/* ── سایت عمومی (ISR) ── */

export type SitePage = {
  title: string;
  slug: string;
  is_single?: boolean;
  blocks: BlockValue[];
  meta?: Record<string, unknown> | null;
  published_at?: string | null;
  updated_at?: string | null;
  version?: number;
  sidebars?: { left?: SidebarData | null; right?: SidebarData | null };
};

/* ── برچسب‌های فارسی ── */

export const PAGE_STATUS_FA: Record<string, string> = { draft: "پیش‌نویس", published: "منتشرشده" };
export const HEALTH_FA: Record<string, string> = {
  healthy: "سالم",
  warning: "هشدار",
  critical: "بحرانی",
  unknown: "نامشخص",
};
