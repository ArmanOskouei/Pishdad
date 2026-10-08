# راهنمای کامل توسعهٔ قالب پیشداد

> نسخهٔ مرجع: قرارداد کدِ فعلی مخزن (`pishdad-core/frontend/src/themes/**`، `pishdad-core/backend/app/...`). هر نام و مسیر این سند از کد واقعی استخراج شده است. این سند در `/admin/themes?tab=zip` نمایش داده می‌شود و برای خروجی PDF طراحی شده است.

## ۱) مفهوم: «قالب» در پیشداد چیست؟

قالب سایت در پیشداد **یک کامپوننت Next.js است که ساختار صفحه را تصمیم می‌گیرد** (هدر، فوتر، ستون‌های کناری، ترتیب محتوا) به‌همراه یک **مانیفست اعلامی** (`theme.json`). دادهٔ محتوا (بلوک‌ها) به قالب ربطی ندارد و قالب آن را فقط رندر می‌کند؛ تفاوت قالب‌ها در «چیدمان و پوسته» است، نه در داده.

دو مسیر متمایز وجود دارد و نباید قاطی شوند:

| | **پوستهٔ درون‌ساخت (کد)** | **بستهٔ ZIP** |
|---|---|---|
| محل | `pishdad-core/frontend/src/themes/{slug}/` با `theme.json` + `index.tsx` | فایل ZIP آپلودی در پنل، در دیسک `local` مسیر `themes/shared/{slug}_{rand}.zip` ذخیره می‌شود |
| رجیستری | `src/themes/manifests.ts` (اسلاگ‌های شناخته‌شده: `minimal`, `editorial`, `commerce`) + `src/themes/registry.tsx` (کامپوننت) | جدول `themes` بک‌اند؛ فقط `manifest.json` از داخل ZIP خوانده می‌شود (`ThemeController::readManifest`) |
| اثر روی ساختار | کامل — کامپوننت خودش هدر/فوتر/ستون می‌سازد | **ندارد** — فقط توکن‌ها (`globals`)، پیش‌فرض فوتر (`footer`) و متادیتا. ساختارِ سایت از کامپوننتِ اسلاگِ فعال می‌آید و اسلاگِ ناشناخته به `DEFAULT_THEME_SLUG = "minimal"` می‌افتد |
| گردش | سورس با فرانت کامپایل می‌شود؛ نصب/امضای ZIP ندارد | آپلود → بازبینی مرکزی (`pending` → `approved`؛ سوپرادمین/اپراتور مستثنا) → فعال‌سازی |

**انتخاب قالب فعال چگونه است؟** بک‌اند در `GET /v1/site/chrome` مقدار `theme.slug` را از `SiteThemeResolver::activeSlug()` می‌گیرد: اول انتخابِ سراسریِ نصب در جدول `site_theme_settings` (`user_id = 0`, `key = 'default'`)، بعد ستون `themes.active` (مسیر ZIP)، و در نهایت `FALLBACK_THEME = 'minimal'`. فرانت با `resolveTheme(slug)` کامپوننت را برمی‌دارد؛ **هر اسلاگ ناشناخته به minimal می‌افتد و هیچ‌وقت خطا یا صفحهٔ سفید نمی‌دهد** — این قرارداد ECO1 است و در `manifests.test.ts` قفل شده.

**قالب پنل ≠ قالب سایت.** ظاهر پنل مدیریت (`/admin/appearance`، توکن‌های `:root`، `data-direction="..."` روی `<html>`) یک سیستم جدا است. سایت عمومی توکن‌هایش را در scope مستقل `.site` با نام‌های `--site-*` و لایهٔ قابل‌بازنویسی `--theme-*` می‌گیرد تا تمِ پنل هرگز به سایت نشت نکند (تصمیم F0.8 در `globals.css`). در `/admin/themes` هم دو تب هست: «پوسته» (انتخاب پوسته × رنگ‌بندی × حالت) و «بستهٔ ZIP» (بستهٔ آپلودی). این سند دربارهٔ **قالب سایت** است، نه ظاهر پنل.

## ۲) قرارداد پوشه و فایل

**قالب کدمحور** — مسیر دقیق: `pishdad-core/frontend/src/themes/{slug}/` و فقط دو فایل:

