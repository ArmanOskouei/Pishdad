"use client";
import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { MenuGroup } from "@/lib/menu";

/** سایدبار F1: رجیستری منو، جمع‌شونده، گروه «مرکزی» بنفش، حالت تعلیق = قفل بجز اشتراک. */
export function Sidebar({
  groups, brand, suspended, collapsed, user,
}: {
  groups: MenuGroup[]; brand: { name: string; sub: string; logoUrl?: string | null }; suspended?: boolean; collapsed: boolean;
  user?: { name: string; avatarUrl: string | null } | null;
}) {
  const path = usePathname();
  const [logoBroken, setLogoBroken] = useState(false);
  const showLogo = !!brand.logoUrl && !logoBroken;
  return (
    <aside className="sidebar" aria-label="ناوبری اصلی">
      <div className="brand">
        {showLogo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={brand.logoUrl as string}
            alt={`لوگوی ${brand.sub}`}
            className="brand-mark"
            style={{ objectFit: "contain" }}
            onError={() => setLogoBroken(true)}
          />
        ) : (
          <span className="brand-mark" aria-hidden>{brand.name.slice(0, 1)}</span>
        )}
        <span><span className="brand-name">{brand.name}</span><br /><span className="brand-sub">{brand.sub}</span></span>
      </div>
      <nav className="nav">
        {groups.map((g, gi) => (
          <div key={gi}>
            {g.title ? <div className={`nav-group${g.central ? " central" : ""}`}>{g.title}</div> : null}
            {g.items.map((it) => {
              const active = path === it.href;
              const locked = suspended && it.href !== "/admin/subscription";
              if (locked) {
                return (
                  <span key={it.key} className="nav-item locked" title="در حالت تعلیق قفل است — فقط اشتراک">
                    <span aria-hidden>{it.icon}</span><span className="nav-label">{it.label} 🔒</span>
                  </span>
                );
              }
              return (
                <Link key={it.key} href={it.href} className={`nav-item${active ? " active" : ""}`} aria-current={active ? "page" : undefined}>
                  <span aria-hidden>{it.icon}</span><span className="nav-label">{it.label}</span>
                </Link>
              );
            })}
          </div>
        ))}
      </nav>
      <div className="side-user">
        {user?.avatarUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={user.avatarUrl} alt={user.name} style={{ inlineSize: 32, blockSize: 32, borderRadius: "50%", objectFit: "cover" }} />
        ) : (
          <span className="brand-mark" style={{ inlineSize: 32, blockSize: 32, fontSize: 14 }}>{(user?.name ?? "م").slice(0, 1)}</span>
        )}
        <span className="u-info" style={{ fontSize: 12.5 }}>{user?.name ?? "مدیر"}</span>
      </div>
    </aside>
  );
}
