"use client";
import { useEffect } from "react";

/**
 * فاوآیکون داینامیک پنل: از settings/site خوانده می‌شود.
 * اگر href نباشد هیچ کاری نمی‌کند (فاوآیکون پیش‌فرض قالب می‌ماند).
 */
export function AdminFavicon({ href }: { href?: string | null }) {
  useEffect(() => {
    if (!href) return;
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
      link = document.createElement("link");
      link.rel = "icon";
      document.head.appendChild(link);
    }
    const prev = link.href;
    link.href = href;
    return () => {
      if (link && link.href === href) link.href = prev;
    };
  }, [href]);
  return null;
}
