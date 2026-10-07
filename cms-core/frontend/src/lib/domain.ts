/** تایپ‌های دامنه F2 — شکل واقعی پاسخ‌های بک‌اند (OpenAPI 1.7.0 + کنترلرها). */

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
 * نرمال‌سازی پاسخ لیست: بک‌اند گاهی paginator خام برمی‌گرداند
 * (pages/media/invoices مشتری) و گاهی `{data: paginator}`.
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
 */
export function mediaUrl(m: Pick<MediaItem, "path"> & { url?: string | null }): string {
  if (typeof m.url === "string" && m.url) return m.url;
  const base = (process.env.NEXT_PUBLIC_MEDIA_URL ?? "").trim().replace(/\/+$/, "");
  return `${base}/${m.path.replace(/^\/+/, "")}`;
}

/* ── اشتراک مشتری (۱.۱۵) ── */

export type MySubscription = {
  plan?: { id: number; name: string; slug: string; tier: string; price_monthly?: number; price_yearly?: number } | null;
  tier: string;
  status: "active" | "past_due" | "suspended" | "deleted";
  due_at: string | null;
  suspended_at?: string | null;
  days_remaining: number;
};

export type Invoice = {
  id: number;
  client_id: number;
  subscription_id?: number | null;
  amount: number;
  currency?: string;
  status: "pending" | "paid" | "failed" | "cancelled";
  due_at?: string | null;
  paid_at?: string | null;
  gateway_ref?: string | null;
  created_at?: string;
  client?: { id: number; name: string; site_id?: string };
};

/**
 * J10 \u2014 \u067e\u0627\u0633\u062e \u0646\u0647\u0627\u0646\u062a \u0633\u0627\u062e\u062a\u0647\u0627\u0631\u062a.
 *
 * \u0646\u0627\u0648\u0639\u062a \u062f\u0648\u0631 \u0648 \u0646\u0647 \u0627\u0646\u0642\u0631\u0627\u0631\u062f \u0627\u0632 \u0628\u0631\u062f\u0627\u0634\u062a \u0641\u0627\u06a9\u062a\u0648\u0631: \u0645\u0646\u0631\u0641\u0639 \u0645\u062f\u0631 \u0646\u0645\u06cc\u200c\u0634\u0648\u062f \u0627\u0633\u062a\u060c \u067e\u0633 \u0646\u0627\u0645\u06cc\u200c\u0628\u0648\u062f\u0646 \u062f\u0631\u06af\u0627\u0647 \u0648 \u0644\u06cc\u0646\u06a9
 * sandbox.zarinpal.com \u0645\u06cc\u200c\u0633\u0627\u062e\u062a \u0628\u0648\u062f\u0647 \u0648 \u0643\u0627\u0631\u0628\u0631 \u062f\u0631\u0627\u06af\u0647\u200c\u0627\u06cc \u0646\u0645\u06cc\u200c\u0633\u0627\u062e\u062a \u0646\u0645\u06cc\u200c\u0628\u095e\u062f.
 *
 * \u0627\u0632 \u0622\u0646 \u062f\u0648 \u0641\u06cc\u0644\u062f \u0647\u0627 \u062d\u0630\u0627\u0641\u0638\u0647 \u06a9\u0647 \u062f\u0627\u0631\u0646\u062f \u0648 pay_url \u0648 uthority
 * \u0646\u06cc\u0633\u062a\u060c \u0637\u0631\u06cc \u0627\u0632 \u0645\u0648\u0636\u0639\u06cc \u0628\u0631\u0627\u06cc \u062f\u0631\u06af\u0627\u0647 \u0642\u0631\u0627\u0631 \u0645\u06cc\u200c\u06af\u064a\u0631\u062f: \u0645\u0642\u0627\u0628\u0644 \u0627\u0635\u0644\u0627\u062d \u0628\u0647 \u0635\u0648\u0631\u062a
 * pending \u0645\u06cc\u200c\u0645\u0627\u0646\u062f \u0648 \u0627\u067e\u0631\u0627\u062a\u0648\u0631 \u0645\u0631\u06a9\u0632\u06cc \u062f\u0633\u062a\u06cc \u0622\u0646\u0631\u0627 \u0631\u0627 \u062a\u0646\u0638\u06cc\u0645 \u0645\u06cc\u200c\u06a9\u0646\u062f.
 */
export type PaySession = {
  manual_billing: true;
  status: string;
  amount: number;
  currency: string;
};

/* ── دسته ۱ (برابری UI/UX): مصرف فضای پلن + پرونده‌های باز مرکزی ── */

export type MediaUsage = {
  used_bytes: number;
  quota_bytes: number;
  tier: string;
  percent: number;
};

