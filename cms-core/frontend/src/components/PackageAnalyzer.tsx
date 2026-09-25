"use client";
import { useRef, useState } from "react";
import { authedForm } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge } from "@/components/ui/primitives";
import { faNum } from "@/lib/fa";

/**
 * فاز ۱.۵ — تحلیل بستهٔ پلاگین بدون نصب.
 *
 * هیچ‌چیز استخراج نمی‌شود و هیچ کد پلاگینی اجرا نمی‌شود. فقط ساختار بسته
 * بررسی می‌شود تا برنامه‌نویس دقیقاً بداند کدام فایل کجاست.
 *
 * طبق تصمیم محصول، نتیجه فقط **نمایش داده می‌شود** و هیچ راهی برای «نصب با
 * وجود خطا» وجود ندارد. خطا یعنی بسته واقعاً ناسازگار یا ناامن است.
 */

export type PackageIssue = {
  severity: "error" | "warning";
  code: string;
  message: string;
  path?: string;
  suggest?: { path: string; reason: string };
  guide?: string;
};

export type PackageAnalysis = {
  ok: boolean;
  severity: "ok" | "warning" | "error";
  errors: PackageIssue[];
  warnings: PackageIssue[];
  manifest: Record<string, unknown> | null;
  summary: {
    errors: number;
    warnings: number;
    slug: string | null;
    version: string | null;
    extension_points: string[];
  };
  contract: {
    allowed: { path: string; label_fa: string }[];
    extension_points: { key: string; label_fa: string; desc_fa: string; json: boolean; example: unknown }[];
  };
};

const CODE_FA: Record<string, string> = {
  "zip.unreadable": "فایل ZIP خوانده نشد",
  "zip.too_large": "حجم بیش از حد",
  "zip.too_many_files": "تعداد فایل بیش از حد",
  "zip.bomb_size": "حجم بازشده بیش از حد",
  "zip.bomb_ratio": "نسبت فشرده‌سازی مشکوک",
  "path.unsafe": "مسیر ناامن",
  "path.symlink": "symlink ممنوع",
  "path.duplicate": "مسیر تکراری",
  "path.case_collision": "تعارض حروف بزرگ/کوچک",
  "path.not_allowed": "خارج از بستهٔ مجاز",
  "package.large": "بسته بزرگ",
  "file.invalid": "فایل خوانده نشد",
  "analysis.exception": "خطا در تحلیل",
  "manifest.missing": "مانیفست پیدا نشد",
  "manifest.misplaced": "مانیفست در جای اشتباه",
  "manifest.missing_field": "فیلد الزامی مانیفست",
  "manifest.system_forbidden": "فیلد system ممنوع",
  "manifest.leaks_key": "نشت کلید",
  "signature.invalid": "امضای نامعتبر",
  "signature.anonymous_publisher": "ناشر ناشناس",
  "panel.bad_point": "نقطهٔ اتصال نامعتبر",
  "panel.unknown_point": "نقطهٔ اتصال ناشناخته",
  "panel.deferred_point": "نقطهٔ اتصال پشتیبانی‌نشده",
  "panel.unreadable": "panel.json خوانده نشد",
  "panel.diverges_from_manifest": "panel.json با مانیفست اختلاف دارد",
  "hooks.not_yet_dispatched": "هوک‌ها هنوز اجرا نمی‌شوند",
};

const GUIDE_FA: Record<string, string> = {
  "plugins.contract": "ساختار مجاز بسته",
  "plugins.extension-points": "نقاط اتصال افزونه",
  "plugins.signature": "امضا و کلید ناشر",
};

/** برچسب خطا برای UI — رشته‌های فارسی اینجا عمداً کوتاه‌اند تا جدول نشکند. */
function IssueRow({ issue }: { issue: PackageIssue }) {
  const [open, setOpen] = useState(false);
  const tone = issue.severity === "error" ? "red" : "amber";
  const hasDetail = Boolean(issue.suggest || issue.guide);

  return (
    <div
      style={{
        display: "flex",
        gap: 8,
        alignItems: "flex-start",
        padding: "8px 10px",
        borderRadius: "var(--radius-md, 8px)",
        borderInlineStart: `3px solid ${issue.severity === "error" ? "var(--danger)" : "var(--warn, #d97706)"}`,
        background: issue.severity === "error" ? "var(--danger-soft, rgba(220,38,38,.08))" : "var(--warn-soft, rgba(217,119,6,.09))",
      }}
    >
      <div style={{ flex: "0 0 auto", paddingBlockStart: 1 }}>
        <Badge tone={tone}>{CODE_FA[issue.code] ?? issue.code}</Badge>
      </div>

      <div style={{ flex: 1, minInlineSize: 0 }}>
        <div style={{ fontSize: 13, lineHeight: 1.7 }}>{issue.message}</div>

        {issue.path ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, lineHeight: 1.7 }}>
            <span style={{ color: "var(--text-muted)" }}>مسیر فعلی: </span>
            <code dir="ltr" style={{ color: "var(--danger)" }}>{issue.path}</code>
          </div>
        ) : null}

        {issue.suggest ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, lineHeight: 1.7 }}>
            <span style={{ color: "var(--text-muted)" }}>مسیر درست: </span>
            <code dir="ltr" style={{ color: "var(--ok, #0f9d58)" }}>{issue.suggest.path}</code>
            <div style={{ color: "var(--text-muted)" }}>{issue.suggest.reason}</div>
          </div>
        ) : null}

        {hasDetail ? (
          <div style={{ marginBlockStart: 4 }}>
            <button
              className="btn btn-ghost btn-sm"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
            >
              {open ? "بستن راهنما" : "راهنمای مرتبط"}
            </button>
          </div>
        ) : null}

        {open && issue.guide ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, color: "var(--text-muted)", lineHeight: 1.7 }}>
            بخش مرتبط در راهنمای ساخت پلاگین: <b>{GUIDE_FA[issue.guide] ?? issue.guide}</b>
          </div>
        ) : null}
      </div>
    </div>
  );
}

