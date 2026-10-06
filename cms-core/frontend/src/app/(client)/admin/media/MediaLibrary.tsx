"use client";
import { useRef, useState, type PointerEvent as ReactPointerEvent } from "react";
import { useRouter } from "next/navigation";
import { authed, authedForm } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Drawer } from "@/components/ui/Overlays";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { presignAndUpload } from "@/lib/media-upload";
import { focalPosition } from "@/lib/focal-point";
import { MediaAltReport } from "./MediaAltReport";
import { SampleContentEmptyState } from "@/components/admin/SampleContentEmptyState";
import { mediaUrl, type MediaFolder, type MediaItem, type MediaTag, type MediaUsage, type Paginator } from "@/lib/domain";
import { faNum, jalali } from "@/lib/fa";

/** WF-L1 — نقطهٔ کانونیِ درحال ویرایش (نرمال‌شدهٔ 0..1) یا null = وسط. */
type FocalPoint = { x: number; y: number };

function fmtSize(b: number): string {
  if (b >= 1048576) return `${faNum((b / 1048576).toFixed(1))} مگ`;
  if (b >= 1024) return `${faNum(Math.round(b / 1024))} کیلو`;
  return `${faNum(b)} بایت`;
}

/** WF-H6 — پیمایش تختِ درخت پوشه‌ها با عمق (برای `<select>` و نمایش). */
function flatFolders(folders: MediaFolder[]): { id: number; label: string; depth: number }[] {
  const byParent = new Map<number, MediaFolder[]>();
  for (const f of folders) {
    const key = f.parent_id ?? 0;
    byParent.set(key, [...(byParent.get(key) ?? []), f]);
  }
  const out: { id: number; label: string; depth: number }[] = [];
  const walk = (parent: number, depth: number) => {
    for (const f of byParent.get(parent) ?? []) {
      out.push({ id: f.id, label: f.name, depth });
      walk(f.id, depth + 1);
    }
  };
  walk(0, 0);
  return out;
}

