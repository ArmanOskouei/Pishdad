"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { authedEnvelope } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { EmptyState } from "@/components/ui/primitives";

/**
 * WF-M21 — حالت خالیِ لیست‌های پنل + دکمهٔ «ساخت محتوای نمونه».
 *
 * یک درخواست idempotent به `/v1/admin/sample-content` می‌زند، پیام موفقیت/
 * خطا را toast می‌کند و لیست را refresh می‌کند. آیکون SVG درون‌خطی است (بدون
 * CDN). هیچ fetch/setState‌ای در بدنهٔ render نیست — فقط در هندلر.
 */
export function SampleContentEmptyState({
  title,
  hint,
  sampleLabel = "ساخت محتوای نمونه",
}: {
  title: string;
  hint?: string;
  sampleLabel?: string;
}) {
  const toast = useToast();
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  const seed = async () => {
    if (busy) return;
    setBusy(true);
    try {
      const res = await authedEnvelope("/v1/admin/sample-content", { method: "POST" });
      toast(res.message ?? "محتوای نمونه ساخته شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت محتوای نمونه ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <EmptyState
      icon={
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M12 3v18M3 12h18" />
          <path d="M5.6 5.6l12.8 12.8M18.4 5.6L5.6 18.4" opacity="0.35" />
        </svg>
      }
      title={title}
      hint={hint}
      cta={{ label: busy ? "در حال ساخت…" : sampleLabel, onClick: () => void seed(), disabled: busy }}
    />
  );
}
