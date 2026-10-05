"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, Skeleton } from "@/components/ui/primitives";
import { Modal } from "@/components/ui/Overlays";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { DragDropList } from "@/components/ui/DragDropList";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { faNum, jalali } from "@/lib/fa";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { withResolvedMedia } from "@/lib/block-preview";
import { mediaUrl, asList, type BlockDef, type BlockValue, type PageItem, type Revision, type SideName, type SidePreset } from "@/lib/domain";

type Row = BlockValue & { _key: string };
let seq = 1;
const key = () => `b${Date.now()}_${seq++}`;

/** مقدار پیش‌فرض بلوک از schema (defaultها + رشته خالی برای requiredها). */
function defaultsFor(def: BlockDef): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, p] of Object.entries(def.schema.properties ?? {})) {
    if (p.default !== undefined) out[k] = p.default;
    else if (p.type === "boolean") out[k] = false;
    else if (p.type === "array") out[k] = [];
    else if (p.type === "integer" || p.type === "number") out[k] = p.minimum ?? 0;
    else out[k] = "";
  }
  return out;
}

/** ویرایشگر Immersive صفحات: پالت + بوم + Inspector + نسخه‌ها + سئو + پیش‌نمایش. */
export function PageEditor({
  page, registry, initialRevisions,
}: {
  page: PageItem;
  registry: BlockDef[];
  initialRevisions: Revision[];
}) {
  const toast = useToast();
  const router = useRouter();
  const [title, setTitle] = useState(page.title);
  const [slug, setSlug] = useState(page.slug);
  const [blocks, setBlocks] = useState<Row[]>(
    (page.blocks ?? []).map((b) => ({ ...b, data: b.data ?? {}, _key: key() })),
  );
  const [meta, setMeta] = useState<Record<string, unknown>>((page.meta as Record<string, unknown>) ?? {});
  const [status, setStatus] = useState(page.status);
  const [isSingle, setIsSingle] = useState(page.is_single ?? false);
  const [selected, setSelected] = useState<string | null>(null);
  const [revisions, setRevisions] = useState<Revision[]>(initialRevisions);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [preview, setPreview] = useState(false);
  const [armPublish, setArmPublish] = useState(false);
  const [ogPicker, setOgPicker] = useState(false);
  const [armBack, setArmBack] = useState(false);
  const [armRestore, setArmRestore] = useState<number | null>(null);
  const [mediaCache, setMediaCache] = useState<Record<number, string>>({});
  const mediaSeen = useRef<Set<number>>(new Set());
  const publishInFlight = useRef(false);
  const markDirty = useCallback(() => setDirty(true), []);

  /* ── ستون‌های کناری (پریست مشترک با ارجاع زنده) ── */
  const [presets, setPresets] = useState<SidePreset[]>([]);
  const [leftEnabled, setLeftEnabled] = useState(page.left_enabled ?? true);
  const [rightEnabled, setRightEnabled] = useState(page.right_enabled ?? true);
  const [leftPresetId, setLeftPresetId] = useState<number | null>(page.left_preset_id ?? null);
  const [rightPresetId, setRightPresetId] = useState<number | null>(page.right_preset_id ?? null);
  const [sideTab, setSideTab] = useState<"right" | "left">("right");
  const [presetModal, setPresetModal] = useState<{ mode: "create" | "edit"; side: SideName; preset?: SidePreset } | null>(null);
  const [delPreset, setDelPreset] = useState<SidePreset | null>(null);

  const refreshPresets = useCallback(async () => {
    try {
      const r = await authed<unknown>("/v1/admin/side-presets");
      setPresets(asList<SidePreset>(r));
    } catch { /* پریست اختیاری */ }
  }, []);

  useEffect(() => { void refreshPresets(); }, [refreshPresets]);

  const doDeletePreset = async (preset: SidePreset) => {
    try {
      await authed(`/v1/admin/side-presets/${preset.id}`, { method: "DELETE" });
      if (leftPresetId === preset.id) setLeftPresetId(null);
      if (rightPresetId === preset.id) setRightPresetId(null);
      await refreshPresets();
      markDirty();
      toast(`پریست «${preset.name}» حذف شد.`, "ok");
      return `پریست «${preset.name}» حذف شد.`;
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود.", "err");
      throw e;
    } finally {
      setDelPreset(null);
    }
  };

  const defOf = useCallback((t: string) => registry.find((d) => d.type === t), [registry]);

  // Unsaved-guard هنگام خروج (تب/رفرش).
  useEffect(() => {
    if (!dirty) return;
    const fn = (e: BeforeUnloadEvent) => { e.preventDefault(); };
    window.addEventListener("beforeunload", fn);
    return () => window.removeEventListener("beforeunload", fn);
  }, [dirty]);

  // resolve نشانی تصاویر بلوک‌ها برای پیش‌نمایش واقعی.
  useEffect(() => {
    const ids = new Set<number>();
    for (const b of blocks) {
      const d = b.data ?? {};
      if (typeof d.media_id === "number" && d.media_id > 0) ids.add(d.media_id);
      if (typeof d.image_id === "number" && d.image_id > 0) ids.add(d.image_id);
      if (Array.isArray(d.media_ids)) for (const x of d.media_ids) if (typeof x === "number") ids.add(x);
    }
    const fresh = [...ids].filter((id) => !mediaSeen.current.has(id));
    if (fresh.length === 0) return;
    fresh.forEach((id) => mediaSeen.current.add(id));
    (async () => {
      const urls: Record<number, string> = {};
      for (const id of fresh) {
        try {
          const m = await authed<{ path: string }>(`/v1/admin/media/${id}`);
          urls[id] = mediaUrl(m);
        } catch { /* پیش‌نمایش بدون تصویر */ }
      }
      if (Object.keys(urls).length) setMediaCache((p) => ({ ...p, ...urls }));
    })();
  }, [blocks]);

  const payload = () => ({
    title: title.trim(),
    slug: slug.trim(),
    is_single: isSingle,
    blocks: blocks.map(({ type, data }) => ({ type, data })),
    meta,
    left_preset_id: leftPresetId,
    right_preset_id: rightPresetId,
    left_enabled: leftEnabled,
    right_enabled: rightEnabled,
  });

  const refreshRevisions = useCallback(async () => {
    try {
      const r = await authed<{ data: Revision[] } | Revision[]>(`/v1/admin/pages/${page.id}/revisions`);
      setRevisions(Array.isArray(r) ? r : (r.data ?? []));
    } catch { /* نسخه‌ها اختیاری */ }
  }, [page.id]);

  const persistDraft = async () => {
    const res = await authed<{ status?: string }>(`/v1/admin/pages/${page.id}`, { method: "PUT", body: payload() });
    if (res?.status) setStatus(res.status as PageItem["status"]);
    return res;
  };

  const saveDraft = async () => {
    if (!title.trim()) { toast("عنوان صفحه الزامی است.", "err"); return; }
    setSaving(true);
    try {
      await persistDraft();
      setDirty(false);
      toast("پیش‌نویس ذخیره شد (نسخه جدید ساخته شد).", "ok");
      await refreshRevisions();
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setSaving(false);
    }
  };

  const publish = async () => {
    if (saving || publishing) return;
    setArmPublish(true);
  };

  const doPublish = async () => {
    if (publishInFlight.current) {
      throw new Error("عملیات انتشار در حال انجام است.");
    }
    publishInFlight.current = true;
    setPublishing(true);
    setSaving(true);
    try {
      let draftSaved = false;
      if (dirty) {
        if (!title.trim()) throw new Error("عنوان صفحه الزامی است.");
        try {
          await persistDraft();
        } catch {
          throw new Error("ذخیره پیش‌نویس ناموفق بود؛ انتشار انجام نشد.");
        }
        setDirty(false);
        draftSaved = true;
      }
      try {
        await authed(`/v1/admin/pages/${page.id}/publish`, { method: "POST" });
      } catch {
        throw new Error(draftSaved
          ? "انتشار ناموفق بود؛ پیش‌نویس ذخیره شد، اما صفحه منتشر نشد."
          : "انتشار صفحه ناموفق بود؛ دوباره تلاش کنید.");
      }
      setStatus("published");
      setDirty(false);
      toast("صفحه با موفقیت منتشر شد.", "ok");
      await refreshRevisions();
      router.refresh();
      return "صفحه با موفقیت منتشر شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "انتشار صفحه ناموفق بود؛ دوباره تلاش کنید.";
      toast(message, "err");
      throw new Error(message);
    } finally {
      publishInFlight.current = false;
      setSaving(false);
      setPublishing(false);
    }
  };

  const restore = async (rev: Revision) => {
    setSaving(true);
    try {
      await authed(`/v1/admin/pages/${page.id}/restore/${rev.version}`, { method: "POST" });
      const full = await authed<PageItem>(`/v1/admin/pages/${page.id}`);
      setBlocks((full.blocks ?? []).map((b) => ({ ...b, data: b.data ?? {}, _key: key() })));
      setMeta((full.meta as Record<string, unknown>) ?? {});
      setSelected(null);
      setDirty(false);
      setArmRestore(null);
      toast(`به نسخه ${faNum(rev.version)} برگشت داده شد.`, "ok");
      await refreshRevisions();
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "بازگشت ناموفق بود.", "err");
    } finally {
      setSaving(false);
    }
  };

  const addBlock = (t: string) => {
    const def = defOf(t);
    if (!def) return;
    const row: Row = { type: t, data: defaultsFor(def), _key: key() };
    setBlocks((p) => [...p, row]);
    setSelected(row._key);
    markDirty();
  };

  const goBack = () => {
    if (dirty && !armBack) { setArmBack(true); setTimeout(() => setArmBack(false), 3000); return; }
    router.push("/admin/pages");
  };

  return (
    <div>
      {/* نوار بالا عین دمو: بازگشت + عنوان + بج‌ها + ذخیره پررنگ + پیش‌نمایش (بدون انتشار) */}
      <div className="ed-bar" role="toolbar" aria-label="نوار ویرایشگر">
        <button className="btn btn-ghost btn-sm" onClick={goBack}>{armBack ? "تغییرات ذخیره نشده — دوباره بزن" : "→ بازگشت به لیست"}</button>
        <div style={{ display: "flex", gap: 8, alignItems: "center", flex: 1, minInlineSize: 200 }}>
          <input className="input" value={title} onChange={(e) => { setTitle(e.target.value); markDirty(); }} aria-label="عنوان صفحه" style={{ fontWeight: 700 }} />
          <Badge tone={status === "published" ? "green" : "gray"}>{status === "published" ? "منتشرشده" : "پیش‌نویس"}</Badge>
          {dirty ? <Badge tone="amber">ذخیره‌نشده</Badge> : null}
        </div>
        <button className="btn btn-primary" onClick={() => void saveDraft()} disabled={saving || !dirty} style={{ fontWeight: 800 }}>{saving ? "…" : "✓ ذخیره پیش‌نویس"}</button>
        <button className="btn btn-ghost" onClick={() => setPreview(true)}>پیش‌نمایش</button>
      </div>

      <div className="editor">
        {/* ستون اصلی */}
        <div>
          <div className="card card-pad" style={{ marginBlockEnd: 12 }}>
            <div className="card-title" style={{ marginBlockEnd: 10 }}>＋ افزودن بلوک</div>
            <div className="block-palette">
              {registry.map((d) => (
                <button key={d.type} className="palette-item" onClick={() => addBlock(d.type)} title={d.description ?? d.title}>
                  {d.title} ＋
                </button>
              ))}
            </div>
            {registry.length === 0 ? <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>بلوک فعالی ثبت نشده.</p> : null}
          </div>

          <div id="canvas">
            {blocks.length === 0 ? (
              <div className="empty">بوم خالی است — از پالت بلوک اضافه کنید.</div>
            ) : (
              <DragDropList
                items={blocks.map((b) => ({ id: b._key }))}
                onMove={(from, to) => {
                  setBlocks((p) => { const n = [...p]; const [x] = n.splice(from, 1); n.splice(to, 0, x); return n; });
                  markDirty();
                }}
                render={(it, i, h) => {
                  const b = blocks[i];
                  const def = defOf(b.type);
                  const isSel = selected === b._key;
                  return (
                    <div className={`block${isSel ? " selected" : ""}`} onClick={() => setSelected(b._key)}>
                      <div className="block-head">
                        <span className="grip" title="بکش برای جابه‌جایی" aria-hidden>⋮⋮</span>
                        <span>بلوک {def?.title ?? b.type}</span>
                        <span className="b-actions">
                          <button className="icon-btn" title="بالا" onClick={(e) => { e.stopPropagation(); h.moveUp(); markDirty(); }}>↑</button>
                          <button className="icon-btn" title="پایین" onClick={(e) => { e.stopPropagation(); h.moveDown(); markDirty(); }}>↓</button>
                          <button className="icon-btn" title="حذف" onClick={(e) => { e.stopPropagation(); setBlocks((p) => p.filter((x) => x._key !== b._key)); if (selected === b._key) setSelected(null); markDirty(); }}>✕</button>
                        </span>
                      </div>
                      <div className="block-body"><BlockPreview row={b} mediaCache={mediaCache} schema={def?.schema} /></div>
                      {isSel && def ? (
                        <div className="block-settings" onClick={(e) => e.stopPropagation()}>
                          <div className="card-title" style={{ marginBlockEnd: 8 }}>تنظیمات: {def.title}</div>
                          <SchemaForm
                            schema={def.schema}
                            value={b.data}
                            onChange={(next) => {
                              setBlocks((p) => p.map((x) => x._key === b._key ? { ...x, data: next } : x));
                              markDirty();
                            }}
                          />
                        </div>
                      ) : null}
                    </div>
                  );
                }}
              />
            )}
          </div>

          <div className="card card-pad" style={{ marginBlockStart: 12 }}>
            <div className="card-title" style={{ marginBlockEnd: 10 }}>سئو</div>
            <div className="form-grid">
              <div className="field"><label>عنوان سئو</label><input className="input" value={String(meta.seo_title ?? "")} onChange={(e) => { setMeta({ ...meta, seo_title: e.target.value }); markDirty(); }} /></div>
              <div className="field"><label>نامک (slug)</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={slug} onChange={(e) => { setSlug(e.target.value); markDirty(); }} /></div>
              <div className="field full"><label>توضیحات متا</label><textarea className="input" rows={2} value={String(meta.seo_description ?? "")} onChange={(e) => { setMeta({ ...meta, seo_description: e.target.value }); markDirty(); }} /></div>
              <div className="field"><label>کنونیکال</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={String(meta.canonical ?? "")} onChange={(e) => { setMeta({ ...meta, canonical: e.target.value }); markDirty(); }} /></div>
              <div className="field">
                <label>تصویر og</label>
                <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                  <span className="chip">{typeof meta.og_image === "number" && meta.og_image > 0 ? `فایل #${meta.og_image}` : "انتخاب نشده"}</span>
                  <button className="btn btn-ghost btn-sm" onClick={() => setOgPicker(true)}>انتخاب…</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* ستون کناری ۳۰۰px */}
        <div className="side-col">
          <div className="card card-pad">
            <div className="card-title" style={{ marginBlockEnd: 6 }}>انتشار</div>
            <div className="kv"><span>وضعیت</span><span><Badge tone={status === "published" ? "green" : "gray"}>{status === "published" ? "منتشرشده" : "پیش‌نویس"}</Badge></span></div>
            <div style={{ display: "flex", gap: 10, alignItems: "center", marginBlockStart: 10 }}>
              <button
                type="button"
                role="switch"
                aria-checked={isSingle}
                className="switch"
                aria-label="این صفحه تک‌صفحه‌ای است"
                title={isSingle ? "فعال — برای غیرفعال کلیک کن" : "غیرفعال — برای فعال کلیک کن"}
                onClick={() => { setIsSingle((v) => !v); markDirty(); }}
              />
              <span>این صفحه تک‌صفحه‌ای است</span>
            </div>
            <div className="kv"><span>نسخه فعلی</span><span>{revisions.length > 0 ? faNum(Math.max(...revisions.map((r) => r.version))) : "—"}</span></div>

            <div className="kv"><span>آخرین انتشار</span><span>{page.published_at ? jalali(page.published_at) : "—"}</span></div>
            <button className="btn btn-success btn-block" style={{ marginBlockStart: 12 }} onClick={() => void publish()} disabled={saving || publishing}>
              {publishing ? "در حال انتشار…" : saving ? "در حال ذخیره…" : "انتشار"}
            </button>
            <button className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={() => setPreview(true)}>پیش‌نمایش</button>
          </div>

          <div className="card card-pad">
            <div className="card-title" style={{ marginBlockEnd: 6 }}>نسخه‌ها</div>
            {revisions.length === 0 ? (
              <p style={{ fontSize: 13, color: "var(--text-muted)" }}>نسخه‌ای ثبت نشده.</p>
            ) : (
              <div className="timeline">
                {revisions.map((r) => (
                  <div className="tl-item" key={r.id}>
                    <b>نسخه {faNum(r.version)}</b> — {r.note ?? "ویرایش"}
                    <div className="t">
                      {jalali(r.created_at)} —{" "}
                      {armRestore === r.id ? (
                        <button className="btn btn-primary btn-sm" disabled={saving} onClick={() => void restore(r)}>مطمئنی؟ بزن</button>
                      ) : (
                        <a href="#" onClick={(e) => { e.preventDefault(); setArmRestore(r.id); setTimeout(() => setArmRestore(null), 3000); }} style={{ color: "var(--primary)" }}>بازگردانی</a>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* ستون‌های کناری — بدون هیچ کم‌شدن، فقط جابه‌جا شده به سایدبار */}
          <SidebarsCard
            sideTab={sideTab}
            onTab={setSideTab}
            presets={presets}
            leftEnabled={leftEnabled}
            rightEnabled={rightEnabled}
            leftPresetId={leftPresetId}
            rightPresetId={rightPresetId}
            onToggle={(side, v) => { (side === "left" ? setLeftEnabled : setRightEnabled)(v); markDirty(); }}
            onPick={(side, id) => { (side === "left" ? setLeftPresetId : setRightPresetId)(id); markDirty(); }}
            onCreate={(side) => setPresetModal({ mode: "create", side })}
            onEdit={(side, preset) => setPresetModal({ mode: "edit", side, preset })}
            onDelete={(preset) => setDelPreset(preset)}
          />
        </div>
      </div>

      {presetModal ? (
        <PresetModal
          mode={presetModal.mode}
          side={presetModal.side}
          preset={presetModal.preset}
          registry={registry}
          onClose={() => setPresetModal(null)}
          onSaved={(saved) => {
            void refreshPresets().then(() => {
              setPresets((p) => {
                const rest = p.filter((x) => x.id !== saved.id);
                return [...rest, saved];
              });
              (saved.side === "left" ? setLeftPresetId : setRightPresetId)(saved.id);
            });
            setPresetModal(null);
            markDirty();
            toast(presetModal.mode === "create" ? "پریست ساخته و به این ستون وصل شد." : "پریست ویرایش شد (روی همه صفحات اعمال می‌شود).", "ok");
          }}
        />
      ) : null}

      {ogPicker ? (
        <MediaPicker
          open selected={typeof meta.og_image === "number" && meta.og_image > 0 ? [meta.og_image] : []}
          onChange={(ids) => { setMeta({ ...meta, og_image: ids[0] ?? 0 }); markDirty(); }}
          onClose={() => setOgPicker(false)}
        />
      ) : null}

      {preview ? (
        <Modal title={`پیش‌نمایش: ${title}`} onClose={() => setPreview(false)}>
          <div style={{ maxBlockSize: "60vh", overflowY: "auto" }}>
            {blocks.length === 0 ? <Skeleton lines={3} /> : blocks.map((b) => (
              <div key={b._key} style={{ marginBlockEnd: 14 }}>
                <BlockPreview row={b} mediaCache={mediaCache} schema={defOf(b.type)?.schema} />
              </div>
            ))}
          </div>
          <p style={{ fontSize: 12, color: "var(--text-muted)" }}>پیش‌نمایش محلی پیش‌نویس — نسخه منتشرشده در سایت عمومی نمایش داده می‌شود.</p>
        </Modal>
      ) : null}

      <ConfirmStepper
        open={armPublish}
        title="انتشار صفحه؟"
        description="اگر تغییرات ذخیره نشده باشند، ابتدا پیش‌نویس ذخیره و سپس نسخه جدید منتشر می‌شود؛ کش ISR هم بازسازی می‌شود."
        confirmLabel="انتشار"
        onConfirm={() => doPublish()}
        onClose={() => setArmPublish(false)}
      />
      <ConfirmStepper
        open={delPreset !== null}
        title={`حذف پریست «${delPreset?.name ?? ""}»؟`}
        description="پریست از همه ستون‌ها جدا می‌شود. اگر در صفحه‌ای استفاده شده باشد، حذف مجاز نیست و پیامش را می‌بینید."
        confirmLabel="حذف پریست"
        onConfirm={() => (delPreset ? doDeletePreset(delPreset) : Promise.resolve("انصراف"))}
        onClose={() => setDelPreset(null)}
      />
    </div>
  );
}

/**
 * K6.13 — پیش‌نمایش زندهٔ تک‌بلوک با **همان** `BlockRenderer` سایت عمومی.
 *
 * چرا امروز این‌طور است و قبلاً نبود:
 *  - قبل از این، ادیتور فقط `SchemaForm` را نشان می‌داد و `BlockRenderer` را
 *    هرگز صدا نمی‌زد؛ پارامتر `diagnostics` هم بی‌مصرف مانده بود. یعنی ادمین
 *    **فرم** می‌دید نه **نتیجه**، و بلوکِ افزونه پیش از انتشار دیده نمی‌شد.
 *  - الان یکی‌سازی شد: WYSIWYG به‌معنای واقعی. هر الگویی که `BlockRenderer`
 *    رندر می‌کند، این‌جا هم دیده می‌شود — بدون `build`، بدون اجرای JS افزونه.
 *  - `diagnostics` روشن است تا بلوکِ رندر‌نشده در ادیتور به‌جای سکوت، اعلان
 *    بدهد (همان هدفی که کامنتِ پارامتر در `BlockRenderer` نوشته بود).
 *
 * نگاشتِ `media_id`→`url` این‌جا انجام می‌شود چون دادهٔ ادیتور خام است و
 * نشانیِ resolve‌شده فقط در `mediaCache` نشسته (بک‌اند در مسیر عمومی خودش
 * `url` را تزریق می‌کند).
 */
function BlockPreview({
  row,
  mediaCache,
  schema,
}: {
  row: Row;
  mediaCache: Record<number, string>;
  schema?: BlockDef["schema"];
}) {
  const data = withResolvedMedia(row.data ?? {}, mediaCache);
  const schemas = schema ? { [row.type]: schema } : undefined;
  return (
    <BlockRenderer
      blocks={[{ type: row.type, data }]}
      schemas={schemas}
      diagnostics
    />
  );
}

/* ── ستون‌های کناری: تب راست/چپ + فعال‌ساز + انتخاب پریست ── */
function SidebarsCard(props: {
  sideTab: "right" | "left";
  onTab: (t: "right" | "left") => void;
  presets: SidePreset[];
  leftEnabled: boolean;
  rightEnabled: boolean;
  leftPresetId: number | null;
  rightPresetId: number | null;
  onToggle: (side: SideName, v: boolean) => void;
  onPick: (side: SideName, id: number | null) => void;
  onCreate: (side: SideName) => void;
  onEdit: (side: SideName, preset: SidePreset) => void;
  onDelete: (preset: SidePreset) => void;
}) {
  const side: SideName = props.sideTab === "left" ? "left" : "right";
  const enabled = side === "left" ? props.leftEnabled : props.rightEnabled;
  const picked = side === "left" ? props.leftPresetId : props.rightPresetId;
  const options = props.presets.filter((p) => p.side === side);
  const current = options.find((p) => p.id === picked) ?? null;

  return (
    <div className="card card-pad">
      <div className="card-title" style={{ marginBlockEnd: 4 }}>ستون‌های کناری</div>
      <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlock: "0 10px" }}>
        هر ستون جداگانه فعال/غیرفعال می‌شود. پریست «ارجاع زنده» است: ویرایش آن روی همه صفحات استفاده‌کننده اعمال می‌شود، نه کپی.
      </p>
      <div className="tabs" role="tablist" aria-label="ستون کناری" style={{ marginBlockEnd: 12 }}>
        {(["right", "left"] as const).map((t) => (
          <a key={t} className={props.sideTab === t ? "on" : ""} onClick={() => props.onTab(t)} role="tab" aria-selected={props.sideTab === t} style={{ cursor: "pointer" }}>
            {t === "right" ? "ستون راست" : "ستون چپ"}
          </a>
        ))}
      </div>
      <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
        <button
          type="button" role="switch" aria-checked={enabled} className="switch"
          aria-label={side === "right" ? "فعال‌سازی ستون راست" : "فعال‌سازی ستون چپ"}
          title={enabled ? "فعال — برای غیرفعال کلیک کن" : "غیرفعال — برای فعال کلیک کن"}
          onClick={() => props.onToggle(side, !enabled)}
        />
        <span style={{ fontSize: 13 }}>{enabled ? "فعال" : "غیرفعال"}</span>
        <select
          className="input" style={{ maxInlineSize: 280 }} aria-label="انتخاب پریست"
          value={picked ?? ""}
          onChange={(e) => props.onPick(side, e.target.value ? Number(e.target.value) : null)}
        >
          <option value="">— بدون پریست —</option>
          {options.map((p) => <option key={p.id} value={p.id}>{p.name} ({faNum(p.blocks.length)} بلوک)</option>)}
        </select>
        <span style={{ flex: 1 }} />
        <button className="btn btn-ghost btn-sm" onClick={() => props.onCreate(side)}>ذخیره به‌عنوان پریست…</button>
        {current ? (
          <button className="btn btn-ghost btn-sm" onClick={() => props.onEdit(side, current)}>ویرایش پریست «{current.name}»</button>
        ) : null}
      </div>
      {current ? (
        <p style={{ fontSize: 12, color: "var(--text-muted)", marginBlock: "8px 0" }}>
          {faNum(current.blocks.length)} بلوک: {current.blocks.map((b) => b.type).join("، ") || "—"}
        </p>
      ) : null}
      <div style={{ marginBlockStart: 12, borderBlockStart: "1px dashed var(--border)", paddingBlockStart: 10 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700, marginBlockEnd: 8 }}>
          پریست‌های {side === "right" ? "ستون راست" : "ستون چپ"} ({faNum(options.length)})
        </div>
        {options.length === 0 ? (
          <div className="empty" style={{ padding: "16px 10px" }}>
            <b>هنوز پریستی برای این ستون نساخته‌اید</b>
            ترکیب بلوک‌های دلخواهتان را بسازید و با دکمه زیر ذخیره کنید تا در همه صفحات قابل استفاده باشد.
            <div style={{ marginBlockStart: 10 }}>
              <button className="btn btn-primary btn-sm" onClick={() => props.onCreate(side)}>＋ ساخت اولین پریست</button>
            </div>
          </div>
        ) : options.map((p) => (
          <div key={p.id} className="ver-row">
            <span><b>{p.name}</b> <small style={{ color: "var(--text-muted)" }}>({faNum(p.blocks.length)} بلوک)</small></span>
            <span style={{ flex: 1 }} />
            <button className="btn btn-ghost btn-sm" onClick={() => props.onEdit(side, p)}>ویرایش</button>
            <button className="btn btn-ghost btn-sm" onClick={() => props.onDelete(p)}>حذف</button>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ── مودال ساخت/ویرایش پریست (نام + بلوک‌ها با همان رجیستری و SchemaForm) ── */
function PresetModal(props: {
  mode: "create" | "edit";
  side: SideName;
  preset?: SidePreset;
  registry: BlockDef[];
  onClose: () => void;
  onSaved: (saved: SidePreset) => void;
}) {
  const toast = useToast();
  const [name, setName] = useState(props.preset?.name ?? "");
  const [rows, setRows] = useState<Row[]>(
    (props.preset?.blocks ?? []).map((b) => ({ ...b, data: b.data ?? {}, _key: key() })),
  );
  const [sel, setSel] = useState<string | null>(null);
  const [addType, setAddType] = useState("");
  const [saving, setSaving] = useState(false);
  const selRow = rows.find((b) => b._key === sel) ?? null;
  const defOf = (t: string) => props.registry.find((d) => d.type === t);

  const save = async () => {
    if (name.trim().length < 2) { toast("نام پریست حداقل ۲ نویسه است.", "err"); return; }
    setSaving(true);
    try {
      const body = { name: name.trim(), side: props.side, blocks: rows.map(({ type, data }) => ({ type, data })) };
      const saved = props.mode === "create" || !props.preset
        ? await authed<SidePreset>("/v1/admin/side-presets", { method: "POST", body })
        : await authed<SidePreset>(`/v1/admin/side-presets/${props.preset.id}`, { method: "PUT", body });
      props.onSaved(saved);
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره پریست ناموفق بود.", "err");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      title={props.mode === "create"
        ? `پریست جدید ستون ${props.side === "right" ? "راست" : "چپ"}`
        : `ویرایش پریست «${props.preset?.name}» (زنده روی همه صفحات)`}
      onClose={props.onClose}
    >
      <div className="field">
        <label>نام پریست</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="مثلاً ستون راست فروشگاه" />
      </div>
      <div style={{ display: "flex", gap: 8, alignItems: "center", marginBlock: "10px 8px" }}>
        <select className="input" value={addType} onChange={(e) => setAddType(e.target.value)} aria-label="نوع بلوک جدید">
          <option value="">— افزودن بلوک… —</option>
          {props.registry.map((d) => <option key={d.type} value={d.type}>{d.title}</option>)}
        </select>
        <button
          className="btn btn-ghost btn-sm"
          disabled={!addType}
          onClick={() => {
            const def = defOf(addType);
            if (!def) return;
            const row: Row = { type: addType, data: defaultsFor(def), _key: key() };
            setRows((p) => [...p, row]);
            setSel(row._key);
            setAddType("");
          }}
        >افزودن</button>
        <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{faNum(rows.length)} بلوک</span>
      </div>
      {rows.length === 0 ? <div className="empty">بلوکی نیست — از فهرست بالا اضافه کنید.</div> : (
        <div style={{ display: "flex", flexDirection: "column", gap: 6, maxBlockSize: 220, overflowY: "auto" }}>
          {rows.map((b, i) => (
            <div key={b._key} className={`block-card${sel === b._key ? " sel" : ""}`} onClick={() => setSel(b._key)} style={{ cursor: "pointer" }}>
              <span><b>{defOf(b.type)?.title ?? b.type}</b> <Badge tone="gray">#{faNum(i + 1)}</Badge></span>
              <span className="b-actions">
                <button title="بالا" onClick={(e) => { e.stopPropagation(); setRows((p) => { const n = [...p]; if (i > 0) [n[i - 1], n[i]] = [n[i], n[i - 1]]; return n; }); }}>↑</button>
                <button title="پایین" onClick={(e) => { e.stopPropagation(); setRows((p) => { const n = [...p]; if (i < n.length - 1) [n[i + 1], n[i]] = [n[i], n[i + 1]]; return n; }); }}>↓</button>
                <button title="حذف" onClick={(e) => { e.stopPropagation(); setRows((p) => p.filter((x) => x._key !== b._key)); if (sel === b._key) setSel(null); }}>✕</button>
              </span>
            </div>
          ))}
        </div>
      )}
      {selRow && defOf(selRow.type) ? (
        <div style={{ marginBlockStart: 10 }}>
          <div className="card-title" style={{ marginBlockEnd: 8 }}>تنظیمات: {defOf(selRow.type)?.title}</div>
          <SchemaForm
            schema={defOf(selRow.type)!.schema}
            value={selRow.data}
            onChange={(next) => setRows((p) => p.map((x) => x._key === selRow._key ? { ...x, data: next } : x))}
          />
        </div>
      ) : null}
      <div style={{ display: "flex", gap: 8, marginBlockStart: 14 }}>
        <button className="btn btn-primary" disabled={saving} onClick={() => void save()}>{saving ? "…" : props.mode === "create" ? "ساخت پریست" : "ذخیره پریست"}</button>
        <button className="btn btn-ghost" onClick={props.onClose}>انصراف</button>
      </div>
    </Modal>
  );
}
