# قرارداد جست��وی پنل ادمین — ⛔ منسوخ‌شده (B5)

> ## این سند دیگر قرارداد نیست
>
> **`fetchItems` حذف شد.** آن تابع از داخل مرورگر به `/api/proxy/v1/admin/...`
> می‌زد، یعنی هر افزونه‌ای می‌توانست **هر مسیر دلخواهی از API هسته** را از
> مرورگر صدا بزند — همان چیزی که جداسازی API می‌خواست جلویش گرفته شود.
>
> **راه درست:** کلاسی که `App\Search\SearchableProvider` را پیاده می‌کند از راه
> نقطهٔ اتصال `core.service_provider` اعلام کنید تا **در سرور** اجرا شود.
>
> ```json
> {
>   "point": "core.service_provider",
>   "interface": "App\\Search\\SearchableProvider",
>   "class": "Pishdad\\Plugins\\Blog\\SearchProvider"
> }
> ```
>
> سرور از `GET /api/v1/admin/search?q=…` صدا می‌زند و providerها را ادغام
> می‌کند. پیاده‌سازی: `AdminSearchController` + `ServiceProviderRegistry`.
>
> آنچه از قرارداد قدیمی هنوز زنده است: **فهرست برگه‌های ایستا**. داده حساسی
> ندارد و فقط برای راهنمای میانبرهاست. ولی حتی آن هم دیگر برای افزونه‌ها لازم
> نیست.

---

<details>
<summary>متن قدیمی (فقط برای مراجعه)</summary>

> مرجع پیاده‌سازی: `pishdad-core/frontend/src/lib/search-registry.ts`
> کامپوننت‌ها: `pishdad-core/frontend/src/components/search/` — صفحه نتایج: `admin/search?q=…`

## ۱. رجیستری سراسری

```ts
window.__ADMIN_SEARCH__ = {
  sources: SearchSource[],
  registerSearchSource: (p: RegisterPayload) => () => void, // خروجی = تابع لغو ثبت
};
```

ثبت از هر جای کلاینت (کامپوننت پلاگین، اسکریپت تزریقی) مجاز است؛ ثبت مجدد
با همان `plugin` نسخه قبلی را جایگزین می‌کند. تابع برگشتی، ثبت را پاک می‌کند
(در `useEffect` حتماً cleanup کنید).

## ۲. شکل ورودی

```ts
registerSearchSource({
  plugin: "blog",                 // الزامی، یکتا
  pages: [                        // برگه‌های ایستا (اختیاری)
    {
      title: "نوشته‌های بلاگ",    // الزامی
      path: "/admin/blog/posts",   // الزامی — مسیر داخلی پنل
      icon: "✎",                   // الزامی — ایموجی/نویسه (بدون SVG خارجی)
      group: "content",            // الزامی: pages | content | files | other
      subtitle: "مدیریت پست‌ها و دسته‌ها",   // اختیاری
      capabilities: ["پست جدید", "دسته‌بندی بلاگ", "انتشار پست"], // ۲-۴ مترادف فارسی
    },
  ],
  capabilities: ["مدیریت نوشته‌ها"], // اختیاری — قابلیت‌های کلی پلاگین
  fetchItems: async (q) => [...],    // اختیاری — سورس داینامیک (رکوردها)
});
```

### آیتم داینامیک (`SearchHit`)

```ts
{ id: "blog:12", plugin: "blog", title: "…", subtitle: "پست بلاگ",
  path: "/admin/blog/posts/12/edit", icon: "✎", group: "content" }
```

- `id` باید در سطح کل پنل یکتا باشد (پیشوند نام پلاگین).
- `path` لینک مستقیم به رکورد است (ویرایش/مشاهده).
- خطا/401 داخل `fetchItems` را خودتان catch کنید و `[]` برگردانید؛ موتور
  جستجو هم خطا را قورت می‌دهد، اما ریدایرکت لاگین `authed` ممکن است آزاردهنده باشد.

