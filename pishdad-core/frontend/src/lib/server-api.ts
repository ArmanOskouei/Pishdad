import { cookies } from "next/headers";
import { effectiveValue } from "./site-config.ts";

/**
 * فراخوانی سمت سرور به بک‌اند (برای Server Components لیست‌ها).
 * توکن از کوکی httpOnly خوانده می‌شود؛ داده همیشه زنده (no-store).
 * خروجی خام برمی‌گردد تا هر صفحه با asPaginator/asList نرمال کند.
 */
const UPSTREAM =
  effectiveValue("INTERNAL_API_URL", "") ||
  effectiveValue("PISHDAD_PUBLIC_API_URL", "") ||
  (process.env.NEXT_PUBLIC_API_URL ?? "").trim() ||
  "http://localhost:8080/api";

export class ServerApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export async function serverApi<T = unknown>(path: string): Promise<T> {
  const token = (await cookies()).get("auth_token")?.value;
  const res = await fetch(`${UPSTREAM}${path}`, {
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    cache: "no-store",
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new ServerApiError(
      res.status,
      (json as { message?: string })?.message ?? "خطا در بارگذاری.",
    );
  }
  // عمداً unwrap نمی‌شود: لیست‌ها paginator خام‌اند (asPaginator/asList نرمال می‌کنند).
  return json as T;
}

/** منبع تکی (detail/settings): unwrap استاندارد `{data}`. */
export async function serverOne<T>(path: string): Promise<T> {
  const json = await serverApi<unknown>(path);
  return ((json as { data?: unknown })?.data ?? json) as T;
}

/** ساخت querystring کامل (`?…`) از searchParams صفحه + پارامترهای ثابت. */
export function apiQs(
  sp: Record<string, string | string[] | undefined>,
  allow: string[],
  extra: Record<string, string | number> = {},
): string {
  const q = new URLSearchParams();
  for (const k of allow) {
    const v = sp[k];
    const s = Array.isArray(v) ? v[0] : v;
    if (s !== undefined && s !== "") q.set(k, s);
  }
  for (const [k, v] of Object.entries(extra)) q.set(k, String(v));
  const str = q.toString();
  return str ? `?${str}` : "";
}
