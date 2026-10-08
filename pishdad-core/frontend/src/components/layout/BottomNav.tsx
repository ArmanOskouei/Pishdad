"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { authed } from "@/lib/auth";
import type { UiSettings } from "@/lib/api";
import { BOTTOM_NAV_DEFAULTS, type MenuItem } from "@/lib/menu";
import { useLang } from "@/lib/i18n";

export const BOTTOM_NAV_UPDATED_EVENT = "cms-bottom-nav-updated";

function normalizePaths(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null;
  return value.filter((path): path is string => typeof path === "string").slice(0, 6);
}

export function BottomNav({ items }: { items: MenuItem[] }) {
  const path = usePathname();
  const { t } = useLang();
  const [bottomNavPaths, setBottomNavPaths] = useState<string[]>(() => [...BOTTOM_NAV_DEFAULTS]);

  useEffect(() => {
    let cancelled = false;

    const load = () => {
      void authed<UiSettings>("/v1/ui-settings")
        .then((settings) => {
          if (cancelled) return;
          const storedPaths = normalizePaths(settings.bottom_nav);
          if (storedPaths !== null) setBottomNavPaths(storedPaths);
        })
        .catch(() => undefined);
    };

    const refresh = () => load();
    load();
    window.addEventListener(BOTTOM_NAV_UPDATED_EVENT, refresh);

    return () => {
      cancelled = true;
      window.removeEventListener(BOTTOM_NAV_UPDATED_EVENT, refresh);
    };
  }, []);

  const visibleItems = bottomNavPaths
    .map((href) => items.find((item) => item.href === href))
    .filter((item): item is MenuItem => item !== undefined);

  if (visibleItems.length === 0) return null;

  return (
    <nav className="bottomnav" aria-label={t("shell.bottomNav")}>
      <div className="bn-row">
        {visibleItems.map((it) => (
          <Link
            key={`${it.key}-${it.href}`}
            href={it.href}
            className={`bn-item${path === it.href ? " active" : ""}`}
            title={it.label}
            aria-label={it.label}
            aria-current={path === it.href ? "page" : undefined}
          >
            <span aria-hidden className="bn-icon">{it.icon}</span>
            <span className="bn-label">{it.label}</span>
          </Link>
        ))}
      </div>
    </nav>
  );
}
