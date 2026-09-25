"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";

/**
 * قفل تعلیق پنل مشتری (۱.۱۵): وقتی اشتراک suspended است، همه صفحات به‌جز
 * «اشتراک من» قفل‌اند — بنر + لینک پرداخت به‌جای محتوا.
 */
export function SuspensionGuard({ locked, children }: { locked: boolean; children: React.ReactNode }) {
  const path = usePathname();
  const isPayPage = path === "/admin/subscription" || path.startsWith("/admin/subscription/");
  if (locked && !isPayPage) {
    return (
      <div>
        <div className="lock-banner" role="alert">
          <span aria-hidden>⛔</span>
          <span>اشتراک شما معلق است — پنل تا پرداخت قفل است.</span>
          <Link className="btn btn-primary btn-sm" href="/admin/subscription" style={{ marginInlineStart: "auto" }}>
            رفتن به پرداخت
          </Link>
        </div>
        <div className="card card-pad">
          <p style={{ color: "var(--text-muted)", fontSize: 13 }}>برای ادامه کار ابتدا فاکتور معوق را پرداخت کنید.</p>
        </div>
      </div>
    );
  }
  return <>{children}</>;
}
