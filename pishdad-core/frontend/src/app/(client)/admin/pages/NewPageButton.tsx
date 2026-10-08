"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Overlays";

/** ساخت صفحه جدید (POST واقعی) و پرش به ویرایشگر. */
export function NewPageButton() {
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const router = useRouter();

  const create = async () => {
    if (!title.trim()) { toast("عنوان صفحه الزامی است.", "err"); return; }
    setBusy(true);
    try {
      const page = await authed<{ id: number }>("/v1/admin/pages", { method: "POST", body: { title: title.trim() } });
      toast("صفحه ساخته شد.", "ok");
      router.push(`/admin/pages/${page.id}/edit`);
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت صفحه ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button className="btn btn-primary" onClick={() => setOpen(true)}>＋ صفحه جدید</button>
      {open ? (
        <Modal title="صفحه جدید" onClose={() => setOpen(false)}>
          <div className="field">
            <label>عنوان صفحه</label>
            <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="مثلاً: درباره ما" autoFocus
              onKeyDown={(e) => { if (e.key === "Enter") void create(); }} />
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" onClick={() => setOpen(false)}>انصراف</button>
            <button className="btn btn-primary" onClick={() => void create()} disabled={busy}>{busy ? "در حال ساخت…" : "ساخت و رفتن به ویرایشگر"}</button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
