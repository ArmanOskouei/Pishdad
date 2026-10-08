"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { asPaginator } from "@/lib/domain";

type RedirectRow = {
  id: number;
  from_path: string;
  to_path: string;
  status_code: number;
  active: boolean;
  hits: number;
};

/** WF-C2 — مدیریت ریدایرکت‌های ۳۰۱/۳۰۲ از تنظیمات سایت. */
export function RedirectsSection() {
  const toast = useToast();
  const [rows, setRows] = useState<RedirectRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [code, setCode] = useState("301");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const json = await authed<unknown>("/v1/admin/redirects?per_page=200");
      setRows(asPaginator<RedirectRow>(json).data);
    } catch {
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const add = async () => {
    if (!from.trim() || !to.trim()) {
      toast("مسیر مبدأ و مقصد را وارد کنید.", "err");
      return;
    }
    setBusy(true);
    try {
      await authed("/v1/admin/redirects", {
        method: "POST",
        body: { from_path: from.trim(), to_path: to.trim(), status_code: Number(code) },
      });
      setFrom("");
      setTo("");
      await load();
      toast("ریدایرکت ثبت شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ثبت ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const toggle = async (row: RedirectRow) => {
    try {
      await authed(`/v1/admin/redirects/${row.id}`, { method: "PUT", body: { active: !row.active } });
      await load();
    } catch (e) {
      toast(e instanceof Error ? e.message : "تغییر وضعیت ناموفق بود.", "err");
    }
  };

  const remove = async (row: RedirectRow) => {
    try {
      await authed(`/v1/admin/redirects/${row.id}`, { method: "DELETE" });
      await load();
      toast("ریدایرکت حذف شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود.", "err");
    }
  };

  const columns: Column<RedirectRow>[] = [
    { key: "from_path", title: "از", render: (r) => <code dir="ltr">{r.from_path}</code> },
    { key: "to_path", title: "به", render: (r) => <code dir="ltr">{r.to_path}</code> },
    { key: "status_code", title: "کد", render: (r) => <span dir="ltr">{r.status_code}</span> },
    { key: "hits", title: "بازدید", render: (r) => <span dir="ltr">{r.hits.toLocaleString("fa-IR")}</span> },
    {
      key: "active",
      title: "فعال",
      render: (r) => (
        <button
          type="button"
          role="switch"
          aria-checked={r.active}
          className="switch"
          aria-label={r.active ? "غیرفعال کن" : "فعال کن"}
          onClick={() => void toggle(r)}
        />
      ),
    },
    {
      key: "actions",
      title: "",
      render: (r) => (
        <button className="btn btn-ghost btn-sm" onClick={() => void remove(r)}>حذف</button>
      ),
    },
  ];

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">ریدایرکت‌ها (۳۰۱/۳۰۲)</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        مسیر قدیمی را به مقصد جدید بگردانید. هنگام تغییر اسلاگ یک صفحه، ثبت ریدایرکت پیشنهاد می‌شود.
      </p>
      <div className="grid c2">
        <div className="field">
          <label>از</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={from} onChange={(e) => setFrom(e.target.value)} placeholder="/old-page" />
        </div>
        <div className="field">
          <label>به</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={to} onChange={(e) => setTo(e.target.value)} placeholder="/new-page" />
        </div>
      </div>
      <div style={{ display: "flex", gap: 8, alignItems: "flex-end", marginBlockEnd: 12 }}>
        <div className="field" style={{ maxInlineSize: 150 }}>
          <label>کد وضعیت</label>
          <select className="select" value={code} onChange={(e) => setCode(e.target.value)}>
            <option value="301">۳۰۱ دائمی</option>
            <option value="302">۳۰۲ موقت</option>
          </select>
        </div>
        <button className="btn btn-primary" onClick={() => void add()} disabled={busy}>افزودن</button>
      </div>
      {loading ? (
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      ) : (
        <DataTable rows={rows} columns={columns} searchKeys={["from_path", "to_path"]} emptyTitle="ریدایرکتی ثبت نشده است" />
      )}
    </div>
  );
}
