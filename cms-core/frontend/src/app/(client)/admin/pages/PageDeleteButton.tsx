"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { ConfirmDialog } from "@/components/ui/Overlays";

export function PageDeleteButton({ pageId, title }: { pageId: number; title: string }) {
  const toast = useToast();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const deleteInFlight = useRef(false);

  const remove = async () => {
    if (deleteInFlight.current) return;
    deleteInFlight.current = true;
    setDeleting(true);
    try {
      await authed(`/v1/admin/pages/${pageId}`, { method: "DELETE" });
      toast("صفحه به سطل زباله منتقل شد.", "ok");
      setConfirming(false);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "انتقال صفحه به سطل زباله ناموفق بود.", "err");
    } finally {
      deleteInFlight.current = false;
      setDeleting(false);
    }
  };

  return (
    <>
      <button
        type="button"
        className="btn btn-ghost btn-sm"
        onClick={(e) => {
          e.preventDefault();
          e.stopPropagation();
          setConfirming(true);
        }}
        disabled={deleting}
        aria-label={`انتقال صفحه ${title} به سطل زباله`}
        title="انتقال به سطل زباله"
      >
        <svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M4 7h16" />
          <path d="M10 11v6" />
          <path d="M14 11v6" />
          <path d="m6 7 1 13h10l1-13" />
          <path d="M9 7V4h6v3" />
        </svg>
        <span>انتقال به سطل زباله</span>
      </button>
      {confirming ? (
        <ConfirmDialog
          title="انتقال به سطل زباله"
          text="آیا از انتقال این صفحه به سطل زباله مطمئن هستید؟ می‌توانید بعداً آن را بازگردانید."
          confirmLabel="انتقال به سطل زباله"
          onConfirm={() => void remove()}
          onCancel={() => { if (!deleting) setConfirming(false); }}
        />
      ) : null}
    </>
  );
}
