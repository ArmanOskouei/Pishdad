"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { DataTable, type Column } from "@/components/ui/DataTable";
import { faNum, jalali } from "@/lib/fa";

type BackupItem = {
  id: string;
  file: string;
  bytes: number;
  sha256: string | null;
  database: string | null;
  created_at: string | null;
};

type ScheduleStatus = {
  enabled: boolean;
  at: string;
  keep: number;
  last_run: string | null;
  last_file: string | null;
};

type BackupData = {
  backups: BackupItem[];
  schedule: ScheduleStatus;
};

function humanBytes(bytes: number): string {
  const units = ["بایت", "کیلوبایت", "مگابایت", "گیگابایت"];
  let value = bytes;
  let i = 0;
  while (value >= 1024 && i < units.length - 1) {
    value /= 1024;
    i++;
  }
  return `${faNum(value.toFixed(value >= 100 || i === 0 ? 0 : 1))} ${units[i]}`;
}

/**
 * WF-H20 — تبِ «پشتیبان»: «دادهٔ من، خروجی من».
 *
 * بکاپ دستی + فهرست نسخه‌ها با تاریخ شمسی + دانلود + وضعیت زمان‌بندی.
 * دانلود از routeِ اختصاصی `/api/backups/{id}/download` عبور می‌کند، نه پروکسی
 * عمومی JSON، چون بدنه باینری است و باید دست‌نخورده stream شود.
 */
export function BackupSection() {
  const toast = useToast();
  const [data, setData] = useState<BackupData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setData(await authed<BackupData>("/v1/admin/settings/backups"));
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری وضعیت پشتیبان ناموفق بود.", "err");
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    void load();
  }, [load]);

  const run = async () => {
    setBusy(true);
    try {
      await authed("/v1/admin/settings/backups", { method: "POST" });
      await load();
      toast("پشتیبان‌گیری انجام شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "پشتیبان‌گیری ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const download = (item: BackupItem) => {
    window.location.href = `/api/backups/${encodeURIComponent(item.id)}/download`;
  };

  if (loading) {
    return (
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">پشتیبان‌گیری</div>
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      </div>
    );
  }

  const schedule = data?.schedule;
  const backups = data?.backups ?? [];

  const columns: Column<BackupItem>[] = [
    { key: "file", title: "فایل", render: (b) => <code dir="ltr">{b.file}</code> },
    { key: "created_at", title: "تاریخ", render: (b) => <span>{jalali(b.created_at)}</span> },
    { key: "bytes", title: "حجم", render: (b) => <span>{humanBytes(b.bytes)}</span> },
    {
      key: "actions",
      title: "",
      render: (b) => (
        <button className="btn btn-ghost btn-sm" onClick={() => download(b)}>دانلود</button>
      ),
    },
  ];

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">پشتیبان‌گیری</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        «دادهٔ من، خروجی من». یک نسخهٔ کامل از دیتابیس بگیرید و هر زمان خواستید دانلود کنید.
      </p>

      <div
        role="status"
        style={{
          padding: 12,
          borderRadius: 10,
          border: "1px solid var(--border)",
          background: "var(--surface-2, transparent)",
          fontSize: 13,
          marginBlockEnd: 12,
        }}
      >
        {schedule?.enabled ? (
          <div>
            <b>پشتیبان زمان‌بندی‌شده فعال است</b> — هر روز ساعت {faNum(schedule.at)} · نگه‌داشتن {faNum(schedule.keep)} نسخهٔ آخر
          </div>
        ) : (
          <div style={{ color: "var(--warning, #b45309)" }}>
            <b>پشتیبان زمان‌بندی‌شده غیرفعال است</b> — رشتهٔ اتصال دیتابیس (DATABASE_URL) تنظیم نشده؛ تا آن زمان فقط بکاپ دستی کار می‌کند.
          </div>
        )}
        <div style={{ color: "var(--text-muted)", marginBlockStart: 6 }}>
          آخرین پشتیبان: {schedule?.last_run ? `${jalali(schedule.last_run)} (${schedule.last_file})` : "هنوز پشتیبانی گرفته نشده است."}
        </div>
      </div>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockEnd: 12 }}>
        <button className="btn btn-primary" onClick={() => void run()} disabled={busy}>
          {busy ? "در حال پشتیبان‌گیری…" : "اکنون بکاپ بگیر"}
        </button>
      </div>

      {backups.length === 0 ? (
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>نسخهٔ پشتیبانی موجود نیست.</p>
      ) : (
        <DataTable rows={backups} columns={columns} searchKeys={["file"]} emptyTitle="نسخهٔ پشتیبانی موجود نیست" />
      )}
    </div>
  );
}
