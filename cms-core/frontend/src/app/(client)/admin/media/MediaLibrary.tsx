"use client";
import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Drawer } from "@/components/ui/Overlays";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { presignAndUpload } from "@/lib/media-upload";
import { mediaUrl, type MediaItem, type MediaUsage, type Paginator } from "@/lib/domain";
import { faNum, jalali } from "@/lib/fa";

function fmtSize(b: number): string {
  if (b >= 1048576) return `${faNum((b / 1048576).toFixed(1))} مگ`;
  if (b >= 1024) return `${faNum(Math.round(b / 1024))} کیلو`;
  return `${faNum(b)} بایت`;
}

/** کتابخانه مدیا تعاملی: آپلود DropZone + گرید/لیست + Drawer جزئیات + سطل زباله. */
export function MediaLibrary({
  initial, trashedView, usage,
}: {
  initial: Paginator<MediaItem>;
  trashedView: boolean;
  usage?: MediaUsage | null;
}) {
  const toast = useToast();
  const router = useRouter();
  const [view, setView] = useState<"grid" | "list">("grid");
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [sel, setSel] = useState<MediaItem | null>(null);
  const [alt, setAlt] = useState("");
  const [name, setName] = useState("");
  const [confirmPerm, setConfirmPerm] = useState<MediaItem | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const openDetail = (m: MediaItem) => {
    setSel(m);
    setAlt(m.alt ?? "");
    setName(m.original_name);
  };

  const uploadFiles = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setUploading(true);
    let okCount = 0;
    try {
      for (const f of Array.from(files)) {
        const r = await presignAndUpload(f);
        if (r.uploaded) okCount++;
        else toast(`«${f.name}»: رکورد ساخته شد اما PUT مستقیم نرسید (S3 در دسترس نیست).`, "err");
      }
      if (okCount) toast(`${faNum(okCount)} فایل آپلود شد.`, "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "آپلود ناموفق بود.", "err");
    } finally {
      setUploading(false);
    }
  };

  const saveDetail = async () => {
    if (!sel) return;
    try {
      await authed(`/v1/admin/media/${sel.id}`, { method: "PUT", body: { alt: alt || null, original_name: name } });
      toast("فایل به‌روزرسانی شد.", "ok");
      setSel(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    }
  };

  const trash = async () => {
    if (!sel) return;
    try {
      await authed(`/v1/admin/media/${sel.id}`, { method: "DELETE" });
      toast("به سطل زباله منتقل شد.", "ok");
      setSel(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };

  const restore = async (id: number) => {
    try {
      await authed(`/v1/admin/media/${id}/restore`, { method: "POST" });
      toast("فایل بازگردانده شد.", "ok");
      setSel(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };

  const pageBytes = initial.data.reduce((s, m) => s + (m.size || 0), 0);

  const restoreAll = async () => {
    try {
      const r = await authed<{ restored: number }>("/v1/admin/media/restore-all", { method: "POST" });
      toast(r.restored > 0 ? `${faNum(r.restored)} فایل بازگردانده شد.` : "فایلی در سطل زباله نیست.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "خطا رخ داد.", "err");
    }
  };

  return (
    <div>
      {/* کارت مصرف فضای پلن با progress (دسته ۱ — «۲٫۴ از ۱۰ گیگ» دمو) */}
      {usage ? (
        <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
          <div style={{ display: "flex", justifyContent: "space-between", fontSize: 12.5, marginBlockEnd: 8 }}>
            <span>مصرف فضای پلن</span>
            <b>{faNum((usage.used_bytes / 1073741824).toFixed(1))} از {faNum((usage.quota_bytes / 1073741824).toFixed(1))} گیگ</b>
          </div>
          <div className="progress" role="progressbar" aria-valuenow={usage.percent} aria-valuemin={0} aria-valuemax={100} aria-label="مصرف فضای پلن">
            <i style={{ inlineSize: `${Math.min(100, usage.percent)}%` }} />
          </div>
        </div>
      ) : null}
      {/* نشانگر مصرف: تعداد کل + حجم صفحه جاری + سقف هر فایل */}
      <div className="card card-pad" style={{ marginBlockEnd: 14, display: "flex", gap: 16, flexWrap: "wrap", alignItems: "center" }}>
        <span style={{ fontSize: 13 }}>کل فایل‌ها: <b>{faNum(initial.total)}</b></span>
        <span style={{ fontSize: 13 }}>حجم این صفحه: <b>{fmtSize(pageBytes)}</b></span>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>سقف هر فایل: ۵۰ مگابایت</span>
        <div style={{ flex: 1 }} />
        <div className="seg" role="group" aria-label="نما">
          <button className={view === "grid" ? "on" : ""} onClick={() => setView("grid")}>گرید</button>
          <button className={view === "list" ? "on" : ""} onClick={() => setView("list")}>لیست</button>
        </div>
      </div>

      {!trashedView ? (
        <div
          className={`dropzone${dragOver ? " over" : ""}`}
          style={{ marginBlockEnd: 14 }}
          onClick={() => fileRef.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => { e.preventDefault(); setDragOver(false); void uploadFiles(e.dataTransfer.files); }}
          role="button" tabIndex={0} aria-label="آپلود فایل"
          onKeyDown={(e) => { if (e.key === "Enter") fileRef.current?.click(); }}
        >
          <input ref={fileRef} type="file" multiple onChange={(e) => void uploadFiles(e.target.files)} />
          {uploading ? "در حال آپلود…" : "فایل‌ها را اینجا رها کنید یا کلیک کنید (آپلود مستقیم با presign)"}
        </div>
      ) : (
        <div>
          <Alert tone="amber">نمای سطل زباله — فایل‌ها را بازگردانید یا برای همیشه حذف کنید.</Alert>
          {initial.data.length > 0 ? (
            <button className="btn btn-ghost btn-sm" onClick={() => void restoreAll()} style={{ marginBlockEnd: 10 }}>بازیابی همه</button>
          ) : null}
        </div>
      )}

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title={trashedView ? "سطل زباله خالی است" : "فایلی نیست"} hint={trashedView ? undefined : "با DropZone بالا آپلود کنید."} /></div>
      ) : view === "grid" ? (
        <div className="mgrid">
          {initial.data.map((m) => {
            const isImg = m.mime.startsWith("image/");
            return (
              <div key={m.id} className="mitem" onClick={() => openDetail(m)} role="button" tabIndex={0}
                onKeyDown={(e) => { if (e.key === "Enter") openDetail(m); }}>
                {isImg ? <img src={mediaUrl(m)} alt={m.alt ?? m.original_name} loading="lazy" /> : <div className="m-file" aria-hidden>🗎</div>}
                <span className="m-size">{fmtSize(m.size)}</span>
                <div className="m-name">{m.original_name}</div>
              </div>
            );
          })}
        </div>
      ) : (
        <div className="table-wrap">
          {initial.data.map((m) => (
            <div className="mlist-row" key={m.id} onClick={() => openDetail(m)} role="button" tabIndex={0}
              onKeyDown={(e) => { if (e.key === "Enter") openDetail(m); }} style={{ cursor: "pointer" }}>
              <span aria-hidden>{m.mime.startsWith("image/") ? "🖼" : "🗎"}</span>
              <span style={{ flex: 1 }}><b>{m.original_name}</b><br /><small style={{ color: "var(--text-muted)" }} dir="ltr">{m.mime} — {fmtSize(m.size)}</small></span>
              <small style={{ color: "var(--text-muted)" }}>{jalali(m.created_at)}</small>
            </div>
          ))}
        </div>
      )}

      {sel ? (
        <Drawer title="جزئیات فایل" onClose={() => setSel(null)}>
          {sel.mime.startsWith("image/") ? <img src={mediaUrl(sel)} alt={sel.alt ?? sel.original_name} style={{ inlineSize: "100%", borderRadius: 8, marginBlockEnd: 12 }} /> : null}
          <div className="kv"><span>نام</span><span>{sel.original_name}</span></div>
          <div className="kv"><span>نوع</span><span dir="ltr">{sel.mime}</span></div>
          <div className="kv"><span>حجم</span><span>{fmtSize(sel.size)}</span></div>
          <div className="kv"><span>بارگذاری</span><span>{jalali(sel.created_at)}</span></div>
          <div className="field" style={{ marginBlockStart: 12 }}>
            <label>نشانی فایل</label>
            <div style={{ display: "flex", gap: 8 }}>
              <input className="input" dir="ltr" style={{ textAlign: "left", flex: 1 }} readOnly value={mediaUrl(sel)} />
              <CopyToClipboard text={mediaUrl(sel)} />
            </div>
          </div>
          {!trashedView ? (
            <>
              <div className="field"><label>نام فایل</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
              <div className="field"><label>متن جایگزین (alt)</label><input className="input" value={alt} onChange={(e) => setAlt(e.target.value)} maxLength={200} /></div>
              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn btn-primary" onClick={() => void saveDetail()}>ذخیره</button>
                <button className="btn btn-ghost" onClick={() => void trash()}>انتقال به سطل زباله</button>
              </div>
            </>
          ) : (
            <div style={{ display: "flex", gap: 8 }}>
              <button className="btn btn-primary" onClick={() => void restore(sel.id)}>بازگردانی</button>
              <button className="btn btn-ghost" onClick={() => setConfirmPerm(sel)}>حذف دائم…</button>
            </div>
          )}
        </Drawer>
      ) : null}

      <ConfirmStepper
        open={confirmPerm !== null}
        title="حذف دائم فایل"
        description={`«${confirmPerm?.original_name}» برای همیشه حذف می‌شود و قابل بازگشت نیست.`}
        requirePhrase="حذف"
        confirmLabel="بستن"
        onConfirm={async () => {
          if (!confirmPerm) return "—";
          await authed(`/v1/admin/media/${confirmPerm.id}/permanent`, { method: "DELETE" });
          const msg = "فایل برای همیشه حذف شد.";
          setConfirmPerm(null);
          setSel(null);
          router.refresh();
          return msg;
        }}
        onClose={() => setConfirmPerm(null)}
      />
    </div>
  );
}
