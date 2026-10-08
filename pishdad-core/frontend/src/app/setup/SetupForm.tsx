"use client";

import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
import { useLang } from "@/lib/i18n";
import { saveConfig, testConnection, type SetupInput, type TestResult } from "./actions";

/**
 * نصب‌کنندهٔ وبِ پنل (E54) — پاسِ دوزبانه.
 *
 * ## ⚠️ درسی که این فایل را بازنویسی کرد
 *
 * نسخهٔ قبلی با کلاس‌های **Tailwind** نوشته شده بود (`rounded-2xl`,
 * `border-neutral-200`, `text-neutral-500`, …). این پروژه هرگز Tailwind
 * نداشته: نه dependency، نه `tailwind.config.*`، نه `@tailwind`، نه postcss.
 * پس هر `className` فقط یک رشته بود و صفحه به HTMLِ **بی‌استایل** تبدیل می‌شد.
 *
 * حالا استایل از کلاس‌های معناییِ `globals.css` (`.card`، `.field`، `.input`،
 * `.btn`، `.alert`، `.badge`، `.dot`، …) به‌همراه بلوکِ `.setup-*` می‌آید که
 * همان توکن‌های رنگِ پروژه را می‌خوانند. پس در هر قالب/جهتِ روشنی درست دیده
 * می‌شود و هیچ فریم‌ورکِ CSS یا CDNای در میان نیست.
 *
 * ## ⭐ چرا زبان از `useLang`ِ موجود می‌آید و نه یک providerِ تازه
 *
 * `/setup` داخلِ `app/layout.tsx` رندر می‌شود و آن layout از قبل
 * `ThemeProvider` + `LanguageProvider` را دور همهٔ صفحه‌ها می‌پیچد. یعنی کانالِ
 * زبان/جهت **همین حالا** اینجاست و ما چیزی mount نمی‌کنیم — نه هزینهٔ تازه، نه
 * دو منبعِ حقیقت. مزیتش این است که انتخابِ زبان در همان `localStorage`
 * (`cms-ui-f1`) و روی `document.documentElement.dir/lang` می‌نشیند که
 * `ThemeProvider` از قبل مدیریت می‌کند: پس زبانِ انتخابی بین بارگذاری‌ها و
 * میانِ کلِ اپ یکی است و **هیچ `setState`ِ زمانِ رندر** لازم نیست (قانونِ ضدِ
 * React #301). `useLang` را فقط برای گرفتنِ `lang`/`dir`/`setLang` مصرف می‌کنیم؛
 * `dir=rtl` ⇔ `fa` همچنان پیش‌فرض است، چون `DEFAULT_THEME.dir === "rtl"`.
 *
 * ## ⭐ چرا متن‌ها از واژه‌نامهٔ **محلی** می‌آیند و نه کاتالوگِ مشترک
 *
 * گزینهٔ دیگر این بود که رشته‌های این صفحه را به `lib/i18n/fa.ts`/`en.ts` اضافه
 * کنیم. ولی آن دو فایل کاتالوگِ **مشترکِ کلِ سایت و پنل** اند و همراهِ هر
 * بازدیدکنندهٔ سایت بار می‌شوند؛ ~۹۰ رشتهٔ نصب که فقط همین صفحهٔ سیستمی
 * می‌بیند، آن‌جا صرفاً وزنِ باندلِ عمومی را زیاد می‌کند. پس: کانالِ زبان را
 * همان providerِ موجود می‌دهد (رایگان و سازگار)، ولی **متن‌ها** در واژه‌نامهٔ
 * محلیِ همین فایل می‌مانند. نتیجه: یک منبعِ حقیقت برای جهت/زبان + باندلِ تمیز.
 *
 * ## اصولِ چیدمان (چرا این‌طور و نه آن‌طور)
 *
 * ۱. **دو ستون، نه یک ستونِ بلند.** فرم در ستونِ اصلی است و راهنما و کنش‌ها در
 *    ستونِ **چسبانِ** کناری؛ در موبایل روی هم می‌افتند.
 * ۲. **راهنما دو دکمه است، نه دوازده.**
 * ۳. **نتیجه کنارِ دکمه.**
 * ۴. **بلوک‌های کد همیشه `dir="ltr"` و دکمهٔ کپی در لبهٔ راست‌اند** — چون محتوا
 *    لاتین است و مستقل از جهتِ صفحه باید چپ‌به‌راست خوانده شود.
 *
 * ## قیدهای پروژه که رعایت شده
 *
 * - **هیچ `setState`/بارگذاری‌ای در بدنهٔ رندر نیست** (قانونِ ضدِ React #301).
 * - **هیچ CDN/فریم‌ورکِ CSS**: آیکون‌ها SVGِ درون‌خطی‌اند.
 * - مقدارهای لاتین (URL/راز) در هر دو زبان `dir="ltr"` می‌مانند.
 */

type Lang = "fa" | "en";
type PresetId = "local" | "server";

/* ═══ واژه‌نامهٔ محلیِ نصب‌کننده (fa پیش‌فرض) ═══════════════════════════════
   `**…**` = پررنگ، `` `…` `` = کدِ درون‌خطی؛ `rich()` آن‌ها را به JSX تبدیل
   می‌کند. عمداً هیچ رشتهٔ کاربریِ خامی در درختِ رندر نیست. */
