import Link from "next/link";
import { Badge } from "./primitives";

/** تب‌های لینک‌محور (حالت در URL — سازگار با Server Components). */
export function Tabs({
  tabs, active,
}: {
  tabs: Array<{ key: string; label: string; href: string; badge?: string | number }>;
  active: string;
}) {
  return (
    <nav className="tabs" aria-label="تب‌ها">
      {tabs.map((t) => (
        <Link key={t.key} href={t.href} className={t.key === active ? "on" : ""} aria-current={t.key === active ? "page" : undefined}>
          {t.label}
          {t.badge !== undefined && t.badge !== "" ? <Badge tone="gray">{t.badge}</Badge> : null}
        </Link>
      ))}
    </nav>
  );
}
