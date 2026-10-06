import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { isPublicLocale, type PublicLocale } from "@/lib/i18n/public";
import { parseLocalesHeader, SITE_LOCALES_HEADER } from "@/lib/public-routing";

/**
 * WF-C7 — زبانِ مؤثرِ مسیرِ بلاگ.
 *
 * مثل بقیهٔ مسیرهای عمومی، فقط زبانی سرو می‌شود که میدل‌ور در
 * `x-site-locales` اعلام کرده. نبودِ هدر = میدل‌ور خاموش ⇒ دروازه باز است.
 */
export async function requireLocale(raw: string): Promise<PublicLocale> {
  const locale: PublicLocale = isPublicLocale(raw) ? raw : "fa";
  const h = await headers();
  const list = parseLocalesHeader(h.get(SITE_LOCALES_HEADER));
  if (list.length > 0 && !list.includes(locale)) notFound();
  return locale;
}
