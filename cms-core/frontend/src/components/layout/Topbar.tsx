"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTheme } from "@/lib/theme";
import { useAuth } from "@/lib/auth";
import { AdminSearch } from "@/components/search/AdminSearch";

/** تاپ‌بار F1+F2: breadcrumb، جستجو، سوییچ پنل مشتری↔مرکزی (۱.۱)، سوییچ تم، اعلان، آواتار، خروج. */
export function Topbar({
  crumbs, onMenu, alertCount = 0, user,
}: {
  crumbs: string[]; onMenu: () => void; alertCount?: number;
  user?: { name: string; avatarUrl: string | null } | null;
}) {
  const { theme, toggleMode } = useTheme();
  const { logout } = useAuth();
  const path = usePathname();
  const inCentral = path.startsWith("/central");
  return (
    <header className="topbar">
      <button className="icon-btn topbar-menu" onClick={onMenu} aria-label="باز کردن منو">☰</button>
      <nav className="crumbs" aria-label="مسیر">
        {crumbs.map((c, i) => (
          <span key={i} style={{ display: "contents" }}>
            {i > 0 ? <span aria-hidden>/</span> : null}
            {i === crumbs.length - 1 ? <b>{c}</b> : <span className="crumb-root">{c}</span>}
          </span>
        ))}
      </nav>
      <div className="topbar-search">
        <AdminSearch />
      </div>
      <div className="topbar-actions">
        <div className="seg panel-switch" role="group" aria-label="سوییچ پنل">
          <Link href="/admin/dashboard" className={!inCentral ? "on" : ""} aria-current={!inCentral ? "page" : undefined}>مشتری</Link>
          <Link href="/central" className={inCentral ? "on" : ""} aria-current={inCentral ? "page" : undefined}>مرکزی</Link>
        </div>
        <Link href="/" className="topbar-action topbar-site-action" aria-label="مشاهده سایت" title="مشاهده سایت">
          <svg className="topbar-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z" />
          </svg>
          <span className="topbar-action-label">مشاهده سایت</span>
        </Link>
        <button className="icon-btn" onClick={toggleMode} aria-label="سوییچ روشن/تیره">
          {theme.mode === "dark" ? "☀" : "☾"}
        </button>
        <button className="icon-btn" aria-label={`اعلان‌ها${alertCount ? ` (${alertCount} خوانده‌نشده)` : ""}`}>
          🔔{alertCount > 0 ? <span className="dot" /> : null}
        </button>
        <Link href="/admin/profile" className="icon-btn" aria-label={`پروفایل ${user?.name ?? ""}`} title={user?.name ?? "پروفایل"}>
          {user?.avatarUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={user.avatarUrl} alt="" style={{ inlineSize: 26, blockSize: 26, borderRadius: "50%", objectFit: "cover" }} />
          ) : (
            <span aria-hidden>{(user?.name ?? "م").slice(0, 1)}</span>
          )}
        </Link>
        <button
          type="button"
          className="topbar-action topbar-logout"
          aria-label="خروج از حساب"
          title="خروج از حساب"
          onClick={async () => { await logout(); window.location.href = "/login"; }}
        >
          <svg className="topbar-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="m16 17 5-5-5-5M21 12H9" />
          </svg>
          <span className="topbar-action-label">خروج</span>
        </button>
      </div>
    </header>
  );
}