- `theme.json` — مانیفست. کلیدهای الزامی: `slug`, `name`, `description`, `version`, `modes`, `slots`, `tokens`
- `index.tsx` — کامپوننت قالب با امضای `ThemeProps`

اسکلت آماده: `php artisan pishdad-theme:make {slug}` (الگوی اسلاگ: `^[a-z0-9][a-z0-9._-]{1,39}$`؛ مسیر خروجی پیش‌فرض = `pishdad-core/frontend/src/themes`، قابل تغییر با env `THEME_SCAFFOLD_PATH` در `config/theme.php`).

**امضای کامپوننت** (`src/themes/types.ts`):

```ts
export interface ThemeProps {
  page: SitePage;              // صفحهٔ منتشرشده (بلوک‌ها + ستون‌های کناری + متا)
  chrome: SiteChrome | null;   // کروم عمومی + theme.globals
  schemas?: SiteBlockSchemas;  // رجیستری schema بلوک‌های افزونه (chrome.blocks) — برای DeclaredBlock
  banner?: ReactNode;          // بنر opt-in اعلان — فقط صفحهٔ خانه
  jsonLd?: ReactNode;          // اسکریپت JSON-LD — فقط صفحه‌های عمیق
  locale?: PublicLocale;       // "fa" | "en" — پیش‌فرض fa
  switcher?: { locales: PublicLocale[]; primary: PublicLocale }; // فقط اگر سایت دوزبانه باشد
}

export interface ThemeLayoutConfig {
  sidebarPlacement: "columns" | "stacked"; // کنار محتوا یا زیر محتوا
  mainMaxInlineSize?: number;              // بیشینهٔ عرض ستون محتوا (px) — نامشخص = تمام‌عرض
}
```

**نمونهٔ کامل و قابل‌کپی (minimal واقعی مخزن):**

`theme.json`:
```json
{
  "slug": "minimal",
  "name": "مینیمال",
  "description": "چیدمان تنفسی و بدون تزئین؛ ستون‌های کناری زیر محتوا می‌آیند. مناسب وبلاگ و سایت شرکتی سبک.",
  "version": "1.0.0",
  "modes": ["light", "dark", "system"],
  "slots": ["header", "footer", "main", "left", "right", "banner"],
  "tokens": {
    "radius-sm": "2px", "radius-md": "4px", "radius-lg": "6px", "radius-xl": "8px",
    "fs-body": "15px", "fs-small": "13px", "fs-h": "19px", "density": "1"
  }
}
```

`index.tsx`:
```tsx
import { ThemeShell } from "../shared";
import type { ThemeProps } from "../types";

export function MinimalTheme(props: ThemeProps) {
  return <ThemeShell {...props} variant="minimal" layout={{ sidebarPlacement: "stacked" }} />;
}

export default MinimalTheme;
```

**ثبت:** اسلاگ در `THEME_MANIFESTS` در `src/themes/manifests.ts` و `{ manifest, Component }` در `DEFINITIONS` در `src/themes/registry.tsx` ثبت می‌شود. `variant` کلاس‌های `theme-{slug}` و `theme-body-{slug}` روی wrapper می‌سازد.

**بستهٔ ZIP** — در ریشهٔ ZIP فقط یک `manifest.json`:

```json
{
  "name": "نام نمایشی قالب",
  "slug": "my-theme",
  "version": "1.0.0",
  "description": "توضیح کوتاه فارسی",
  "author": "نام شما",
  "requires": { "core": ">=1.6.0" },
  "globals": { "primary": "#0d9488", "bg": "#ffffff", "surface": "#ffffff", "text": "#16181d" },
  "layout": "stacked",
  "builtin": false,
  "footer": {
    "columns": 3,
    "widgets": [
      { "type": "about", "settings": {} },
      { "type": "links", "settings": { "heading": "دسترسی سریع" } },
      { "type": "copyright", "settings": {} }
    ]
  },
  "signature": "BASE64-امضای-Ed25519"
}
```