const DICT: Record<Lang, Record<string, string>> = {
  fa: {
    "lang.aria": "زبانِ صفحه",
    "lang.fa": "فا",
    "lang.en": "EN",
    "optional": "اختیاری",

    "header.eyebrow": "نصب پنل مدیریت پیشداد",
    "header.title": "اتصالِ پنل به بک‌اند",
    "header.lead":
      "فقط اطلاعاتِ بک‌اند را وارد کنید. پس از ذخیره، همین نسخهٔ بیلدشده بدونِ بازسازی با تنظیماتِ شما کار می‌کند.",
    "header.installed": "نصب کامل شد",
    "header.pending": "در انتظارِ تنظیم",

    "writable.title": "فایلِ تنظیمات قابلِ نوشتن نیست.",
    "writable.body":
      "ذخیره از این صفحه ممکن نیست. فرم را پر کنید و مقدارها را از «مقادیرِ .env» در ستونِ کنار بردارید.",

    "s1.title": "اتصال به بک‌اند",
    "s1.lead": "نشانی‌هایی که مرورگرِ کاربران و سرورِ شما با آن‌ها به API می‌رسند.",
    "apiUrl.label": "نشانیِ عمومیِ API",
    "apiUrl.hint": "باید از اینترنت در دسترس باشد. اگر `/api` را ننویسید، «آزمونِ اتصال» خودش پیدایش می‌کند.",
    "internal.label": "نشانیِ داخلیِ API",
    "internal.hint":
      "برای رندرِ سمتِ سرور. در داکر نامِ سرویس است تا ترافیک از اینترنت دور بزند — اگر خالی بماند از نشانیِ عمومی استفاده می‌شود.",
    "internal.same": "همان نشانیِ عمومی",

    "s2.title": "هویتِ سایت",
    "s2.lead": "نشانیِ عمومیِ سایت و جایی که فایل‌ها و تصویرها از آن سرو می‌شوند.",
    "siteUrl.label": "نشانیِ عمومیِ سایت",
    "siteUrl.hint": "در canonical، Open Graph، sitemap و llms.txt استفاده می‌شود.",
    "mediaUrl.label": "نشانی سرور بک‌اند (لاراول)",
    "mediaUrl.hint":
      "فایل‌ها و تصویرهای آپلودی روی **بک‌اند** ذخیره می‌شوند، پس ریشهٔ بک‌اند است — بدونِ `/api`. **خالی بماند، گالری و تصاویر خالی می‌مانند.**",
    "mediaUrl.warn": "این مقدار خالی است — گالری و تصویرهای آپلودی خالی می‌مانند.",
    "mediaUrl.suggest": "بگذار {url}",
    "mediaUrl.suggestTitle": "ریشهٔ بک‌اند از روی نشانیِ API",

    "s3.title": "زبان و امنیت",
    "s3.lead": "زبان‌های سایت و کلیدِ مشترکی که با بک‌اند پاک‌سازیِ کش را امضا می‌کند.",
    "locales.label": "زبان‌های فعال",
    "locales.hint": "جداشده با کاما، مثل fa,en.",
    "primary.label": "زبانِ پایه",
    "primary.hint": "بدونِ پیشوند در URL می‌آید.",
    "secret.label": "رازِ revalidate",
    "secret.hint":
      "در `.env` بک‌اند، کلیدِ `REVALIDATE_SECRET`. باید دقیقاً یکی باشد تا پاک‌سازیِ کش کار کند. **هرگز به مرورگر فرستاده نمی‌شود.**",
    "secret.placeholder": "همان REVALIDATE_SECRET بک‌اند",
    "secret.show": "نمایشِ راز",
    "secret.hide": "پنهان‌کردنِ راز",
    "advanced.toggle": "تنظیماتِ پیشرفته (اختیاری)",
    "vapid.label": "کلیدِ عمومیِ Web Push",
    "vapid.hint": "برای اعلان‌های مرورگر. خالی بماند، اعلان‌ها خاموش می‌مانند.",
    "siteId.label": "شناسهٔ سایت",
    "siteId.hint": "فقط در نصب‌های چندسایتی.",

    "paste.title": "چسباندنِ بلوکِ فرانت‌اند از بک‌اند",
    "paste.lead":
      "اگر صفحهٔ `/install/done` بک‌اند را دیده‌اید، بلوکِ «نصبِ فرانت‌اند (سایت اصلی)» را از همان‌جا کپی کنید و این‌جا بچسبانید تا کلِ فرم یک‌جا پر شود. این یک میان‌بُر است؛ می‌توانید بی‌خیالش شوید و فرم را دستی پر کنید.",
    "paste.placeholder": "PISHDAD_PUBLIC_API_URL=http://localhost:8080/api\nPISHDAD_INTERNAL_API_URL=http://localhost:8080/api\n…",
    "paste.fill": "پر کردنِ فرم از این بلوک",
    "paste.copy": "کپیِ متنِ چسبانده‌شده",
    "paste.copied": "کپی شد",
    "paste.result": "{n} مقدار پر شد.",
    "paste.unknown": "کلیدهای ناشناخته (نادیده گرفته شد): {list}",
    "paste.none": "چیزی برای پرکردن پیدا نشد — متن شبیهِ خطوطِ `KEY=value` نیست.",
    "paste.empty": "چیزی نچسبانده‌اید.",

    "guide.title": "راهنمای سریع",
    "guide.lead": "وضعیتت را انتخاب کن تا کلِ فرم یک‌جا پر شود؛ بعد فقط رازِ revalidate را بگذار.",
    "guide.presetAria": "انتخابِ وضعیتِ نصب",
    "guide.local": "اجرای لوکال",
    "guide.server": "اجرای روی سرور",
    "guide.copyAll": "کپیِ همهٔ این مقدارها",
    "guide.copyAllTitle": "کپیِ همهٔ مقدارها به شکلِ خطوطِ .env",
    "guide.copyValue": "کپیِ این مقدار",
    "guide.noteLocal": "همه‌چیز روی همین ماشین است؛ از localhost استفاده کن.",
    "guide.noteServer":
      "داخلِ کانتینر، localhost خودِ کانتینر است نه میزبان. برای نشانیِ داخلی از نامِ سرویس (مثل backend) یا host.docker.internal استفاده کن.",
    "ref.api": "نشانیِ API",
    "ref.internal": "نشانیِ داخلی",
    "ref.site": "نشانیِ سایت",
    "ref.backend": "سرور بک‌اند",
    "ref.locales": "زبان‌ها",
    "ref.primary": "زبانِ پایه",

    "actions.save": "ذخیره و پایانِ نصب",
    "actions.saving": "در حالِ ذخیره…",
    "actions.test": "آزمونِ اتصال",
    "actions.testing": "در حالِ آزمون…",
    "actions.hint": "پیش از ذخیره، اتصال واقعاً آزموده می‌شود؛ اگر برقرار نشود چیزی نوشته نمی‌شود.",
    "results.doneTitle": "نصب کامل شد.",
    "results.going": "در حال رفتن به سایت…",
    "env.show": "نمایشِ مقادیرِ .env",
    "env.hide": "پنهان‌کردنِ مقادیرِ .env",
    "env.copy": "کپیِ مقادیرِ .env",
    "env.empty": "# هیچ مقداری وارد نشده",
    "common.copy": "کپی",
    "common.copied": "کپی شد",

    "err.url": "نشانی باید با http:// یا https:// شروع شود.",
    "err.internal": "نشانیِ داخلی معتبر نیست.",
    "err.site": "نشانیِ سایت معتبر نیست.",
    "err.media": "نشانی سرور بک‌اند معتبر نیست.",
    "err.locales": "حداقل یک زبان لازم است.",
    "err.primaryEmpty": "زبانِ پایه را وارد کنید.",
    "err.primaryNotIn": "زبانِ پایه باید یکی از زبان‌های فعال باشد.",
    "err.secret": "رازِ revalidate لازم است.",
    "err.testFailed": "اتصال برقرار نشد.",
  },
  en: {
    "lang.aria": "Page language",
    "lang.fa": "فا",
    "lang.en": "EN",
    "optional": "optional",

    "header.eyebrow": "Pishdad admin panel installation",
    "header.title": "Connect the panel to the backend",
    "header.lead":
      "Enter only the backend details. After saving, this same built image works with your settings — no rebuild needed.",
    "header.installed": "Installation complete",
    "header.pending": "Awaiting setup",

    "writable.title": "The config file is not writable.",
    "writable.body":
      "Saving from this page is not possible. Fill in the form and copy the values from “.env values” in the side column.",

    "s1.title": "Backend connection",
    "s1.lead": "The addresses that your users’ browsers and your server use to reach the API.",
    "apiUrl.label": "Public API address",
    "apiUrl.hint": "Must be reachable from the internet. If you omit `/api`, “Test connection” will find it.",
    "internal.label": "Internal API address",
    "internal.hint":
      "Used for server-side rendering. In Docker this is the service name so traffic stays off the internet — if left blank, the public address is used.",
    "internal.same": "Same as public address",

    "s2.title": "Site identity",
    "s2.lead": "The site’s public address and where files and images are served from.",
    "siteUrl.label": "Public site address",
    "siteUrl.hint": "Used in canonical URLs, Open Graph, sitemap and llms.txt.",
    "mediaUrl.label": "Backend (Laravel) server address",
    "mediaUrl.hint":
      "Uploaded files and images are stored on the **backend**, so this is the backend root — without `/api`. **If left blank, the gallery and uploaded images stay empty.**",
    "mediaUrl.warn": "This value is empty — the gallery and uploaded images will stay empty.",
    "mediaUrl.suggest": "Use {url}",
    "mediaUrl.suggestTitle": "Derive the backend root from the API address",

    "s3.title": "Language & security",
    "s3.lead": "The site’s languages and the shared key that signs cache revalidation with the backend.",
    "locales.label": "Active languages",
    "locales.hint": "Comma-separated, e.g. fa,en.",
    "primary.label": "Primary language",
    "primary.hint": "Appears without a prefix in the URL.",
    "secret.label": "Revalidate secret",
    "secret.hint":
      "The `REVALIDATE_SECRET` key in the backend `.env`. It must match exactly for cache revalidation to work. **Never sent to the browser.**",
    "secret.placeholder": "Same as the backend REVALIDATE_SECRET",
    "secret.show": "Show secret",
    "secret.hide": "Hide secret",
    "advanced.toggle": "Advanced settings (optional)",
    "vapid.label": "Web Push public key",
    "vapid.hint": "For browser notifications. If left blank, notifications stay off.",
    "siteId.label": "Site ID",
    "siteId.hint": "Only for multi-site installs.",

    "paste.title": "Paste the frontend block from the backend",
    "paste.lead":
      "If you’ve seen the backend’s `/install/done` page, copy the “frontend install (main site)” block from there and paste it here to fill the whole form at once. This is a shortcut; you can ignore it and fill the form by hand.",
    "paste.placeholder": "PISHDAD_PUBLIC_API_URL=http://localhost:8080/api\nPISHDAD_INTERNAL_API_URL=http://localhost:8080/api\n…",
    "paste.fill": "Fill the form from this block",
    "paste.copy": "Copy the pasted text",
    "paste.copied": "Copied",
    "paste.result": "{n} value(s) filled.",
    "paste.unknown": "Unrecognised keys (ignored): {list}",
    "paste.none": "Nothing to fill — the text doesn’t look like `KEY=value` lines.",
    "paste.empty": "You haven’t pasted anything.",

    "guide.title": "Quick guide",
    "guide.lead": "Pick your situation to fill the whole form at once; then just set the revalidate secret.",
    "guide.presetAria": "Choose the install scenario",
    "guide.local": "Local run",
    "guide.server": "Server run",
    "guide.copyAll": "Copy all these values",
    "guide.copyAllTitle": "Copy all values as .env lines",
    "guide.copyValue": "Copy this value",
    "guide.noteLocal": "Everything runs on this machine; use localhost.",
    "guide.noteServer":
      "Inside a container, localhost is the container itself, not the host. For the internal address use the service name (e.g. backend) or host.docker.internal.",
    "ref.api": "API address",
    "ref.internal": "Internal address",
    "ref.site": "Site address",
    "ref.backend": "Backend server",
    "ref.locales": "Languages",
    "ref.primary": "Primary language",

    "actions.save": "Save and finish installation",
    "actions.saving": "Saving…",
    "actions.test": "Test connection",
    "actions.testing": "Testing…",
    "actions.hint": "Before saving, the connection is really tested; if it fails, nothing is written.",
    "results.doneTitle": "Installation complete.",
    "results.going": "Redirecting to the site…",
    "env.show": "Show .env values",
    "env.hide": "Hide .env values",
    "env.copy": "Copy .env values",
    "env.empty": "# no values entered yet",
    "common.copy": "Copy",
    "common.copied": "Copied",

    "err.url": "The address must start with http:// or https://.",
    "err.internal": "The internal address is not valid.",
    "err.site": "The site address is not valid.",
    "err.media": "The backend server address is not valid.",
    "err.locales": "At least one language is required.",
    "err.primaryEmpty": "Enter the primary language.",
    "err.primaryNotIn": "The primary language must be one of the active languages.",
    "err.secret": "The revalidate secret is required.",
    "err.testFailed": "Could not connect.",
  },
};

