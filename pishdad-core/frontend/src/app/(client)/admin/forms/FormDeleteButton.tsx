"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { ConfirmDialog } from "@/components/ui/Overlays";

export function FormDeleteButton({ formId, name, redirect = false }: { formId: number; name: string; redirect?: boolean }) {
  const toast = useToast();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const inFlight = useRef(false);

  const remove = async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    setDeleting(true);
    try {
      await authed(`/v1/admin/forms/${formId}`, { method: "DELETE" });
      toast("فرم حذف شد.", "ok");
      setConfirming(false);
      if (redirect) router.push("/admin/forms");
      else router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف فرم ناموفق بود.", "err");
    } finally {
      inFlight.current = false;
      setDeleting(false);
    }
  };

  return (
    <>
      <button
        type="button"
        className="btn btn-ghost btn-sm"
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setConfirming(true); }}
        disabled={deleting}
        aria-label={`حذف فرم ${name}`}
      >
        حذف
      </button>
      {confirming ? (
        <ConfirmDialog
          title="حذف فرم"
          text="با حذف فرم، همهٔ پاسخ‌های ثبت‌شدهٔ آن هم حذف می‌شوند. آیا مطمئن هستید؟"
          confirmLabel="حذف فرم"
          onConfirm={() => void remove()}
          onCancel={() => { if (!deleting) setConfirming(false); }}
        />
      ) : null}
    </>
  );
}