فیلدهای واقعاً مصرف‌شده توسط بک‌اند: `name` (الزامی — نبودش 422)، `slug` (اختیاری؛ `Str::slug(name)`، تکراری = 422)، `version` (پیش‌فرض `1.0.0`)، `signature` (اختیاری؛ نامعتبر = 422 و ردِ آپلود با لاگ `theme.upload_rejected`)، `globals` (توکن‌های پایه، در `chrome.theme.globals` ادغام می‌شود)، `footer` (`columns` بین ۱ تا ۴ + `widgets` با type از واژگان هسته/ویجت‌های footer اعلامی همان مانیفست؛ سقف ۳۰ آیتم؛ ورودی نامعتبر fail-soft نادیده می‌شود — `ManifestRegistry::themeFooterDefaults`)، `layout` و `builtin` (فقط در پاسخ `GET /v1/site/theme/{slug}` برمی‌گردند). بسته حداکثر **۲۰ مگابایت**، `mimes:zip`.

## ۳) توکن‌های قالب

منبع حقیقت: `SiteThemeResolver` در `pishdad-core/backend/app/Services/Themes/SiteThemeResolver.php`.

**نقش‌های رنگ (`COLOR_ROLES` — ۱۱ مورد):**
`primary`, `primary-hover`, `primary-soft`, `accent`, `accent-soft`, `bg`, `surface`, `surface-2`, `text`, `text-muted`, `border`
— مقدار مجاز برای بازنویسی کاربر **فقط hex** است: `#rgb` / `#rgba` / `#rrggbb` / `#rrggbbaa`. `var()`، `url()`، `;` و نام رنگ CSS عمداً رد می‌شوند (سطح حملهٔ CSS بسته می‌ماند).

**نقش‌های چیدمان (`LAYOUT_ROLES` — ۸ مورد):**
`radius-sm`, `radius-md`, `radius-lg`, `radius-xl`, `fs-body`, `fs-small`, `fs-h`, `density`
— مقدار مجاز فقط نویسه‌های `#a-z0-9(),.%-_ ` (مثل `14px`, `1.15`).

**ترتیب ادغام:** `layout_tokens` (پوسته) ← `preset.tokens` (رنگ‌بندی) ← `overrides` (ویرایش موردی کاربر). هر لایه قبلی را می‌پوشاند.

**حالت (`mode`) انتخابگر است، نه رنگ:** `light` نیمهٔ `tokens.light` رنگ‌بندی را انتخاب می‌کند، `dark` نیمهٔ `tokens.dark` را، و `system` هیچ نیمه‌ای نمی‌دهد و تصمیم به CSS مرورگر (`prefers-color-scheme`) سپرده می‌شود. رنگ‌بندی‌ها («colorway») در جدول `site_theme_presets` با دو نیمهٔ `light`/`dark` زندگی می‌کنند و از `GET /v1/site/theme/{slug}` به‌شکل `colorways: [{key, name, light, dark}]` + `default_colorway` بیرون می‌آیند (در `/preview/{slug}` با `PreviewControls` زنده عوض می‌شوند).

**از توکن تا CSS — `themeVars()`:** تابع `themeVars()` در `src/components/site/Chrome.tsx` از `chrome.theme.globals` هر کلیدِ هم‌الگوی `^[a-z0-9-]+$` را برمی‌دارد و به `--theme-{key}` تبدیل و به‌صورت inline style روی wrapper می‌گذارد. سپس `globals.css` در scope `.site` نگاشت می‌کند:

```css
.site {
  --primary: var(--theme-primary, var(--site-primary));
  --bg: var(--theme-bg, var(--site-bg));
  /* ... برای هر ۱۱ نقش رنگ + ۸ نقش چیدمان */
}
.site[data-mode="light"] { /* پالت روشنِ مستقلِ سایت */ }
```

قواعد مهم:
- پایه با نام `--site-*` جدا تعریف شده تا هیچ ارث‌بری از `:root` پنل و از `[data-direction]` رخ ندهد.
- `-soft`ها (`--primary-soft`, `--accent-soft`) همیشه **صریح** با قالب اعلام شوند؛ چون `color-mix` به متغیر پویا حل نمی‌شود، اگر فقط رنگ پایه را بدهید نرمِ دکمه‌ها با رنگ دکمه ناهماهنگ می‌شود.
- `--font` عمداً قابل‌قالب نیست (فونت سایت و پنل یکی است — Vazirmatn). سایه‌ها و `--success/--warning/--danger/--info` هم hook به `--theme-*` ندارند و وعده‌شان ندهید.
- حالت روشن فقط با `data-mode="light"` روی **عنصر `.site`** فعال می‌شود، هرگز با `:root` — روشن‌کردن سایت نباید پنل را روشن کند.

