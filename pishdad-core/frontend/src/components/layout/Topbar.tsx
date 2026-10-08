"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTheme } from "@/lib/theme";
import { useAuth } from "@/lib/auth";
import { LOGIN_PATH } from "@/lib/login-path";
import { useLang } from "@/lib/i18n";
import { AdminSearch } from "@/components/search/AdminSearch";
import { PluginToolsButton } from "@/components/admin/PluginToolsDrawer";
import { NotificationBell } from "@/components/admin/NotificationBell";
import type { ProfileData } from "@/lib/domain";
import type { MenuGroup, MenuItem } from "@/lib/menu";

/** تاپ‌بار F1+F2: breadcrumb، جستجو، سوییچ پنل (۱.۱)، سوییچ تم، آواتار، خروج. */
export function Topbar({
  crumbs, onMenu, user, profile, groups = [],
}: {
  crumbs: string[]; onMenu: () => void;
  user?: { name: string; avatarUrl: string | null } | null;
  // K6.2 — فقط برای دروازهٔ پرمیشن ابزارها. `user` عمداً این را ندارد چون
  // فقط نام و آواتار است و پروژه هر چیز غیرضروری را در props پخش نمی‌کند.
  profile?: ProfileData | null;
  // L-B12 — سوییچ پنل دیگر «مشتری ↔ مرکزی» نیست، چون هسته نباید بداند چه
  // افزونه‌ای وجود دارد. به‌جایش هر گروهِ بیرون از `/admin` یک سوییچ می‌شود
  // و برچسبش از همان منو می‌آید. در نصب عمومی که افزونه‌ای نیست، سوییچ اصلاً
  // رندر نمی‌شود.
  groups?: MenuGroup[];
}) {
  const { theme, toggleMode } = useTheme();
  const { logout } = useAuth();
  const { t } = useLang();
  const path = usePathname();

  // پنل‌های بیرونی: اولین آیتم هر گروهی که مسیرش `/admin` نیست.
  const altPanels = groups
    .map((g) => g.items.find((it) => !it.href.startsWith("/admin")))
    .filter((it): it is MenuItem => Boolean(it));
  const customerOn = !altPanels.some((it) => path.startsWith(it.href));
  // I1-b — آواتارِ بدون تصویر: حرفِ اولِ نام، وگرنه حرفِ اولِ «مدیر»/«Manager».
  const who = user?.name || t("shell.managerFallback");
  return (
    <header className="topbar">
      <button className="icon-btn topbar-menu" onClick={onMenu} aria-label={t("shell.openMenu")}>☰</button>
      <nav className="crumbs" aria-label={t("shell.path")}>
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
        {altPanels.length > 0 ? (
          <div className="seg panel-switch" role="group" aria-label={t("shell.panelSwitch")}>
            <Link
              href="/admin/dashboard"
              className={customerOn ? "on" : ""}
              aria-current={customerOn ? "page" : undefined}
            >
              {t("shell.customerPanel")}
            </Link>
            {altPanels.map((it) => {
              const on = path.startsWith(it.href);
              return (
                <Link
                  key={it.key}
                  href={it.href}
                  className={on ? "on" : ""}
                  aria-current={on ? "page" : undefined}
                >
                  {it.label}
                </Link>
              );
            })}
          </div>
        ) : null}
        <Link href="/" className="topbar-action topbar-site-action" aria-label={t("shell.viewSite")} title={t("shell.viewSite")}>
          <svg className="topbar-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z" />
          </svg>
          <span className="topbar-action-label">{t("shell.viewSite")}</span>
        </Link>
        <button className="icon-btn" onClick={toggleMode} aria-label={t("shell.toggleTheme")}>
          {theme.mode === "dark" ? "☀" : "☾"}
        </button>
        {/* K6.2 — دکمهٔ ابزارها. یک دکمهٔ واحد، نه یکی به‌ازای هر افزونه:
            وگرنه `Topbar` به تعداد افزونه‌های نصب‌شده رشد می‌کرد و هر افزونه
            به layout هسته دست می‌زد. افزونه فقط داده می‌دهد. */}
        <PluginToolsButton profile={profile ?? null} />
        {/* K5.8 — زنگ اعلان. کنار ابزارها، و هر دو از راه داده کار می‌کنند:
            افزونه به layout هسته دست نمی‌زند. */}
        <NotificationBell />
        <Link href="/admin/profile" className="icon-btn" aria-label={`${t("shell.profile")} ${user?.name ?? ""}`} title={user?.name ?? t("shell.profile")}>
          {user?.avatarUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={user.avatarUrl} alt="" style={{ inlineSize: 26, blockSize: 26, borderRadius: "50%", objectFit: "cover" }} />
          ) : (
            <span aria-hidden>{who.slice(0, 1)}</span>
          )}
        </Link>
        <button
          type="button"
          className="topbar-action topbar-logout"
          aria-label={t("shell.logoutConfirm")}
          title={t("shell.logoutConfirm")}
          onClick={async () => { await logout(); window.location.href = LOGIN_PATH; }}
        >
          <svg className="topbar-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path d="m16 17 5-5-5-5M21 12H9" />
          </svg>
          <span className="topbar-action-label">{t("shell.logout")}</span>
        </button>
      </div>
    </header>
  );
}
