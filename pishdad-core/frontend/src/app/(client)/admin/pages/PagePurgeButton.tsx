"use client";

import { useRef, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

export function PagePurgeButton({ slug }: { slug: string }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);

  const purge = async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    try {
      await authed("/v1/admin/cache/purge-page", { method: "POST", body: { slug } });
      toast("کش این صفحه پاک شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "پاک‌سازی کش صفحه ناموفق بود.", "err");
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      className="btn btn-ghost btn-sm"
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        void purge();
      }}
      disabled={busy}
      aria-label={`پاک‌سازی کش صفحه ${slug}`}
      title="پاک‌سازی کش این صفحه"
    >
      <svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M3 12a9 9 0 1 0 3-6.7" />
        <path d="M3 4v5h5" />
      </svg>
      <span>{busy ? "در حال پاک‌سازی…" : "پاک‌سازی کش"}</span>
    </button>
  );
}