## ۴) دادهٔ بک‌اند که قالب مصرف می‌کند

**`SiteChrome`** (پاسخ `GET /v1/site/chrome`، کش بک‌اند ۱۲۰ ثانیه):

| کلید | نوع | توضیح |
|---|---|---|
| `title`, `description` | `string` | نام و توضیح سایت |
| `logo_url`, `favicon_url` | `string?` | نشانی عمومی لوگو/فاوآیکون (لوگو همیشه از تنظیمات سایت می‌آید) |
| `phone`, `email`, `address` | `string?` | تماس — ویجت `contact` و JSON-LD |
| `site_url`, `og_image_url`, `ai_summary`, `robots_index` | — | سئو/GEO |
| `locale`, `locales`, `primary_locale`, `timezone` | — | `locales` زبان اصلی اول؛ تک‌زبانه = یک عضو |
| `homepage_page_id`, `homepage_slug` | `number?/string?` | صفحهٔ خانه تنظیم‌شده |
| `header`, `footer` | `LayoutData` | `{ widgets: WidgetValue[], layout }` — رزولو زندهٔ آیتم‌های page و `media_url` انجام شده |
| `socials` | `SocialItem[]` | فقط `active`ها: `{key, url, active, label?, icon_url?}` |
| `mode` | `"light"\|"dark"\|"system"\|null` | فعلاً `/site/chrome` پرش نمی‌کند؛ اختیاری/دفاعی مصرف شود |
| `theme` | `{name, slug, version, globals} \| null` | `globals` = ادغام مانیفست قالب + توکن‌های resolver (بدون پیشوند `--theme-`؛ پیشوند را `themeVars()` می‌گذارد) |
| `blocks` | `SiteBlockSchemas` | رجیستری schema بلوک‌های هسته + افزونه‌های فعال (schema-only، بدون JS) |

**`SitePage`** (پاسخ `site/homepage` و `site/pages/{path}`):

```ts
{ title, slug, is_single, blocks: BlockValue[], meta?, published_at?, updated_at?, version?,
  sidebars?: { left?: { enabled, preset_id?, blocks }, right?: { ... } } }
```
بلوک‌ها به‌شکل `{ type: string, data: Record<string, unknown> }` هستند و بک‌اند نشانی فایل‌ها را تزریق کرده: `url` (برای `media_id`/`image_id`)، `url_0..N` (برای `media_ids`)، `poster_url`.

**خواندن داده (فقط از `src/lib/site.ts` — نه fetch خام):**
`fetchSiteChrome()`, `fetchHomepage(locale?)`, `fetchSitePage(slug, locale?)`, `fetchSitePages(locale?)`, `fetchSiteTheme(slug)`, `fetchSiteStatus(siteId)`, `siteBaseUrl(chrome)`. همه ISR با `revalidate: 300` و تگ `pages` (+ تگ‌های اختصاصی `page:{slug}`, `site-chrome`, `site-homepage`, `theme:{slug}`) و خطا → `null` (نه throw). آدرس upstream از `INTERNAL_API_URL ?? NEXT_PUBLIC_API_URL ?? http://localhost:8080/api`.

**چیزی که مسیر (route) به قالب می‌دهد** — دقیقاً همان `ThemeProps`: `page`, `chrome`, `schemas={chrome?.blocks}`, `locale`, `switcher` (فقط اگر `locales.length > 1`)، و `banner` (`<PushOptInBanner />` فقط در خانه) یا `jsonLd` (فقط در صفحهٔ عمیق، با فرار امن `< > &`).

## ۵) موتور رندر

**هدر/فوتر — از `SiteHeader`/`SiteFooter`/`ChromeWidget` (فایل `src/components/site/Chrome.tsx`) استفاده کنید؛ بازنویسی نکنید.** لیست کامل ویجت‌ها (`CORE_CHROME_WIDGET_TYPES`، هم‌خط `config/widgets.php`):

