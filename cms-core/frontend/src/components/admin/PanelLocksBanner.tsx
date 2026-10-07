"use client";
import { useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { Alert } from "@/components/ui/primitives";
import { summarizeLocks, type LockSummary } from "@/lib/panel-locks";

/**
 * بنر قفل‌های پنل، داده‌محور.
 *
 * فقط یک API موجود خوانده می‌شود (`/v1/admin/plugins`) — هیچ endpoint تازه‌ای
 * اختراع نشده (بک‌اند مالک آن است).
 *
 * `dev_mode` endpoint ندارد، پس به‌جای ادعای «خاموش»، «نامشخص» نشان داده می‌شود.
 *
 * قاعدهٔ رندر AGENTS.md: هیچ fetch/setState در بدنهٔ render — همه در useEffect.
 */
export function PanelLocksBanner() {
  const [locks, setLocks] = useState<LockSummary | null>(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      const raw: unknown = await authed<unknown>("/v1/admin/plugins?per_page=50").catch(() => null);
      if (!alive) return;
      // `authed` یک سطح unwrap می‌کند، ولی شکل صفحه‌بندی تضمینی نیست.
      const inner = Array.isArray(raw) ? raw : (raw as { data?: unknown } | null)?.data;
      setLocks(summarizeLocks(Array.isArray(inner) ? inner : null));
    })().catch(() => {
      if (alive) setLocks(null);
    });
    return () => {
      alive = false;
    };
  }, []);

  if (!locks || !locks.hasLock) return null;

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 8, marginBlockEnd: 12 }}>
      {locks.pendingReview > 0 ? (
        <Alert tone="amber">
          {locks.pendingReview === 1 ? "یک پلاگین" : `${locks.pendingReview} پلاگین`} در انتظار بازبینی است و
          فعال‌سازی‌اش قفل است.
        </Alert>
      ) : null}
      {locks.unsignedActive > 0 ? (
        <Alert tone="red">یک افزونهٔ فعال امضای معتبر ندارد — سلامت نصب را بررسی کنید.</Alert>
      ) : null}
    </div>
  );
}