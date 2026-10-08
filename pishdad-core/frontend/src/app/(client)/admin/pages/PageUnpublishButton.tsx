"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";

/** WF-H5 — اکشن مستقلِ «لغو انتشار» برای ردیف‌های منتشرشده در لیست صفحات. */
export function PageUnpublishButton({ pageId, title }: { pageId: number; title: string }) {
  const toast = useToast();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [unpublishing, setUnpublishing] = useState(false);
  const inFlight = useRef(false);

  const unpublish = async () => {
    if (inFlight.current) return "عملیات در حال انجام است.";
    inFlight.current = true;
    setUnpublishing(true);
    try {
      await authed(`/v1/admin/pages/${pageId}/unpublish`, { method: "POST" });
      toast("انتشار صفحه لغو شد.", "ok");
      setConfirming(false);
      router.refresh();
      return "انتشار صفحه لغو شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "لغو انتشار ناموفق بود.";
      toast(message, "err");
      throw new Error(message);
    } finally {
      inFlight.current = false;
      setUnpublishing(false);
    }
  };

  return (
    <>
      <button
        type="button"
        className="btn btn-ghost btn-sm"
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setConfirming(true); }}
        disabled={unpublishing}
        aria-label={`لغو انتشار صفحه ${title}`}
        title="لغو انتشار (برگشت به پیش‌نویس)"
      >
        <svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M3 3l18 18" />
          <path d="M10.6 5.1A9.9 9.9 0 0 1 12 5c5 0 9 4.5 10 7a13.2 13.2 0 0 1-3.2 4.2" />
          <path d="M6.2 6.2A13.4 13.4 0 0 0 2 12c1 2.5 5 7 10 7a9.7 9.7 0 0 0 4.3-1" />
          <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        </svg>
        <span>{unpublishing ? "…" : "لغو انتشار"}</span>
      </button>
      <ConfirmStepper
        open={confirming}
        title="لغو انتشار صفحه؟"
        description={`«${title}» از دسترس عموم خارج می‌شود اما حذف نمی‌شود؛ تاریخ و نسخهٔ انتشار برای بازگردانی حفظ می‌مانند.`}
        confirmLabel="لغو انتشار"
        busyLabel="در حال لغو انتشار…"
        onConfirm={unpublish}
        onClose={() => { if (!unpublishing) setConfirming(false); }}
      />
    </>
  );
}