/** مقادیرِ آمادهٔ هر وضعیت — آنچه واقعاً باید در فرم بنشیند. */
const PRESETS: Record<PresetId, Pick<SetupInput, "apiUrl" | "internalApiUrl" | "siteUrl" | "mediaUrl" | "locales" | "primaryLocale">> = {
  local: {
    apiUrl: "http://localhost:8080/api",
    internalApiUrl: "http://localhost:8080/api",
    siteUrl: "http://localhost:3000",
    mediaUrl: "http://localhost:8080",
    locales: "fa",
    primaryLocale: "fa",
  },
  server: {
    apiUrl: "https://api.example.com/api",
    internalApiUrl: "http://backend:8080/api",
    siteUrl: "https://example.com",
    mediaUrl: "https://api.example.com",
    locales: "fa",
    primaryLocale: "fa",
  },
};

/** ردیف‌های مرجعِ راهنمای سریع: کلیدِ برچسب در واژه‌نامه + نامِ کلیدِ `.env`. */
const REF_ROWS: Array<{ key: keyof (typeof PRESETS)["local"]; label: string; env: string }> = [
  { key: "apiUrl", label: "ref.api", env: "PISHDAD_PUBLIC_API_URL" },
  { key: "internalApiUrl", label: "ref.internal", env: "PISHDAD_INTERNAL_API_URL" },
  { key: "siteUrl", label: "ref.site", env: "PISHDAD_SITE_URL" },
  { key: "mediaUrl", label: "ref.backend", env: "PISHDAD_PUBLIC_MEDIA_URL" },
  { key: "locales", label: "ref.locales", env: "PISHDAD_SITE_LOCALES" },
  { key: "primaryLocale", label: "ref.primary", env: "PISHDAD_SITE_PRIMARY_LOCALE" },
];

