"use client";
import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useLang } from "@/lib/i18n";
import type { MenuGroup } from "@/lib/menu";

/** سایدبار F1: رجیستری منو، جمع‌شونده، گروه «مرکزی» بنفش. */
export function Sidebar({
  groups, brand, collapsed, user,
}: {
  groups: MenuGroup[]; brand: { name: string; sub: string; logoUrl?: string | null }; collapsed: boolean;
  user?: { name: string; avatarUrl: string | null } | null;
}) {
  const path = usePathname();
  const { t } = useLang();
  const [logoBroken, setLogoBroken] = useState(false);
  const showLogo = !!brand.logoUrl && !logoBroken;
  // I1-b — به‌جای «م» و «مدیر»ِ هاردکد، یک نامِ ترجمه‌شده که حرفِ اولش آواتار
  // می‌شود. یعنی یک رشته به‌جای دو، و در انگلیسی «M» درمی‌آید نه «م».
  const who = user?.name || t("shell.managerFallback");
  return (
    <aside className="sidebar" aria-label={t("shell.mainNav")}>
      <div className="brand">
        {showLogo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={brand.logoUrl as string}
            alt={t("shell.brandLogoAlt", { name: brand.sub })}
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
            {g.title ? <div className="nav-group">{g.title}</div> : null}
            {g.items.map((it) => {
              const active = path === it.href;
              return (
                <div key={it.key}>
                  <Link href={it.href} className={`nav-item${active ? " active" : ""}`} aria-current={active ? "page" : undefined}>
                    <span aria-hidden>{it.icon}</span><span className="nav-label">{it.label}</span>
                  </Link>
                  {it.children?.length ? (
                    <div className="nav-sub">
                      {it.children.map((c) => {
                        const childActive = path === c.href;
                        return (
                          <Link key={c.key} href={c.href} className={`nav-item nav-sub-item${childActive ? " active" : ""}`} aria-current={childActive ? "page" : undefined}>
                            <span aria-hidden>{c.icon}</span><span className="nav-label">{c.label}</span>
                          </Link>
                        );
                      })}
                    </div>
                  ) : null}
                </div>
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
          <span className="brand-mark" style={{ inlineSize: 32, blockSize: 32, fontSize: 14 }}>{who.slice(0, 1)}</span>
        )}
        <span className="u-info" style={{ fontSize: 12.5 }}>{who}</span>
      </div>
    </aside>
  );
}
