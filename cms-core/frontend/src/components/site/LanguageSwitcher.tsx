"use client";

import { usePathname, useSearchParams } from "next/navigation";
import {
  localizeHref,
  publicDir,
  publicHtmlLang,
  publicT,
  type PublicLocale,
} from "@/lib/i18n/public";

/**
 * ECO2 — دکمهٔ تغییر زبانِ سایتِ عمومی.
 *
 * مدل a: زبان پایه در ریشه (`/about`) و زبان دوم زیر پیشوند (`/en/about`).
 * پس تغییر زبان = برداشتن/گذاشتنِ پیشوند روی **همان مسیر**، تا کاربر جای خودش
 * بماند. `next/link` عمدی نیست: این فقط یک `href` است و ریلود کامل، حالتِ
 * ناوبری را ساده و بدون ناسازگاری نگه می‌دارد.
 *
 * `dir` از زبان می‌آید (logical properties در بقیهٔ کامپوننت‌ها تضمینش می‌کند)
 * و `lang` صریح می‌نشیند تا صفحه‌خوان زبانِ درست را بخواند.
 */
export function LanguageSwitcher({
  locales,
  primary,
  current,
}: {
  locales: PublicLocale[];
  primary: PublicLocale;
  current: PublicLocale;
}) {
  const pathname = usePathname() ?? "/";
  const searchParams = useSearchParams();

  const rest = pathname.replace(/^\/+|\/+$/g, "");
  const parts = rest ? rest.split("/") : [];
  // پیشوندِ زبان فقط وقتی معتبر است که در فهرست باشد؛ وگرنه بخشی از اسلاگ است.
  const hasPrefix = parts.length > 0 && (locales as readonly string[]).includes(parts[0]!);
  const bare = hasPrefix ? parts.slice(1) : parts;
  const barePath = bare.length ? `/${bare.join("/")}` : "";

  const qs = searchParams.toString();

  const hrefFor = (loc: PublicLocale): string => {
    const path = localizeHref(barePath || "/", loc, primary);
    return qs ? `${path}?${qs}` : path;
  };

  return (
    <nav className="site-lang" aria-label={publicT(current, "lang.label")}>
      {locales.map((loc) => {
        const active = loc === current;
        return (
          <a
            key={loc}
            href={hrefFor(loc)}
            className={`btn btn-ghost btn-sm${active ? " active" : ""}`}
            aria-current={active ? "true" : undefined}
            hrefLang={publicHtmlLang(loc)}
            dir={publicDir(loc)}
            lang={publicHtmlLang(loc)}
          >
            {publicT(loc, loc === "en" ? "lang.en" : "lang.fa")}
          </a>
        );
      })}
    </nav>
  );
}