/**
 * نگاشتِ فیلدِ فرم به کلیدِ `.env` — یک جا، تا هم بلوکِ `.env` و هم جعبهٔ
 * چسباندن از آن بسازند و دو فهرستِ موازی کهنه نشود.
 */
const ENV_KEYS: Array<[keyof SetupInput, string]> = [
  ["apiUrl", "PISHDAD_PUBLIC_API_URL"],
  ["internalApiUrl", "PISHDAD_INTERNAL_API_URL"],
  ["siteUrl", "PISHDAD_SITE_URL"],
  ["locales", "PISHDAD_SITE_LOCALES"],
  ["primaryLocale", "PISHDAD_SITE_PRIMARY_LOCALE"],
  ["mediaUrl", "PISHDAD_PUBLIC_MEDIA_URL"],
  ["revalidateSecret", "REVALIDATE_SECRET"],
  ["vapidPublicKey", "PISHDAD_PUBLIC_VAPID_PUBLIC_KEY"],
  ["siteId", "PISHDAD_SITE_ID"],
];

/**
 * نگاشتِ کلیدهای `.env` به فیلدهای فرم برای «جعبهٔ چسباندن».
 *
 * هم نام‌های فعلی و هم املاهای **قدیمیِ `NEXT_PUBLIC_*`** پذیرفته می‌شوند، چون
 * نسخه‌های پیشینِ بک‌اند همان‌ها را در صفحهٔ `/install/done` چاپ می‌کردند و
 * کاربری که آن متن را کنار گذاشته نباید بی‌دلیل شکست بخورد.
 */
const ENV_TO_FORM: Record<string, keyof SetupInput> = {
  PISHDAD_PUBLIC_API_URL: "apiUrl",
  PISHDAD_INTERNAL_API_URL: "internalApiUrl",
  PISHDAD_SITE_URL: "siteUrl",
  PISHDAD_PUBLIC_MEDIA_URL: "mediaUrl",
  PISHDAD_SITE_LOCALES: "locales",
  PISHDAD_SITE_PRIMARY_LOCALE: "primaryLocale",
  REVALIDATE_SECRET: "revalidateSecret",
  PISHDAD_PUBLIC_VAPID_PUBLIC_KEY: "vapidPublicKey",
  PISHDAD_SITE_ID: "siteId",
  NEXT_PUBLIC_API_URL: "apiUrl",
  NEXT_PUBLIC_INTERNAL_API_URL: "internalApiUrl",
  NEXT_PUBLIC_SITE_URL: "siteUrl",
  NEXT_PUBLIC_MEDIA_URL: "mediaUrl",
  NEXT_PUBLIC_SITE_LOCALES: "locales",
  NEXT_PUBLIC_SITE_PRIMARY_LOCALE: "primaryLocale",
  NEXT_PUBLIC_VAPID_PUBLIC_KEY: "vapidPublicKey",
  NEXT_PUBLIC_SITE_ID: "siteId",
};

/**
 * پارسِ مقاومِ بلوکِ `.env`.
 *
 * قواعد عمداً سخت‌گیرانه-نیستند: خطِ خالی و `#` نادیده، `export ` برداشته
 * می‌شود، دورِ مقدارهای کوتیشن‌دار حذف می‌شود، و یک خطِ بی`=` فقط **رد** می‌شود
 * (نه که کلِ پارس را بترکاند) — چون کاربر ممکن است خطِ توضیحی یا آشغال هم
 * بچسباند. کلیدهای ناشناخته در `unknown` برمی‌گردند تا کاربر بداند چه چیزی
 * نادیده ماند.
 */
function parseEnvBlock(text: string): { values: Partial<SetupInput>; count: number; unknown: string[] } {
  const values: Partial<SetupInput> = {};
  const unknown: string[] = [];
  let count = 0;

  for (const rawLine of text.split(/\r?\n/)) {
    let line = rawLine.trim();
    if (line === "" || line.startsWith("#")) continue;

    // `export KEY=…` در پوسته رایج است؛ اجازه بده.
    line = line.replace(/^export\s+/, "");

    const eq = line.indexOf("=");
    if (eq === -1) continue; // خطِ بی`=` ⇒ نادیده، بدونِ خطا.

    const key = line.slice(0, eq).trim();
    if (key === "") continue;

    let value = line.slice(eq + 1).trim();
    // کوتیشن‌های دورِ مقدار (تکی/دوبل) برداشته می‌شوند.
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }

    const formKey = ENV_TO_FORM[key] ?? ENV_TO_FORM[key.toUpperCase()];
    if (!formKey) {
      if (!unknown.includes(key)) unknown.push(key);
      continue;
    }
    values[formKey] = value;
    count++;
  }

  return { values, count, unknown };
}

/* ── آیکون‌های درون‌خطی (هیچ CDN) ───────────────────────────────── */

const STROKE = {
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.8,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

function IconCheck() {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M4 10.5l4 4 8-8" {...STROKE} />
    </svg>
  );
}

function IconAlert() {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M10 3.2l7.5 13.3h-15L10 3.2z" {...STROKE} />
      <path d="M10 8.2v3.6M10 14.2v.6" {...STROKE} />
    </svg>
  );
}

function IconEye({ off = false }: { off?: boolean }) {
  return (
    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M2.5 10S5.5 4.8 10 4.8 17.5 10 17.5 10 14.5 15.2 10 15.2 2.5 10 2.5 10z" {...STROKE} />
      <circle cx="10" cy="10" r="2.4" {...STROKE} />
      {off ? <path d="M3.5 3.5l13 13" {...STROKE} /> : null}
    </svg>
  );
}

function IconChevron() {
  return (
    <svg className="setup-chev" width="16" height="16" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M5.5 8l4.5 4.5L14.5 8" {...STROKE} />
    </svg>
  );
}

function IconCopy() {
  return (
    <svg width="15" height="15" viewBox="0 0 20 20" aria-hidden="true">
      <rect x="7" y="7" width="9" height="9" rx="1.5" {...STROKE} />
      <path d="M13 7V5.5A1.5 1.5 0 0 0 11.5 4h-6A1.5 1.5 0 0 0 4 5.5v6A1.5 1.5 0 0 0 5.5 13H7" {...STROKE} />
    </svg>
  );
}

/* ── اجزای کوچک ─────────────────────────────────────────────────── */

/**
 * متنِ واژه‌نامه را با نشانه‌گذاریِ سبک به JSX تبدیل می‌کند:
 *   `code` → `<code>` · **bold** → `<strong>`
 * تا همهٔ رشته‌های کاربری از یک منبع (واژه‌نامه) بیایند، بی‌آنکه HTML تزریق شود.
 */
