# ستون‌بندی فوتر

> مرجع پیاده‌سازی: `config/widgets.php` (ویجت `links`)،
> `App\Services\Layouts\LinkItems::effectiveFooterColumns`،
> `ManifestRegistry::footerBase` / `themeFooterDefaults`،
> `SiteChromeController`، ویرایشگر `admin/header-footer`، رندر عمومی (`app/page.tsx` و `app/[...path]/page.tsx`).

## مدل

- **هر ویجت `links` = یک ستون** (عنوان از `heading` + لیست `links`).
- چند ویجت `links` = چند ستون، **به ترتیب بوم، راست‌به‌چپ** (اولین ویجت = راست‌ترین ستون).
- `layout.columns` (۱ تا ۴) تعداد ستون‌های گرید را صریح می‌کند؛
  خالی یا نامعتبر = **تعداد واقعی ویجت‌های `links`** محدود به ۱ تا ۴ (بدون ستون links = ۱).
- رندر عمومی گرید واقعی است (`display: grid`)؛ ویجت `copyright` تمام عرض را می‌گیرد.

## قالب آپلودی: اعلام ستون در مانیفست

قالب در `manifest.json` کلید اختیاری `footer` می‌گذارد — **ویجت‌های پیش‌فرض خودش + ستون**:

```json
{
  "footer": {
    "columns": 3,
    "widgets": [
      { "type": "about", "settings": { "text": "…" } },
      { "type": "links", "settings": { "heading": "دسترسی سریع", "links": [{ "label": "خانه", "href": "/" }] } },
      { "type": "links", "settings": { "heading": "تماس", "links": [{ "label": "تلفن", "href": "tel:+9821" }] } }
    ]
  }
}
```

- `columns`: اختیاری، فقط ۱ تا ۴ (غیر آن نادیده گرفته می‌شود).
- `widgets`: اختیاری (سقف ۳۰)؛ هر ورودی `{type, settings}` با همان شکل `config/widgets.php`.
  فقط `type`های شناخته‌شده پذیرفته می‌شود (هسته + ویجت‌های `footer` اعلام‌شده در
  `widgets` همان مانیفست)؛ **type ناشناس fail-soft حذف می‌شود**، نه خطا.
- آیتم‌های `links` داخل پیش‌فرض قالب همان مدل `{kind, page_id?, label?, href?}` را دارند
  (`{label, href}` = سفارشی).

## fallback (وقتی قالب ستونی اعلام نکرد)

1. مانیفست بدون کلید `footer` (یا خالی/نامعتبر) → مبنای هسته: `about` + `copyright` با `columns: 3`.
2. **شخصی‌سازی کاربر همیشه بر مبنای قالب غلبه می‌کند**: به‌محض ذخیره فوتر در
   `admin/header-footer`، پیش‌فرض قالب دیگر استفاده نمی‌شود (حتی بعد از تعویض قالب،
   تنظیم کاربر دست‌نخورده می‌ماند).
3. `layout.columns` ذخیره‌نشده/نامعتبر → تعداد واقعی ستون‌های کاربر (قاعده بالا)؛
   در خروجی عمومی `GET /v1/site/chrome` همیشه ستون **مؤثر** برمی‌گردد.
4. جابه‌جایی ستون‌ها = همان درگ‌اندروپ بوم؛ پیش‌نمایش زنده مینیاتوری
   (عنوان هر ستون + تعداد لینک) در تب فوتر دیده می‌شود.
