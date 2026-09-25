# قرارداد مانیفست پلاگین: دسترسی‌ها، ویجت‌ها، انواع صفحه

> مرجع پیاده‌سازی: `cms-core/backend/app/Services/Plugins/ManifestRegistry.php`
> (تک‌منبع ادغام هسته + مانیفست) — کنترلرها: `ManagerController::permissionsMatrix`،
> `WidgetSchemaController`، `LayoutController::pageTypes` — فرانت: `admin/managers?tab=roles`
> (ماتریس)، `admin/header-footer` (مودال تنظیمات)، `admin/blocks` (تب‌ها).

پلاگین «اعلانی» است: کافی است در `manifest.json` یکی از کلیدهای زیر را بگذارید؛
هسته هنگام **فعال‌سازی** می‌خواند و در همان‌جای پنل قابل تنظیم می‌شود.
غیرفعال/حذف = ناپدید از ماتریس/پالت/تب‌ها (داده ذخیره‌شده قبلی دست‌نخورده می‌ماند).

## ۱. هوک `permissions` — ماژول دسترسی جدید

```json
{
  "permissions": [
    { "module": "blog", "title_fa": "بلاگ", "actions": ["view", "edit", "delete"] }
  ]
}
```

- `module`: الزامی، `^[a-z0-9_-]{2,40}$` (مثل `blog`).
- `title_fa`: الزامی‌نما — عنوان فارسی ستون «ماژول» در ماتریس نقش‌ها
  (`GET /api/v1/admin/permissions` → `modules[].{name,title_fa,source}`).
- `actions`: اختیاری، زیرمجموعه `view/edit/delete` (پیش‌فرض هر سه).
- هنگام فعال‌سازی، رکوردهای `blog.view` و… در جدول permissions ساخته می‌شوند
  (`PluginController::ensurePermissions`) تا نقش همان‌جا تیک بخورد؛
  گارد مسیرهای خودتان: `->middleware('perm:blog.view')` (سوپرادمین همیشه عبور می‌کند).
- نام تکراری با ماژول هسته نادیده گرفته می‌شود؛ ورودی نامعتبر کل مانیفست را
  خراب نمی‌کند (fail-soft در خواندن).

## ۲. هوک `widgets` — ویجت هدر/فوتر جدید

```json
{
  "widgets": {
    "header": {
      "promo": {
        "title": "بنر تخفیف",
        "description": "نوار تخفیف بالای هدر.",
        "schema": {
          "type": "object",
          "properties": {
            "text": { "type": "string", "maxLength": 120 }
          }
        },
        "ui": { "labels": { "text": "متن بنر" } }
      }
    }
  }
}
```

- شکل هر ورودی دقیقاً مثل `config/widgets.php`: `title` + `description` +
  JSON Schema سبک + `ui.labels` (برچسب فارسی هر فیلد) و `ui.media_field`
  (کلیدی که با MediaPicker انتخاب می‌شود، مثل `media_id` در ویجت `logo`).
- فقط `type` جدید پذیرفته می‌شود (بازنویسی type هسته ممنوع).
- `GET /api/v1/admin/widgets/schema` همه را با `source: "plugin:{slug}"` می‌دهد و
  `PUT layouts/{area}` همان typeها را قبول می‌کند؛ فرانت مودال تنظیمات را با
  `SchemaForm` می‌سازد و برای type ناشناس fallback به JSON خام می‌دهد (نه حذف).
- قرارداد قالب‌ها هم همین است: `manifest` قالب فعال با همان شکل `widgets`
  خوانده می‌شود (`source: "theme:{slug}"`).

## ۳. هوک `page_types` — نوع صفحه جدید (تب بلوک‌ها)

```json
{
  "page_types": {
    "faq": {
      "title": "سوالات پرتکرار",
      "description": "صفحه FAQ پلاگین.",
      "default_blocks": [{ "type": "hero", "data": { "title": "سوالات پرتکرار" } }]
    }
  }
}
```

- تب‌های `admin/blocks` فقط از `GET layouts/page-types` می‌آیند (بدون هاردکد،
  حتی `blog`) و فقط typeهای `enabled !== false`؛ override هر type در
  `page_blocks:global:{type}` ذخیره می‌شود.
- `default_blocks` فقط typeهای `config/blocks.php` را می‌پذیرد.

## ۴. قوانین مشترک

1. همه هوک‌ها اختیاری‌اند؛ مانیفست بدون آن‌ها معتبر است.
2. امضای Ed25519 و گردش تأیید مرکزی سر جایش است (مورد ۶) — این قرارداد فقط
   «پس از فعال‌سازی» اعمال می‌شود.
3. داده‌های فعال پلاگین در یک نصب تک‌سایتی مشترک‌اند؛ `user_id` فقط سازنده/حسابرسی است و در خواندن فیلتر نمی‌شود.