| ویجت | ناحیه | کلیدهای settings | رفتار رندرر |
|---|---|---|---|
| `logo` | هدر | `show_title` (پیش‌فرض `true`) | تصویر همیشه از `chrome.logo_url` (کلیدهای `media_id/size/media_url` سرور حذف می‌کند) |
| `nav` | هدر | `links` (حداکثر ۱۲ آیتم؛ هر آیتم `{kind: "page"\|"custom", page_id?, label?, href?, children?[]}`؛ زیرمنو ≤۸، عمق ≤۲؛ kind=page عنوان/نشانی زنده از بک‌اند) + `style: "horizontal"\|"mega"` | رندر با `SiteNav` |
| `search` | هدر | `placeholder` | رندر با `SiteSearchBox` |
| `cta` | هدر | `label`, `href` | با `safeHref` — `javascript:` می‌میرد |
| `socials` | هدر | — | از `chrome.socials` (فقط activeها) |
| `about` | فوتر | `text` (≤۵۰۰) | fallback: `chrome.description` |
| `links` | فوتر | `heading`, `links` (همان ساختار nav) | هر ویجت links = یک ستون گرید |
| `contact` | فوتر | (`text` در schema هست ولی رندرر فعلی نشان نمی‌دهد) | نمایش `chrome.phone`/`chrome.email`؛ هیچ‌کدام نبود → پیام پشتیبانی |
| `newsletter` | فوتر | `text` (≤۲۰۰) | متن ساده |
| `copyright` | فوتر | `text` (≤۲۰۰) | fallback: `© سال chrome.title` — همیشه تمام‌عرض گرید |

گرید فوتر: تعداد ستون = `footer.layout.columns` (۱ تا ۴) وگرنه تعداد ویجت‌های `links`. `Socials` در انتهای فوتر اضافه می‌شود. `type` ناشناس هرگز throw نمی‌کند: لاگ سمت سرور + اعلان دیداری فقط در حالت diagnostics.

**بلوک‌ها — از `BlockRenderer` (فایل `src/components/site/BlockRenderer.tsx`) استفاده کنید.** بلوک با `data._enabled === false` رد می‌شود؛ لیست خالی → پیام خنثی. لیست کامل بلوک‌های هسته (`BUILTIN_BLOCK_TYPES` + کلیدهای `config/blocks.php`) و کلیدهای دقیق `data`:

| type | کلیدهای data | نکتهٔ رندر |
|---|---|---|
| `hero` | `title` (≤۱۶۰، الزامی), `subtitle` (≤۳۰۰), `image_id` (→ `url` تزریقی), `align: "right"\|"center"\|"left"` (پیش‌فرض right) | پس‌زمینه `url(...) center/cover` وگرنه `var(--primary-soft)` |
| `text` | `body` (fallback: `html`) | **تنها** بلوک HTML — از `safeHtml` می‌گذرد؛ قالبِ خودتان هم همین‌طور |
| `image` | `media_id` (→ `url`), یا `url`/`src`/`image_url`/`path`, `alt` (≤۲۰۰), `caption` (≤۳۰۰) | نبودِ src → figure نقطه‌چین «تصویر در دسترس نیست» |
| `cta` | `label` (≤۸۰), `href` (≤۲۰۴۸), `style: "primary"\|"secondary"\|"ghost"` | `safeHref` |
| `gallery` | `media_ids` (۱..۲۴؛ → `url_0..N` تزریقی), `columns` (۱..۶، پیش‌فرض ۳) | گرید |
| `quote` | `quote` یا `text` یا `body`, `author` یا `cite` | `blockquote` با خط رنگ primary |
| `video` | `url` یا `src`, `caption` | `.mp4` → `<video controls>`؛ بقیه → کارت لینک با `safeHref` |
| `faq` | `items: [{q (≤۲۰۰), a}]` — حداکثر ۲۰ ردیف | `<details>/<summary>` با کلاس‌های `site-faq` |
| `contact-form` / `contact` | `title` (≤۱۲۰), `email`, `show_phone` (پیش‌فرض `true`) | کامپوننت `ContactForm` (ارسال به `POST /v1/contact/tickets` + honeypot) |

