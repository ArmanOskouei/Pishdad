"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { publicT, type PublicLocale, type PublicMessageKey } from "@/lib/i18n/public";
import { accessibilityPathFor } from "@/lib/accessibility-statement";
import { acceptCookieConsent, hasCookieConsent, privacyPathFor } from "@/lib/cookie-consent";

/**
 * WF-M20 — بنر «فقط کوکی ضروری».
 *
 * در نخستین بازدید نشان داده می‌شود و با «پذیرش» در `localStorage` ثبت می‌شود
 * (بدون کوکی و بدون ردیابی). چون وضعیت از `localStorage` می‌آید، تعیینِ نمایش
 * فقط در `useEffect` انجام می‌شود تا ناهماهنگیِ hydration پیش نیاید؛ هیچ
 * `setState` در بدنهٔ render نیست.
 */
export function CookieConsentBanner({ locale = "fa" }: { locale?: PublicLocale }) {
  const pathname = usePathname();
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    setVisible(!hasCookieConsent(window.localStorage));
  }, []);

  if (!visible) return null;

  const t = (k: PublicMessageKey) => publicT(locale, k);

  const accept = () => {
    acceptCookieConsent(window.localStorage);
    setVisible(false);
  };

  return (
    <section
      role="region"
      aria-label={t("cookie.aria")}
      style={{
        position: "fixed",
        insetInline: 16,
        insetBlockEnd: 16,
        zIndex: 60,
        maxInlineSize: 640,
        marginInline: "auto",
        display: "flex",
        flexWrap: "wrap",
        gap: 12,
        alignItems: "center",
        justifyContent: "space-between",
        padding: "14px 16px",
        background: "var(--surface, #fff)",
        color: "var(--text, #111)",
        border: "1px solid var(--border, #ddd)",
        borderRadius: 12,
        boxShadow: "0 10px 30px rgba(0,0,0,.12)",
        fontSize: 13.5,
        lineHeight: 1.9,
      }}
    >
      <p style={{ margin: 0, flex: "1 1 260px" }}>{t("cookie.message")}</p>
      <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
        <a href={privacyPathFor(pathname ?? "/")} style={{ color: "var(--primary, #2563eb)" }}>
          {t("cookie.privacyLink")}
        </a>
        {/* WF-M17 — بیانیهٔ دسترس‌پذیری کنار لینکِ حریم خصوصی (همان ردیفِ
            لینک‌های قانونیِ کروم عمومی؛ فوتر/منو داده‌محور است و لینکِ ثابت
            ندارد). */}
        <a href={accessibilityPathFor(pathname ?? "/")} style={{ color: "var(--primary, #2563eb)" }}>
          {t("accessibility.link")}
        </a>
        <button type="button" className="btn btn-primary btn-sm" onClick={accept}>
          {t("cookie.accept")}
        </button>
      </div>
    </section>
  );
}
