"use client";
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { Modal } from "@/components/ui/Overlays";
import { Pagination } from "@/components/ui/Pagination";
import { faNum, jalali } from "@/lib/fa";
import { TICKET_STATUS_FA, TICKET_PRIORITY_FA, type Ticket, type TicketMessage, type Paginator } from "@/lib/domain";

const statusTone = (s: string) => (s === "open" ? "green" : s === "pending" ? "amber" : "gray") as "green" | "amber" | "gray";

/** Master-Detail تیکت: لیست (راست) + گفتگو (چپ) + پاسخ + بستن/بازگشایی. */
export function TicketsClient({ initial, status, search, priority, label, page, selectedId }: { initial: Paginator<Ticket>; status: string; search: string; priority: string; label: string; page: number; selectedId?: number | null }) {
  const toast = useToast();
  const router = useRouter();
  const [selected, setSelected] = useState<number | null>(selectedId ?? initial.data[0]?.id ?? null);
  const [creating, setCreating] = useState(false);

  const exportQs = new URLSearchParams();
  if (status) exportQs.set("status", status);
  if (search) exportQs.set("search", search);
  if (priority) exportQs.set("priority", priority);
  if (label) exportQs.set("label", label);

  return (
    <div>
      <form className="toolbar" method="get" action="/admin/tickets">
        <div className="field grow">
          <label htmlFor="tq">جستجو</label>
          <input id="tq" name="search" className="input" placeholder="موضوع یا متن پیام…" defaultValue={search} />
        </div>
        <div className="field">
          <label htmlFor="ts">وضعیت</label>
          <select id="ts" name="status" className="select" defaultValue={status}>
            <option value="">همه</option>
            <option value="open">باز</option>
            <option value="pending">در انتظار</option>
            <option value="closed">بسته</option>
          </select>
        </div>
        <div className="field">
          <label htmlFor="tp">اولویت</label>
          <select id="tp" name="priority" className="select" defaultValue={priority}>
            <option value="">همه</option>
            <option value="low">کم</option>
            <option value="normal">عادی</option>
            <option value="high">زیاد</option>
            <option value="urgent">فوری</option>
          </select>
        </div>
        <div className="field">
          <label htmlFor="tl">برچسب</label>
          <input id="tl" name="label" className="input" placeholder="مثلاً پرداخت" defaultValue={label} />
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
        <a className="btn btn-ghost" href={`/api/tickets/export?${exportQs.toString()}`}>خروجی CSV</a>
        <div style={{ flex: 1 }} />
        <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>＋ تیکت جدید</button>
      </form>

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title="تیکتی نیست" hint="اولین تیکت را ثبت کنید." /></div>
      ) : (
        <div className="grid tickets-grid">
          <div className="card" role="listbox" aria-label="لیست تیکت‌ها" style={{ overflow: "hidden" }}>
            {initial.data.map((t) => (
              <button
                key={t.id}
                role="option"
                aria-selected={selected === t.id}
                onClick={() => setSelected(t.id)}
                style={{
                  display: "block", inlineSize: "100%", textAlign: "start", padding: "10px 12px",
                  border: "none", borderBlockEnd: "1px solid var(--border)",
                  background: selected === t.id ? "var(--primary-soft)" : "transparent",
                  cursor: "pointer", color: "var(--text)",
                }}
              >
                <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
                  <b style={{ fontSize: 13.5 }}>#{faNum(t.id)} {t.subject}</b>
                </div>
                <div style={{ display: "flex", gap: 6, marginBlockStart: 4, alignItems: "center", flexWrap: "wrap" }}>
                  <Badge tone={statusTone(t.status)}>{TICKET_STATUS_FA[t.status]}</Badge>
                  <Badge tone="violet">{TICKET_PRIORITY_FA[t.priority] ?? t.priority}</Badge>
                  {(t.labels ?? []).map((lbl) => <Badge key={lbl} tone="gray">{lbl}</Badge>)}
                  <small style={{ color: "var(--text-muted)" }}>{jalali(t.updated_at)}</small>
                </div>
              </button>
            ))}
          </div>

          <div>
            {selected !== null ? (
              <TicketDetail key={selected} id={selected} onChanged={() => router.refresh()} onBack={() => setSelected(null)} />
            ) : (
              <div className="card card-pad"><EmptyState title="تیکتی انتخاب نشده" /></div>
            )}
          </div>
        </div>
      )}

      <Pagination page={initial.current_page} lastPage={initial.last_page} total={initial.total} base="/admin/tickets" params={{ status, search, priority, label, page: String(page) }} />
      {creating ? <TicketCreate onClose={() => { setCreating(false); router.refresh(); }} /> : null}
    </div>
  );
}