**بلوک‌های افزونه‌ای (declared/plugin):** هر `type` بیرون از واژگان هسته → schema از `schemas` (که از `chrome.blocks` می‌آید) → `selectBlockPattern`: اول `x-pattern` صریح schema (`default` یا `enum` تک‌عضوی)، بعد جدول مترادف‌ها، بعد خودِ الگو. ۱۱ الگوی مجاز (`BLOCK_PATTERNS`): `hero, image, grid, list, gallery, form, rich-text, embed, faq, quote, cta` — رندر در `DeclaredBlock` با همان sanitizerهای مشترک. الگوی `default` یعنی ناشناس → لاگ + اعلان فقط در diagnostics؛ **هرگز throw نیست.**

**الگوی درست رندر یک صفحه — بازتاب دقیق `ThemeShell`:**

```tsx
<div className={`site theme-${variant}`} style={themeVars(chrome)} dir={lang === "en" ? "ltr" : "rtl"} lang={lang}>
  {jsonLd}
  <SiteHeader chrome={chrome} locale={lang} switcher={switcher} />
  <div className={`site-body ${cols} theme-body-${variant} theme-place-${layout.sidebarPlacement}`}>
    {/* columns: ستون‌ها قبل از main · stacked: بعد از main */}
    <main style={layout.mainMaxInlineSize ? { maxInlineSize: layout.mainMaxInlineSize } : undefined}>
      <article><BlockRenderer blocks={page.blocks ?? []} schemas={schemas} /></article>
      {banner}
    </main>
  </div>
  <SiteFooter chrome={chrome} locale={lang} />
</div>
```

ستون‌ها فقط وقتی رندر شوند که `enabled` و `blocks.length > 0` (`visibleSidebar`). `aria-label` ستون‌ها («ستون راست»/«ستون چپ») حفظ شود.

**چرخش ساختاری خاصِ قالب بدون شکستن a11y/امنیت:**
- با کلاس `theme-{variant}` + CSS محدود به همان scope (logical properties، بدون `margin-left`) عرض کانتینر، ترتیب header-inner، کارت‌ها و تایپوگرافی را عوض کنید.
- با `layout={{ sidebarPlacement, mainMaxInlineSize }}` چیدمان ستون را تعیین کنید (editorial: `columns` + عرض ~۷۲۰px؛ minimal: `stacked`).
- **هیچ‌وقت `BlockRenderer` یا `SiteHeader/SiteFooter` را fork نکنید** — sanitizerهای `safeHref/safeSrc/safeHtml`، برچسب‌های ARIA، رفتار diagnostics و تست‌های قفلِ واژگان فقط در نسخهٔ مشترک نگه داشته می‌شوند. یک اصلاح امنیتی باید یک‌جا اعمال شود، نه سه‌بار کپی شود.

## ۶) APIهای مجاز برای قالب

همه **عمومی و بدون احراز هویت** (پیشوند `/api/v1`):

| متد + مسیر | خروجی | نکته |
|---|---|---|
| `GET /site/chrome?site_id=` | `SiteChrome` | throttle `60,1`؛ `Cache-Control: public, max-age=60` |
| `GET /site/homepage?site_id=&locale=` | `SitePage` \| 404 | ترتیب: `homepage_page_id` → اسلاگ `home` → جدیدترین published |
| `GET /site/pages?locale=` | `SitePageIndex[]` (≤۵۰۰) | فهرست سبک برای sitemap/llms.txt — بدون بلوک‌ها |
| `GET /site/pages/{path}?locale=` | `SitePage` \| 404 | فقط published |
| `GET /site/theme/{slug}` | `SiteThemeTokens` \| 404 | `name, slug, version, layout, globals, builtin, layout_tokens, colorways[{key,name,light,dark}], default_colorway` — مبنای `/preview/{slug}` |
| `GET /site/search?q=&per_page=` | `{data, meta:{q,total,per_page}}` | `q` الزامی ۲..۲۰۰ نویسه، `per_page` ≤۵۰، throttle `site-search` |
| `GET /site/status?site_id=` | `{status: "active", message}` | وضعیت سبک سایت عمومی؛ پیام، نام برند سایت را دارد |
| `POST /contact/tickets` | `{ticket_id}` | فرم تماس عمومی (هانی‌پات + rate-limit) — فراخوانی توسط `ContactForm` انجام می‌شود، نه قالب |