/**
 * `initialAnalysis` وقتی داده می‌شود، نتیجه از قبل نمایش داده می‌شود.
 * برای حالتی است که آپلود رد شده و بک‌اند تحلیل را در بدنهٔ خطا فرستاده —
 * تا کاربر مجبور نباشد دوباره همان فایل را انتخاب کند.
 */
export function PackageAnalyzer({ initialAnalysis = null }: { initialAnalysis?: PackageAnalysis | null }) {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<PackageAnalysis | null>(initialAnalysis);

  const analyze = async (file: File | undefined) => {
    if (!file) return;
    if (!file.name.endsWith(".zip")) {
      toast("بسته باید فایل ZIP باشد.", "err");
      return;
    }
    const form = new FormData();
    form.append("file", file);
    setBusy(true);
    setResult(null);
    try {
      const res = await authedForm<{ analysis: PackageAnalysis }>("/v1/admin/plugins/validate", form);
      setResult(res.analysis);
    } catch (e) {
      toast(e instanceof Error ? e.message : "تحلیل بسته ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="card card-pad" style={{ marginBlockEnd: 16 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
        <div style={{ flex: 1, minInlineSize: 220 }}>
          <b>بررسی ساختار بسته</b>
          <div style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
            فایل ZIP را انتخاب کنید تا بدون نصب و بدون اجرای هیچ کدی، ساختار آن بررسی شود.
          </div>
        </div>
        <input ref={fileRef} type="file" accept=".zip" hidden onChange={(e) => void analyze(e.target.files?.[0])} />
        <button className="btn" disabled={busy} onClick={() => fileRef.current?.click()}>
          {busy ? "در حال بررسی…" : "انتخاب فایل ZIP…"}
        </button>
      </div>

      {result ? (
        <div style={{ marginBlockStart: 14, display: "grid", gap: 12 }}>
          <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
            {result.ok ? (
              <Badge tone={result.warnings.length ? "amber" : "green"}>
                {result.warnings.length ? `ساختار درست با ${faNum(result.warnings.length)} هشدار` : "بسته معتبر است"}
              </Badge>
            ) : (
              <Badge tone="red">{`${faNum(result.errors.length)} خطا — نصب ممکن نیست`}</Badge>
            )}
            {result.summary.slug ? (
              <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
                <code dir="ltr">{result.summary.slug}</code>
              </span>
            ) : null}
            {result.summary.version ? (
              <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
                نسخه <code dir="ltr">{result.summary.version}</code>
              </span>
            ) : null}
          </div>

          {result.errors.length ? (
            <div style={{ display: "grid", gap: 6 }}>
              <div style={{ fontSize: 13, fontWeight: 600 }}>خطاها</div>
              {result.errors.map((e, i) => (
                <IssueRow key={`e${i}`} issue={{ ...e, severity: "error" }} />
              ))}
            </div>
          ) : null}

          {result.warnings.length ? (
            <div style={{ display: "grid", gap: 6 }}>
              <div style={{ fontSize: 13, fontWeight: 600 }}>هشدارها</div>
              {result.warnings.map((w, i) => (
                <IssueRow key={`w${i}`} issue={{ ...w, severity: "warning" }} />
              ))}
            </div>
          ) : null}

          <details>
            <summary style={{ cursor: "pointer", fontSize: 13, fontWeight: 600 }}>
              ساختار مجاز بسته ({faNum(result.contract.allowed.length)} مقصد)
            </summary>
            <ul style={{ marginBlockStart: 8, display: "grid", gap: 4 }}>
              {result.contract.allowed.map((a) => (
                <li key={a.path} style={{ fontSize: 12.5 }}>
                  <code dir="ltr" style={{ color: "var(--ok, #0f9d58)" }}>{a.path}</code>
                  <span style={{ color: "var(--text-muted)" }}> — {a.label_fa}</span>
                </li>
              ))}
            </ul>
            <Alert tone="blue" >
              هر فایلی خارج از این فهرست رد می‌شود. عمداً فهرست بسته است: افزودن مقصد تازه یک ارتقای پیشداد است.
            </Alert>
          </details>

          <details>
            <summary style={{ cursor: "pointer", fontSize: 13, fontWeight: 600 }}>
              نقاط اتصال افزونه ({faNum(result.contract.extension_points.length)} نقطه)
            </summary>
            <div style={{ marginBlockStart: 8, display: "grid", gap: 8 }}>
              {result.contract.extension_points.map((p) => {
                const used = result.summary.extension_points.includes(p.key);
                return (
                  <div
                    key={p.key}
                    style={{
                      padding: "7px 9px",
                      borderRadius: 8,
                      borderInlineStart: `3px solid ${used ? "var(--ok, #0f9d58)" : "var(--border, #ddd)"}`,
                      background: used ? "var(--ok-soft, rgba(15,157,88,.07))" : "transparent",
                    }}
                  >
                    <div style={{ fontSize: 12.5, fontWeight: 600 }}>
                      {p.label_fa} {used ? <Badge tone="green">استفاده شده</Badge> : null}
                    </div>
                    <code dir="ltr" style={{ fontSize: 11.5, color: "var(--text-muted)" }}>{p.key}</code>
                    <div style={{ fontSize: 12, color: "var(--text-muted)" }}>{p.desc_fa}</div>
                  </div>
                );
              })}
            </div>
          </details>
        </div>
      ) : null}
    </section>
  );
}
