# 📁 اسناد پروژه CMS اختصاصی — راهنما

## فایل‌های موجود

| فایل | محتوا | وضعیت |
|---|---|---|
| **`PLAN.md` / `.html` / `.pdf`** | پلن کامل نهایی (معماری، فازها، قیدها، تصمیمات) | ✅ مرجع اصلی |
| **`COST-TIERING.md`** | تیربندی هزینه (Budget/Standard/Enterprise) | ✅ مرجع |
| **`ADMIN-PAGES-SPEC.md` / `.html` / `.pdf`** | مشخصات + وایرفریم صفحات پنل (ادغام شده) | ✅ آماده تحویل به UI/UX |
| **`DESIGN-DIRECTIONS.md` / `.html` / `.pdf`** | ۵ جهت بصری پنل ادمین + معماری شخصی‌سازی ظاهر | ✅ برای انتخاب |
| **`DEV-TASKS.md` / `.html` / `.pdf`** | 📋 **تسک‌های UIUX → Backend → Frontend** (اولویت‌بندی + API ها) | ⭐ برای اجرا |
| **`demo/dashboard-demo.html`** | 🎨 **نمونه تعاملی داشبورد با ۵ جهت بصری** | ⭐ برای انتخاب جهت |
| **`DEPLOY-CHECKLIST.md`** | 🚀 چک‌لیست استقرار — مراحلی که **خودکار نیستند** | ⭐ **قبل از هر استقرار بخوان** |
| **`PLUGIN-GUIDE.md`** | 🔌 راهنمای افزونه‌ها: بستهٔ مجاز، مانیفست، امضا، حالت توسعه‌دهنده | ✅ مرجع |

## 🎨 راهنمای دموی تعاملی (demo/dashboard-demo.html)

این فایل را در **مرورگر** باز کنید:

```
E:\Website Design\ArmanOskouei.ir\docs\demo\dashboard-demo.html
```

### چطور کار می‌کند
- یک **داشبورد نمونه واقعی** (سایدبار + تاپ‌بار + کارت آمار + هشدار + ویجت) نمایش داده می‌شود
- در **پایین-چپ** یک سوییچر است: ۵ جهت بصری + دکمه روشن/تیره
- با کلیک روی هر جهت، کل پنل **زنده** عوض می‌شود (بدون رفرش)
- انتخاب در `localStorage` ذخیره می‌شود

### لینک مستقیم به هر جهت (برای اشتراک‌گذاری)
```
dashboard-demo.html?dir=sahar&mode=light     → سپیده (مینیمال روشن)
dashboard-demo.html?dir=sahar&mode=dark      → سپیده (تیره)
dashboard-demo.html?dir=amaliyat&mode=dark   → عملیات (دارک کنسول)
dashboard-demo.html?dir=amaliyat&mode=light  → عملیات (روشن)
dashboard-demo.html?dir=arya&mode=light      → آریا (رنگارنگ)
dashboard-demo.html?dir=arya&mode=dark       → آریا (تیره)
dashboard-demo.html?dir=narm&mode=light      → نرم (سافت)
dashboard-demo.html?dir=narm&mode=dark       → نرم (تیره)
dashboard-demo.html?dir=divan&mode=light     → دیوان (کلاسیک)
dashboard-demo.html?dir=divan&mode=dark      → دیوان (تیره)
```

## ۵ جهت بصری (خلاصه)

| جهت | شخصیت | بهترین برای |
|---|---|---|
| **۱. سپیده** | مینیمال روشن، آرام | پنل مشتری (پیش‌فرض) |
| **۲. عملیات** | دارک DevOps، کنسول | پنل مرکزی |
| **۳. آریا** | رنگارنگ، گرادیانی | آژانس برندمحور |
| **۴. نرم** | سافت، دوستانه | مشتری غیرفنی |
| **۵. دیوان** | کلاسیک، وردپرسی | مهاجران وردپرس |

## 💡 شخصی‌سازی ظاهر پنل (الزام)
هر جهت با ۳ رنگ‌بندی پیشنهادی (Preset) قابل شخصی‌سازی است (۱۵ ترکیب). جزئیات در `DESIGN-DIRECTIONS.md`.

---

_این اسناد مرجع طراحی و توسعه پروژه هستند._