function TicketDetail({ id, onChanged, onBack }: { id: number; onChanged: () => void; onBack: () => void }) {
  const toast = useToast();
  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [body, setBody] = useState("");
  const [busy, setBusy] = useState(false);
  const [labelInput, setLabelInput] = useState("");

  const load = useCallback(async () => {
    try {
      setError(null);
      const t = await authed<Ticket>(`/v1/admin/tickets/${id}`);
      setTicket(t);
    } catch (e) {
      setError(e instanceof Error ? e.message : "خطا در بارگذاری گفتگو.");
    }
  }, [id]);

  // بارگذاری در effect، نه حین render: فراخوانی load() داخل render باعث
  // setState در چرخه رندر می‌شد و React خطای #301 (too many re-renders) می‌داد.
  useEffect(() => {
    void load();
  }, [load]);

  const reply = async () => {
    if (!body.trim()) { toast("متن پیام الزامی است.", "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/admin/tickets/${id}/messages`, { method: "POST", body: { body: body.trim() } });
      setBody("");
      await load();
      onChanged();
      toast("پاسخ ثبت شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ارسال پاسخ ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const setStatus = async (status: "open" | "closed") => {
    try {
      await authed(`/v1/admin/tickets/${id}`, { method: "PUT", body: { status } });
      await load();
      onChanged();
      toast(status === "closed" ? "تیکت بسته شد." : "تیکت بازگشایی شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "تغییر وضعیت ناموفق بود.", "err");
    }
  };

  const setPriority = async (priority: string) => {
    try {
      await authed(`/v1/admin/tickets/${id}`, { method: "PUT", body: { priority } });
      await load();
      onChanged();
      toast("اولویت به‌روزرسانی شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "تغییر اولویت ناموفق بود.", "err");
    }
  };

  const saveLabels = async (labels: string[]) => {
    try {
      await authed(`/v1/admin/tickets/${id}`, { method: "PUT", body: { labels } });
      await load();
      onChanged();
      toast("برچسب‌ها به‌روزرسانی شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره برچسب‌ها ناموفق بود.", "err");
    }
  };

  const addLabel = () => {
    const value = labelInput.trim();
    if (!value) return;
    const current = ticket?.labels ?? [];
    if (current.includes(value)) { setLabelInput(""); return; }
    void saveLabels([...current, value]);
    setLabelInput("");
  };

  if (error) return <Alert tone="red">{error} <button className="btn btn-ghost btn-sm" onClick={() => { setTicket(null); setError(null); }}>تلاش مجدد</button></Alert>;
  if (!ticket) return <div className="card card-pad"><p style={{ color: "var(--text-muted)" }}>در حال بارگذاری گفتگو…</p></div>;

  return (
    <div className="card card-pad">
      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
        {/* حالت موبایل تک‌ستونه: بازگشت به لیست (دسته ۱) */}
        <button className="btn btn-ghost btn-sm" onClick={onBack} aria-label="بازگشت به لیست تیکت‌ها">→ لیست</button>
        <b>#{faNum(ticket.id)} {ticket.subject}</b>
        <Badge tone={statusTone(ticket.status)}>{TICKET_STATUS_FA[ticket.status]}</Badge>
        <div style={{ flex: 1 }} />
        {ticket.status === "closed" ? (
          <button className="btn btn-ghost btn-sm" onClick={() => void setStatus("open")}>بازگشایی</button>
        ) : (
          <button className="btn btn-ghost btn-sm" onClick={() => void setStatus("closed")}>بستن تیکت</button>
        )}
      </div>

      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", marginBlockStart: 10 }}>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="tdp" style={{ fontSize: 12 }}>اولویت</label>
          <select id="tdp" className="select" value={ticket.priority} onChange={(e) => void setPriority(e.target.value)}>
            <option value="low">کم</option>
            <option value="normal">عادی</option>
            <option value="high">زیاد</option>
            <option value="urgent">فوری</option>
          </select>
        </div>
        <div style={{ display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap" }}>
          {(ticket.labels ?? []).map((lbl) => (
            <span key={lbl} className="badge b-gray" style={{ display: "inline-flex", gap: 4, alignItems: "center" }}>
              {lbl}
              <button
                type="button"
                aria-label={`حذف برچسب ${lbl}`}
                onClick={() => void saveLabels((ticket.labels ?? []).filter((x) => x !== lbl))}
                style={{ border: "none", background: "transparent", cursor: "pointer", color: "inherit", padding: 0 }}
              >×</button>
            </span>
          ))}
          {(ticket.labels ?? []).length === 0 ? <small style={{ color: "var(--text-muted)" }}>بدون برچسب</small> : null}
        </div>
        <div style={{ display: "flex", gap: 6, alignItems: "flex-end" }}>
          <input
            className="input" style={{ inlineSize: 150 }} placeholder="برچسب جدید…" aria-label="برچسب جدید"
            value={labelInput} onChange={(e) => setLabelInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addLabel(); } }}
          />
          <button type="button" className="btn btn-ghost btn-sm" onClick={addLabel}>افزودن</button>
        </div>
      </div>

      <div style={{ display: "flex", flexDirection: "column", gap: 10, marginBlock: 14, maxBlockSize: 420, overflowY: "auto" }}>
        {(ticket.messages ?? []).map((m: TicketMessage) => (
          <div
            key={m.id}
            style={{
              alignSelf: m.author_type === "operator" ? "flex-start" : "flex-end",
              maxInlineSize: "85%", padding: "8px 12px", borderRadius: 12,
              background: m.author_type === "operator" ? "var(--surface-2, var(--surface))" : "var(--primary-soft)",
              border: "1px solid var(--border)",
            }}
          >
            <div style={{ fontSize: 12, color: "var(--text-muted)", marginBlockEnd: 4 }}>
              {m.author_type === "operator" ? "پشتیبانی" : (m.user?.name ?? "شما")} — {jalali(m.created_at)}
            </div>
            <div style={{ fontSize: 13.5, whiteSpace: "pre-wrap" }}>{m.body}</div>
          </div>
        ))}
        {(ticket.messages ?? []).length === 0 ? <EmptyState title="پیامی نیست" /> : null}
      </div>

      {ticket.status === "closed" ? (
        <Alert tone="amber">تیکت بسته شده است؛ برای ادامه، <Link href="/admin/tickets" onClick={(e) => e.preventDefault()} style={{ color: "var(--primary)" }}>تیکت جدید</Link> ثبت کنید.</Alert>
      ) : (
        <div style={{ display: "flex", gap: 8 }}>
          <input
            className="input" style={{ flex: 1 }} placeholder="پاسخ شما…" aria-label="متن پاسخ"
            value={body} onChange={(e) => setBody(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") void reply(); }}
          />
          <button className="btn btn-primary" onClick={() => void reply()} disabled={busy}>{busy ? "…" : "ارسال"}</button>
        </div>
      )}
    </div>
  );
}

function TicketCreate({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [priority, setPriority] = useState("normal");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!subject.trim() || !body.trim()) { toast("موضوع و متن پیام الزامی است.", "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/admin/tickets`, { method: "POST", body: { subject: subject.trim(), body: body.trim(), priority } });
      toast("تیکت ثبت شد.", "ok");
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ثبت تیکت ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="تیکت جدید" onClose={onClose}>
      <div className="field"><label>موضوع *</label><input className="input" value={subject} onChange={(e) => setSubject(e.target.value)} autoFocus /></div>
      <div className="field"><label>اولویت</label>
        <select className="select" value={priority} onChange={(e) => setPriority(e.target.value)}>
          <option value="low">کم</option><option value="normal">عادی</option><option value="high">زیاد</option><option value="urgent">فوری</option>
        </select>
      </div>
      <div className="field"><label>متن پیام *</label><textarea className="input" rows={4} value={body} onChange={(e) => setBody(e.target.value)} /></div>
      <div style={{ display: "flex", gap: 8 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ثبت تیکت"}</button>
      </div>
    </Modal>
  );
}