قاعده: قالب فقط از wrapperهای `src/lib/site.ts` بخواند. مسیرهای admin (`/v1/admin/themes/*`) پشت Sanctum هستند و در سایت عمومی فراخوانی نمی‌شوند.

## ۷) ممنوعیت‌های مطلق و پیامدشان

| ممنوع | چرا — پیامد واقعی |
|---|---|
| ساخت/بازنویسی هر فایلی زیر `src/app/**` — شامل روت‌های عمومی (`[locale]/...`, `preview/...`)، **کل پنل** (`(client)/admin/**`, `admin/(auth)/**`) و میدل‌ور `src/proxy.ts` | روتِ app روتینگِ پنل و سایت را مالکیت می‌کند؛ ادغامِ اشتباه = گم‌شدن صفحهٔ لاگین/پنل یا سایت → **پنل تا ریستِ دستی از کار می‌افتد (صفحهٔ سفید)** |
| نوشتن در `src/components/**` یا `src/lib/**` | بازنویسی `BlockRenderer`/`site.ts` یعنی دورزدن sanitizerها و ISR؛ رفتار امنیتی هسته بی‌صدا عوض می‌شود |
| نوشتن در `next.config.ts`, `package.json`, `.env*` | تغییر بیلد/وابستگی/محیط = crash در build → کل فرانت پایین |
| افزودن Route Handler یا API سرور در فرانت | لایهٔ API مالِ بک‌اند است؛ endpoint موازی یعنی دورزدن throttle/permission و سطح حملهٔ جدید |
| `import` از `next/server` در کد قالب | کد قالب باید قابل‌رندر در RSC/client بدون وابستگی به runtime میدل‌ور باشد |
| دست‌کاری DB از قالب | قالب فقط مصرف‌کنندهٔ read-only است |
| `<script>` خام یا `dangerouslySetInnerHTML` از دادهٔ غیرقابل‌اعتماد | فقط `text` با `safeHtml` مجاز است؛ اسلات `jsonLd` را مسیر می‌سازد و خودش `< > &` را escape می‌کند |
| استفاده از توکن‌های پنل (`.topbar`, `data-direction`, `--sidebar-w`, ...) | سایت توکن خودش را دارد (`--theme-*`/`--site-*`)؛ توکن پنل یا بی‌اثر است یا نشتِ تم می‌سازد |
| تکیه بر اسلاگِ خودتان بدون fallback | اسلاگ ناشناخته به `minimal` می‌افتد؛ قالب باید با هر `chrome` نال/ناقص هم رندر شود (هرگز throw نکنید) |

## ۸) گردش ساخت → انتشار → پیش‌نمایش

**الف) قالب کدمحور (پوسته):**
1. `php artisan pishdad-theme:make my-theme --name="نام" --description="توضیح"`
2. `theme.json` و `index.tsx` را کامل کنید (توکن‌ها فقط از `LAYOUT_ROLES`).
3. در `src/themes/manifests.ts` به `THEME_MANIFESTS` و در `src/themes/registry.tsx` به `DEFINITIONS` اضافه کنید (روی سکو: پوشهٔ پوسته + رجیستری `site_themes`).
4. رنگ‌بندی‌ها در `site_theme_presets` (هر کدام `tokens.light`/`tokens.dark` با کلیدهای `COLOR_ROLES`).

