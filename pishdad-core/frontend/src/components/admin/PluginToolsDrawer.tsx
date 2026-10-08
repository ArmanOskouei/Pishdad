"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Drawer } from "@/components/ui/Overlays";
import { Alert, EmptyState, Skeleton } from "@/components/ui/primitives";
import { authed } from "@/lib/auth";
import type { ProfileData } from "@/lib/domain";

/**
 * K6.2 — دکمهٔ «ابزارهای پلاگین» در هدر + drawer.
 *
 * ## چرا drawer سراسری و نه آیکون جدا برای هر افزونه
 *
 * اگر هر افزونه آیکون خودش را در `Topbar` می‌گذاشت، `Topbar` (که مال هسته است)
 * به تعداد افزونه‌های نصب‌شده رشد می‌کرد و هر افزونه به layout هسته دست می‌زد.
 * یک زنگِ واحد این را حل می‌کند و افزونه فقط **داده** می‌دهد.
 *
 * ## چرا `href` از بیرون نمی‌آید
 *
 * قرارداد این نقطه `no_href` دارد و دلیلش امنیتی است: `href` آزاد یعنی هر
 * افزونه می‌تواند به هر مسیری لینک بدهد، حتی مسیری که ثبت نکرده. پس افزونه
 * فقط `notification_id` می‌دهد و **اینجا** به مسیر تبدیل می‌شود — ولی فقط اگر
 * آن مسیر واقعاً در رجیستری صفحه‌ها باشد.
 */

type Tool = {
  slug: string;
  key: string;
  title_fa: string;
  icon?: string | null;
  description?: string | null;
  notification_id?: number | null;
  permission?: string | null;
};

type ToolsResponse = { tools?: Tool[] };

/** مسیرهای ثبت‌شده — تنها جایی که `notification_id` به URL تبدیل می‌شود. */
type PagesResponse = { pages?: { slug: string; path: string }[] };

function hrefFor(tool: Tool, pages: { slug: string; path: string }[]): string | null {
  if (!tool.notification_id) return null;
  const page = pages.find((p) => p.slug === tool.slug);
  // ثبت‌نشده ⇒ ابزار بی‌مقصد. به‌جای لینک شکسته، خودِ کارت نمایش داده می‌شود
  // ولی قابل کلیک نیست — چون «کار کرد ولی به جایی نمی‌رسد» همان چیزی است که
  // کل این نقطه می‌خواست از آن جلوگیری کند.
  return page ? `${page.path}/${tool.notification_id}` : null;
}

export function PluginToolsButton({ profile }: { profile: ProfileData | null }) {
  const [open, setOpen] = useState(false);
  const [tools, setTools] = useState<Tool[] | null>(null);
  const [pages, setPages] = useState<{ slug: string; path: string }[]>([]);
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();

  // بارگذاری در `useEffect`، نه شرط در بدنهٔ رندر — قانون پروژه برای جلوگیری
  // از React #301.
  //
  // عمداً وقتی `open` می‌شود دوباره نمی‌خوانیم: ابزارها با هر بار باز شدن
  // نباید شبکه را صدا بزنند مگر کاربر تازه‌سازی بخواهد.
  useEffect(() => {
    if (!open) return;

    let cancelled = false;

    (async () => {
      try {
        const [t, p] = await Promise.all([
          authed<ToolsResponse>("/v1/admin/plugins/tools"),
          authed<PagesResponse>("/v1/admin/plugins/pages"),
        ]);
        if (cancelled) return;
        setTools(Array.isArray(t?.tools) ? t.tools : []);
        setPages(Array.isArray(p?.pages) ? p.pages : []);
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "بارگذاری ابزارها انجام نشد.");
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [open]);

  const close = useCallback(() => setOpen(false), []);

  // ابزارهایی که `permission` دارند با دروازهٔ فرانت فیلتر می‌شوند (fail-closed).
  const granted = new Set(profile?.permissions ?? []);
  const visible = (tools ?? []).filter((t) => !t.permission || granted.has(t.permission));

  return (
    <>
      <button
        type="button"
        className="icon-btn"
        onClick={() => setOpen(true)}
        aria-label="ابزارهای پلاگین"
        title="ابزارهای پلاگین"
      >
        <span aria-hidden>⚒</span>
      </button>

      {open ? (
        <Drawer title="ابزارهای پلاگین" onClose={close}>
          {error ? (
            <div style={{ padding: 16 }}>
              <Alert tone="amber">{error}</Alert>
            </div>
          ) : null}

          {!error && tools === null ? (
            <div style={{ padding: 16 }}>
              <Skeleton />
            </div>
          ) : null}

          {!error && tools !== null && visible.length === 0 ? (
            <EmptyState
              title="ابزاری ثبت نشده"
              hint="افزونه‌ای که ابزار اعلام کند اینجا نمایش داده می‌شود."
            />
          ) : null}

          <div className="drawer-body" style={{ display: "grid", gap: 10, padding: 12 }}>
            {visible.map((tool) => {
              const href = hrefFor(tool, pages);
              const body = (
                <>
                  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                    {tool.icon ? <span aria-hidden>{tool.icon}</span> : null}
                    <b>{tool.title_fa}</b>
                  </div>
                  {tool.description ? (
                    <p className="muted" style={{ margin: "4px 0 0" }}>
                      {tool.description}
                    </p>
                  ) : null}
                  {!href ? (
                    <p className="muted" style={{ margin: "4px 0 0", fontSize: 12 }}>
                      {tool.notification_id
                        ? "صفحه‌ای برای این ابزار ثبت نشده."
                        : "بدون مقصد."}
                    </p>
                  ) : null}
                </>
              );

              // کارت بدون مقصد، `div` است نه دکمه — تا کاربر کلیک نکند و
              // منتظر نماند چیزی باز می‌شود که باز نمی‌شود.
              return href ? (
                <button
                  key={`${tool.slug}:${tool.key}`}
                  className="card"
                  style={{ textAlign: "start", cursor: "pointer" }}
                  onClick={() => {
                    close();
                    router.push(href);
                  }}
                >
                  {body}
                </button>
              ) : (
                <div key={`${tool.slug}:${tool.key}`} className="card" aria-disabled>
                  {body}
                </div>
              );
            })}
          </div>
        </Drawer>
      ) : null}
    </>
  );
}
