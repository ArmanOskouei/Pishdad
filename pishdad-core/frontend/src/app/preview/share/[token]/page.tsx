import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { fetchSharedPreview, fetchSiteChrome } from "@/lib/site";
import { resolveTheme } from "@/themes/registry";
import type { SitePage } from "@/lib/domain";
import { isPublicLocale, type PublicLocale } from "@/lib/i18n/public";

/**
 * WF-H2 — پیش‌نمایشِ عمومیِ پیش‌نویس با لینکِ اشتراک.
 *
 * این مسیر عمداً از `/preview/[theme]` جداست: آن یکی قالب را پیش‌نمایش می‌دهد،
 * این یکی محتوای یک revisionِ پیش‌نویسِ به‌اشتراک‌گذاشته را. توکن مجوز است و
 * بک‌اند فقط همان نسخه را با `noindex` برمی‌گرداند. هیچ ورودی لازم نیست.
 */
export const dynamic = "force-dynamic";
export const revalidate = 0;

export const metadata: Metadata = {
  title: "پیش‌نمایش پیش‌نویس",
  robots: { index: false, follow: false, nocache: true },
};

type Props = { params: Promise<{ token: string }> };

export default async function SharedPreviewPage({ params }: Props) {
  const { token } = await params;

  const [page, chrome] = await Promise.all([
    fetchSharedPreview(token).catch(() => null),
    fetchSiteChrome().catch(() => null),
  ]);

  if (!page) notFound();

  const locale: PublicLocale = isPublicLocale(chrome?.locale) ? chrome!.locale : "fa";
  const def = resolveTheme(chrome?.theme?.slug ?? null);

  return (
    <div>
      <div className="preview-bar">
        <span>پیش‌نمایش پیش‌نویس — فقط با لینک اشتراک</span>
        <div style={{ flex: 1 }} />
        <Link href="/">سایتِ واقعی</Link>
      </div>
      <def.Component
        page={page as SitePage}
        chrome={chrome}
        schemas={chrome?.blocks}
        locale={locale}
      />
    </div>
  );
}
