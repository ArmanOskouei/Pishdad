"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

/**
 * WF-M4 — تکثیر صفحه از ردیفِ لیست.
 *
 * سرور یک پیش‌نویسِ تازه با اسلاگِ `-copy` (یکتا در همان زبان) می‌سازد و بلوک‌ها،
 * متا/سئو و تنظیماتِ ستون‌ها را کپی می‌کند؛ کاربر مستقیم به ویرایشگرِ کپی
 * می‌رود تا همان‌جا تغییرش دهد. ساختنِ صفحهٔ تازه برگشت‌پذیر است (سطل زباله)،
 * پس برخلاف حذف/لغو انتشار تأییدِ دومانه نمی‌خواهد.
 */
export function PageDuplicateButton({ pageId, title }: { pageId: number; title: string }) {
  const toast = useToast();
  const router = useRouter();
  const [duplicating, setDuplicating] = useState(false);
  const inFlight = useRef(false);

  const duplicate = async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    setDuplicating(true);
    try {
      const page = await authed<{ id: number }>(`/v1/admin/pages/${pageId}/duplicate`, { method: "POST" });
      toast("نسخهٔ تکثیرشده ساخته شد.", "ok");
      router.push(`/admin/pages/${page.id}/edit`);
    } catch (e) {
      toast(e instanceof Error ? e.message : "تکثیر صفحه ناموفق بود.", "err");
    } finally {
      inFlight.current = false;
      setDuplicating(false);
    }
  };

  return (
    <button
      type="button"
      className="btn btn-ghost btn-sm"
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        void duplicate();
      }}
      disabled={duplicating}
      aria-label={`تکثیر صفحه ${title}`}
      title="تکثیر صفحه — پیش‌نویس تازه با اسلاگ -copy"
    >
      <svg width={16} height={16} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <rect x="9" y="9" width="11" height="11" rx="2" />
        <path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" />
      </svg>
      <span>{duplicating ? "…" : "تکثیر"}</span>
    </button>
  );
}