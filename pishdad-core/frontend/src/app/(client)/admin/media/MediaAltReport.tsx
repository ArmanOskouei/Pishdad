"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, EmptyState } from "@/components/ui/primitives";
import { mediaUrl, type MediaItem, type Paginator } from "@/lib/domain";
import { faNum } from "@/lib/fa";

/** WF-H7 — گزارش تصاویر بدون alt با ویرایش درجا + «ذخیره همه». */
export function MediaAltReport() {
  const toast = useToast();
  const [items, setItems] = useState<MediaItem[]>([]);
  const [drafts, setDrafts] = useState<Record<number, string>>({});
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const page = await authed<Paginator<MediaItem>>("/v1/admin/media/alt-report?per_page=100");
      setItems(page.data);
      setTotal(page.total);
      setDrafts(Object.fromEntries(page.data.map((m) => [m.id, m.alt ?? ""])));
    } catch (e) {
      setError(e instanceof Error ? e.message : "خطا در بارگذاری گزارش.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const changed = items.filter((m) => (drafts[m.id] ?? "") !== (m.alt ?? ""));

  const saveAll = async () => {
    if (changed.length === 0) return toast("تغییری برای ذخیره نیست.", "err");
    setSaving(true);
    try {
      const r = await authed<{ updated: number }>("/v1/admin/media/bulk/alt", {
        method: "POST",
        body: { items: changed.map((m) => ({ id: m.id, alt: (drafts[m.id] ?? "").trim() || null })) },
      });
      toast(`${faNum(r.updated)} متن جایگزین ذخیره شد.`, "ok");
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBlockEnd: 8, flexWrap: "wrap" }}>
        <b style={{ fontSize: 14 }}>تصاویر بدون متن جایگزین (alt)</b>
        <span className="chip">{faNum(total)}</span>
        <div style={{ flex: 1 }} />
        <button className="btn btn-ghost btn-sm" onClick={() => void load()} disabled={loading}>بازخوانی</button>
        <button className="btn btn-primary" onClick={() => void saveAll()} disabled={saving || changed.length === 0}>
          {saving ? "در حال ذخیره…" : `ذخیره همه${changed.length > 0 ? ` (${faNum(changed.length)})` : ""}`}
        </button>
      </div>
      <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockEnd: 10 }}>
        متن جایگزین هم به خوانندهٔ صفحه (WCAG) و هم به موتورهای جستجو (سئوی تصویر) کمک می‌کند.
      </p>
      {error ? <Alert tone="red">{error}</Alert> : null}
      {loading ? (
        <div style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</div>
      ) : items.length === 0 ? (
        <EmptyState title="تصویری بدون alt نیست" hint="همهٔ تصاویر متن جایگزین دارند." />
      ) : (
        <div className="table-wrap">
          {items.map((m) => (
            <div key={m.id} className="mlist-row" style={{ gap: 10, alignItems: "center" }}>
              <img src={mediaUrl(m)} alt={drafts[m.id] || m.original_name} loading="lazy"
                style={{ inlineSize: 44, blockSize: 44, objectFit: "cover", borderRadius: 6 }} />
              <span style={{ minInlineSize: 0, flex: "0 1 260px" }}>
                <b>{m.original_name}</b><br />
                <small style={{ color: "var(--text-muted)" }} dir="ltr">{m.mime}</small>
                {m.folder ? <small style={{ color: "var(--text-muted)" }}> · 📁 {m.folder.name}</small> : null}
              </span>
              <input
                className="input"
                style={{ flex: 1, minInlineSize: 180 }}
                value={drafts[m.id] ?? ""}
                maxLength={200}
                placeholder="متن جایگزین را بنویسید…"
                aria-label={`متن جایگزین برای ${m.original_name}`}
                onChange={(e) => setDrafts((d) => ({ ...d, [m.id]: e.target.value }))}
              />
            </div>
          ))}
          {total > items.length ? (
            <div style={{ fontSize: 12, color: "var(--text-muted)", padding: 8 }}>
              {faNum(items.length)} از {faNum(total)} مورد نشان داده شده — پس از ذخیره، گزارش به‌روز می‌شود.
            </div>
          ) : null}
        </div>
      )}
    </div>
  );
}
