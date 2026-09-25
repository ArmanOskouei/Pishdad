import Link from "next/link";
import { Badge } from "@/components/ui/primitives";
import type { SiteStatus } from "@/lib/domain";

const UPSTREAM =
  process.env.INTERNAL_API_URL ??
  process.env.NEXT_PUBLIC_API_URL ??
  "http://localhost:8080/api";

/**
 * صفحه تعلیق عمومی ۱.۱۶ (سمت سایت — بدون نیاز به لاگین).
 * پیام برند از GET /api/v1/site/status می‌آید؛ هیچ لینک /admin ندارد.
 */
export default async function SuspendedPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const siteId = sp.site_id ?? "";
  let status: SiteStatus | null = null;
  let error: string | null = null;

  if (siteId) {
    try {
      const res = await fetch(`${UPSTREAM}/v1/site/status?site_id=${encodeURIComponent(siteId)}`, { headers: { Accept: "application/json" }, cache: "no-store" });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json?.message ?? "سایتی یافت نشد.");
      status = (json?.data ?? json) as SiteStatus;
    } catch (e) {
      error = e instanceof Error ? e.message : "خطا در بارگذاری وضعیت.";
    }
  }

  return (
    <main style={{ minBlockSize: "100vh", display: "grid", placeItems: "center", padding: 24, background: "var(--bg)", color: "var(--text)" }}>
      <div className="card card-pad" style={{ maxInlineSize: 520, inlineSize: "100%", textAlign: "center" }}>
        <div style={{ fontSize: 44 }} aria-hidden>⛔</div>
        <h1 style={{ fontSize: 19, marginBlock: "8px 4px" }}>سایت موقتاً در دسترس نیست</h1>
        {siteId && status ? (
          <div>
            <Badge tone={status.status === "suspended" ? "red" : "green"}>
              {status.status === "suspended" ? "معلق" : "فعال"}
            </Badge>
            <p style={{ fontSize: 14, color: "var(--text-muted)", marginBlockStart: 10 }}>{status.message}</p>
          </div>
        ) : error ? (
          <p role="alert" style={{ fontSize: 14, color: "var(--danger)" }}>{error}</p>
        ) : (
          <p style={{ fontSize: 14, color: "var(--text-muted)" }}>
            اشتراک هاست این سایت تمام شده است. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.
          </p>
        )}
        {!siteId ? (
          <form method="get" action="/suspended" style={{ display: "flex", gap: 8, marginBlockStart: 14 }}>
            <input name="site_id" className="input" placeholder="شناسه سایت (site_id)" defaultValue="" dir="ltr" style={{ textAlign: "left", flex: 1 }} />
            <button className="btn btn-ghost" type="submit">بررسی وضعیت</button>
          </form>
        ) : (
          <div style={{ marginBlockStart: 14 }}>
            <Link className="btn btn-ghost" href="/suspended">بررسی سایت دیگر</Link>
          </div>
        )}
        <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlockEnd: 0 }}>تماس با پشتیبانی: support@example.ir</p>
      </div>
    </main>
  );
}
