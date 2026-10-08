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
  "search.needQueryHint": "حداقل ۳ حرف لازم است.",
  "search.noResult": "نتیجه‌ای برای «{q}» پیدا نشد.",
  "search.noResultHint": "املا را بررسی کنید یا از واژه‌های ساده‌تر استفاده کنید.",
  "search.count": "{n} نتیجه برای «{q}»",
  "notFound.title": "صفحه یافت نشد",
  "notFound.description": "صفحه‌ای که دنبالش بودید پیدا نشد. می‌توانید جستجو کنید یا از پیوندهای پیشنهادی زیر استفاده کنید.",
  "notFound.searchPlaceholder": "عبارت موردنظر را جستجو کنید…",
  "notFound.searchCta": "جستجو",
  "notFound.suggested": "صفحات پیشنهادی",

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

  // ── WF-L3 — مسیر راهنما + حالت روشن/تیرهٔ سایت ──
  "crumbs.aria": "مسیر راهنما",
  "mode.toggle": "تغییر حالت روشن/تیره",
  "mode.light": "روشن",
  "mode.dark": "تیره",

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
  "block.formLoading": "در حال بارگذاری فرم…",
  "block.formUnavailable": "این فرم در دسترس نیست.",
  "block.formSubmit": "ارسال",
  "block.formSending": "در حال ارسال…",
  "block.formSuccess": "پاسخ شما ثبت شد. سپاسگزاریم.",
  "block.formError": "ارسال ناموفق بود. دوباره تلاش کنید.",
  "block.formRequired": "تکمیل فیلدهای ستاره‌دار الزامی است.",

  // ── ویجت ناشناس ──
  "widget.unknownNotice": "ویجت «{name}» رندر نشد — این نوع ویجت در رندرر هسته پیاده‌سازی نشده است.",
  "widget.emptyName": "(خالی)",

  // ── بایگانی بلاگ (WF-C7) ──
  "blog.archiveTitle": "بلاگ",
  "blog.categoryTitle": "دسته: {name}",
  "blog.tagTitle": "برچسب: {name}",
  "blog.empty": "هنوز نوشته‌ای منتشر نشده است.",
  "blog.readMore": "ادامه مطلب",
  "blog.backToBlog": "بازگشت به بلاگ",
  "blog.feed": "خوراک RSS",
  "blog.pagination": "صفحه‌بندی نوشته‌ها",
  "blog.prev": "قبلی",
  "blog.next": "بعدی",
  "blog.notFound": "نوشته یافت نشد",

  // ── WF-M20 — بنر رضایت کوکی + سیاست حریم خصوصی ──
  "cookie.aria": "رضایت کوکی",
  "cookie.message": "این سایت فقط از کوکی‌های ضروری استفاده می‌کند و هیچ کوکی تحلیلی یا تبلیغاتی نمی‌گذارد.",
  "cookie.accept": "پذیرش",
  "cookie.privacyLink": "سیاست حریم خصوصی",
  "privacy.title": "سیاست حریم خصوصی",
  "privacy.empty": "متن سیاست حریم خصوصی هنوز تنظیم نشده است.",

  // ── WF-M17 — بیانیهٔ دسترس‌پذیری ──
  "accessibility.link": "بیانیهٔ دسترس‌پذیری",
  "accessibility.title": "بیانیهٔ دسترس‌پذیری",
  "accessibility.contact": "راه تماس برای گزارش مانعِ دسترس‌پذیری",
  "accessibility.phoneLabel": "تلفن",
  "accessibility.emailLabel": "ایمیل",
  "accessibility.noContact": "هنوز شماره تماس یا ایمیلی برای گزارش مانعِ دسترس‌پذیری ثبت نشده است.",
} as const;

export type PublicMessageKey = keyof typeof publicFa;
