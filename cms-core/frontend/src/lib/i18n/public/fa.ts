/**
 * ECO2 — فرهنگِ لغتِ سایتِ عمومی (فارسی، زبانِ پایه).
 *
 * همین الگوی پنل (F4.4): جدولِ تخت با کلیدِ نقطه‌ای. این فایل **منبعِ حقیقتِ
 * کلیدها** است و `en.ts` باید دقیقاً همین کلیدها را داشته باشد؛ نگهبانِ
 * `parity.test.ts` همین را می‌سنجد. کلیدها را بازنویسی نکنید — فقط متن را.
 */
export const publicFa = {
  // ── دکمهٔ تغییر زبان ──
  "lang.label": "زبان",
  "lang.fa": "فارسی",
  "lang.en": "English",

  // ── صفحهٔ خانه ──
  "home.preparing": "سایت در حال آماده‌سازی است",
  "home.preparingMeta": "سایت در حال آماده‌سازی",
  "home.emptyHint": "هنوز صفحه‌ای منتشر نشده است. به‌زودی محتوای سایت اینجا نمایش داده می‌شود.",
  "home.login": "ورود به پنل مدیریت",

  // ── تعلیق ──
  "suspended.title": "سایت موقتاً در دسترس نیست",
  "suspended.support": "تماس با پشتیبانی: support@example.ir",

  // ── جستجو ──
  "search.pageTitle": "جستجو در سایت",
  "search.queryTitle": "جستجو: {q}",
  "search.aria": "جستجوی سایت",
  "search.ariaInput": "جستجو در سایت",
  "search.placeholder": "جستجو…",
  "search.submit": "جستجو",
  "search.results": "نتایج زنده جستجو",
  "search.all": "مشاهده همه نتایج برای «{q}» ↵",
  "search.none": "نتیجه‌ای نیست — صفحه نتایج برای «{q}» ↵",
  "search.hint": "جستجو در همه صفحات منتشرشده (عنوان و متن)",
  "search.queryLabel": "عبارت جستجو",
  "search.placeholderLong": "مثلاً: تماس، خدمات، سوالات متداول…",
  "search.loading": "در حال جستجو…",
  "search.needQuery": "عبارت جستجو را بنویسید",
  "search.needQueryHint": "حداقل ۲ نویسه لازم است.",
  "search.noResult": "نتیجه‌ای برای «{q}» پیدا نشد.",
  "search.noResultHint": "املا را بررسی کنید یا از واژه‌های ساده‌تر استفاده کنید.",
  "search.count": "{n} نتیجه برای «{q}»",
  "notFound.title": "یافت نشد",

  // ── کروم عمومی (هدر/فوتر) ──
  "chrome.navAria": "ناوبری اصلی",
  "chrome.menu": "فهرست",
  "chrome.submenu": "زیرمنوی {label}",
  "chrome.home": "خانه",
  "chrome.brandHome": "خانه",
  "chrome.logoAlt": "لوگو",
  "chrome.cta": "اقدام",
  "chrome.phone": "تلفن:",
  "chrome.email": "ایمیل:",
  "chrome.support": "تماس با پشتیبانی: support@example.ir",
  "chrome.newsletter": "عضویت در خبرنامه به‌زودی.",
  "chrome.defaultTitle": "وب‌سایت من",
  "chrome.socialsAria": "شبکه‌های اجتماعی",

  // ── بلوک‌های صفحه ──
  "block.empty": "محتوایی برای این صفحه ثبت نشده است.",
  "block.unknownNotice": "بلوک «{name}» رندر نشد — این نوع بلوک در هسته پیاده‌سازی نشده است.",
  "block.emptyName": "(خالی)",
  "block.imagePending": "تصویر #{id} — resolve عمومی مدیا در بک‌اند نیست (TODO).",
  "block.galleryPending": "گالری {n} تصویر دارد — resolve عمومی مدیا در بک‌اند نیست (TODO).",
  "block.imageAlt": "تصویر",
  "block.galleryAlt": "گالری",
  "block.watchVideo": "مشاهده ویدیو",
  "block.contactForm": "فرم تماس",
  "block.ctaDefault": "ادامه",

  // ── ویجت ناشناس ──
  "widget.unknownNotice": "ویجت «{name}» رندر نشد — این نوع ویجت در رندرر هسته پیاده‌سازی نشده است.",
  "widget.emptyName": "(خالی)",
} as const;

export type PublicMessageKey = keyof typeof publicFa;
