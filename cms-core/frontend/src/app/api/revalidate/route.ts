import { NextRequest, NextResponse } from "next/server";
import { revalidateTag } from "next/cache";
import { createHmac, timingSafeEqual } from "node:crypto";
import { REVALIDATE_LEEWAY, isAllowedLocalTag } from "@/lib/revalidate";
import { effectiveValue } from "@/lib/site-config";

/**
 * Next 16 changed the signature: `revalidateTag(tag)` is gone and the second
 * argument is now a cacheLife profile. `{ expire: 0 }` is the immediate purge,
 * which is what the bare one-argument call did in Next 15.
 *
 * `updateTag(tag)` looks like the tidier replacement but is wrong here: it can
 * only be called from inside a Server Action and throws otherwise. This file is
 * a Route Handler, so the backend's HMAC-signed call into `/api/revalidate`
 * would blow up at runtime while typechecking cleanly.
 */
const EXPIRE_NOW = { expire: 0 };

/**
 * F3 — Route Handler امن revalidate (۱.۵/۴.۲).
 * همان منطق RevalidateSigner بک‌اند (app/Services/RevalidateSigner.php):
 *   signature = HMAC-SHA256("ts|nonce|tag1,tag2,...", REVALIDATE_SECRET)
 * + پنجره تازگی ۳۰۰ ثانیه + nonce یکبارمصرف (حافظه process-local؛ در
 *   استقرار چندنمونه‌ای، Redis/DB لازم است — با cacheHandler روی Redis هم‌خانواده شود).
 *
 * مسیر دوم (local:true): ادمین لاگین‌کرده (نشست معتبر) می‌تواند تگ‌های
 * allowlistشده را باطل کند — برای دکمه «فعال‌سازی قالب» در پنل.
 */

const SECRET = effectiveValue("REVALIDATE_SECRET", process.env.REVALIDATE_SECRET);
// L-B8/F0.7 — سیاستِ allowlist در `lib/revalidate.ts` است (آزمون‌پذیر).
const LEEWAY = REVALIDATE_LEEWAY;

const seenNonces = new Map<string, number>(); // nonce -> expiresAt (ms)

function prune() {
  const now = Date.now();
  for (const [k, exp] of seenNonces) {
    if (exp < now) seenNonces.delete(k);
  }
  if (seenNonces.size > 5000) {
    const first = seenNonces.keys().next().value;
    if (first) seenNonces.delete(first);
  }
}

function expectedSignature(tags: string[], timestamp: number, nonce: string): Buffer {
  const sorted = [...tags].sort();
  const message = `${timestamp}|${nonce}|${sorted.join(",")}`;
  return Buffer.from(createHmac("sha256", SECRET).update(message).digest("hex"), "utf8");
}

async function hasValidSession(req: NextRequest): Promise<boolean> {
  const token = req.cookies.get("auth_token")?.value;
  if (!token) return false;
  const upstream =
    process.env.INTERNAL_API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";
  try {
    const res = await fetch(`${upstream}/v1/admin/profile`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function POST(req: NextRequest) {
  const body = await req.json().catch(() => null);
  const tags = body?.tags;
  if (!Array.isArray(tags) || tags.length === 0 || tags.some((t: unknown) => typeof t !== "string")) {
    return NextResponse.json({ message: "تگ‌ها الزامی‌اند." }, { status: 400 });
  }

  // مسیر ادمین لاگین‌کرده (بدون HMAC، با allowlist تگ)
  if (body?.local === true) {
    if (!(await hasValidSession(req))) {
      return NextResponse.json({ message: "نشست معتبر نیست." }, { status: 401 });
    }
    const allowed = (tags as string[]).filter((t) => isAllowedLocalTag(t));
    for (const t of allowed) revalidateTag(t, EXPIRE_NOW);
    return NextResponse.json({ message: "کش باطل شد.", revalidated: allowed });
  }

  // مسیر امضاشده بک‌اند (HMAC + nonce + timestamp)
  if (!SECRET) {
    return NextResponse.json({ message: "سرور revalidate پیکربندی نشده است." }, { status: 500 });
  }
  const { timestamp, nonce, signature } = body ?? {};
  if (typeof timestamp !== "number" || typeof nonce !== "string" || typeof signature !== "string") {
    return NextResponse.json({ message: "payload نامعتبر است." }, { status: 400 });
  }
  if (Math.abs(Date.now() / 1000 - timestamp) > LEEWAY) {
    return NextResponse.json({ message: "timestamp منقضی است." }, { status: 400 });
  }
  const expected = expectedSignature(tags as string[], timestamp, nonce);
  const got = Buffer.from(signature, "utf8");
  if (got.length !== expected.length || !timingSafeEqual(got, expected)) {
    return NextResponse.json({ message: "امضا معتبر نیست." }, { status: 403 });
  }
  prune();
  if (seenNonces.has(nonce)) {
    return NextResponse.json({ message: "nonce تکراری است." }, { status: 403 });
  }
  seenNonces.set(nonce, Date.now() + LEEWAY * 1000);

  for (const t of tags as string[]) revalidateTag(t, EXPIRE_NOW);
  return NextResponse.json({ message: "کش باطل شد.", revalidated: tags });
}
