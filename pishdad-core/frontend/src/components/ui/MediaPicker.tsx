"use client";
import { useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "./Toast";
import type { MediaItem } from "@/lib/domain";
import { mediaUrl } from "@/lib/domain";
import { faNum } from "@/lib/fa";
import { presignAndUpload } from "@/lib/media-upload";
import { Modal } from "./Overlays";
import { EmptyState } from "./primitives";

/**
 * MediaPicker مشترک (۱.۵/۱.۶): مودال + گرید + جستجو + آپلود + انتخاب تکی/چندتایی.
 * همه داده از API واقعی؛ هیچ mock.
 */
export function MediaPicker({
  open, multiple = false, selected, onChange, onClose,
}: {
  open: boolean;
  multiple?: boolean;
  selected: number[];
  onChange: (ids: number[], items: MediaItem[]) => void;
  onClose: () => void;
}) {
  const toast = useToast();
  const [items, setItems] = useState<MediaItem[] | null>(null);
  const [q, setQ] = useState("");
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async (search = "") => {
    try {
      setError(null);
      const res = await authed<{ data?: MediaItem[] } | MediaItem[]>(
        `/v1/admin/media?per_page=30${search ? `&search=${encodeURIComponent(search)}` : ""}`,
      );
      const list = Array.isArray(res) ? res : (res.data ?? []);
      setItems(list as MediaItem[]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "خطا در بارگذاری فایل‌ها.");
    }
  };

  const toggle = (m: MediaItem) => {
    if (multiple) {
      const has = selected.includes(m.id);
      const ids = has ? selected.filter((x) => x !== m.id) : [...selected, m.id];
      const known = (items ?? []).filter((x) => ids.includes(x.id));
      onChange(ids, known);
    } else {
      onChange([m.id], [m]);
    }
  };

  const onFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setUploading(true);
    try {
      for (const f of Array.from(files)) {
        const r = await presignAndUpload(f);
        if (!r.uploaded) toast("رکورد ساخته شد اما PUT مستقیم به S3 نرسید (محیط dev).", "err");
      }
      toast("آپلود انجام شد.", "ok");
      await load(q);
    } catch (e) {
      toast(e instanceof Error ? e.message : "آپلود ناموفق بود.", "err");
    } finally {
      setUploading(false);
    }
  };

  // بارگذاری اولیه هنگام باز شدن مودال (هوک‌ها قبل از return شرطی)
  useEffect(() => {
    if (!open) return;
    setItems(null);
    setError(null);
    void load("");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  return (
    <Modal title={multiple ? "انتخاب فایل‌ها" : "انتخاب فایل"} onClose={onClose}>
      <div style={{ display: "flex", gap: 8, marginBlockEnd: 12 }}>
        <input
          className="input" placeholder="جستجو…" aria-label="جستجو در فایل‌ها"
          value={q} onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") void load(q); }}
          style={{ flex: 1 }}
        />
        <button className="btn btn-ghost" onClick={() => void load(q)}>جستجو</button>
      </div>
      <label className="dropzone" style={{ padding: "14px", marginBlockEnd: 12 }}>
        <input type="file" multiple={multiple} disabled={uploading} onChange={(e) => void onFiles(e.target.files)} />
        {uploading ? "در حال آپلود…" : "＋ آپلود فایل جدید (کلیک یا رها کردن)"}
      </label>
      {error ? <div className="alert a-red">{error} <button className="btn btn-ghost btn-sm" onClick={() => void load(q)}>تلاش مجدد</button></div> : null}
      {items === null && !error ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>در حال بارگذاری…</p> : null}
      {items !== null && items.length === 0 ? <EmptyState title="فایلی نیست" hint="اول فایل آپلود کنید." /> : null}
      {items !== null && items.length > 0 ? (
        <div className="mgrid" style={{ maxBlockSize: 320, overflowY: "auto" }}>
          {items.map((m) => {
            const sel = selected.includes(m.id);
            const isImg = m.mime.startsWith("image/");
            return (
              <div key={m.id} className={`mitem${sel ? " sel" : ""}`} onClick={() => toggle(m)} role="checkbox" aria-checked={sel} tabIndex={0}
                onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(m); } }}>
                {isImg ? <img src={mediaUrl(m)} alt={m.alt ?? m.original_name} loading="lazy" /> : <div className="m-file" aria-hidden>🗎</div>}
                {sel ? <span className="m-tick">✓</span> : null}
                <div className="m-name">{m.original_name}</div>
              </div>
            );
          })}
        </div>
      ) : null}
      <div style={{ display: "flex", gap: 8, marginBlockStart: 14, alignItems: "center" }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{multiple ? `${faNum(selected.length)} انتخاب‌شده` : ""}</span>
        <div style={{ flex: 1 }} />
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={onClose}>تأیید انتخاب</button>
      </div>
    </Modal>
  );
}
