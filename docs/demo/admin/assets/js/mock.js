/* ═══ داده‌های نمایشی (Mock) — اعداد و تاریخ فارسی ═══ */
const M = {
  user: { name: "آرمان اسکوئی", role: "مدیر ارشد", site: "فروشگاه نمونه" },
  stats: [
    { t: "صفحات منتشرشده", v: "۲۴", d: "+۳ این ماه", up: true, ic: "page", c: "var(--primary-soft)|var(--primary)" },
    { t: "فایل‌های رسانه", v: "۱۸۶", d: "۲٫۴ گیگ", up: true, ic: "media", c: "var(--info-soft)|var(--info)" },
    { t: "تیکت‌های باز", v: "۳", d: "۱ فوری", up: false, ic: "ticket", c: "var(--warning-soft)|var(--warning)" },
    { t: "روز تا سررسید", v: "۱۲", d: "پلن استاندارد", up: true, ic: "card", c: "var(--success-soft)|var(--success)" },
  ],
  pages: [
    { t: "خانه", p: "/", s: "published", u: "۱۴۰۵/۰۷/۰۱", v: "۱۲" },
    { t: "درباره ما", p: "/about", s: "published", u: "۱۴۰۵/۰۶/۲۸", v: "۵" },
    { t: "خدمات", p: "/services", s: "published", u: "۱۴۰۵/۰۶/۲۵", v: "۸" },
    { t: "تماس با ما", p: "/contact", s: "draft", u: "۱۴۰۵/۰۷/۰۲", v: "۳" },
    { t: "بلاگ", p: "/blog", s: "published", u: "۱۴۰۵/۰۶/۲۰", v: "۱۵" },
    { t: "سوالات متداول", p: "/faq", s: "draft", u: "۱۴۰۵/۰۷/۰۱", v: "۲" },
  ],
  files: [
    { n: "بنر-اصلی.jpg", s: "۲۴۰ KB", d: "۱۴۰۵/۰۷/۰۱", e: "🖼️", bg: "linear-gradient(135deg,#0d948822,#0d948855)" },
    { n: "لوگو.svg", s: "۱۸ KB", d: "۱۴۰۵/۰۶/۲۹", e: "🎨", bg: "linear-gradient(135deg,#7c3aed22,#7c3aed55)" },
    { n: "کاتالوگ.pdf", s: "۱٫۲ MB", d: "۱۴۰۵/۰۶/۲۵", e: "📄", bg: "linear-gradient(135deg,#dc262622,#dc262655)" },
    { n: "معرفی.mp4", s: "۸٫۴ MB", d: "۱۴۰۵/۰۶/۲۰", e: "🎬", bg: "linear-gradient(135deg,#2563eb22,#2563eb55)" },
    { n: "تیم.jpg", s: "۳۱۰ KB", d: "۱۴۰۵/۰۶/۱۸", e: "🖼️", bg: "linear-gradient(135deg,#d9770622,#d9770655)" },
    { n: "پادکست.mp3", s: "۴٫۱ MB", d: "۱۴۰۵/۰۶/۱۵", e: "🎵", bg: "linear-gradient(135deg,#16a34a22,#16a34a55)" },
  ],
  tickets: [
    { t: "خطا در درگاه پرداخت", u: "مریم رضایی", s: "open", p: "فوری", d: "۲ ساعت پیش", n: "۳" },
    { t: "تغییر لوگوی سایت", u: "علی محمدی", s: "pending", p: "عادی", d: "دیروز", n: "۱" },
    { t: "آموزش ویرایشگر بلوکی", u: "سارا کریمی", s: "closed", p: "عادی", d: "۳ روز پیش", n: "۵" },
  ],
  invoices: [
    { n: "فاکتور ۱۰۴۲", p: "پلن استاندارد — مهر", a: "۸۹۰٬۰۰۰ تومان", d: "۱۴۰۵/۰۷/۱۰", s: "unpaid" },
    { n: "فاکتور ۱۰۳۱", p: "پلن استاندارد — شهریور", a: "۸۹۰٬۰۰۰ تومان", d: "۱۴۰۵/۰۶/۱۰", s: "paid" },
    { n: "فاکتور ۱۰۲۰", p: "پلن استاندارد — مرداد", a: "۸۹۰٬۰۰۰ تومان", d: "۱۴۰۵/۰۵/۱۰", s: "paid" },
  ],
  clients: [
    { n: "فروشگاه نمونه", t: "Standard", s: "active", h: 98, d: "۱۴۰۵/۰۳/۱۲", due: "۱۲ روز" },
    { n: "شرکت آریا", t: "Budget", s: "active", h: 100, d: "۱۴۰۵/۰۵/۰۱", due: "۲۵ روز" },
    { n: "کلینیک سپید", t: "Standard", s: "suspended", h: 0, d: "۱۴۰۵/۰۲/۲۰", due: "۳ روز مانده به حذف" },
    { n: "رستوران دیوان", t: "Budget", s: "active", h: 96, d: "۱۴۰۵/۰۶/۰۵", due: "۳۰ روز" },
  ],
  plugins: [
    { n: "فرم تماس", v: "۲٫۴٫۱", s: true, u: false, d: "فرم‌ساز با اعلان پیامکی" },
    { n: "سئوی خودکار", v: "۱٫۸٫۰", s: true, u: true, d: "متا + نقشه سایت خودکار" },
    { n: "گالری پیشرفته", v: "۳٫۱٫۲", s: false, u: false, d: "اسلایدر و لایت‌باکس" },
    { n: "پیامک اطلاع‌رسانی", v: "۱٫۲٫۰", s: true, u: false, d: "اتصال به پنل پیامکی" },
  ],
  themes: [
    { n: "سپیده", d: "مینیمال روشن", g: "linear-gradient(135deg,#f7f7f8 60%,#0d9488 60%)", cur: true },
    { n: "عملیات", d: "دارک کنسول", g: "linear-gradient(135deg,#0b0f17 60%,#6366f1 60%)", cur: false },
    { n: "آریا", d: "رنگارنگ مدرن", g: "linear-gradient(135deg,#7c3aed,#ec4899)", cur: false },
    { n: "نرم", d: "سافت دوستانه", g: "linear-gradient(135deg,#fdf3ec 60%,#c2703d 60%)", cur: false },
  ],
};
const ST = { published: ["ب-green", "منتشرشده"], draft: ["ب-gray", "پیش‌نویس"], open: ["ب-red", "باز"], pending: ["ب-amber", "در انتظار"], closed: ["ب-green", "بسته‌شده"], active: ["ب-green", "فعال"], suspended: ["ب-red", "معلق"], paid: ["ب-green", "پرداخت‌شده"], unpaid: ["ب-amber", "پرداخت‌نشده"] };
const badge = s => `<span class="badge ${ST[s][0]}">${ST[s][1]}</span>`;