function rich(text: string, keyPrefix = "r"): React.ReactNode {
  const parts = text.split(/(`[^`]+`|\*\*[^*]+\*\*)/g);
  return parts.map((part, i) => {
    const key = `${keyPrefix}-${i}`;
    if (part.startsWith("`") && part.endsWith("`") && part.length >= 2) return <code key={key}>{part.slice(1, -1)}</code>;
    if (part.startsWith("**") && part.endsWith("**") && part.length >= 4) return <strong key={key}>{part.slice(2, -2)}</strong>;
    return <Fragment key={key}>{part}</Fragment>;
  });
}

/** فیلدِ فرم: برچسبِ `for`دار + نشانهٔ لازم/اختیاری + پیامِ خطا/هشدار + راهنما. */
function Field({
  id,
  label,
  required = false,
  optional = false,
  optionalLabel = "",
  hint,
  error,
  warn,
  children,
}: {
  id: string;
  label: string;
  required?: boolean;
  optional?: boolean;
  optionalLabel?: string;
  hint: React.ReactNode;
  error?: string;
  warn?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="field">
      <label htmlFor={id}>
        {label}
        {required ? (
          <span className="setup-req" aria-hidden="true">
            *
          </span>
        ) : null}
        {optional ? <span className="setup-opt">{optionalLabel}</span> : null}
      </label>
      {children}
      {error ? (
        <p id={`${id}-err`} className="setup-field-error">
          {error}
        </p>
      ) : null}
      {!error && warn ? <p className="setup-field-warn">{warn}</p> : null}
      <p id={`${id}-hint`} className="hint">
        {hint}
      </p>
    </div>
  );
}

/** سرِ یک بخشِ شماره‌دار. */
function Section({
  step,
  title,
  lead,
  children,
}: {
  step: number;
  title: string;
  lead: string;
  children: React.ReactNode;
}) {
  return (
    <section className="card card-pad">
      <header className="setup-card-head">
        <span className="setup-step" aria-hidden="true">
          {step}
        </span>
        <div>
          <h2>{title}</h2>
          <p>{lead}</p>
        </div>
      </header>
      {children}
    </section>
  );
}

/* ── اعتبارسنجیِ سبکِ کلاینت ─────────────────────────────────────── */

function isHttpUrl(raw: string): boolean {
  try {
    const url = new URL(raw.trim());
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

/**
 * فقط برای نشان‌دادنِ زودهنگامِ فیلدِ مشکل‌دار (`aria-invalid` + پیامِ کنارِ
 * همان فیلد). **منبعِ حقیقت همان `actions.ts` است** — اینجا فهرستِ زبان‌های
 * مجاز تکرار نمی‌شود (تکرار یعنی دو حقیقت که یکی کهنه می‌شود)؛ خطاهای مربوط به
 * «زبانِ ناشناخته» از پاسخِ سرور در جعبهٔ نتایج دیده می‌شوند.
 */
function clientErrors(form: SetupInput, t: (key: string, values?: Record<string, string | number>) => string): Partial<Record<keyof SetupInput, string>> {
  const errors: Partial<Record<keyof SetupInput, string>> = {};

  if (!isHttpUrl(form.apiUrl)) errors.apiUrl = t("err.url");
  if (form.internalApiUrl.trim() && !isHttpUrl(form.internalApiUrl)) errors.internalApiUrl = t("err.internal");
  if (form.siteUrl.trim() && !isHttpUrl(form.siteUrl)) errors.siteUrl = t("err.site");
  if (form.mediaUrl.trim() && !isHttpUrl(form.mediaUrl)) errors.mediaUrl = t("err.media");

  const locales = form.locales
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  if (locales.length === 0) errors.locales = t("err.locales");

  const primary = form.primaryLocale.trim();
  if (!primary) errors.primaryLocale = t("err.primaryEmpty");
  else if (locales.length > 0 && !locales.includes(primary)) errors.primaryLocale = t("err.primaryNotIn");

  if (!form.revalidateSecret.trim()) errors.revalidateSecret = t("err.secret");

  return errors;
}

/* ── فرمِ اصلی ──────────────────────────────────────────────────── */

export function SetupForm({ defaults, writable }: { defaults: SetupInput; writable: boolean }) {
  // زبان/جهت از providerِ مشترکِ پوسته می‌آید (توضیح در سرِ فایل). هیچ
  // `setState`ِ زمانِ رندی در کار نیست؛ `setLang` صرفاً در رویداد صدا زده می‌شود.
  const { lang, dir, setLang } = useLang();

  const [form, setForm] = useState<SetupInput>(defaults);
  const [preset, setPreset] = useState<PresetId>("local");
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [test, setTest] = useState<TestResult | null>(null);
  const [save, setSave] = useState<{ ok: boolean; message: string; errors?: string[] } | null>(null);
  const [attempted, setAttempted] = useState(false);
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [showEnv, setShowEnv] = useState(false);
  const [showPaste, setShowPaste] = useState(false);
  const [revealSecret, setRevealSecret] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);
  // ⚠️ متنِ خامِ چسبانده‌شده فقط در stateِ کامپوننت می‌ماند؛ هیچ‌جا ذخیره نمی‌شود.
  const [pasteText, setPasteText] = useState("");
  const [pasteResult, setPasteResult] = useState<{ count: number; unknown: string[]; empty: boolean } | null>(null);

  /** مترجمِ محلیِ واژه‌نامه؛ کلیدِ ناشناخته به خودِ کلید برمی‌گردد تا سطر گم نشود. */
  const tr = useCallback(
    (key: string, values?: Record<string, string | number>) => {
      const template = DICT[lang][key] ?? DICT.fa[key] ?? key;
      if (!values) return template;
      return template.replace(/\{(\w+)\}/g, (whole, k: string) => (k in values ? String(values[k]) : whole));
    },
    [lang],
  );

  /**
   * عنوانِ مرورگر از `metadata`ِ سرور می‌آید (فارسی). چون سوییچِ زبان کلاینتی
   * است، هنگام تغییرِ زبان در `useEffect` عنوان را هم عوض می‌کنیم — نه در
   * بدنهٔ رندر (قانونِ ضدِ React #301).
   */
  useEffect(() => {
    document.title = tr("header.title");
  }, [tr]);

  // ⚠️ همهٔ `setState`ها داخلِ رویدادند — هیچ‌کدام در بدنهٔ رندر صدا زده
  // نمی‌شوند (قانونِ ضدِ React #301).
  const set = useCallback(<K extends keyof SetupInput>(key: K, value: SetupInput[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  }, []);

  const copy = useCallback(async (value: string, tag: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(tag);
      window.setTimeout(() => setCopied((current) => (current === tag ? null : current)), 1500);
    } catch {
      // بسترِ ناامن (http روی دامنهٔ غیرِ localhost) یا نبودِ اجازه.
      setCopied(null);
    }
  }, []);

  /** پر کردنِ کلِ فرم از یک پیش‌تنظیم. */
  const applyPreset = useCallback((id: PresetId) => {
    setPreset(id);
    setForm((prev) => ({ ...prev, ...PRESETS[id] }));
  }, []);

  /**
   * پر کردنِ فرم از متنِ چسبانده‌شده. مقدارهای موجود در بلوک **بر** آنچه کاربر
   * تایپ کرده اولویت دارند، ولی بقیهٔ فیلدها دست‌نخورده می‌مانند.
   */
  const onPasteFill = useCallback(() => {
    const parsed = parseEnvBlock(pasteText);
    setPasteResult({ count: parsed.count, unknown: parsed.unknown, empty: pasteText.trim() === "" });
    if (parsed.count > 0) setForm((prev) => ({ ...prev, ...parsed.values }));
  }, [pasteText]);

  const onTest = useCallback(async () => {
    setTesting(true);
    setTest(null);
    setSave(null);
    try {
      const result = await testConnection(form);
      setTest(result);
      // نشانیِ تصحیح‌شده را می‌نشانیم تا کاربر نفهمد اشتباه نوشته بوده — و
      // همان را در فرم می‌گذاریم تا ذخیره با نشانیِ کارآمد انجام شود.
      if (result.ok && result.apiUrl && result.apiUrl !== form.apiUrl.trim().replace(/\/+$/, "")) {
        set("apiUrl", result.apiUrl);
      }
    } finally {
      setTesting(false);
    }
  }, [form, set]);

  const onSave = useCallback(async () => {
    // خطاها را زودتر نشان بده و از یک درخواستِ بی‌ثمر جلوگیری کن؛ اعتبارسنجیِ
    // نهایی همچنان سمتِ سرور است.
    setAttempted(true);
    if (Object.keys(clientErrors(form, tr)).length > 0) return;

    setSaving(true);
    setSave(null);
    setTest(null);
    try {
      const result = await saveConfig(form);
      setSave(result);
      if (result.ok) window.setTimeout(() => window.location.assign("/"), 1200);
    } finally {
      setSaving(false);
    }
  }, [form, tr]);

  const errors = useMemo(() => clientErrors(form, tr), [form, tr]);
  /** خطا فقط پس از نخستین تلاشِ ذخیره نشان داده می‌شود (نه هنگامِ تایپ). */
  const shown = useCallback((key: keyof SetupInput) => (attempted ? errors[key] : undefined), [attempted, errors]);

  const done = Boolean(save?.ok);
  const busy = testing || saving;

  /** پیشنهادِ ریشهٔ بک‌اند از روی نشانیِ API، وقتی با هم نمی‌خوانند. */
  const suggestedMedia = useMemo(() => {
    let origin = "";
    try {
      origin = new URL(form.apiUrl.trim()).origin;
    } catch {
      origin = "";
    }
    return origin !== "" && origin !== form.mediaUrl.trim().replace(/\/+$/, "") ? origin : "";
  }, [form.apiUrl, form.mediaUrl]);

  const envBlock = useMemo(
    () =>
      ENV_KEYS.map(([key, name]) => [name, form[key].trim()] as const)
        .filter(([, value]) => value !== "")
        .map(([name, value]) => `${name}=${value}`)
        .join("\n"),
    [form],
  );

  const caseEnv = useMemo(
    () => REF_ROWS.map((row) => `${row.env}=${PRESETS[preset][row.key]}`).join("\n"),
    [preset],
  );

  const activePreset = PRESETS[preset];
  const apiErr = shown("apiUrl") ?? (test && !test.ok ? tr("err.testFailed") : undefined);
  const mediaMissing = form.mediaUrl.trim() === "";

  /**
   * `aria-describedby`/`aria-invalid` برای یک ورودی. هر فیلد یک راهنما دارد،
   * پس شناسهٔ راهنما همیشه هست؛ شناسهٔ خطا فقط وقتی خطایی هست.
   */
  const aria = (id: string, err?: string) => ({
    "aria-describedby": err ? `${id}-err ${id}-hint` : `${id}-hint`,
    "aria-invalid": err ? true : undefined,
  });

  return (
    <div className="setup" dir={dir} lang={lang}>
      <div className="setup-wrap">
        {/* ── سرصفحه ─────────────────────────────────────────────── */}
        <header className="setup-head">
          <div>
            <p className="setup-eyebrow">{tr("header.eyebrow")}</p>
            <h1>{tr("header.title")}</h1>
            <p className="setup-lead">{tr("header.lead")}</p>
          </div>
          <div className="setup-head-actions">
            {/* سوییچرِ زبان: سگمنتِ «فا / EN». `role=group` + `aria-pressed` تا
                صفحه‌خوان بفهمد کدام‌یک فعال است. بدونِ ایموجی. */}
            <div className="setup-lang" role="group" aria-label={tr("lang.aria")}>
              <button type="button" aria-pressed={lang === "fa"} onClick={() => setLang("fa")}>
                {tr("lang.fa")}
              </button>
              <button type="button" aria-pressed={lang === "en"} onClick={() => setLang("en")}>
                {tr("lang.en")}
              </button>
            </div>
            <span className={done ? "setup-status ok" : "setup-status"}>
              <span className={done ? "dot ok" : "dot warn"} aria-hidden="true" />
              {done ? tr("header.installed") : tr("header.pending")}
            </span>
          </div>
        </header>

        {/* ستونِ اصلی = فرم، ستونِ کناری = راهنما/کنش‌ها. `onSubmit` کلیدِ Enter
            در فیلدها را به «ذخیره» وصل می‌کند. */}
        <form
          className="setup-grid"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            void onSave();
          }}
        >
          {/* ══ ستونِ اصلی ═══════════════════════════════════════════ */}
          <div className="setup-main">
            {!writable ? (
              <div className="alert a-amber" role="alert">
                <IconAlert />
                <div>
                  <strong>{tr("writable.title")}</strong>
                  <p className="setup-hint">{tr("writable.body")}</p>
                </div>
              </div>
            ) : null}

            {/* ── میان‌بُر: چسباندنِ بلوکِ بک‌اند ────────────────────── */}
            <section className="card card-pad setup-paste">
              <button
                type="button"
                className="setup-disclosure"
                aria-expanded={showPaste}
                aria-controls="setup-paste-body"
                onClick={() => setShowPaste((v) => !v)}
              >
                <span>{tr("paste.title")}</span>
                <IconChevron />
              </button>

              {showPaste ? (
                <div id="setup-paste-body">
                  <p className="setup-hint">{rich(tr("paste.lead"), "paste-lead")}</p>

                  {/* کادرِ متن `dir="ltr"` است و دکمهٔ کپی در لبهٔ **راست** —
                      مستقل از زبانِ صفحه، چون محتوا لاتین است. */}
                  <div className="setup-paste-area" dir="ltr">
                    <textarea
                      className="setup-paste-text"
                      dir="ltr"
                      spellCheck={false}
                      autoComplete="off"
                      aria-label={tr("paste.title")}
                      placeholder={tr("paste.placeholder")}
                      value={pasteText}
                      onChange={(e) => setPasteText(e.target.value)}
                    />
                    <button
                      type="button"
                      className={copied === "paste" ? "setup-copy done" : "setup-copy"}
                      aria-label={tr("paste.copy")}
                      title={tr("paste.copy")}
                      onClick={() => void copy(pasteText, "paste")}
                    >
                      {copied === "paste" ? <IconCheck /> : <IconCopy />}
                    </button>
                  </div>

                  <div className="setup-paste-actions">
                    <button type="button" className="btn btn-soft btn-sm" onClick={onPasteFill}>
                      {tr("paste.fill")}
                    </button>
                  </div>

                  {pasteResult ? (
                    <p className="setup-paste-result" role="status">
                      {pasteResult.empty ? (
                        <span className="setup-warn">{tr("paste.empty")}</span>
                      ) : pasteResult.count === 0 ? (
                        <span className="setup-warn">{tr("paste.none")}</span>
                      ) : (
                        <span className="setup-ok">
                          {tr("paste.result", { n: pasteResult.count })}
                          {pasteResult.unknown.length > 0 ? ` ${tr("paste.unknown", { list: pasteResult.unknown.join(", ") })}` : ""}
                        </span>
                      )}
                    </p>
                  ) : null}
                </div>
              ) : null}
            </section>

            {/* ── بخشِ ۱: اتصال ──────────────────────────────────── */}
            <Section step={1} title={tr("s1.title")} lead={tr("s1.lead")}>
              <Field
                id="apiUrl"
                label={tr("apiUrl.label")}
                required
                error={apiErr}
                hint={rich(tr("apiUrl.hint"), "apiUrl-hint")}
              >
                <input
                  id="apiUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://api.example.com/api"
                  value={form.apiUrl}
                  onChange={(e) => set("apiUrl", e.target.value)}
                  {...aria("apiUrl", apiErr)}
                />
              </Field>

              <Field
                id="internalApiUrl"
                label={tr("internal.label")}
                optional
                optionalLabel={tr("optional")}
                error={shown("internalApiUrl")}
                hint={rich(tr("internal.hint"), "internal-hint")}
              >
                <input
                  id="internalApiUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="http://backend:8080/api"
                  value={form.internalApiUrl}
                  onChange={(e) => set("internalApiUrl", e.target.value)}
                  {...aria("internalApiUrl", shown("internalApiUrl"))}
                />
                <button type="button" className="setup-linkbtn" onClick={() => set("internalApiUrl", form.apiUrl)}>
                  {tr("internal.same")}
                </button>
              </Field>
            </Section>

            {/* ── بخشِ ۲: هویتِ سایت ─────────────────────────────── */}
            <Section step={2} title={tr("s2.title")} lead={tr("s2.lead")}>
              <Field
                id="siteUrl"
                label={tr("siteUrl.label")}
                required
                error={shown("siteUrl")}
                hint={tr("siteUrl.hint")}
              >
                <input
                  id="siteUrl"
                  className="input"
                  dir="ltr"
                  inputMode="url"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="https://example.com"
                  value={form.siteUrl}
                  onChange={(e) => set("siteUrl", e.target.value)}
                  {...aria("siteUrl", shown("siteUrl"))}
                />
              </Field>

              {/*
                ⚠️ این فیلد عمداً بیرون از بخشِ «پیشرفته» و همین‌جا است. فایل‌ها و
                تصویرهای آپلودی روی **بک‌اند** ذخیره می‌شوند و نشانی‌شان از این
                پایه ساخته می‌شود؛ خالی‌بودنش **گالری** را بی‌سروصدا خالی می‌کند و
                علتش از بیرون قابلِ حدس نیست. پس باید جلوی چشم باشد.
              */}
              <Field
                id="mediaUrl"
                label={tr("mediaUrl.label")}
                required
                error={shown("mediaUrl")}
                warn={mediaMissing ? tr("mediaUrl.warn") : undefined}
                hint={rich(tr("mediaUrl.hint"), "mediaUrl-hint")}
              >
                <div className="setup-input-row">
                  <input
                    id="mediaUrl"
                    className="input"
                    dir="ltr"
                    inputMode="url"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="http://localhost:8080"
                    value={form.mediaUrl}
                    onChange={(e) => set("mediaUrl", e.target.value)}
                    {...aria("mediaUrl", shown("mediaUrl"))}
                  />
                  {suggestedMedia ? (
                    <button
                      type="button"
                      className="setup-suggest"
                      onClick={() => set("mediaUrl", suggestedMedia)}
                      title={tr("mediaUrl.suggestTitle")}
                    >
                      {tr("mediaUrl.suggest", { url: suggestedMedia })}
                    </button>
                  ) : null}
                </div>
              </Field>
            </Section>

            {/* ── بخشِ ۳: زبان و امنیت ───────────────────────────── */}
            <Section step={3} title={tr("s3.title")} lead={tr("s3.lead")}>
              <div className="form-grid">
                <Field
                  id="locales"
                  label={tr("locales.label")}
                  required
                  error={shown("locales")}
                  hint={tr("locales.hint")}
                >
                  <input
                    id="locales"
                    className="input"
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa,en"
                    value={form.locales}
                    onChange={(e) => set("locales", e.target.value)}
                    {...aria("locales", shown("locales"))}
                  />
                </Field>
                <Field
                  id="primaryLocale"
                  label={tr("primary.label")}
                  required
                  error={shown("primaryLocale")}
                  hint={tr("primary.hint")}
                >
                  <input
                    id="primaryLocale"
                    className="input"
                    dir="ltr"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="fa"
                    value={form.primaryLocale}
                    onChange={(e) => set("primaryLocale", e.target.value)}
                    {...aria("primaryLocale", shown("primaryLocale"))}
                  />
                </Field>
              </div>

              <Field
                id="revalidateSecret"
                label={tr("secret.label")}
                required
                error={shown("revalidateSecret")}
                hint={rich(tr("secret.hint"), "secret-hint")}
              >
                <div className="setup-input-row">
                  <input
                    id="revalidateSecret"
                    className="input"
                    dir="ltr"
                    type={revealSecret ? "text" : "password"}
                    autoComplete="new-password"
                    spellCheck={false}
                    placeholder={tr("secret.placeholder")}
                    value={form.revalidateSecret}
                    onChange={(e) => set("revalidateSecret", e.target.value)}
                    {...aria("revalidateSecret", shown("revalidateSecret"))}
                  />
                  <button
                    type="button"
                    className="setup-icon-btn"
                    onClick={() => setRevealSecret((v) => !v)}
                    aria-label={revealSecret ? tr("secret.hide") : tr("secret.show")}
                  >
                    <IconEye off={revealSecret} />
                  </button>
                </div>
              </Field>

              {/* افشاگرِ پیشرفته — `aria-expanded` + `aria-controls` برای کیبورد/صفحه‌خوان. */}
              <button
                type="button"
                className="setup-disclosure"
                aria-expanded={showAdvanced}
                aria-controls="setup-advanced"
                onClick={() => setShowAdvanced((v) => !v)}
              >
                <span>{tr("advanced.toggle")}</span>
                <IconChevron />
              </button>

              {showAdvanced ? (
                <div id="setup-advanced">
                  <Field id="vapidPublicKey" label={tr("vapid.label")} optional optionalLabel={tr("optional")} hint={tr("vapid.hint")}>
                    <input
                      id="vapidPublicKey"
                      className="input"
                      dir="ltr"
                      autoComplete="off"
                      spellCheck={false}
                      value={form.vapidPublicKey}
                      onChange={(e) => set("vapidPublicKey", e.target.value)}
                      {...aria("vapidPublicKey")}
                    />
                  </Field>
                  <Field id="siteId" label={tr("siteId.label")} optional optionalLabel={tr("optional")} hint={tr("siteId.hint")}>
                    <input
                      id="siteId"
                      className="input"
                      dir="ltr"
                      autoComplete="off"
                      spellCheck={false}
                      value={form.siteId}
                      onChange={(e) => set("siteId", e.target.value)}
                      {...aria("siteId")}
                    />
                  </Field>
                </div>
              ) : null}
            </Section>
          </div>

          {/* ══ ستونِ کناری: راهنما + کنش‌ها ═══════════════════════ */}
          <aside className="setup-side">
            {/* راهنمای سریع: دو پیش‌تنظیم، نه دوازده دکمه */}
            <section className="card card-pad">
              <h2 className="card-title">{tr("guide.title")}</h2>
              <p className="setup-hint">{tr("guide.lead")}</p>

              <div className="setup-presets" role="group" aria-label={tr("guide.presetAria")}>
                {(["local", "server"] as const).map((id) => (
                  <button
                    key={id}
                    type="button"
                    className={preset === id ? "setup-preset on" : "setup-preset"}
                    aria-pressed={preset === id}
                    onClick={() => applyPreset(id)}
                  >
                    {tr(id === "local" ? "guide.local" : "guide.server")}
                  </button>
                ))}
              </div>

              <div>
                {REF_ROWS.map((row) => (
                  /* بلوکِ مقدار `dir="ltr"` است و دکمهٔ کپی در لبهٔ **راستِ**
                     همان بلوک می‌نشیند (نه لبهٔ راستِ سطرِ راست‌به‌چپ). */
                  <div className="setup-ref" key={row.key}>
                    <span className="setup-ref-label">{tr(row.label)}</span>
                    <div className="setup-ref-val" dir="ltr">
                      <code title={activePreset[row.key]}>{activePreset[row.key]}</code>
                      <button
                        type="button"
                        className={copied === row.key ? "setup-copy done" : "setup-copy"}
                        aria-label={tr("guide.copyValue")}
                        title={tr("guide.copyValue")}
                        onClick={() => void copy(activePreset[row.key], row.key)}
                      >
                        {copied === row.key ? <IconCheck /> : <IconCopy />}
                      </button>
                    </div>
                  </div>
                ))}
              </div>

              <button
                type="button"
                className="setup-linkbtn"
                onClick={() => void copy(caseEnv, "case")}
                title={tr("guide.copyAllTitle")}
              >
                {copied === "case" ? `${tr("common.copied")} ✓` : tr("guide.copyAll")}
              </button>

              <p className="setup-note">{preset === "local" ? tr("guide.noteLocal") : tr("guide.noteServer")}</p>
            </section>

            {/* کنش‌ها + نتیجه‌ها */}
            <section className="card card-pad">
              <div className="setup-actions">
                <button type="submit" className="btn btn-primary btn-block" disabled={busy || !writable || done}>
                  {saving ? tr("actions.saving") : tr("actions.save")}
                </button>
                <button type="button" className="btn btn-ghost btn-block" disabled={busy} onClick={() => void onTest()}>
                  {testing ? tr("actions.testing") : tr("actions.test")}
                </button>
              </div>

              <p className="setup-hint">{tr("actions.hint")}</p>

              {/* نتیجه‌ها همین‌جا، کنارِ دکمه‌ای که زده شد — نه پایینِ صفحه. */}
              <div className="setup-results" aria-live="polite">
                {done ? (
                  <div className="alert a-green" role="status">
                    <IconCheck />
                    <div>
                      <strong>{tr("results.doneTitle")}</strong>
                      <p className="setup-hint">{tr("results.going")}</p>
                    </div>
                  </div>
                ) : null}

                {test ? (
                  <div className={test.ok ? "alert a-green" : "alert a-red"} role={test.ok ? "status" : "alert"}>
                    {test.ok ? <IconCheck /> : <IconAlert />}
                    <span>{test.message}</span>
                  </div>
                ) : null}

                {save && !save.ok ? (
                  <div className="alert a-red" role="alert">
                    <IconAlert />
                    <div>
                      <strong>{save.message}</strong>
                      {save.errors && save.errors.length > 0 ? (
                        <ul className="setup-errors">
                          {save.errors.map((e) => (
                            <li key={e}>{e}</li>
                          ))}
                        </ul>
                      ) : null}
                    </div>
                  </div>
                ) : null}
              </div>

              <div className="setup-env-toggle">
                <button
                  type="button"
                  className="setup-linkbtn"
                  aria-expanded={showEnv}
                  aria-controls="setup-env"
                  onClick={() => setShowEnv((v) => !v)}
                >
                  {showEnv ? tr("env.hide") : tr("env.show")}
                </button>
                {showEnv ? (
                  /* بلوکِ `.env` هم `dir="ltr"` است و دکمهٔ کپی در راستِ کادر. */
                  <div className="setup-codewrap" dir="ltr">
                    <pre id="setup-env" className="setup-env">
                      {envBlock || tr("env.empty")}
                    </pre>
                    <button
                      type="button"
                      className={copied === "env" ? "setup-copy done" : "setup-copy"}
                      aria-label={tr("env.copy")}
                      title={tr("env.copy")}
                      onClick={() => void copy(envBlock, "env")}
                    >
                      {copied === "env" ? <IconCheck /> : <IconCopy />}
                    </button>
                  </div>
                ) : null}
              </div>
            </section>
          </aside>
        </form>
      </div>
    </div>
  );
}
