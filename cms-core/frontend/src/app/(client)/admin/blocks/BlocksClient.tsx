"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge } from "@/components/ui/primitives";
import { Tabs } from "@/components/ui/Tabs";
import { DragDropList } from "@/components/ui/DragDropList";
import { Modal } from "@/components/ui/Overlays";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { faNum } from "@/lib/fa";
import type { BlockDef, BlockValue, PageTypeItem } from "@/lib/domain";

type BItem = BlockValue & { id: string };

const uid = () => `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/**
 * ویرایشگر بلوک‌های پیش‌فرض نوع صفحه — UX ساده برای کاربر غیرفنی (تسک ۷):
 * کارت‌های بزرگ با توضیح فارسی، جابه‌جایی با دکمه، پیش‌نمایش زنده خلاصه،
 * ویرایش با فرم (نه JSON) — بدون اصطلاح فنی.
 * تب‌ها فقط typeهای enabled از GET layouts/page-types هستند (بدون هاردکد)؛
 * type ثبت‌شده از مانیفست پلاگین خودکار تب تازه می‌گیرد.
 *
 * پرچم فعال/غیرفعال در `data._enabled` نگه داشته می‌شود (بک‌اند data را دست‌نخورده ذخیره می‌کند
 * و رندرر عمومی بلوک‌های `_enabled === false` را رد می‌کند).
 */
export function BlocksClient({ types, registry, active }: { types: PageTypeItem[]; registry: BlockDef[]; active: string }) {
  const toast = useToast();
  const router = useRouter();
  const visible = types.filter((t) => t.enabled !== false);
  const current = visible.find((t) => t.type === active) ?? visible[0];
  const [items, setItems] = useState<BItem[]>(
    (current?.blocks ?? []).map((b) => ({ ...b, id: uid() })),
  );
  const [editing, setEditing] = useState<BItem | null>(null);
  const [advanced, setAdvanced] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setItems((current?.blocks ?? []).map((b) => ({ ...b, id: uid() })));
    setEditing(null);
    setAdvanced(false);
  }, [current?.type, current?.blocks]);

  if (!current) {
    return <Alert tone="amber">نوع صفحه‌ای در بک‌اند تعریف نشده (config/page_types).</Alert>;
  }

  const titleOf = (type: string) => registry.find((b) => b.type === type)?.title ?? type;
  const unknown = (type: string) => !registry.some((b) => b.type === type);

  const dirty = JSON.stringify(items.map(({ id, ...r }) => r)) !== JSON.stringify(current.blocks);

  const move = (from: number, to: number) =>
    setItems((prev) => {
      const list = [...prev];
      const [x] = list.splice(from, 1);
      list.splice(to, 0, x);
      return list;
    });

  const toggleEnabled = (id: string) =>
    setItems((prev) =>
      prev.map((b) => {
        if (b.id !== id) return b;
        const on = (b.data as Record<string, unknown>)._enabled !== false;
        return { ...b, data: { ...b.data, _enabled: !on } };
      }),
    );

  const save = async () => {
    setBusy(true);
    try {
      const blocks = items.map(({ type, data }) => ({ type, data }));
      const result = await authed<{
        type: string;
        blocks: BlockValue[];
        applied_pages?: Array<{ id: number; slug: string }>;
      }>(`/v1/admin/layouts/page-type/${current.type}/blocks`, { method: "PUT", body: { blocks } });
      const applied = result.applied_pages?.length ?? 0;
      toast(applied > 0
        ? `بلوک‌ها ذخیره شد و روی ${faNum(applied)} صفحه بدون override اعمال شد.`
        : "بلوک‌های نوع صفحه ذخیره شد؛ overrideهای مستقل صفحات حفظ شدند.",
      "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div key={current.type}>
      <Tabs
        active={current.type}
        tabs={visible.map((t) => ({
          key: t.type,
          label: t.title,
          href: `/admin/blocks?type=${encodeURIComponent(t.type)}`,
          badge: t.customized ? "سفارشی" : "",
        }))}
      />

      {current.description ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>{current.description}</p> : null}
      <Alert tone="blue">این پیش‌فرض هنگام ذخیره روی صفحات بدون override مستقل اعمال می‌شود؛ ویرایش‌های اختصاصی صفحات حفظ می‌شوند.</Alert>

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div className="card card-pad">
          <div className="card-title">افزودن بخش جدید</div>
          <p className="card-sub">یک کارت را لمس کنید تا به صفحه اضافه شود.</p>
          <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
            {registry.map((b) => (
              <button
                key={b.type}
                className="btn btn-ghost"
                style={{ justifyContent: "flex-start", textAlign: "start", padding: "12px 14px", minBlockSize: 56 }}
                title={b.description ?? b.title}
                onClick={() => setItems((prev) => [...prev, { id: uid(), type: b.type, data: defaultsFor(b) }])}
              >
                <span aria-hidden style={{ fontSize: 18 }}>＋</span>
                <span>
                  <b style={{ display: "block", fontSize: 14 }}>{b.title}</b>
                  {b.description ? <small style={{ color: "var(--text-muted)", fontSize: 12 }}>{b.description}</small> : null}
                </span>
              </button>
            ))}
          </div>
          {registry.length === 0 ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>بخشی برای افزودن تعریف نشده.</p> : null}
        </div>

        <div className="card card-pad">
          <div className="card-title">
            صفحه «{current.title}» ({faNum(items.length)} بخش) {dirty ? <span style={{ color: "var(--warning)" }}>● ذخیره‌نشده</span> : null}
          </div>
          {items.length === 0 ? <p style={{ color: "var(--text-muted)", fontSize: 13 }}>هنوز بخشی اضافه نشده — از فهرست کناری انتخاب کنید.</p> : null}
          <DragDropList
            items={items}
            onMove={move}
            render={(b, i, { moveUp, moveDown }) => {
              const on = (b.data as Record<string, unknown>)._enabled !== false;
              return (
                <div className="card" style={{ padding: 12, marginBlockEnd: 10, opacity: on ? 1 : 0.6 }}>
                  <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    <span aria-hidden style={{ cursor: "grab", fontSize: 16 }}>⠿</span>
                    <b style={{ fontSize: 14 }}>{faNum(i + 1)}. {titleOf(b.type)}</b>
                    {unknown(b.type) ? <Badge tone="gray">ناشناس</Badge> : null}
                    {on ? null : <Badge tone="gray">مخفی</Badge>}
                  </div>
                  <p style={{ fontSize: 12.5, color: "var(--text-muted)", margin: "6px 0 10px" }}>{summaryOf(b.data)}</p>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    <button className="btn btn-ghost btn-sm"  onClick={moveUp} aria-label="انتقال به بالا">↑ بالا</button>
                    <button className="btn btn-ghost btn-sm"  onClick={moveDown} aria-label="انتقال به پایین">↓ پایین</button>
                    <button className="btn btn-ghost btn-sm"  onClick={() => setEditing(b)}>ویرایش</button>
                    <button className="btn btn-ghost btn-sm"  onClick={() => toggleEnabled(b.id)}>{on ? "مخفی کن" : "نمایش بده"}</button>
                    <button className="btn btn-ghost btn-sm"  onClick={() => setItems((prev) => prev.filter((x) => x.id !== b.id))}>حذف</button>
                  </div>
                </div>
              );
            }}
          />
          <div style={{ display: "flex", gap: 8, marginBlockStart: 10, alignItems: "center" }}>
            <button className="btn btn-ghost btn-sm" onClick={() => setAdvanced(true)}>ویرایش پیشرفته…</button>
            <div style={{ flex: 1 }} />
            <button className="btn btn-primary" onClick={() => void save()} disabled={busy || !dirty}>{busy ? "…" : "ذخیره"}</button>
          </div>
        </div>
      </div>

      {editing ? (
        <EditBlockModal
          key={editing.id}
          item={editing}
          def={registry.find((b) => b.type === editing.type) ?? null}
          onApply={(data) => { setItems((prev) => prev.map((x) => (x.id === editing.id ? { ...x, data } : x))); setEditing(null); }}
          onClose={() => setEditing(null)}
        />
      ) : null}

      {advanced ? (
        <RawJsonModal
          value={items.map(({ id, ...r }) => r)}
          onApply={(blocks) => { setItems(blocks.map((b) => ({ ...b, id: uid() }))); setAdvanced(false); }}
          onClose={() => setAdvanced(false)}
        />
      ) : null}
    </div>
  );
}

/** پیش‌نمایش زنده خلاصه: مهم‌ترین فیلد نمایشی هر بخش، به فارسی ساده. */
function summaryOf(data: Record<string, unknown>): string {
  const str = (v: unknown) => (typeof v === "string" ? v.trim() : "");
  for (const k of ["title", "label", "heading", "quote", "text", "subtitle", "caption"]) {
    const v = str(data[k]);
    if (v) return v.length > 90 ? `${v.slice(0, 90)}…` : v;
  }
  const body = str(data.body).replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
  if (body) return body.length > 90 ? `${body.slice(0, 90)}…` : body;
  const href = str(data.href);
  if (href) return `پیوند: ${href}`;
  if (typeof data.media_id === "number" && data.media_id > 0) return `تصویر شماره ${faNum(data.media_id)}`;
  if (Array.isArray(data.media_ids)) return `${faNum(data.media_ids.length)} تصویر`;
  if (Array.isArray(data.links)) return `${faNum(data.links.length)} پیوند`;
  return "بدون متن — «ویرایش» را بزنید.";
}

/** مقادیر پیش‌فرض از schema رجیستری (default props). */
function defaultsFor(b: BlockDef): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, p] of Object.entries(b.schema?.properties ?? {})) {
    if (p.default !== undefined) out[k] = p.default;
  }
  return out;
}

function EditBlockModal({ item, def, onApply, onClose }: { item: BItem; def: BlockDef | null; onApply: (d: Record<string, unknown>) => void; onClose: () => void }) {
  const toast = useToast();
  const [raw, setRaw] = useState(JSON.stringify(item.data ?? {}, null, 2));
  const [value, setValue] = useState<Record<string, unknown>>((item.data ?? {}) as Record<string, unknown>);

  const applyRaw = () => {
    try {
      const parsed = JSON.parse(raw) as Record<string, unknown>;
      if (typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("bad");
      onApply(parsed);
    } catch {
      toast("متن واردشده معتبر نیست.", "err");
    }
  };

  return (
    <Modal title={`ویرایش: ${def?.title ?? item.type}`} onClose={onClose}>
      {def?.description ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>{def.description}</p> : null}
      {def ? (
        <div>
          <SchemaForm schema={def.schema} value={value} onChange={setValue} />
          <div style={{ display: "flex", gap: 8, marginBlockStart: 12 }}>
            <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
            <button className="btn btn-primary" onClick={() => onApply(value)}>اعمال</button>
          </div>
        </div>
      ) : (
        <div>
          <Alert tone="amber">این بخش در رجیستری شناخته‌شده نیست (مثلاً از پلاگین غیرفعال) — حذف نمی‌شود؛ فقط متن خام آن قابل ویرایش است.</Alert>
          <div className="field"><label>متن خام</label>
            <textarea className="input" dir="ltr" style={{ textAlign: "left", fontFamily: "monospace", minBlockSize: 160 }} value={raw} onChange={(e) => setRaw(e.target.value)} />
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
            <button className="btn btn-primary" onClick={applyRaw}>اعمال</button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function RawJsonModal({ value, onApply, onClose }: { value: BlockValue[]; onApply: (b: BlockValue[]) => void; onClose: () => void }) {
  const toast = useToast();
  const [raw, setRaw] = useState(JSON.stringify(value, null, 2));

  const apply = () => {
    try {
      const parsed = JSON.parse(raw) as BlockValue[];
      if (!Array.isArray(parsed) || parsed.some((b) => typeof b.type !== "string" || typeof b.data !== "object")) {
        throw new Error("bad");
      }
      onApply(parsed);
    } catch {
      toast("متن واردشده معتبر نیست.", "err");
    }
  };

  return (
    <Modal title="ویرایش پیشرفته" onClose={onClose}>
      <div className="field"><label>متن خام بخش‌ها</label>
        <textarea className="input" dir="ltr" style={{ textAlign: "left", fontFamily: "monospace", minBlockSize: 200 }} value={raw} onChange={(e) => setRaw(e.target.value)} />
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={apply}>اعمال</button>
      </div>
    </Modal>
  );
}
