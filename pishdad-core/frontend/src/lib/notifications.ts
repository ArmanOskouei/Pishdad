/**
 * F4.2.F — واژگانِ مشترکِ صندوق و ترجیحاتِ اعلان.
 *
 * ## ⭐ چرا `action_href` را اینجا sanitize می‌کنیم و نه فقط در بک‌اند
 *
 * بک‌اند سه بار از آن دفاع کرده: allowlistِ کاتالوگ، allowlistِ دوباره هنگام
 * خواندن، و رد کردنِ لینک برای ردیف‌های بی‌کاتالوگ. اینجا هم **بارِ چهارم** را
 * می‌گذاریم و دلیلش معماری است:
 *
 * یک سرویسِ داده، یک قرارداد دارد. کنترلرِ K5.8 (`/v1/admin/notifications`) هم
 * به همین جدول می‌نویسد و کنترلرِ F4.2.B هم می‌خواند. اگر فرانت به «قولِ» کنترلر
 * تکیه کند ولی یک نوشتنِ دیگر مسیر را دور بزند، لینکِ بد **بدون هیچ خطایی**
 * رندر می‌شود. دفاعِ فرانت ارزان است و شکستِ آن گران.
 *
 * قاعده: فقط مسیرِ **داخلیِ هم‌ریشه** (`/…` و *نه* `//` و *نه* `/\`) قبول است —
 * همان قاعدهٔ `Catalog::isSafeActionHref`. `javascript:`، `data:` و
 * `https://evil.example` همگی رد می‌شوند.
 */

/** سطرِ صندوق، همان‌طور که `NotificationInboxController::row()` می‌سازد. */
export type InboxItem = {
  id: string;
  catalog_key: string | null;
  title: string;
  body: string;
  severity: "info" | "warning" | "critical";
  /** از allowlist گذشته و از راه allowlistِ فرانت هم گذشته — یا `null`. */
  action_href: string | null;
  action_label: string | null;
  read_at: string | null;
  created_at: string | null;
};

export type InboxResponse = { items?: InboxItem[]; unread?: number };

/** ⚠️ گروه ⇒ کانال ⇒ فعال؟. خروجی بک‌اند همیشه **کامل** است. */
export type PrefMatrix = Record<string, Record<string, boolean>>;

/**
 * WF-M15 — تنظیماتِ کانالِ کاربر (خارج از ماتریسِ گروه×کانال).
 *
 * `telegram_configured` وضعیتِ **نصب** را می‌گوید نه کاربر: اگر توکن ربات نباشد
 * UI باید صادقانه بگوید «پیامی نمی‌رود»، حتی اگر کاربر chat_id داده باشد.
 */
export type NotificationChannelSettings = {
  telegram_chat_id: string | null;
  daily_digest: boolean;
  telegram_configured: boolean;
};

/** E73 — وضعیتِ رباتِ نصب (توکنِ کامل هرگز به مرورگر نمی‌آید). */
export type TelegramBotStatus = {
  configured: boolean;
  masked: string | null;
};

export type PrefResponse = {
  groups: Record<string, string>;
  channels: string[];
  matrix: PrefMatrix;
  settings?: NotificationChannelSettings;
};

/** کانال‌های `Catalog::CHANNELS`. */
export const CHANNELS = ["email", "sms", "push", "telegram"] as const;
export type Channel = (typeof CHANNELS)[number];

export const isChannel = (v: string): v is Channel => (CHANNELS as readonly string[]).includes(v);

/**
 * تنها دروازهٔ ساختِ لینک. هر چیزی که از این رد نشود، **اصلاً** `<a>` نمی‌شود.
 *
 * `safeHref` در `lib/sanitize.ts` برای لینک‌های ویجتِ سایت است و عمداً
 * بازتر است (می‌تواند `https://` باشد چون ادمین آن را گذاشته). اینجا **نمی‌تواند**
 * باز باشد: لینکِ اعلان از داده‌ای می‌آید که پلاگین‌ها هم می‌نویسند.
 */
export function safeActionHref(href: string | null | undefined): string | null {
  if (typeof href !== "string" || href === "") return null;
  // کاراکترِ کنترلی (شامل \n و \t) در URL بی‌معنی و در `javascript:` کلاسیک است.
  // eslint-disable-next-line no-control-regex
  if (/[\x00-\x1f\x7f]/.test(href)) return null;
  if (!href.startsWith("/")) return null;
  if (href.startsWith("//") || href.startsWith("/\\")) return null;
  return href;
}
