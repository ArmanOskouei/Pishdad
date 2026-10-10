import { cookies } from "next/headers";
import { serverOne, serverApi } from "@/lib/server-api";
import { serverApiBase } from "@/lib/server-api-base";
import type { SiteChrome } from "@/lib/site";
import type { LayoutData, WidgetSchema } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { LayoutsClient } from "./LayoutsClient";

/**
 * کروم resolve‌شده (لینک‌های page با title/url زنده) برای پیش‌نمایش زنده.
 * با token کوکی و no-store خوانده می‌شود تا پس از ذخیره، پیش‌نمایش تازه باشد.
 *
 * SEC-6 — پایهٔ API در زمانِ درخواست از `serverApiBase()` خوانده می‌شود
 * (config/runtime.json را می‌بیند)؛ نسخهٔ ماژول‌اسکوپِ قبلی نصب‌کنندهٔ وب را
 * نادیده می‌گرفت. این هم Server Component است، پس ساختنِ Bearer از کوکیِ
 * httpOnly اینجا **درست** است — همان الگوی پروکسی.
 */
async function fetchChromeForAdmin(): Promise<SiteChrome | null> {
  try {
    const token = (await cookies()).get("auth_token")?.value;
    const res = await fetch(`${serverApiBase()}/v1/site/chrome`, {
      headers: {
        Accept: "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      cache: "no-store",
    });
    if (!res.ok) return null;
    const json = await res.json().catch(() => ({}));
    return ((json as { data?: SiteChrome })?.data ?? null) as SiteChrome | null;
  } catch {
    return null;
  }
}

/** هدر/فوتر ۱.۱۱ + تسک ۶ — Widget Area با تب هدر/فوتر + درگ‌اندروپ + تنظیمات SchemaForm + ذخیره + پیش‌نمایش زنده. */
export default async function HeaderFooterPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const tab = sp.tab === "footer" ? "footer" : "header";

  let header: LayoutData | null = null;
  let footer: LayoutData | null = null;
  let schemas: WidgetSchema[] = [];
  let error: string | null = null;
  let chromeBase: SiteChrome | null = null;
  try {
    const [h, f, sj, chrome] = await Promise.all([
      serverOne<LayoutData>(`/v1/admin/layouts/header`),
      serverOne<LayoutData>(`/v1/admin/layouts/footer`),
      serverApi<unknown>(`/v1/admin/widgets/schema`),
      fetchChromeForAdmin(),
    ]);
    header = h;
    footer = f;
    const sd = (sj as { data?: unknown })?.data ?? sj;
    schemas = Array.isArray(sd) ? (sd as WidgetSchema[]) : [];
    chromeBase = chrome;
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری چیدمان.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>هدر و فوتر</h1><p>Widget Area — پالت ویجت + بوم + درگ‌اندروپ</p></div>
      </div>
      {error || !header || !footer ? (
        <Alert tone="red">{error ?? "چیدمان یافت نشد."}</Alert>
      ) : (
        <LayoutsClient initial={{ header, footer }} tab={tab} schemas={schemas} chromeBase={chromeBase} />
      )}
    </div>
  );
}
