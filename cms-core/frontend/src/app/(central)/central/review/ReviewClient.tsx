"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { Modal } from "@/components/ui/Overlays";
import { faNum, jalali } from "@/lib/fa";
import type { ReviewQueueItem } from "@/lib/domain";

/** صف بازبینی مرکزی: جزئیات manifest، تأیید، رد با دلیل اجباری. */
export function ReviewClient({ initial }: { initial: ReviewQueueItem[] }) {
  const toast = useToast();
  const router = useRouter();
  const [rejecting, setRejecting] = useState<ReviewQueueItem | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);

  const refresh = () => router.refresh();

  const approve = async (item: ReviewQueueItem) => {
    setBusy(true);
    try {
      await authed(`/v1/p/central/review/${item.type}/${item.id}/approve`, { method: "POST" });
      toast("مورد تأیید شد و فعال‌سازی برای مشتری آزاد شد.", "ok");
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "تأیید ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const reject = async () => {
    if (!rejecting) return;
    if (note.trim().length < 5) {
      toast("دلیل رد الزامی است (حداقل ۵ نویسه).", "err");
      return;
    }
    setBusy(true);
    try {
      await authed(`/v1/p/central/review/${rejecting.type}/${rejecting.id}/reject`, {
        method: "POST",
        body: { note: note.trim() },
      });
      toast("مورد رد شد و دلیل برای مشتری ثبت شد.", "ok");
      setRejecting(null);
      setNote("");
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "رد ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  if (initial.length === 0) {
    return (
      <div className="card card-pad">
        <EmptyState title="صف بازبینی خالی است" hint="موردی در انتظار تأیید نیست." />
      </div>
    );
  }

  return (
    <div style={{ display: "grid", gap: 12 }}>
      <Alert tone="blue">{faNum(initial.length)} مورد در انتظار بررسی — SLA پاسخ‌گویی ۳ روز کاری.</Alert>
      {initial.map((item) => (
        <div key={`${item.type}-${item.id}`} className="card card-pad">
          <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
            <b>{item.name}</b>
            <small dir="ltr" style={{ color: "var(--text-muted)" }}>{item.slug} · {item.version}</small>
            <Badge tone={item.type === "plugin" ? "violet" : "amber"}>{item.type === "plugin" ? "پلاگین" : "قالب"}</Badge>
            <Badge tone={item.signature_valid ? "green" : "gray"}>{item.signature_valid ? "امضا معتبر" : "بدون امضا"}</Badge>
            <div style={{ flex: 1 }} />
            <small style={{ color: "var(--text-muted)" }}>
              {item.owner ? `${item.owner.name} (${item.owner.email})` : "مالک نامشخص"} · {jalali(item.submitted_at)}
            </small>
          </div>

          <details style={{ marginBlockStart: 8 }}>
            <summary style={{ cursor: "pointer", fontSize: 12.5, color: "var(--text-muted)" }}>جزئیات manifest</summary>
            <pre dir="ltr" style={{ background: "var(--surface-2)", border: "1px solid var(--border)", borderRadius: 8, padding: 12, overflowX: "auto", fontSize: 12, marginBlockStart: 8 }}>
              {JSON.stringify(item.manifest ?? {}, null, 2)}
            </pre>
          </details>

          <div style={{ display: "flex", gap: 8, marginBlockStart: 10, flexWrap: "wrap" }}>
            <button className="btn btn-primary btn-sm" disabled={busy} onClick={() => void approve(item)}>
              تأیید و آزادسازی فعال‌سازی
            </button>
            <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => { setRejecting(item); setNote(""); }}>
              رد با دلیل…
            </button>
          </div>
        </div>
      ))}

      {rejecting ? (
        <Modal title={`رد ${rejecting.type === "plugin" ? "پلاگین" : "قالب"} «${rejecting.name}»`} onClose={() => { setRejecting(null); setNote(""); }}>
          <p style={{ fontSize: 13, color: "var(--text-muted)" }}>
            دلیل رد برای مشتری نمایش داده می‌شود؛ دقیق و راه‌گشا بنویسید (حداقل ۵ نویسه).
          </p>
          <textarea
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            placeholder="مثلاً: در فایل bootstrap.php ورودی کاربر بدون اعتبارسنجی اجرا می‌شود…"
            style={{ inlineSize: "100%", marginBlockStart: 8, padding: 8, borderRadius: 8, border: "1px solid var(--border)", background: "var(--surface)", color: "var(--text)", fontFamily: "inherit", fontSize: 13 }}
          />
          <div style={{ display: "flex", gap: 8, marginBlockStart: 12 }}>
            <button className="btn btn-ghost" onClick={() => { setRejecting(null); setNote(""); }}>انصراف</button>
            <button className="btn btn-primary" disabled={busy} onClick={() => void reject()}>ثبت رد</button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
