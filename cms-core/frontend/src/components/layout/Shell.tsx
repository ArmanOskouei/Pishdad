"use client";
import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { Sidebar } from "./Sidebar";
import { Topbar } from "./Topbar";
import { BottomNav } from "./BottomNav";
import type { MenuGroup } from "@/lib/menu";
import type { ProfileData } from "@/lib/domain";
import { crumbsForPath } from "@/lib/crumbs";

const DRAWER_BREAKPOINT = 1100;

// K6.9 — منطق crumb از این فایل به `lib/crumbs.ts` منتقل شد تا قابل تست باشد.
// تا قبل از آن، این تابع — که تمام breadcrumb پنل را می‌ساخت — هیچ تستی نداشت و
// هر تغییر منوی ادمین می‌توانست مسیر ناوبری را بی‌سروصدا خراب کند.

/** شل مشترک F1: سایدبار + تاپ‌بار + BottomNav + Drawer موبایل. */
export function Shell({
  groups, brand, crumbs, children, suspended, user, profile,
}: {
  groups: MenuGroup[]; brand: { name: string; sub: string; logoUrl?: string | null }; crumbs: string[];
  children: React.ReactNode; suspended?: boolean;
  user?: { name: string; avatarUrl: string | null } | null;
  // K6.2 — برای دروازهٔ پرمیشن ابزارهای افزونه در هدر لازم است.
  profile?: ProfileData | null;
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
          crumbs={activeCrumbs} user={user} profile={profile}
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