**ب) بستهٔ ZIP:**
1. ZIP بسازید که `manifest.json` در **ریشهٔ** ZIP باشد (حداکثر ۲۰MB).
2. پنل → `/admin/themes` → تب **بستهٔ ZIP** → «آپلود قالب (ZIP)» → `POST /v1/admin/themes/upload` (فیلد `file`). پاسخ 201. قالبی که مدیرِ عادی آپلود کند `pending` ذخیره می‌شود و فعال‌شدنش ممکن نیست؛ قالبی که اپراتور آپلود کند از همان ابتدا `approved` است. یعنی `review_status` خاصیتِ «چه کسی آپلود کرد» است، نه یک مرحلهٔ تأییدِ جدا.
3. فعال‌سازی → دکمهٔ فعال‌سازی → `POST /v1/admin/themes/{id}/activate`. فعال‌سازی: قالب قبلی غیرفعال، کش `site_chrome` (۱۲۰ ثانیه)، کش توکن (`SiteThemeResolver::flush`) و ISR فرانت (تگ‌های `site-theme`, `theme`, `site-chrome`) باطل می‌شود. اگر اسلاگ پوستهٔ درون‌ساخت باشد، resolver هم هم‌گام می‌شود (رنگ‌بندی/حالت دست‌نخورده می‌ماند).
4. دکمهٔ پیش‌نمایش → `POST /v1/admin/themes/{id}/preview` → `preview_url = /preview/{slug}`. صفحهٔ `/preview/{slug}` با توکن‌های همان قالب و کامپوننت رزولوشده رندر می‌کند (`force-dynamic`، noindex)؛ قالب غیرفعال هم قابل پیش‌نمایش است. صفحهٔ عمومی `/preview` هم پیش‌نمایش قالبِ فعال را می‌دهد.
5. قالب فعال حذف نمی‌شود (`قالب فعال را نمی‌توان حذف کرد`)؛ `DELETE /v1/admin/themes/{id}` فقط برای غیرفعال‌ها.

## ۹) چک‌لیست نهایی + اشتباهات رایج

**چک‌لیست:**
- [ ] `theme.json` هر ۷ کلید الزامی را دارد؛ `tokens` فقط از ۸ نقش چیدمان؛ رنگی در `tokens` نیست (رنگ مالِ preset/resolver است).
- [ ] اسلاگ با الگو `^[a-z0-9][a-z0-9._-]{1,39}$` و یکتاست.
- [ ] کامپوننت با `ThemeProps` کامپایل می‌شود و بدون `chrome` (null) هم رندر می‌شود.
- [ ] wrapper: `className="site theme-{slug}"` + `style={themeVars(chrome)}` + `dir`/`lang` درست.
- [ ] هدر/فوتر/بلوک‌ها فقط از `SiteHeader`/`SiteFooter`/`BlockRenderer`/`ChromeWidget`.
- [ ] هیچ فایلی بیرون از `src/themes/{slug}/` ساخته نشده؛ ثبت در `manifests.ts` + `registry.tsx` انجام شده.
- [ ] CSS فقط با logical properties، فقط `var(--theme-*)`/`var(--site-*)`/کلاس‌های `site-*`.
- [ ] برای ZIP: `manifest.json` در ریشه، ≤۲۰MB، `name` دارد، رنگ‌ها در `globals` فقط hex.
- [ ] پیش‌نمایش `/preview/{slug}` در حالت light و dark دیده شده.

| اشتباه رایج | نتیجه |
|---|---|
| گذاشتن رنگ در `theme.json.tokens` | بی‌اثر — `tokens` فقط نقش‌های چیدمان را قبول می‌کند |
| کلید با پیشوند `--theme-` داخل `globals` | `themeVars()` دوباره پیشوند می‌گذارد → کلید بی‌اثر؛ همیشه **bare** بنویسید (`primary` نه `--theme-primary`) |
| پوشاندن `-soft`ها | رنگ نرم دکمه‌ها با رنگ دکمه ناهماهنگ — همیشه همراه رنگ پایه اعلام شود |
| `data-mode` روی `:root` | پنل هم روشن می‌شود؛ فقط روی `.site` |
| کپی‌کردن BlockRenderer برای «استایل بهتر» | گم‌شدن sanitizer/A11Y و درگیری با تست‌های قفل واژگان |
| فرض کردن `chrome.mode` به‌عنوان حتمی | فعلاً `/site/chrome` آن را پر نمی‌کند — دفاعی بخوانید |
| آپلود ZIP با `manifest.json` در پوشهٔ داخلی | خواندن با `FL_NODIR` اولین occurrence را می‌گیرد ولی قرارداد رسمی ریشه است؛ در بازبینی رد می‌شود |
| انتظار کد JSX از ZIP | ZIP کد اجرایی حمل نمی‌کند — فقط مانیفست/توکن/پیش‌فرض فوتر |