export type OpenCase = {
  kind: "wallet" | "pool" | "suspension" | "deploy" | "overdue" | string;
  severity: "info" | "warning" | "critical";
  title: string;
  detail?: string | null;
  action_label: string;
  action: string;
};

/* ── مرکزی (۲.۲ / ۲.۴) ── */

export type Plan = {
  id: number;
  name: string;
  slug: string;
  tier: "budget" | "standard" | "enterprise";
  price_monthly?: number | null;
  price_yearly?: number | null;
  features?: string[];
  active?: boolean;
};

export type Payment = {
  id: number;
  invoice_id?: number | null;
  gateway: string;
  ref: string;
  amount: number;
  status: string;
  created_at?: string;
  invoice?: { id: number; client_id: number; amount: number; status: string } | null;
};


export type Backup = {
  id: number;
  client_id: number;
  type: string;
  status: string;
  path: string;
  created_at?: string;
};

export type SuspendedSub = {
  id: number;
  client_id: number;
  status: string;
  suspended_at?: string | null;
  due_at?: string | null;
  days_remaining: number;
  client?: { id: number; name: string; site_id?: string; domain?: string | null } | null;
  plan?: { id: number; name: string; slug: string } | null;
};

export type GatewaySettings = {
  driver: string;
  merchant_id_masked: string | null;
  sandbox: boolean;
};

export type SiteStatus = { status: "active" | "suspended"; message: string };

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
  subscription: "اشتراک",
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

export type WidgetValue = { type: string; settings: Record<string, unknown> };

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

/* ── مرکزی: داشبورد (۲.۱) ── */



/* ── مرکزی: چابکان (۲.۳) ── */

export type Wallet = { balance: number; currency?: string; low?: boolean; threshold?: number };

export type ServiceItem = { name: string; status: string; size?: string; [k: string]: unknown };

export type Deployment = {
  id: number;
  service: string;
  status: string;
  triggered_by?: number | null;
  created_at?: string;
};

export type Reconciliation = {
  stub_services: string[];
  recorded_services: string[];
  missing_in_db: string[];
  missing_in_chabokan: string[];
  driver: string;
  checked_at?: string;
};

/* ── مرکزی: مانیتورینگ (۲.۵) ── */

export type HealthRow = {
  client_id: number;
  name: string;
  site_id: string;
  status?: string | null;
  version?: string | null;
  last_seen_at?: string | null;
  state: "online" | "silent" | "never";
};


/* ── مرکزی: آپدیت‌ها (۲.۶) ── */

export type CoreVersion = { id: number; version: string; is_latest: boolean; created_at?: string };

export type InstallRow = {
  client_id: number;
  name: string;
  site_id: string;
  current_version?: string | null;
  update_available: boolean;
  /** دسته UIUX (افزودنی): آخرین وضعیت رول‌اوت نصب (برای بج شکست‌خورده). */
  last_rollout_status?: string | null;
};

/** دسته UIUX (افزودنی): کارت رول‌اوت جاری. */
export type CurrentRollout = {
  rollout: { id: number; version: string; status: string } | null;
  total?: number;
  done?: number;
  failed?: number;
  pending?: number;
  progress?: number;
};

export type UpdatesData = { latest: CoreVersion | null; versions: CoreVersion[]; installs: InstallRow[] };

/** دسته UIUX (افزودنی): سری آپتایم روزانه هر نصب. */
export type UptimeDay = { date: string; status: "online" | "silent" | "unknown" };
export type UptimeRow = {
  client_id: number;
  name: string;
  site_id: string;
  today: string;
  days: UptimeDay[];
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

/* ── مرکزی: اپراتور (۲.۷) ── */

export type OperatorActivityItem = {
  id: number;
  action: string;
  payload?: Record<string, unknown> | null;
  user?: { id: number; name: string; email: string } | null;
  created_at?: string;
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

export const TIER_FA: Record<string, string> = { budget: "بودجه", standard: "استاندارد", enterprise: "سازمانی" };
export const PAGE_STATUS_FA: Record<string, string> = { draft: "پیش‌نویس", published: "منتشرشده" };
export const INVOICE_STATUS_FA: Record<string, string> = {
  pending: "در انتظار پرداخت",
  paid: "پرداخت‌شده",
  failed: "ناموفق",
  cancelled: "لغوشده",
};
export const SUB_STATUS_FA: Record<string, string> = {
  active: "فعال",
  past_due: "سررسید گذشته",
  suspended: "معلق",
  deleted: "حذف‌شده",
};
export const HEALTH_FA: Record<string, string> = {
  healthy: "سالم",
  warning: "هشدار",
  critical: "بحرانی",
  unknown: "نامشخص",
};
