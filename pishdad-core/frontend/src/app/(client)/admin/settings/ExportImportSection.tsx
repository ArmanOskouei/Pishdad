"use client";
import { useRef, useState } from "react";
import { authedForm } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

type ImportReport = {
  created: number;
  skipped: number;
  errors: number;
  media_linked: number;
  media_unresolved: number;
  authors: number;
  categories: number;
  truncated: boolean;
  error_samples: string[];
};

/**
 * WF-C6 — برون‌بری/درون‌بری محتوا.
 *
 * «کانال رشد = مهاجر وردپرس». دو دکمهٔ دانلود (JSON برای پشتیبانِ کامل، WXR
 * برای وردپرس) + یک آپلودِ XML وردپرس که گزارشِ واقعی (ساخته/ردشده/خطا)
 * را نشان می‌دهد.
 *
 * دانلود از routeِ اختصاصی `/api/export/*` عبور می‌کند، نه پروکسی عمومی:
 * آن پروکسی بدنه را همیشه JSON می‌خواند و XML را خراب می‌کرد.
 */
export function ExportImportSection() {
  const toast = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [report, setReport] = useState<ImportReport | null>(null);

  const download = (kind: "json" | "wxr") => {
    window.location.href = `/api/export/${kind}`;
    toast(kind === "wxr" ? "دانلود خروجی وردپرس آغاز شد." : "دانلود خروجی JSON آغاز شد.", "ok");
  };

  const upload = async (file: File) => {
    setBusy(true);
    setReport(null);
    try {
      const form = new FormData();
      form.append("file", file);
      const result = await authedForm<ImportReport>("/v1/admin/content/import/wxr", form);
      setReport(result);
      toast(
        result.created > 0
          ? `${result.created.toLocaleString("fa-IR")} مورد درون‌بری شد.`
          : "مورد تازه‌ای برای درون‌بری نبود.",
        "ok",
      );
    } catch (e) {
      toast(e instanceof Error ? e.message : "درون‌بری ناموفق بود.", "err");
    } finally {
      setBusy(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">برون‌بری و درون‌بری محتوا</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        کل محتوا (صفحات، بلوک‌ها، رسانه و تنظیمات) را برون‌بری کنید، یا خروجی XML وردپرس (WXR) را
        درون‌بری کنید. درون‌بری دوبارهٔ همان فایل، محتوا را تکرار نمی‌کند.
      </p>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBlockEnd: 14 }}>
        <button type="button" className="btn btn-ghost" onClick={() => download("json")}>
          برون‌بری JSON
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => download("wxr")}>
          برون‌بری WXR (وردپرس)
        </button>
      </div>

      <div className="field">
        <label>درون‌بری از XML وردپرس (WXR)</label>
        <input
          ref={inputRef}
          className="input"
          type="file"
          dir="ltr"
          accept=".xml,application/xml,text/xml"
          disabled={busy}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void upload(file);
          }}
        />
        <small style={{ color: "var(--text-muted)" }}>
          حداکثر ۲۰ مگابایت. نویسندگان، دسته‌ها، برگه‌ها، نوشته‌ها و رسانه‌ها به بلوک‌های هسته نگاشت
          می‌شوند.
        </small>
      </div>

      {busy ? (
        <p style={{ fontSize: 13, color: "var(--text-muted)", marginBlockStart: 12 }}>در حال درون‌بری…</p>
      ) : null}

      {report ? (
        <div
          role="status"
          style={{
            marginBlockStart: 14,
            padding: 12,
            borderRadius: 10,
            border: "1px solid var(--border)",
            background: "var(--surface-2, transparent)",
          }}
        >
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", fontSize: 13.5 }}>
            <span>ساخته‌شده: <b>{report.created.toLocaleString("fa-IR")}</b></span>
            <span>ردشده: <b>{report.skipped.toLocaleString("fa-IR")}</b></span>
            <span>خطا: <b>{report.errors.toLocaleString("fa-IR")}</b></span>
            <span>رسانهٔ پیوندشده: <b>{report.media_linked.toLocaleString("fa-IR")}</b></span>
            <span>تصویرِ بی‌رسانه: <b>{report.media_unresolved.toLocaleString("fa-IR")}</b></span>
          </div>
          {report.truncated ? (
            <p style={{ fontSize: 12.5, color: "var(--warning, #b45309)", marginBlock: "8px 0" }}>
              فایل بیش از حد بزرگ بود؛ فقط بخشی از موارد پردازش شد. فایل را تقسیم کنید.
            </p>
          ) : null}
          {report.error_samples.length > 0 ? (
            <ul style={{ fontSize: 12.5, color: "var(--danger)", margin: "8px 0 0", paddingInlineStart: 18 }}>
              {report.error_samples.map((msg, i) => (
                <li key={i}>{msg}</li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
