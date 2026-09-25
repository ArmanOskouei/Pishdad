"use client";
import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { Sidebar } from "./Sidebar";
import { Topbar } from "./Topbar";
import { BottomNav } from "./BottomNav";
import type { MenuGroup } from "@/lib/menu";

const DRAWER_BREAKPOINT = 1100;

/** شل مشترک F1: سایدبار + تاپ‌بار + BottomNav + Drawer موبایل. */
export function Shell({
  groups, brand, crumbs, children, suspended, alertCount, user,
}: {
  groups: MenuGroup[]; brand: { name: string; sub: string; logoUrl?: string | null }; crumbs: string[];
  children: React.ReactNode; suspended?: boolean; alertCount?: number;
  user?: { name: string; avatarUrl: string | null } | null;
}) {
  const pathname = usePathname();
  const [collapsed, setCollapsed] = useState(false);
  const [drawer, setDrawer] = useState(false);
  const flat = groups.flatMap((g) => g.items);

  useEffect(() => {
    setDrawer(false);
  }, [pathname]);

  return (
    <div className={`app${collapsed ? " collapsed" : ""}${drawer ? " drawer-open" : ""}`}>
      <Sidebar groups={groups} brand={brand} suspended={suspended} collapsed={collapsed} user={user} />
      <div className="main">
        <Topbar
          crumbs={crumbs} alertCount={alertCount} user={user}
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
