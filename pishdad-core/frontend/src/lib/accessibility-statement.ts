// نسبی و با پسوند: هم روتِ `[locale]/accessibility` (Next) و هم
// `accessibility-statement.test.ts` (`node --test`) همین فایل را بار می‌کنند
// و alias آنجا resolve نمی‌شود.
import type { PublicLocale } from "./i18n/public/index.ts";

/**
 * WF-M17 — منطقِ خالصِ صفحهٔ بیانیهٔ دسترس‌پذیری.
 *
 * دلیلِ جدا بودنش از صفحه: سه تصمیمِ قابل‌آزمون اینجا می‌افتد و هیچ‌کدام به
 * React نیاز ندارند — پاراگراف‌بندیِ متن، پیش‌فرضِ وقتی تنظیم خالی است، و
 * پاک‌سازیِ راه تماس. صفحه فقط رنگ می‌کند.
 */

/** اسلاگِ مسیرِ ثابت؛ هم‌ارز `privacy`، یعنی بر `[...path]` مقدم است. */
export const ACCESSIBILITY_SLUG = "accessibility";

/**
 * متنِ پیش‌فرضِ فارسی — همتای `DEFAULT_ACCESSIBILITY_STATEMENT` بک‌اند.
 *
 * عمداً «هدفِ انطباق با WCAG 2.2 AA» و «راه تماس» را هر دو نام می‌برد؛
 * بیانیه‌ای که راهِ گزارشِ مانع ندارد عملاً بی‌فایده است.
 */
export const DEFAULT_ACCESSIBILITY_STATEMENT_FA =
  "هدف این وب‌سایت انطباق با «راهنمای دسترس‌پذیری محتوای وب» (WCAG 2.2) در سطح AA است. متن راست‌به‌چپ و قابل بزرگ‌نمایی، کنتراست رنگ کافی، پیمایش با صفحه‌کلید، برچسب‌های خوانا برای صفحه‌خوان و احترام به تنظیم «کاهش حرکت» رعایت شده‌اند.\n\nاگر مانعی در دسترسی به محتوای این سایت برای شما پیش آمد، از راه‌های تماسِ درج‌شده در پایین همین صفحه با ما در میان بگذارید تا در کوتاه‌ترین زمان برطرفش کنیم.\n\nاگر پاسخِ ما رضایت‌بخش نبود، می‌توانید موضوع را به مرجعِ ملی دسترس‌پذیری ارجاع دهید.";

/** همتای انگلیسی — تا صفحهٔ دوزبانه به فارسی برای خوانندهٔ انگلیسی نشت نکند. */
export const DEFAULT_ACCESSIBILITY_STATEMENT_EN =
  "This website aims to conform to WCAG 2.2 at level AA (Web Content Accessibility Guidelines). Right-to-left Persian text that scales with browser zoom, sufficient colour contrast, full keyboard navigation, screen-reader labels and reduced-motion preferences are all respected in the design.\n\nIf you encounter any barrier to accessing this website's content, please report it using the contact details at the bottom of this page and we will get back to you as soon as possible.\n\nIf our response is not satisfactory, you may escalate the matter to your national accessibility authority.";

/** متن به پاراگراف‌ها: جداکنندهٔ اصلی خط خالی، وگرنه هر خط. */
export function toStatementParagraphs(text: string): string[] {
  const trimmed = text.trim();
  if (!trimmed) return [];
  const blocks = trimmed.split(/\n\s*\n/).length > 1 ? trimmed.split(/\n\s*\n/) : trimmed.split(/\n/);
  return blocks.map((b) => b.trim()).filter(Boolean);
}

/**
 * پاراگراف‌های قابل رندرِ بیانیه.
 *
 * تنظیمِ خالی/ناموجود ⇒ **پیش‌فرضِ همان زبان** را برمی‌گردانیم، نه پیامِ
 * «تنظیم نشده»: صفحهٔ دسترس‌پذیریِ خالی یعنی نصبی که عملاً هیچ چیزی اعلام
 * نکرده، درست خلافِ کاری که این تسک می‌خواهد.
 */
export function accessibilityParagraphs(
  statement: string | null | undefined,
  locale: PublicLocale,
): string[] {
  const stored = toStatementParagraphs(statement ?? "");
  if (stored.length > 0) return stored;
  return toStatementParagraphs(
    locale === "en" ? DEFAULT_ACCESSIBILITY_STATEMENT_EN : DEFAULT_ACCESSIBILITY_STATEMENT_FA,
  );
}

/**
 * مقصدِ لینکِ بیانیه برای زبانِ جاری.
 *
 * مدل مسیرِ دوزبانه (a): زبانِ پایه در ریشه (`/accessibility`) و زبانِ دوم زیر
 * پیشوند (`/en/accessibility`). پیشوندِ موجود در `pathname` حفظ می‌شود —
 * همان قاعدهٔ `privacyPathFor`.
 */
export function accessibilityPathFor(pathname: string): string {
  const seg = (pathname.split("/").filter(Boolean)[0] ?? "").toLowerCase();
  return seg === "fa" || seg === "en" ? `/${seg}/${ACCESSIBILITY_SLUG}` : `/${ACCESSIBILITY_SLUG}`;
}

export type AccessibilityContact = {
  /** نشانی آمادهٔ `mailto:` — فقط اگر ایمیلِ معتبر باشد. */
  mailto?: string;
  /** شمارهٔ آمادهٔ `tel:` — فقط اگر فقط رقم/علائمِ تلفن داشته باشد. */
  tel?: string;
};

/**
 * راه تماسِ قابل اعتماد از کروم عمومی.
 *
 * هر دو مقدار از تنظیمات مدیر می‌آیند و در `href` می‌نشینند، پس قبل از رندر
 * **سفیدسپید** می‌شوند: ایمیل با الگوی RFC-گونه (بدون فاصله، گیومه یا `<`)
 * و تلفن با ارقام/علائم تلفن. یعنی حتی اگر روزی کسی `javascript:` را در
 * تنظیمات جا بدهد، این صفحه لینکِ اجرایی نمی‌سازد.
 */
export function accessibilityContact(chrome: {
  phone?: string | null;
  email?: string | null;
} | null | undefined): AccessibilityContact {
  const out: AccessibilityContact = {};

  const email = (chrome?.email ?? "").trim();
  if (email.length <= 200 && /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(email)) {
    out.mailto = `mailto:${email}`;
  }

  const phone = (chrome?.phone ?? "").trim();
  if (/^[+\d][\d\s()-]{5,24}$/.test(phone)) {
    out.tel = `tel:${phone.replace(/[^\d+]/g, "")}`;
  }

  return out;
}