/** کتابخانه مدیا تعاملی: آپلود DropZone + گرید/لیست + Drawer جزئیات + سطل زباله + پوشه/برچسب. */
export function MediaLibrary({
  initial, trashedView, usage, folders = [], tags = [],
}: {
  initial: Paginator<MediaItem>;
  trashedView: boolean;
  usage?: MediaUsage | null;
  folders?: MediaFolder[];
  tags?: MediaTag[];
}) {
  const toast = useToast();
  const router = useRouter();
  const [view, setView] = useState<"grid" | "list">("grid");
  const [dragOver, setDragOver] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [sel, setSel] = useState<MediaItem | null>(null);
  const [alt, setAlt] = useState("");
  const [name, setName] = useState("");
  // WF-L1 — نقطهٔ کانونیِ درحال ویرایش در Drawer.
  const [focal, setFocal] = useState<FocalPoint | null>(null);
  const draggingFocal = useRef(false);
  const [confirmPerm, setConfirmPerm] = useState<MediaItem | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  // WF-M6 — جایگزینی فایل با حفظ URL.
  const [pendingReplace, setPendingReplace] = useState<File | null>(null);
  const replaceRef = useRef<HTMLInputElement>(null);

  // WF-H6 — انتخاب گروهی و پوشه/برچسب.
  const [selected, setSelected] = useState<number[]>([]);
  const [showManager, setShowManager] = useState(false);
  // WF-H7 — گزارش تصاویر بدون alt.
  const [showAltReport, setShowAltReport] = useState(false);
  const [newFolderName, setNewFolderName] = useState("");
  const [newFolderParent, setNewFolderParent] = useState("");
  const [newTagName, setNewTagName] = useState("");
  const [newTagColor, setNewTagColor] = useState("#6d28d9");
  const [bulkFolder, setBulkFolder] = useState("");
  const [bulkTag, setBulkTag] = useState("");
  const [bulkMode, setBulkMode] = useState<"add" | "remove" | "sync">("add");

  const options = flatFolders(folders);

  const openDetail = (m: MediaItem) => {
    setSel(m);
    setAlt(m.alt ?? "");
    setName(m.original_name);
    setFocal(
      typeof m.focal_x === "number" && typeof m.focal_y === "number"
        ? { x: m.focal_x, y: m.focal_y }
        : null,
    );
  };

  /**
   * WF-L1 — کلیک/کشیدن روی پیش‌نمایش، نقطهٔ کانونی را از مختصات فیزیکیِ
   * تصویر می‌گیرد (نسبت به بالا-چپ) تا دقیقاً با محورِ `object-position` بخواند.
   * گِردکردن به سه رقم، نویزِ درگ را حذف می‌کند.
   */
  const applyFocal = (e: ReactPointerEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return;
    const x = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    const y = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height));
    setFocal({ x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000 });
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
      await authed(`/v1/admin/media/${sel.id}`, {
        method: "PUT",
        body: {
          alt: alt || null,
          original_name: name,
          focal_x: focal?.x ?? null,
          focal_y: focal?.y ?? null,
        },
      });
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

  const toggleSelect = (id: number) => {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const goFolder = (value: string) => {
    const qs = new URLSearchParams();
    if (value) qs.set("folder", value);
    if (trashedView) qs.set("trashed", "only");
    const q = qs.toString();
    router.push(`/admin/media${q ? `?${q}` : ""}`);
  };

  const createFolder = async () => {
    if (!newFolderName.trim()) return toast("نام پوشه را وارد کنید.", "err");
    try {
      await authed("/v1/admin/media/folders", {
        method: "POST",
        body: { name: newFolderName.trim(), parent_id: newFolderParent ? Number(newFolderParent) : null },
      });
      toast("پوشه ساخته شد.", "ok");
      setNewFolderName("");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت پوشه ناموفق بود.", "err");
    }
  };

  const deleteFolder = async (id: number) => {
    try {
      await authed(`/v1/admin/media/folders/${id}`, { method: "DELETE" });
      toast("پوشه حذف شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف پوشه ناموفق بود.", "err");
    }
  };

  const createTag = async () => {
    if (!newTagName.trim()) return toast("نام برچسب را وارد کنید.", "err");
    try {
      await authed("/v1/admin/media/tags", { method: "POST", body: { name: newTagName.trim(), color: newTagColor || null } });
      toast("برچسب ساخته شد.", "ok");
      setNewTagName("");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت برچسب ناموفق بود.", "err");
    }
  };

  const deleteTag = async (id: number) => {
    try {
      await authed(`/v1/admin/media/tags/${id}`, { method: "DELETE" });
      toast("برچسب حذف شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف برچسب ناموفق بود.", "err");
    }
  };

  const bulkMove = async () => {
    if (selected.length === 0) return;
    try {
      const r = await authed<{ moved: number }>("/v1/admin/media/bulk/move", {
        method: "POST",
        body: { ids: selected, folder_id: bulkFolder ? Number(bulkFolder) : null },
      });
      toast(`${faNum(r.moved)} فایل منتقل شد.`, "ok");
      setSelected([]);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "انتقال ناموفق بود.", "err");
    }
  };

  const bulkTagApply = async () => {
    if (selected.length === 0) return;
    if (!bulkTag) return toast("برچسبی انتخاب کنید.", "err");
    try {
      const r = await authed<{ tagged: number }>("/v1/admin/media/bulk/tag", {
        method: "POST",
        body: { ids: selected, tag_ids: [Number(bulkTag)], mode: bulkMode },
      });
      toast(`${faNum(r.tagged)} فایل به‌روزرسانی شد.`, "ok");
      setSelected([]);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "برچسب‌گذاری ناموفق بود.", "err");
    }
  };

  const checkStyle = { position: "absolute" as const, insetInlineStart: 6, insetBlockStart: 6, zIndex: 2 };

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
        <button className="btn btn-ghost btn-sm" onClick={() => setShowAltReport((v) => !v)}>
          {showAltReport ? "بستن گزارش alt" : "تصاویر بدون alt"}
        </button>
        <button className="btn btn-ghost btn-sm" onClick={() => setShowManager((v) => !v)}>
          {showManager ? "بستن پوشه‌ها/برچسب‌ها" : "پوشه‌ها و برچسب‌ها"}
        </button>
        <div className="seg" role="group" aria-label="نما">
          <button className={view === "grid" ? "on" : ""} onClick={() => setView("grid")}>گرید</button>
          <button className={view === "list" ? "on" : ""} onClick={() => setView("list")}>لیست</button>
        </div>
      </div>

      {showAltReport ? <MediaAltReport /> : null}

      {showManager ? (
        <div className="card card-pad" style={{ marginBlockEnd: 14, display: "grid", gap: 14, gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))" }}>
          <div>
            <h3 style={{ fontSize: 14, marginBlockEnd: 8 }}>پوشه‌ها (درختی)</h3>
            <div style={{ marginBlockEnd: 10 }}>
              <button className="btn btn-ghost btn-sm" onClick={() => goFolder("")}>همه فایل‌ها</button>{" "}
              <button className="btn btn-ghost btn-sm" onClick={() => goFolder("none")}>بدون پوشه</button>
            </div>
            <ul style={{ listStyle: "none", padding: 0, margin: 0, display: "grid", gap: 4 }}>
              {options.map((o) => (
                <li key={o.id} style={{ display: "flex", alignItems: "center", gap: 6, paddingInlineStart: o.depth * 14 }}>
                  <button className="btn btn-ghost btn-sm" style={{ flex: 1, justifyContent: "flex-start" }} onClick={() => goFolder(String(o.id))}>
                    📁 {o.label}
                  </button>
                  <button className="btn btn-ghost btn-sm" aria-label={`حذف پوشه ${o.label}`} onClick={() => void deleteFolder(o.id)}>×</button>
                </li>
              ))}
              {options.length === 0 ? <li style={{ fontSize: 12.5, color: "var(--text-muted)" }}>پوشه‌ای وجود ندارد.</li> : null}
            </ul>
            <div className="toolbar" style={{ marginBlockStart: 10 }}>
              <div className="field grow"><label>نام پوشهٔ جدید</label><input className="input" value={newFolderName} onChange={(e) => setNewFolderName(e.target.value)} /></div>
              <div className="field"><label>والد</label>
                <select className="select" value={newFolderParent} onChange={(e) => setNewFolderParent(e.target.value)}>
                  <option value="">ریشه</option>
                  {options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </select>
              </div>
              <button className="btn btn-primary" onClick={() => void createFolder()}>افزودن پوشه</button>
            </div>
          </div>
          <div>
            <h3 style={{ fontSize: 14, marginBlockEnd: 8 }}>برچسب‌ها</h3>
            <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginBlockEnd: 10 }}>
              {tags.map((t) => (
                <span className="chip" key={t.id}>
                  <span style={{ inlineSize: 8, blockSize: 8, borderRadius: 99, background: t.color || "var(--primary)", display: "inline-block" }} />
                  {t.name}
                  <button aria-label={`حذف برچسب ${t.name}`} onClick={() => void deleteTag(t.id)}>×</button>
                </span>
              ))}
              {tags.length === 0 ? <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>برچسبی وجود ندارد.</span> : null}
            </div>
            <div className="toolbar">
              <div className="field grow"><label>نام برچسب</label><input className="input" value={newTagName} onChange={(e) => setNewTagName(e.target.value)} /></div>
              <div className="field"><label>رنگ</label><input type="color" className="input" style={{ inlineSize: 56 }} value={newTagColor} onChange={(e) => setNewTagColor(e.target.value)} /></div>
              <button className="btn btn-primary" onClick={() => void createTag()}>افزودن برچسب</button>
            </div>
          </div>
        </div>
      ) : null}

      {selected.length > 0 ? (
        <div className="card card-pad" style={{ marginBlockEnd: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end" }}>
          <b style={{ fontSize: 13 }}>{faNum(selected.length)} فایل انتخاب شده</b>
          <div style={{ flex: 1 }} />
          <div className="field" style={{ marginBlockEnd: 0 }}>
            <label>انتقال به پوشه</label>
            <select className="select" value={bulkFolder} onChange={(e) => setBulkFolder(e.target.value)}>
              <option value="">بدون پوشه</option>
              {options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
            </select>
          </div>
          <button className="btn btn-primary" onClick={() => void bulkMove()}>انتقال</button>
          <div className="field" style={{ marginBlockEnd: 0 }}>
            <label>برچسب</label>
            <select className="select" value={bulkTag} onChange={(e) => setBulkTag(e.target.value)}>
              <option value="">انتخاب…</option>
              {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div className="field" style={{ marginBlockEnd: 0 }}>
            <label>عمل</label>
            <select className="select" value={bulkMode} onChange={(e) => setBulkMode(e.target.value as "add" | "remove" | "sync")}>
              <option value="add">افزودن</option>
              <option value="remove">حذف</option>
              <option value="sync">جایگزینی</option>
            </select>
          </div>
          <button className="btn btn-ghost" onClick={() => void bulkTagApply()}>اعمال برچسب</button>
          <button className="btn btn-ghost" onClick={() => setSelected([])}>پاک‌کردن انتخاب</button>
        </div>
      ) : null}

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
        <div className="card card-pad">
          {trashedView
            ? <EmptyState title="سطل زباله خالی است" />
            : <SampleContentEmptyState title="فایلی نیست" hint="با DropZone بالا آپلود کنید یا محتوای نمونه (همراه با تصاویر) را یک‌کلیکی بسازید." />}
        </div>
      ) : view === "grid" ? (
        <div className="mgrid">
          {initial.data.map((m) => {
            const isImg = m.mime.startsWith("image/");
            const picked = selected.includes(m.id);
            return (
              <div key={m.id} className={`mitem${picked ? " sel" : ""}`} onClick={() => openDetail(m)} role="button" tabIndex={0}
                onKeyDown={(e) => { if (e.key === "Enter") openDetail(m); }}>
                <input type="checkbox" checked={picked} aria-label={`انتخاب ${m.original_name}`} style={checkStyle}
                  onClick={(e) => e.stopPropagation()} onChange={() => toggleSelect(m.id)} />
                {isImg ? (
                  <img
                    src={mediaUrl(m)}
                    alt={m.alt ?? m.original_name}
                    loading="lazy"
                    style={{ objectPosition: focalPosition(m.focal_x, m.focal_y) }}
                  />
                ) : <div className="m-file" aria-hidden>🗎</div>}
                <span className="m-size">{fmtSize(m.size)}</span>
                <div className="m-name">{m.original_name}</div>
                {m.tags && m.tags.length > 0 ? (
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 3, padding: "0 8px 6px" }}>
                    {m.tags.map((t) => <span className="chip" key={t.id} style={{ fontSize: 10, padding: "1px 7px" }}>{t.name}</span>)}
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>
      ) : (
        <div className="table-wrap">
          {initial.data.map((m) => (
            <div className="mlist-row" key={m.id} onClick={() => openDetail(m)} role="button" tabIndex={0}
              onKeyDown={(e) => { if (e.key === "Enter") openDetail(m); }} style={{ cursor: "pointer" }}>
              <input type="checkbox" checked={selected.includes(m.id)} aria-label={`انتخاب ${m.original_name}`}
                onClick={(e) => e.stopPropagation()} onChange={() => toggleSelect(m.id)} />
              <span aria-hidden>{m.mime.startsWith("image/") ? "🖼" : "🗎"}</span>
              <span style={{ flex: 1 }}>
                <b>{m.original_name}</b><br />
                <small style={{ color: "var(--text-muted)" }} dir="ltr">{m.mime} — {fmtSize(m.size)}</small>
                {m.folder ? <small style={{ color: "var(--text-muted)" }}> · 📁 {m.folder.name}</small> : null}
              </span>
              <small style={{ color: "var(--text-muted)" }}>{jalali(m.created_at)}</small>
            </div>
          ))}
        </div>
      )}

      {sel ? (
        <Drawer title="جزئیات فایل" onClose={() => setSel(null)}>
          {sel.mime.startsWith("image/") ? (
            <div style={{ marginBlockEnd: 12 }}>
              <label style={{ fontSize: 12.5, color: "var(--text-muted)", display: "block", marginBlockEnd: 4 }}>
                نقطهٔ کانونی (روی تصویر کلیک یا بکشید)
              </label>
              {/* `dir="ltr"` عمدی است: محور افقیِ تصویر فیزیکی است و باید با
                  محورِ `object-position` یکی باشد؛ در RTL فقط جای نشانگر می‌چرخید. */}
              <div
                dir="ltr"
                role="button"
                tabIndex={0}
                aria-label="انتخاب نقطهٔ کانونی تصویر"
                onPointerDown={(e) => {
                  draggingFocal.current = true;
                  e.currentTarget.setPointerCapture(e.pointerId);
                  applyFocal(e);
                }}
                onPointerMove={(e) => { if (draggingFocal.current) applyFocal(e); }}
                onPointerUp={(e) => {
                  draggingFocal.current = false;
                  if (e.currentTarget.hasPointerCapture(e.pointerId)) e.currentTarget.releasePointerCapture(e.pointerId);
                }}
                onKeyDown={(e) => { if (e.key === "Enter") setFocal(null); }}
                style={{ position: "relative", cursor: "crosshair", touchAction: "none", borderRadius: 8, overflow: "hidden", border: "1px solid var(--border)" }}
              >
                <img
                  src={mediaUrl(sel)}
                  alt={sel.alt ?? sel.original_name}
                  style={{
                    inlineSize: "100%",
                    aspectRatio: "16 / 9",
                    display: "block",
                    objectFit: "cover",
                    objectPosition: focalPosition(focal?.x, focal?.y),
                  }}
                />
                <span
                  aria-hidden
                  style={{
                    position: "absolute",
                    insetInlineStart: `${(focal?.x ?? 0.5) * 100}%`,
                    insetBlockStart: `${(focal?.y ?? 0.5) * 100}%`,
                    transform: "translate(-50%, -50%)",
                    inlineSize: 16,
                    blockSize: 16,
                    borderRadius: "50%",
                    border: "2px solid #fff",
                    background: "rgba(0,0,0,.4)",
                    boxShadow: "0 0 0 1px rgba(0,0,0,.6)",
                    pointerEvents: "none",
                  }}
                />
              </div>
              <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8, marginBlockStart: 6 }}>
                <small style={{ color: "var(--text-muted)" }}>
                  {focal
                    ? `کانون: ${faNum(Math.round(focal.x * 100))}٪ افقی، ${faNum(Math.round(focal.y * 100))}٪ عمودی`
                    : "کانون: وسط تصویر"}
                </small>
                <button className="btn btn-ghost btn-sm" onClick={() => setFocal(null)} disabled={!focal}>
                  بازنشانی
                </button>
              </div>
            </div>
          ) : null}
          <div className="kv"><span>نام</span><span>{sel.original_name}</span></div>
          <div className="kv"><span>نوع</span><span dir="ltr">{sel.mime}</span></div>
          <div className="kv"><span>حجم</span><span>{fmtSize(sel.size)}</span></div>
          <div className="kv"><span>پوشه</span><span>{sel.folder?.name ?? "بدون پوشه"}</span></div>
          <div className="kv"><span>برچسب‌ها</span><span>{sel.tags && sel.tags.length > 0 ? sel.tags.map((t) => t.name).join("، ") : "—"}</span></div>
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
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                <button className="btn btn-primary" onClick={() => void saveDetail()}>ذخیره</button>
                <button className="btn btn-ghost" onClick={() => replaceRef.current?.click()}>جایگزینی فایل</button>
                <button className="btn btn-ghost" onClick={() => void trash()}>انتقال به سطل زباله</button>
              </div>
              <input
                ref={replaceRef}
                type="file"
                aria-label="انتخاب فایل جایگزین"
                style={{ display: "none" }}
                onChange={(e) => {
                  const f = e.target.files?.[0] ?? null;
                  e.target.value = "";
                  if (f) setPendingReplace(f);
                }}
              />
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
        open={pendingReplace !== null}
        title="جایگزینی فایل"
        description={`«${pendingReplace?.name ?? ""}» جای فایل فعلی را می‌گیرد. نشانی (URL) ثابت می‌ماند و نسخه‌های تصویر بازتولید می‌شوند.`}
        confirmLabel="بستن"
        busyLabel="در حال جایگزینی…"
        onConfirm={async () => {
          if (!sel || !pendingReplace) return "—";
          const form = new FormData();
          form.append("file", pendingReplace, pendingReplace.name);
          await authedForm(`/v1/admin/media/${sel.id}/replace`, form);
          await fetch("/api/revalidate", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ tags: ["pages", "site-chrome"], local: true }),
          }).catch(() => undefined);
          setPendingReplace(null);
          toast("فایل جایگزین شد.", "ok");
          setSel(null);
          router.refresh();
          return "فایل جایگزین شد.";
        }}
        onClose={() => setPendingReplace(null)}
      />

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
