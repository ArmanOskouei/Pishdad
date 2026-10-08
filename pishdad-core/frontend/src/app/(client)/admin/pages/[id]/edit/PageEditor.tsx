"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed, ApiError } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, Skeleton } from "@/components/ui/primitives";
import { Modal } from "@/components/ui/Overlays";
import { ConfirmStepper } from "@/components/ui/ConfirmStepper";
import { CopyToClipboard } from "@/components/ui/CopyToClipboard";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { DragDropList } from "@/components/ui/DragDropList";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { JalaliDatePicker } from "@/components/ui/JalaliDatePicker";
import { faNum, jalali } from "@/lib/fa";
import { BlockRenderer } from "@/components/site/BlockRenderer";
import { withResolvedMedia } from "@/lib/block-preview";
import {
  autosaveGate,
  createDebouncer,
  formatSavedClock,
  LOCK_HEARTBEAT_MS,
  AUTOSAVE_MS,
  type EditLockHolder,
  type TimerApi,
} from "@/lib/page-edit-lock";
import { mediaUrl, asList, type BlockDef, type BlockValue, type PageItem, type Revision, type SideName, type SidePreset } from "@/lib/domain";
import {
  serpCharState,
  serpLength,
  serpUrlParts,
  truncateForSerp,
  SERP_DESC_MAX,
  SERP_DESC_MIN,
  SERP_TITLE_MAX,
  SERP_TITLE_MIN,
  type SerpCharState,
} from "@/lib/seo-preview";

type Row = BlockValue & { _key: string };
let seq = 1;
const key = () => `b${Date.now()}_${seq++}`;

/* WF-H14 — همزادِ ترجمهٔ زبان مقابل + وضعیت مقایسهٔ revision. */
type TranslationSibling = {
  id: number; title: string; slug: string; locale: string; status: string;
  is_single: boolean; source_revision_id: number | null; updated_at: string | null;
};
type TranslationInfo = {
  source: { id: number; locale: string | null; slug: string; latest_revision_id: number | null };
  target_locale: string;
  status: "none" | "translated" | "needs_update";
  sibling: TranslationSibling | null;
};

function translationStatusBadge(status: TranslationInfo["status"]) {
  if (status === "translated") return { tone: "green" as const, label: "ترجمه‌شده" };
  if (status === "needs_update") return { tone: "amber" as const, label: "نیازمند به‌روزرسانی" };
  return { tone: "gray" as const, label: "بدون ترجمه" };
}

const SERP_TONE: Record<SerpCharState, string> = {
  short: "var(--warning)",
  good: "var(--success)",
  long: "var(--danger)",
};

const realTimers: TimerApi = {
  set: (fn, ms) => window.setTimeout(fn, ms),
  clear: (h) => window.clearTimeout(h as number),
};

