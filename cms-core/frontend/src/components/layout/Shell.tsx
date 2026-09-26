"use client";
import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { Sidebar } from "./Sidebar";
import { Topbar } from "./Topbar";
import { BottomNav } from "./BottomNav";
import type { MenuGroup } from "@/lib/menu";

const DRAWER_BREAKPOINT = 1100;

/** برچسب مسیر از رجیستری منو خوانده می‌شود، نه از URL خام — تنها منبع حقیقت همین `groups` است.
 *  وقتی K6.9 صفحه‌دسکریپتور اضافه شد، فقط همین تابع عوض می‌شود و بقیهٔ شل دست‌نخورده. */
function crumbsForPath(pathname: string, groups: MenuGroup[], root: string[]): string[] {
  let hit: { href: string; label: string; group?: string } | null = null;
  for (const g of groups) {
    for (const item of g.items) {
      if (pathname !== item.href && !pathname.startsWith(`${item.href}/`)) continue;
      if (!hit || item.href.length > hit.href.length) {
        hit = { href: item.href, label: item.label, group: g.title };
      }
    }
  }
  const parts = (hit ? [root, hit.group, hit.label] : [root]).flat().filter((p): p is string => Boolean(p));
  return parts.filter((p, i) => i === 0 || p !== parts[i - 1]);
}

/** شل مشترک F1: سایدبار + تاپ‌بار + BottomNav + Drawer موبایل. */
export function Shell({
  groups, brand, crumbs, children, suspended, user,
}: {
  groups: MenuGroup[]; brand: { name: string; sub: string; logoUrl?: string | null }; crumbs: string[];
  children: React.ReactNode; suspended?: boolean;
  user?: { name: string; avatarUrl: string | null } | null;
}) {
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const flat = groups.flatMap((g) => g.items);
  const activeCrumbs = crumbsForPath(pathname, groups, crumbs);

  useEffect(() => {
    setDrawer(false);
  }, [pathname]);

  return (
    <div className={`app${collapsed ? " collapsed" : ""}${drawer ? " drawer-open" : ""}`}>
      <Sidebar groups={groups} brand={brand} suspended={suspended} collapsed={collapsed} user={user} />
      <div className="main">
        <Topbar
          crumbs={activeCrumbs} user={user}
          onMenu={() => {
            if (window.innerWidth <= DRAWER_BREAKPOINT) setDrawer((d) => !d);
            else setCollapsed((c) => !c);
          }}
        />
        <main className="content">{children}</main>
      </div>
      <BottomNav items={flat} />
      <div className={`scrim${drawer ? " show" : ""}`} onClick={() => setDrawer(false)} />
    </div>
  );
}
