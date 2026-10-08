"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";

export function PageTrashActions({ pageId, title }: { pageId: number; title: string }) {
  const toast = useToast();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [restoring, setRestoring] = useState(false);
  const restoreInFlight = useRef(false);

  const restore = async () => {
    if (restoreInFlight.current) return;
    restoreInFlight.current = true;
    setRestoring(true);
    try {
      await authed(`/v1/admin/pages/${pageId}/restore`, { method: "POST" });
      toast("صفحه بازگردانده شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "بازگردانی صفحه ناموفق بود.", "err");
    } finally {
      restoreInFlight.current = false;
      setRestoring(false);
    }
  };

  return (
    <>
      <button
        type="button"
        className="btn btn-primary btn-sm"
        onClick={() => void restore()}
        disabled={restoring}
        aria-label={`بازگردانی صفحه ${title}`}
      >
        {restoring ? "در حال بازگردانی…" : "بازگردانی"}
      </button>
      <button
        type="button"
        className="btn btn-ghost btn-sm"
        onClick={() => setConfirming(true)}
        aria-label={`حذف دائم صفحه ${title}`}
      >
        حذف دائم…
      </button>
      <ConfirmStepper
        open={confirming}
        title="حذف دائم صفحه"
        description={`«${title}» برای همیشه حذف می‌شود و قابل بازگشت نیست.`}
        requirePhrase="حذف"
        confirmLabel="بستن"
        onConfirm={async () => {
          await authed(`/v1/admin/pages/${pageId}/force`, { method: "DELETE" });
          setConfirming(false);
          router.refresh();
          return "صفحه برای همیشه حذف شد.";
        }}
        onClose={() => setConfirming(false)}
      />
    </>
  );
}