/* WF-C3 — کمک‌تابع‌های تبدیل تاریخ/ساعت محلی برای زمان‌بندی انتشار. */
const pad2 = (x: number) => String(x).padStart(2, "0");
const todayLocal = () => {
  const d = new Date();
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
};
const isoToLocalDate = (iso: string) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? todayLocal() : `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
};
const isoToLocalTime = (iso: string) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "09:00" : `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
};

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
  const [editSeq, setEditSeq] = useState(0);
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [savedAt, setSavedAt] = useState<Date | null>(null);
  const [autosaving, setAutosaving] = useState(false);
  const [lockHolder, setLockHolder] = useState<EditLockHolder | null>(null);
  const [conflict, setConflict] = useState(false);
  const [preview, setPreview] = useState(false);
  const [armPublish, setArmPublish] = useState(false);
  /* WF-H5 — لغو انتشار (برگشت به پیش‌نویس با حفظ نسخه/تاریخ انتشار). */
  const [unpublishing, setUnpublishing] = useState(false);
  const [armUnpublish, setArmUnpublish] = useState(false);
  /* WF-C3 — زمان‌بندی انتشار. */
  const [scheduledAt, setScheduledAt] = useState<string | null>(page.scheduled_at ?? null);
  const [showSchedule, setShowSchedule] = useState(false);
  const [schedDate, setSchedDate] = useState<string | null>(null);
  const [schedTime, setSchedTime] = useState("09:00");
  const [scheduling, setScheduling] = useState(false);
  const [armCancelSchedule, setArmCancelSchedule] = useState(false);
  /* WF-H2 — لینک اشتراک پیش‌نویس. */
  const [shareLink, setShareLink] = useState<{ url: string; token: string; expires_at: string } | null>(null);
  const [sharing, setSharing] = useState(false);
  const [revokingShare, setRevokingShare] = useState(false);
  const [armRevokeShare, setArmRevokeShare] = useState(false);
  const [ogPicker, setOgPicker] = useState(false);
  const [redirectSuggestion, setRedirectSuggestion] = useState<{ from_path: string; to_path: string } | null>(null);
  const [armBack, setArmBack] = useState(false);
  const [armRestore, setArmRestore] = useState<number | null>(null);
  /* WF-H14 — ترجمهٔ محتوای چندزبانه. */
  const [translation, setTranslation] = useState<TranslationInfo | null>(null);
  const [translating, setTranslating] = useState(false);
  const [mediaCache, setMediaCache] = useState<Record<number, string>>({});
  const mediaSeen = useRef<Set<number>>(new Set());
  const publishInFlight = useRef(false);
  /* WF-H3 — ردیابیِ نسخهٔ سرور برای کنترل همروندی + زمان‌بندیِ خودکار. */
  const lastUpdatedRef = useRef<string | undefined>(page.updated_at);
  const editSeqRef = useRef(0);
  const autosavingRef = useRef(false);
  const doAutosaveRef = useRef<() => Promise<void>>(async () => {});
  const markDirty = useCallback(() => { editSeqRef.current += 1; setEditSeq(editSeqRef.current); setDirty(true); }, []);

  /* WF-C1 — مقادیر مشتقِ سئو: شناسهٔ تصویر OG و وضعیت noindex. */
  const ogImageId = (() => {
    const a = meta.og_image_media_id;
    if (typeof a === "number" && a > 0) return a;
    const b = meta.og_image;
    return typeof b === "number" && b > 0 ? b : 0;
  })();
  const noindex = meta.noindex === true || String(meta.robots ?? "").toLowerCase().includes("noindex");

  /* WF-M1 — مقادیر زندهٔ سئو برای پیش‌نمایش SERP (خالص، بدون state). */
  const seoTitle = String(meta.title ?? meta.seo_title ?? "");
  const seoDescription = String(meta.description ?? meta.seo_description ?? "");
  const seoTitleState = serpCharState(serpLength(seoTitle), SERP_TITLE_MIN, SERP_TITLE_MAX);
  const seoDescState = serpCharState(serpLength(seoDescription), SERP_DESC_MIN, SERP_DESC_MAX);
  const serpUrl = serpUrlParts(String(meta.canonical ?? ""), slug);

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
    /* WF-H3 — آخرین `updated_at` شناخته‌شده؛ اگر سرور جلوتر رفته باشد ۴۰۹ می‌دهد. */
    updated_at: lastUpdatedRef.current,
  });

  const refreshRevisions = useCallback(async () => {
    try {
      const r = await authed<{ data: Revision[] } | Revision[]>(`/v1/admin/pages/${page.id}/revisions`);
      setRevisions(Array.isArray(r) ? r : (r.data ?? []));
    } catch { /* نسخه‌ها اختیاری */ }
  }, [page.id]);

  /* WF-H14 — بارگذاری وضعیت ترجمه + ساخت/همگام‌سازی همزادِ زبانِ مقابل. */
  const sourceLocale = (page as PageItem & { locale?: string | null }).locale ?? "fa";

  const loadTranslation = useCallback(async () => {
    try {
      const r = await authed<TranslationInfo>(`/v1/admin/pages/${page.id}/translation`);
      setTranslation(r);
    } catch { /* ترجمه اختیاری است */ }
  }, [page.id]);

  useEffect(() => { void loadTranslation(); }, [loadTranslation]);

  const syncTranslation = async (navigate: boolean) => {
    setTranslating(true);
    try {
      const r = await authed<TranslationInfo>(`/v1/admin/pages/${page.id}/translation`, { method: "POST" });
      setTranslation(r);
      toast("ترجمه ساخته/همگام شد.", "ok");
      if (navigate && r.sibling) {
        window.location.assign(`/admin/pages/${r.sibling.id}/edit`);
      }
    } catch (e) {
      toast(e instanceof Error ? e.message : "عملیات ترجمه ناموفق بود.", "err");
    } finally {
      setTranslating(false);
    }
  };

  const goToLocale = (target: string) => {
    if (target === sourceLocale) return;
    if (dirty) { toast("ابتدا تغییرات را ذخیره کنید؛ سپس زبان را عوض کنید.", "err"); return; }
    if (translation?.sibling) { window.location.assign(`/admin/pages/${translation.sibling.id}/edit`); return; }
    void syncTranslation(true);
  };

  const tBadge = translation ? translationStatusBadge(translation.status) : null;

  const persistDraft = async () => {
    const res = await authed<{ status?: string; updated_at?: string }>(`/v1/admin/pages/${page.id}`, { method: "PUT", body: payload() });
    if (res?.status) setStatus(res.status as PageItem["status"]);
    if (res?.updated_at) lastUpdatedRef.current = res.updated_at;
    return res;
  };

  const saveDraft = async () => {
    if (autosavingRef.current) return;
    if (!title.trim()) { toast("عنوان صفحه الزامی است.", "err"); return; }
    setSaving(true);
    try {
      const res = await persistDraft() as {
        status?: string;
        redirect_suggestion?: { from_path: string; to_path: string } | null;
      };
      setDirty(false);
      setConflict(false);
      setSavedAt(new Date());
      toast("پیش‌نویس ذخیره شد (نسخه جدید ساخته شد).", "ok");
      if (res?.redirect_suggestion) setRedirectSuggestion(res.redirect_suggestion);
      await refreshRevisions();
      router.refresh();
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setConflict(true);
        toast("این صفحه توسط مدیر دیگری تغییر کرده است — برای جلوگیری از بازنویسی، محتوای تازه را بارگذاری کنید.", "err");
      } else {
        toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
      }
    } finally {
      setSaving(false);
    }
  };

  /* WF-H3 — بارگذاری محتوای تازه از سرور (رفع تعارض نسخه/قفلِ کهنه). */
  const reloadFromServer = async () => {
    try {
      const full = await authed<PageItem>(`/v1/admin/pages/${page.id}`);
      setTitle(full.title);
      setSlug(full.slug);
      setBlocks((full.blocks ?? []).map((b) => ({ ...b, data: b.data ?? {}, _key: key() })));
      setMeta((full.meta as Record<string, unknown>) ?? {});
      setStatus(full.status);
      setIsSingle(full.is_single ?? false);
      setLeftEnabled(full.left_enabled ?? true);
      setRightEnabled(full.right_enabled ?? true);
      setLeftPresetId(full.left_preset_id ?? null);
      setRightPresetId(full.right_preset_id ?? null);
      lastUpdatedRef.current = full.updated_at;
      setSelected(null);
      setConflict(false);
      setDirty(false);
      toast("محتوای تازه از سرور بارگذاری شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری محتوای تازه ناموفق بود.", "err");
    }
  };

  /* WF-H3 — تصاحبِ عمدیِ قفل در دستِ مدیر دیگر. */
  const takeOverLock = async () => {
    try {
      await authed(`/v1/admin/pages/${page.id}/lock`, { method: "POST", body: { force: true } });
      setLockHolder(null);
      toast("قفل ویرایش به شما منتقل شد؛ مراقب ویرایش هم‌زمان باشید.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "تصاحب قفل ناموفق بود.", "err");
    }
  };

  /* WF-H3 — heartbeat قفل + بنر «در حال ویرایش توسط …» + آزادسازی هنگام خروج. */
  useEffect(() => {
    let stopped = false;
    const beat = async () => {
      try {
        await authed<{ owned: boolean; lock: EditLockHolder }>(`/v1/admin/pages/${page.id}/lock`, { method: "POST", body: {} });
        if (!stopped) setLockHolder(null);
      } catch (e) {
        if (stopped) return;
        if (e instanceof ApiError && e.status === 409) {
          const body = e.payload as { data?: { lock?: EditLockHolder } } | undefined;
          setLockHolder(body?.data?.lock ?? null);
        }
      }
    };
    void beat();
    const timer = window.setInterval(() => { void beat(); }, LOCK_HEARTBEAT_MS);
    return () => {
      stopped = true;
      window.clearInterval(timer);
      void authed(`/v1/admin/pages/${page.id}/lock`, { method: "DELETE" }).catch(() => undefined);
    };
  }, [page.id]);

  /* WF-H3 — نگه‌داشتنِ تازه‌ترین تابع ذخیرهٔ خودکار (پرهیز از closure کهنه). */
  useEffect(() => {
    doAutosaveRef.current = async () => {
      if (!autosaveGate({ dirty, saving, publishing, hasConflict: conflict, hasLockHolder: lockHolder !== null })) return;
      if (autosavingRef.current) return;
      autosavingRef.current = true;
      setAutosaving(true);
      const seqAtStart = editSeqRef.current;
      try {
        const res = await authed<{ status?: string; updated_at?: string }>(
          `/v1/admin/pages/${page.id}`,
          { method: "PUT", body: payload() },
        );
        if (res?.status) setStatus(res.status as PageItem["status"]);
        if (res?.updated_at) lastUpdatedRef.current = res.updated_at;
        if (editSeqRef.current === seqAtStart) setDirty(false);
        setSavedAt(new Date());
        void refreshRevisions();
      } catch (e) {
        if (e instanceof ApiError && e.status === 409) {
          setConflict(true);
          toast("این صفحه توسط مدیر دیگری تغییر کرده است — ذخیرهٔ خودکار متوقف شد.", "err");
        } else {
          toast("ذخیرهٔ خودکار ناموفق بود.", "err");
        }
      } finally {
        autosavingRef.current = false;
        setAutosaving(false);
      }
    };
  });

  /* WF-H3 — زمان‌بندیِ ذخیرهٔ خودکار ~۳۰ ثانیه پس از آخرین تغییر (debounce). */
  useEffect(() => {
    if (!autosaveGate({ dirty, saving, publishing, hasConflict: conflict, hasLockHolder: lockHolder !== null })) return;
    const d = createDebouncer(() => { void doAutosaveRef.current(); }, AUTOSAVE_MS, realTimers);
    d.schedule();
    return () => d.cancel();
  }, [dirty, saving, publishing, conflict, lockHolder, editSeq]);

  const createSuggestedRedirect = async () => {
    if (!redirectSuggestion) return "انصراف";
    try {
      await authed("/v1/admin/redirects", {
        method: "POST",
        body: { from_path: redirectSuggestion.from_path, to_path: redirectSuggestion.to_path, status_code: 301 },
      });
      setRedirectSuggestion(null);
      toast("ریدایرکت ۳۰۱ از اسلاگ قدیم به جدید ثبت شد.", "ok");
      return "ریدایرکت ثبت شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "ثبت ریدایرکت ناموفق بود.";
      toast(message, "err");
      throw new Error(message);
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
        } catch (e) {
          if (e instanceof ApiError && e.status === 409) setConflict(true);
          throw new Error("ذخیره پیش‌نویس ناموفق بود؛ انتشار انجام نشد.");
        }
        setDirty(false);
        draftSaved = true;
      }
      try {
        const pub = await authed<{ updated_at?: string }>(`/v1/admin/pages/${page.id}/publish`, { method: "POST" });
        if (pub?.updated_at) lastUpdatedRef.current = pub.updated_at;
      } catch {
        throw new Error(draftSaved
          ? "انتشار ناموفق بود؛ پیش‌نویس ذخیره شد، اما صفحه منتشر نشد."
          : "انتشار صفحه ناموفق بود؛ دوباره تلاش کنید.");
      }
      setStatus("published");
      setDirty(false);
      setSavedAt(new Date());
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

  /* WF-H5 — لغو انتشار: status به draft برمی‌گردد اما نسخه/تاریخ انتشار حفظ می‌شود. */
  const doUnpublish = async () => {
    if (unpublishing) return "عملیات در حال انجام است.";
    setUnpublishing(true);
    setSaving(true);
    try {
      if (dirty) {
        if (!title.trim()) throw new Error("عنوان صفحه الزامی است.");
        try {
          await persistDraft();
        } catch (e) {
          if (e instanceof ApiError && e.status === 409) setConflict(true);
          throw new Error("ذخیره پیش‌نویس ناموفق بود؛ لغو انتشار انجام نشد.");
        }
        setDirty(false);
      }
      try {
        const res = await authed<{ updated_at?: string }>(`/v1/admin/pages/${page.id}/unpublish`, { method: "POST" });
        if (res?.updated_at) lastUpdatedRef.current = res.updated_at;
      } catch {
        throw new Error("لغو انتشار صفحه ناموفق بود؛ دوباره تلاش کنید.");
      }
      setStatus("draft");
      setSavedAt(new Date());
      toast("انتشار صفحه لغو شد (به پیش‌نویس برگشت).", "ok");
      await refreshRevisions();
      router.refresh();
      return "انتشار صفحه لغو شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "لغو انتشار صفحه ناموفق بود؛ دوباره تلاش کنید.";
      toast(message, "err");
      throw new Error(message);
    } finally {
      setSaving(false);
      setUnpublishing(false);
    }
  };

  /* WF-C3 — باز/بستهٔ پنل زمان‌بندی با پیش‌پرکردن از موعد فعلی. */
  const openSchedule = () => {
    if (scheduledAt) {
      setSchedDate(isoToLocalDate(scheduledAt));
      setSchedTime(isoToLocalTime(scheduledAt));
    } else {
      setSchedDate(todayLocal());
      setSchedTime("09:00");
    }
    setShowSchedule(true);
  };

  const doSchedule = async () => {
    if (!schedDate) { toast("تاریخ انتشار را انتخاب کنید.", "err"); return; }
    const local = new Date(`${schedDate}T${schedTime || "00:00"}:00`);
    if (Number.isNaN(local.getTime())) { toast("تاریخ یا ساعت نامعتبر است.", "err"); return; }
    if (local.getTime() <= Date.now()) { toast("زمان انتخاب‌شده باید در آینده باشد.", "err"); return; }
    setScheduling(true);
    try {
      await authed(`/v1/admin/pages/${page.id}/schedule`, {
        method: "POST",
        body: { scheduled_at: local.toISOString() },
      });
      setScheduledAt(local.toISOString());
      setShowSchedule(false);
      toast("انتشار زمان‌بندی شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "زمان‌بندی انتشار ناموفق بود.", "err");
    } finally {
      setScheduling(false);
    }
  };

  const doCancelSchedule = async () => {
    try {
      await authed(`/v1/admin/pages/${page.id}/schedule`, { method: "DELETE" });
      setScheduledAt(null);
      setShowSchedule(false);
      toast("زمان‌بندی انتشار لغو شد.", "ok");
      router.refresh();
      return "زمان‌بندی لغو شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "لغو زمان‌بندی ناموفق بود.";
      toast(message, "err");
      throw new Error(message);
    } finally {
      setArmCancelSchedule(false);
    }
  };

  /* WF-H2 — ساخت/لغو لینک اشتراک پیش‌نویس (آخرین نسخهٔ ذخیره‌شده). */
  const createShareLink = async () => {
    setSharing(true);
    try {
      const res = await authed<{ url: string; token: string; expires_at: string }>(
        `/v1/admin/pages/${page.id}/share`,
        { method: "POST" },
      );
      setShareLink(res);
      toast("لینک اشتراک پیش‌نویس ساخته شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت لینک اشتراک ناموفق بود.", "err");
    } finally {
      setSharing(false);
    }
  };

  const revokeShareLink = async () => {
    setRevokingShare(true);
    try {
      await authed(`/v1/admin/pages/${page.id}/share`, { method: "DELETE" });
      setShareLink(null);
      setArmRevokeShare(false);
      toast("لینک اشتراک پیش‌نویس لغو شد.", "ok");
      return "لینک اشتراک لغو شد.";
    } catch (e) {
      const message = e instanceof Error ? e.message : "لغو لینک اشتراک ناموفق بود.";
      toast(message, "err");
      throw new Error(message);
    } finally {
      setRevokingShare(false);
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
      lastUpdatedRef.current = full.updated_at;
      setConflict(false);
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
          {autosaving ? <span style={{ fontSize: 12, color: "var(--text-muted)" }}>در حال ذخیرهٔ خودکار…</span> : null}
          {savedAt && !dirty && !autosaving ? <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{`ذخیره شد در ${formatSavedClock(savedAt)}`}</span> : null}
        </div>
        <button className="btn btn-primary" onClick={() => void saveDraft()} disabled={saving || autosaving || !dirty || lockHolder !== null} style={{ fontWeight: 800 }}>{saving ? "…" : "✓ ذخیره پیش‌نویس"}</button>
        <button className="btn btn-ghost" onClick={() => setPreview(true)}>پیش‌نمایش</button>
      </div>

      {/* WF-H3 — قفل نرم: بنر دارندهٔ قفل + هشدار تعارض نسخه */}
      {lockHolder ? (
        <Alert tone="amber">
          <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
            <span>در حال ویرایش توسط «{lockHolder.user_name}» — تا آزادشدنِ قفل، ذخیرهٔ خودکار متوقف است تا از بازنویسی جلوگیری شود.</span>
            <button className="btn btn-ghost btn-sm" onClick={() => void takeOverLock()}>تصاحب ویرایش</button>
            <button className="btn btn-ghost btn-sm" onClick={() => void reloadFromServer()}>بارگذاری محتوای تازه</button>
          </div>
        </Alert>
      ) : null}
      {conflict ? (
        <Alert tone="red">
          <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
            <span>این صفحه از زمانِ بارگذاریِ شما تغییر کرده است؛ برای جلوگیری از بازنویسی، محتوای تازه را بارگذاری کنید.</span>
            <button className="btn btn-ghost btn-sm" onClick={() => void reloadFromServer()}>بارگذاری محتوای تازه</button>
          </div>
        </Alert>
      ) : null}

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
              <div className="field">
                <label style={{ display: "flex", justifyContent: "space-between", gap: 8 }}>
                  <span>عنوان سئو</span>
                  <span
                    style={{ color: SERP_TONE[seoTitleState], fontVariantNumeric: "tabular-nums" }}
                    title={`بازهٔ پیشنهادی ${faNum(SERP_TITLE_MIN)} تا ${faNum(SERP_TITLE_MAX)} کاراکتر`}
                  >
                    {faNum(serpLength(seoTitle))} / {faNum(SERP_TITLE_MAX)}
                  </span>
                </label>
                <input className="input" value={seoTitle} onChange={(e) => { setMeta({ ...meta, title: e.target.value }); markDirty(); }} />
              </div>
              <div className="field"><label>نامک (slug)</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={slug} onChange={(e) => { setSlug(e.target.value); markDirty(); }} /></div>
              <div className="field full">
                <label style={{ display: "flex", justifyContent: "space-between", gap: 8 }}>
                  <span>توضیحات متا</span>
                  <span
                    style={{ color: SERP_TONE[seoDescState], fontVariantNumeric: "tabular-nums" }}
                    title={`بازهٔ پیشنهادی ${faNum(SERP_DESC_MIN)} تا ${faNum(SERP_DESC_MAX)} کاراکتر`}
                  >
                    {faNum(serpLength(seoDescription))} / {faNum(SERP_DESC_MAX)}
                  </span>
                </label>
                <textarea className="input" rows={2} value={seoDescription} onChange={(e) => { setMeta({ ...meta, description: e.target.value }); markDirty(); }} />
              </div>
              <div className="field"><label>کنونیکال</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={String(meta.canonical ?? "")} onChange={(e) => { setMeta({ ...meta, canonical: e.target.value }); markDirty(); }} /></div>
              <div className="field">
                <label>تصویر og</label>
                <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                  <span className="chip">{ogImageId > 0 ? `فایل #${ogImageId}` : "انتخاب نشده"}</span>
                  <button className="btn btn-ghost btn-sm" onClick={() => setOgPicker(true)}>انتخاب…</button>
                </div>
              </div>
              <div className="field">
                <label>عدم ایندکس در موتورهای جستجو</label>
                <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={noindex}
                    className="switch"
                    aria-label="این صفحه ایندکس نشود"
                    title={noindex ? "فعال — برای غیرفعال کلیک کن" : "غیرفعال — برای فعال کلیک کن"}
                    onClick={() => {
                      const next = !noindex;
                      setMeta({ ...meta, noindex: next, robots: next ? "noindex, nofollow" : "" });
                      markDirty();
                    }}
                  />
                  <span>{noindex ? "ایندکس نمی‌شود (noindex)" : "ایندکس می‌شود"}</span>
                </div>
              </div>
            </div>

            {/* WF-M1 — پیش‌نمایش گوگل‌مانند با مقادیر زندهٔ فیلدهای بالا. */}
            <div style={{ marginBlockStart: 14, borderBlockStart: "1px dashed var(--border)", paddingBlockStart: 12 }}>
              <div style={{ fontSize: 12, color: "var(--text-muted)", marginBlockEnd: 8 }}>پیش‌نمایش تقریبی نتیجهٔ گوگل</div>
              <div style={{ background: "var(--bg)", border: "1px solid var(--border)", borderRadius: 10, padding: "14px 16px", maxInlineSize: 620 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 8, marginBlockEnd: 8 }}>
                  <span
                    aria-hidden
                    style={{ inlineSize: 28, blockSize: 28, borderRadius: "50%", background: "var(--primary-soft)", color: "var(--primary)", display: "inline-flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, flexShrink: 0 }}
                  >
                    {serpUrl.host.replace(/^www\./, "").charAt(0).toUpperCase() || "؟"}
                  </span>
                  <span style={{ display: "flex", flexDirection: "column", lineHeight: 1.25, minInlineSize: 0 }}>
                    <span style={{ fontSize: 13.5, color: "var(--text)" }}>{serpUrl.host}</span>
                    <span style={{ fontSize: 11.5, color: "var(--text-muted)" }}>وب‌سایت شما</span>
                  </span>
                </div>
                <div style={{ color: "var(--info)", fontSize: 17, lineHeight: 1.5, marginBlockEnd: 2, overflowWrap: "anywhere" }}>
                  {truncateForSerp(seoTitle.length > 0 ? seoTitle : "عنوان صفحه", SERP_TITLE_MAX)}
                </div>
                <div dir="ltr" style={{ fontSize: 12.5, color: "var(--text-muted)", textAlign: "left", overflowWrap: "anywhere", marginBlockEnd: 4 }}>
                  {serpUrl.host}{serpUrl.path}
                </div>
                <div style={{ fontSize: 13.5, color: "var(--text)", lineHeight: 1.65, overflowWrap: "anywhere" }}>
                  {truncateForSerp(seoDescription.length > 0 ? seoDescription : "توضیحات متا برای این صفحه ثبت نشده است.", SERP_DESC_MAX)}
                </div>
              </div>
              {noindex ? (
                <p style={{ fontSize: 12, color: "var(--warning)", marginBlock: "8px 0" }}>
                  این صفحه با noindex علامت خورده و در نتایج گوگل نمایش داده نمی‌شود.
                </p>
              ) : null}
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
            <button className="btn btn-success btn-block" style={{ marginBlockStart: 12 }} onClick={() => void publish()} disabled={saving || publishing || conflict || lockHolder !== null}>
              {publishing ? "در حال انتشار…" : saving ? "در حال ذخیره…" : "انتشار"}
            </button>

            {/* WF-H5 — لغو انتشار مستقل؛ فقط روی صفحهٔ منتشرشده. */}
            {status === "published" ? (
              <button
                className="btn btn-ghost btn-block"
                style={{ marginBlockStart: 8 }}
                onClick={() => setArmUnpublish(true)}
                disabled={saving || publishing || unpublishing || conflict || lockHolder !== null}
              >
                {unpublishing ? "در حال لغو انتشار…" : "لغو انتشار (برگشت به پیش‌نویس)"}
              </button>
            ) : null}

            {/* WF-C3 — انتشار زمان‌بندی‌شده */}
            {scheduledAt ? (
              <>
                <div className="kv" style={{ marginBlockStart: 10 }}>
                  <span>زمان انتشار</span>
                  <span><Badge tone="amber">زمان‌بندی‌شده</Badge></span>
                </div>
                <div className="kv"><span>{jalali(scheduledAt)}</span></div>
                <button className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={() => setArmCancelSchedule(true)} disabled={scheduling}>
                  لغو زمان‌بندی
                </button>
              </>
            ) : showSchedule ? (
              <div style={{ marginBlockStart: 12, borderBlockStart: "1px dashed var(--border)", paddingBlockStart: 10 }}>
                <JalaliDatePicker label="تاریخ انتشار" value={schedDate} onChange={setSchedDate} />
                <div className="field">
                  <label>ساعت انتشار</label>
                  <input type="time" className="input" value={schedTime} onChange={(e) => setSchedTime(e.target.value)} aria-label="ساعت انتشار" />
                </div>
                <div style={{ display: "flex", gap: 8, marginBlockStart: 10 }}>
                  <button className="btn btn-primary btn-sm" onClick={() => void doSchedule()} disabled={scheduling}>
                    {scheduling ? "…" : "ثبت زمان‌بندی"}
                  </button>
                  <button className="btn btn-ghost btn-sm" onClick={() => setShowSchedule(false)}>انصراف</button>
                </div>
              </div>
            ) : (
              <button className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={openSchedule}>
                زمان‌بندی انتشار
              </button>
            )}

            <button className="btn btn-ghost btn-block" style={{ marginBlockStart: 8 }} onClick={() => setPreview(true)}>پیش‌نمایش</button>
          </div>

          {/* WF-H14 — ترجمهٔ محتوای چندزبانه: سوییچ زبان + همزاد + نشان وضعیت */}
          <div className="card card-pad">
            <div className="card-title" style={{ marginBlockEnd: 6 }}>ترجمه</div>
            <div className="tabs" role="tablist" aria-label="زبان ویرایش" style={{ marginBlockEnd: 10 }}>
              {(["fa", "en"] as const).map((loc) => (
                <a
                  key={loc}
                  className={sourceLocale === loc ? "on" : ""}
                  role="tab"
                  aria-selected={sourceLocale === loc}
                  onClick={() => goToLocale(loc)}
                  style={{ cursor: sourceLocale === loc ? "default" : "pointer" }}
                >
                  {loc === "fa" ? "فارسی" : "English"}
                </a>
              ))}
            </div>
            {translation === null || tBadge === null ? (
              <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>در حال بررسی وضعیت ترجمه…</p>
            ) : (
              <>
                <div className="kv">
                  <span>زبان مقابل</span>
                  <span>{translation.target_locale === "fa" ? "فارسی" : "English"}</span>
                </div>
                <div className="kv">
                  <span>وضعیت</span>
                  <span><Badge tone={tBadge.tone}>{tBadge.label}</Badge></span>
                </div>
                {translation.sibling ? (
                  <>
                    <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlock: "8px 0" }}>
                      همزاد: {translation.sibling.title} (<span dir="ltr">{translation.sibling.slug}</span>)
                    </p>
                    <div style={{ display: "flex", gap: 8, marginBlockStart: 10, flexWrap: "wrap" }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => goToLocale(translation.target_locale)}>
                        ویرایش ترجمه
                      </button>
                      {translation.status === "needs_update" ? (
                        <button className="btn btn-primary btn-sm" onClick={() => void syncTranslation(false)} disabled={translating}>
                          {translating ? "…" : "ثبت همگام‌سازی"}
                        </button>
                      ) : null}
                    </div>
                  </>
                ) : (
                  <button className="btn btn-primary btn-block" style={{ marginBlockStart: 10 }} onClick={() => void syncTranslation(true)} disabled={translating}>
                    {translating ? "…" : "ساخت ترجمه"}
                  </button>
                )}
              </>
            )}
          </div>

          {/* WF-H2 — لینک اشتراک پیش‌نویس (کوتاه‌عمر، بدون ورود، noindex) */}
          <div className="card card-pad">
            <div className="card-title" style={{ marginBlockEnd: 6 }}>لینک اشتراک پیش‌نویس</div>
            <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlock: "0 10px" }}>
              لینک کوتاه‌عمر از آخرین نسخهٔ ذخیره‌شده می‌سازد؛ بدون ورود قابل مشاهده است و در موتورهای جستجو ایندکس نمی‌شود.
            </p>
            {shareLink ? (
              <>
                <div className="kv">
                  <span>انقضا</span>
                  <span>{jalali(shareLink.expires_at)}</span>
                </div>
                <input
                  className="input"
                  dir="ltr"
                  readOnly
                  value={shareLink.url}
                  aria-label="نشانی لینک اشتراک"
                  style={{ marginBlockStart: 8, textAlign: "left" }}
                />
                <div style={{ display: "flex", gap: 8, marginBlockStart: 8, flexWrap: "wrap" }}>
                  <CopyToClipboard text={shareLink.url} label="کپی لینک" />
                  <a className="btn btn-ghost btn-sm" href={shareLink.url} target="_blank" rel="noreferrer">بازکردن</a>
                  <button className="btn btn-ghost btn-sm" onClick={() => setArmRevokeShare(true)} disabled={revokingShare}>
                    {revokingShare ? "…" : "لغو لینک"}
                  </button>
                </div>
              </>
            ) : (
              <button className="btn btn-ghost btn-block" onClick={() => void createShareLink()} disabled={sharing}>
                {sharing ? "…" : "ساخت لینک اشتراک"}
              </button>
            )}
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
          open selected={ogImageId > 0 ? [ogImageId] : []}
          onChange={(ids) => { setMeta({ ...meta, og_image_media_id: ids[0] ?? 0 }); markDirty(); }}
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
        open={armUnpublish}
        title="لغو انتشار صفحه؟"
        description="صفحه از دسترس عموم خارج می‌شود اما حذف نمی‌شود؛ نسخه و تاریخ انتشار برای بازگردانی حفظ می‌مانند و بعداً می‌توان دوباره منتشر کرد."
        confirmLabel="لغو انتشار"
        busyLabel="در حال لغو انتشار…"
        onConfirm={() => doUnpublish()}
        onClose={() => setArmUnpublish(false)}
      />
      <ConfirmStepper
        open={armCancelSchedule}
        title="لغو زمان‌بندی انتشار؟"
        description={scheduledAt ? `انتشار زمان‌بندی‌شده برای «${jalali(scheduledAt)}» لغو شود؟` : ""}
        confirmLabel="لغو زمان‌بندی"
        onConfirm={() => doCancelSchedule()}
        onClose={() => setArmCancelSchedule(false)}
      />
      <ConfirmStepper
        open={armRevokeShare}
        title="لغو لینک اشتراک پیش‌نویس؟"
        description="پس از لغو، این لینک (و هر لینک فعالِ این صفحه) دیگر باز نمی‌شود."
        confirmLabel="لغو لینک"
        onConfirm={() => revokeShareLink()}
        onClose={() => setArmRevokeShare(false)}
      />
      <ConfirmStepper
        open={redirectSuggestion !== null}
        title="ثبت ریدایرکت ۳۰۱؟"
        description={redirectSuggestion
          ? `اسلاگ این صفحه عوض شد. مسیر «${redirectSuggestion.from_path}» به «${redirectSuggestion.to_path}» ریدایرکت دائمی شود تا لینک‌های قدیمی نشکنند؟`
          : ""}
        confirmLabel="ثبت ریدایرکت"
        onConfirm={() => createSuggestedRedirect()}
        onClose={() => setRedirectSuggestion(null)}
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