## ۳. مثال کامل ۱ — پلاگین بلاگ (پست‌ها در نتایج)

```tsx
"use client";
import { useEffect } from "react";
import { registerSearchSource } from "@/lib/search-registry";
import { authed } from "@/lib/auth";

export function BlogSearchSource() {
  useEffect(() => {
    return registerSearchSource({
      plugin: "blog",
      pages: [
        {
          title: "نوشته‌های بلاگ", path: "/admin/blog/posts", icon: "✎",
          group: "content", subtitle: "مدیریت پست‌ها و دسته‌ها",
          capabilities: ["پست جدید", "دسته‌بندی بلاگ", "انتشار پست"],
        },
      ],
      capabilities: ["مدیریت نوشته‌ها"],
      fetchItems: async (q) => {
        try {
          const posts = await authed<Array<{ id: number; title: string }>>(
            `/v1/admin/blog/posts?per_page=8&search=${encodeURIComponent(q)}`,
          );
          return posts.map((p) => ({
            id: `blog:${p.id}`, plugin: "blog", title: p.title,
            subtitle: "پست بلاگ", path: `/admin/blog/posts/${p.id}/edit`,
            icon: "✎", group: "content" as const,
          }));
        } catch {
          return [];
        }
      },
    });
  }, []);
  return null;
}
```

## ۴. مثال کامل ۲ — پلاگین کاربران (اعضا در نتایج)

```tsx
"use client";
import { useEffect } from "react";
import { registerSearchSource } from "@/lib/search-registry";
import { authed } from "@/lib/auth";

export function MembersSearchSource() {
  useEffect(() => {
    return registerSearchSource({
      plugin: "members",
      pages: [
        {
          title: "اعضا", path: "/admin/members", icon: "◍",
          group: "other", subtitle: "فهرست اعضای سایت",
          capabilities: ["عضو جدید", "جستجوی اعضا", "سطح دسترسی عضو"],
        },
      ],
      capabilities: ["مدیریت اعضا"],
      fetchItems: async (q) => {
        try {
          const members = await authed<Array<{ id: number; name: string }>>(
            `/v1/admin/members?per_page=8&search=${encodeURIComponent(q)}`,
          );
          return members.map((m) => ({
            id: `members:${m.id}`, plugin: "members", title: m.name,
            subtitle: "عضو سایت", path: `/admin/members/${m.id}`,
            icon: "◍", group: "other" as const,
          }));
        } catch {
          return [];
        }
      },
    });
  }, []);
  return null;
}
```

## ۵. قوانین

1. **نرمال‌سازی با موتور است** — شما متن خام فارسی بفرستید؛ ي/ك عربی، اعراب و
   نیم‌فاصله خودکار یکدست می‌شود. تطبیق = includes + شروع‌کلمه روی همه توکن‌ها (AND).
2. **کش داینامیک با شماست** — برای هر کوئری حداکثر یک فراخوانی؛ کش ۶۰ثانیه‌ای
   سمت کلاینت توصیه می‌شود (هسته همین کار را می‌کند).
3. **هیچ داده حساسی در ایندکس ایستا** — نه ایمیل، نه مبلغ، نه توکن. رکوردهای
   داینامیک فقط `title/subtitle/path` برمی‌گردانند.
4. `group` را درست انتخاب کنید تا در صفحه نتایج زیر سرفصل مناسب بیاید:
   `pages` (برگه‌ها) • `content` (محتوای سایت) • `files` (فایل‌ها) • `other` (سایر).
5. دراپ‌داون Topbar حداکثر **۸** نتیجه نشان می‌دهد؛ Enter = صفحه نتایج کامل
   (`/admin/search?q=…`) با گروه‌بندی و هایلایت.

## ۶. TODOهای شناخته‌شده

- تحمل غلط املایی عمیق‌تر (فاصله لونشتین) فعلاً نیست — فقط includes + شروع‌کلمه.
- اولویت‌بندی وزنی بین سورس‌ها (مثلاً بوست پلاگین فعال) تعریف نشده.

</details>
