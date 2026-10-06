"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, EmptyState } from "@/components/ui/primitives";
import { faNum, jalali } from "@/lib/fa";
import {
  ACTIVITY_ACTION_FA,
  ACTIVITY_FIELD_FA,
  type ActivityItem,
  type Manager,
  type Paginator,
} from "@/lib/domain";

export type ActivityFilters = { user_id: string; action: string; from: string; to: string };

function badgeTone(action: string): "green" | "violet" | "red" | "amber" | "gray" {
  if (action.endsWith(".create") || action.endsWith(".upload")) return "green";
  if (action.endsWith(".publish") || action.endsWith(".restore")) return "violet";
  if (action.endsWith(".force_delete") || action.endsWith(".delete")) return "red";
  return "gray";
}

/** WF-H9 — گزارش فعالیت: فیلتر کاربر/نوع عمل/تاریخ + بازگردانی نسخهٔ قبلی صفحه. */
export function ActivityLogClient({
  initial, managers, filters,
}: {
  initial: Paginator<ActivityItem>;
  managers: Manager[];
  filters: ActivityFilters;
}) {
  const toast = useToast();
  const router = useRouter();
  const [local, setLocal] = useState<ActivityFilters>(filters);
  const [restoring, setRestoring] = useState<number | null>(null);

  const apply = () => {
    const q = new URLSearchParams({ tab: "activities" });
    if (local.user_id) q.set("user_id", local.user_id);
    if (local.action) q.set("action", local.action);
    if (local.from) q.set("from", local.from);
    if (local.to) q.set("to", local.to);
    router.push(`/admin/managers?${q.toString()}`);
  };

  const reset = () => {
    setLocal({ user_id: "", action: "", from: "", to: "" });
    router.push("/admin/managers?tab=activities");
  };

  const restore = async (item: ActivityItem) => {
    if (restoring !== null) return;
    setRestoring(item.id);
    try {
      await authed(`/v1/admin/activities/${item.id}/restore`, { method: "POST" });
      toast("نسخهٔ قبلی صفحه بازگردانده شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "بازگردانی ناموفق بود.", "err");
    } finally {
      setRestoring(null);
    }
  };

  const changedFields = (item: ActivityItem): string[] => {
    const c = item.diff?.changed;
    return Array.isArray(c) ? c.map((x) => ACTIVITY_FIELD_FA[String(x)] ?? String(x)) : [];
  };

  return (
    <div>
      <div className="card card-pad" style={{ marginBlockEnd: 12, display: "flex", gap: 8, flexWrap: "wrap", alignItems: "flex-end" }}>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="act-user">کاربر</label>
          <select id="act-user" className="select" style={{ minInlineSize: 150 }} value={local.user_id} onChange={(e) => setLocal({ ...local, user_id: e.target.value })}>
            <option value="">همه کاربران</option>
            {managers.map((m) => <option key={m.id} value={String(m.id)}>{m.name}</option>)}
          </select>
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="act-action">نوع عمل</label>
          <select id="act-action" className="select" style={{ minInlineSize: 150 }} value={local.action} onChange={(e) => setLocal({ ...local, action: e.target.value })}>
            <option value="">همه اعمال</option>
            {Object.entries(ACTIVITY_ACTION_FA).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="act-from">از تاریخ</label>
          <input id="act-from" className="input" type="date" dir="ltr" style={{ maxInlineSize: 160 }} value={local.from} onChange={(e) => setLocal({ ...local, from: e.target.value })} />
        </div>
        <div className="field" style={{ margin: 0 }}>
          <label htmlFor="act-to">تا تاریخ</label>
          <input id="act-to" className="input" type="date" dir="ltr" style={{ maxInlineSize: 160 }} value={local.to} onChange={(e) => setLocal({ ...local, to: e.target.value })} />
        </div>
        <button className="btn btn-primary" onClick={apply}>اعمال فیلتر</button>
        <button className="btn btn-ghost" onClick={reset}>پاک‌کردن</button>
      </div>

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title="رویدادی ثبت نشده" hint="با ساخت یا ویرایش محتوا، گزارش اینجا ساخته می‌شود." /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>زمان</th><th>کاربر</th><th>عمل</th><th>خلاصه</th><th>اکشن</th></tr></thead>
            <tbody>
              {initial.data.map((item) => {
                const fields = changedFields(item);
                const restorable = item.subject_type === "page" && item.restorable_revision_id;
                return (
                  <tr key={item.id}>
                    <td data-label="زمان">{jalali(item.created_at)}</td>
                    <td data-label="کاربر">{item.user?.name ?? "سیستم"}</td>
                    <td data-label="عمل">
                      <Badge tone={badgeTone(item.action)}>{ACTIVITY_ACTION_FA[item.action] ?? item.action}</Badge>
                    </td>
                    <td data-label="خلاصه">
                      <div>{item.summary}</div>
                      {fields.length > 0 ? (
                        <div style={{ marginBlockStart: 4, display: "flex", gap: 4, flexWrap: "wrap" }}>
                          {fields.map((f) => <Badge key={f} tone="gray">{f}</Badge>)}
                        </div>
                      ) : null}
                    </td>
                    <td data-label="اکشن">
                      {restorable ? (
                        <button
                          className="btn btn-ghost btn-sm"
                          disabled={restoring === item.id}
                          onClick={() => void restore(item)}
                          aria-label={`بازگردانی نسخهٔ قبلی رویداد ${item.id}`}
                        >
                          {restoring === item.id ? "…" : "بازگردانی نسخهٔ قبلی"}
                        </button>
                      ) : "—"}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.total)} رویداد</span>
    </div>
  );
}
