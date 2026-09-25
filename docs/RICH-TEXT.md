# ویرایشگر ریچ‌تکست (Jodit)

> کامپوننت مشترک: `cms-core/frontend/src/components/ui/RichText.tsx`
> مصرف فعلی: `SchemaForm` (فیلد `body` بلوک `text` در ویرایشگر صفحات).

## استفاده مجدد

```tsx
import { RichText } from "@/components/ui/RichText";

<RichText value={html} onChange={setHtml} label="متن" />
```

- `value` رشته HTML است؛ کامیت در `blur` انجام می‌شود (بدون ری‌رندر هر کلید).
- کانفیگ ثابت: `language: "fa"` + `direction: "rtl"` + نوار ابزار جمع‌وجور
  (بدون سورس/اسکریپت/آی‌فریم) + استایل تم با `var()` (کلاس `jodit-rtl` در `globals.css`).

## امنیت (sanitize دو‌لایه)

1. **ادیتور:** `allowTags`/`denyTags` در کانفیگ Jodit
   (`script/style/iframe/object/embed/form/…` مسدود).
2. **نمایش:** `BlockRenderer` خروجی را با allowlist خودش تمیز می‌کند —
   **هرگز HTML ذخیره‌شده را مستقیم رندر نکنید.**

## نکته‌ها

- `jodit-react` با `next/dynamic` و `ssr: false` لود می‌شود (Jodit به DOM نیاز دارد).
- فیلدهای متنی ساده (`subtitle/description/caption`، سئو) عمداً `textarea` مانده‌اند —
  Jodit فقط برای بدنه HTML است.
