"use client";
import { useEffect, useRef, useState } from "react";
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
  allowed?: string[];
  pattern?: string;
  current?: string;
  required?: string;
};

export type ExtensionPoint = {
  key: string;
  label_fa: string;
  desc_fa: string;
  status: "live" | "declared_only" | "deferred";
  openness: "closed" | "schema_defined" | "open_vocabulary";
  openness_why?: string;
  since?: string;
  schema_version?: number;
  forbidden?: string[];
  schema?: { fields?: unknown[]; cross?: unknown[] } | null;
  open_schema?: boolean;
  example_ok?: unknown;
  example_bad?: { why?: string; decl?: unknown }[];
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
    extension_points: ExtensionPoint[];
  };
};

/** ترجمهٔ کدهای خطا. هر کدی که اینجا نباشد، خودِ `code` نمایش داده می‌شود. */
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
  "manifest.missing": "مانیفست پیدا نشد",
  "manifest.misplaced": "مانیفست در جای اشتباه",
  "manifest.bad_slug": "شناسهٔ پلاگین نامعتبر",
  "manifest.missing_field": "فیلد الزامی مانیفست",
  "manifest.system_forbidden": "فیلد system ممنوع",
  "manifest.leaks_key": "نشت کلید",
  "signature.invalid": "امضای نامعتبر",
  "signature.anonymous_publisher": "ناشر ناشناس",
  "panel.bad_point": "نقطهٔ اتصال نامعتبر",
  "panel.unknown_point": "نقطهٔ اتصال ناشناخته",
  "panel.deferred_point": "نقطهٔ اتصال پشتیبانی‌نشده",
  "hooks.not_yet_dispatched": "فیلد «hooks» در قرارداد پلاگین نیست",
  "devmode.unsigned_allowed": "نصب بدون امضا — به‌خاطر حالت توسعه‌دهنده",
  "interface_not_overridable": "این رابط قابل جایگزینی نیست",
  "bad_plugin_namespace": "کلاس بیرون از ریشهٔ افزونه",
  "requires.invalid": "بخش requires نامعتبر",
  "requires.core.invalid": "الگوی نیازمندی هسته نامعتبر",
  "requires.core.unsatisfied": "نیازمندی هسته برآورده نشد",
  "core.version_invalid": "نسخهٔ هسته نامعتبر",
};

const GUIDE_FA: Record<string, string> = {
  "plugins.contract": "ساختار مجاز بسته",
  "plugins.extension-points": "نقاط اتصال افزونه",
  "plugins.signature": "امضا و کلید ناشر",
};

const STATUS_FA: Record<ExtensionPoint["status"], { label: string; tone: "green" | "amber" | "gray"; hint: string }> = {
  live: {
    label: "فعال — الان کار می‌کند",
    tone: "green",
    hint: "این نقطه در نسخهٔ فعلی پیشداد پیاده شده و واقعاً در پنل یا سایت نمایش داده می‌شود.",
  },
  declared_only: {
    label: "اعلام‌شده — هنوز کار نمی‌کند",
    tone: "amber",
    hint: "این نقطه در قرارداد ثبت شده ولی هنوز نمایشی ندارد. اگر از آن استفاده کنی بسته‌ات رد می‌شود تا بی‌صدا نادیده نگرفته شود.",
  },
  deferred: {
    label: "در این نسخه پشتیبانی نمی‌شود",
    tone: "gray",
    hint: "عمداً به تعویق افتاده. دلیلش در راهنمای نقطهٔ اتصال آمده.",
  },
};

const OPENNESS_FA: Record<ExtensionPoint["openness"], { label: string; hint: string }> = {
  closed: {
    label: "بسته",
    hint: "فقط فیلدهای ثابت. هیچ چیزی برای تعریف آزاد وجود ندارد.",
  },
  schema_defined: {
    label: "نیمه‌باز",
    hint: "نوع قفل است ولی محتوا باز است.",
  },
  open_vocabulary: {
    label: "باز",
    hint: "می‌توانی نام فیلدهای خودت را تعریف کنی. گرامر مقادیر بسته است.",
  },
};

/** نمایش `code` خام وقتی ترجمه ندارد — بهتر از متن انگلیسی بی‌معنی است. */
function codeLabel(code: string): string {
  return CODE_FA[code] ?? code;
}

function IssueRow({ issue }: { issue: PackageIssue }) {
  const [open, setOpen] = useState(false);
  const tone = issue.severity === "error" ? "red" : "amber";
  const translated = CODE_FA[issue.code] !== undefined;

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
        <Badge tone={tone}>{codeLabel(issue.code)}</Badge>
      </div>

      <div style={{ flex: 1, minInlineSize: 0 }}>
        {!translated ? (
          <div style={{ fontSize: 11, marginBlockEnd: 3 }} dir="ltr">
            <code style={{ color: "var(--text-muted)" }}>{issue.code}</code>
          </div>
        ) : null}

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

        {issue.allowed && issue.allowed.length ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, lineHeight: 1.7 }}>
            <span style={{ color: "var(--text-muted)" }}>مجاز: </span>
            {issue.allowed.map((a) => (
              <code key={a} dir="ltr" style={{ marginInlineEnd: 6 }}>{a}</code>
            ))}
          </div>
        ) : null}

        {issue.pattern ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, lineHeight: 1.7 }}>
            <span style={{ color: "var(--text-muted)" }}>الگوی مجاز: </span>
            <code dir="ltr">{issue.pattern}</code>
          </div>
        ) : null}

        {issue.required || issue.current ? (
          <div style={{ fontSize: 12, marginBlockStart: 4, lineHeight: 1.7 }}>
            <span style={{ color: "var(--text-muted)" }}>نیازمند: </span>
            <code dir="ltr">{issue.required ?? "—"}</code>
            <span style={{ color: "var(--text-muted)" }}> · نسخهٔ هسته: </span>
            <code dir="ltr">{issue.current ?? "—"}</code>
          </div>
        ) : null}

        {issue.guide ? (
          <div style={{ marginBlockStart: 4 }}>
            <button className="btn btn-ghost btn-sm" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
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

function PointRow({ p, used }: { p: ExtensionPoint; used: boolean }) {
  const st = STATUS_FA[p.status] ?? STATUS_FA.deferred;
  const op = OPENNESS_FA[p.openness];

  return (
    <div
      style={{
        padding: "9px 11px",
        borderRadius: 8,
        borderInlineStart: `3px solid ${p.status === "live" ? "var(--ok, #0f9d58)" : "var(--border, #ddd)"}`,
        background: used ? "var(--ok-soft, rgba(15,157,88,.07))" : "transparent",
      }}
    >
      <div style={{ display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap" }}>
        <b style={{ fontSize: 12.5 }}>{p.label_fa}</b>
        <Badge tone={st.tone}>{st.label}</Badge>
        <Badge tone={p.openness === "open_vocabulary" ? "violet" : "gray"}>{op ? op.label : p.openness}</Badge>
        {used ? <Badge tone="green">در بستهٔ شما استفاده شده</Badge> : null}
      </div>

      <code dir="ltr" style={{ fontSize: 11.5, color: "var(--text-muted)" }}>{p.key}</code>
      {p.since ? <span dir="ltr" style={{ fontSize: 11, color: "var(--text-muted)" }}> · از نسخهٔ {p.since}</span> : null}

      <div style={{ fontSize: 12, color: "var(--text-muted)", lineHeight: 1.7, marginBlockStart: 2 }}>{p.desc_fa}</div>
      <div style={{ fontSize: 12, lineHeight: 1.7, marginBlockStart: 4 }}>{st.hint}</div>
      {p.openness_why ? (
        <div style={{ fontSize: 12, lineHeight: 1.7 }}>
          <b>چرا {op?.label}:</b> {p.openness_why}
        </div>
      ) : null}

      {/* I3 — آرایهٔ خالی در JS truthy است، پس شرطِ قبلی برای نقطه‌ای که
          `example_ok` ندارد یک بلوک خالی با متن «[]» نشان می‌داد. حالا طول
          آرایه سنجیده می‌شود و جای آن دلیلِ نبودِ مثال رندر می‌شود — سکوت یا
          صفرِ بی‌معنا هر دو دروغ‌اند. */}
      {Array.isArray(p.example_ok) && p.example_ok.length > 0 ? (
        <details style={{ marginBlockStart: 6 }}>
          <summary style={{ cursor: "pointer", fontSize: 12 }}>نمونهٔ درست</summary>
          <pre dir="ltr" style={{ fontSize: 11, marginBlockStart: 4, overflowX: "auto", whiteSpace: "pre-wrap" }}>
            {JSON.stringify(p.example_ok, null, 2)}
          </pre>
        </details>
      ) : (
        <div style={{ fontSize: 12, color: "var(--text-muted)", marginBlockStart: 6 }}>
          این نقطه نمونهٔ معتبر ندارد — هر اعلانی برایش با قاعدهٔ اسکیمای خودش رد می‌شود.
        </div>
      )}

      {p.example_bad && p.example_bad.length ? (
        <details style={{ marginBlockStart: 4 }}>
          <summary style={{ cursor: "pointer", fontSize: 12 }}>نمونهٔ نادرست و دلیلش</summary>
          {p.example_bad.map((b, i) => (
            <div key={i} style={{ marginBlockStart: 4 }}>
              {b.why ? <div style={{ fontSize: 12 }}>{b.why}</div> : null}
              <pre dir="ltr" style={{ fontSize: 11, marginBlockStart: 2, overflowX: "auto", whiteSpace: "pre-wrap" }}>
                {JSON.stringify(b.decl, null, 2)}
              </pre>
            </div>
          ))}
        </details>
      ) : null}

      {p.forbidden && p.forbidden.length ? (
        <div style={{ fontSize: 11.5, marginBlockStart: 6, lineHeight: 1.7, color: "var(--text-muted)" }}>
          همیشه ممنوع در این نقطه: {p.forbidden.map((f) => (
            <code key={f} dir="ltr" style={{ marginInlineEnd: 5 }}>{f}</code>
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * `initialAnalysis` وقتی داده می‌شود، نتیجه از همان ابتدا نمایش داده می‌شود.
 *
 * `useEffect` لازم است چون مقدار اولیهٔ `useState` فقط بار اول خوانده می‌شود،
 * ولی این prop بعد از رد شدن آپلود عوض می‌شود — و همان مسیر «نتیجهٔ بررسی را
 * ببینید» است که کاربر به آن نیاز دارد.
 */
export function PackageAnalyzer({ initialAnalysis = null }: { initialAnalysis?: PackageAnalysis | null }) {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<PackageAnalysis | null>(initialAnalysis);

  useEffect(() => {
    if (initialAnalysis) setResult(initialAnalysis);
  }, [initialAnalysis]);

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
            <div style={{ marginBlockStart: 8 }}>
              <Alert tone="blue">
                هر فایلی خارج از این فهرست رد می‌شود. عمداً فهرست بسته است: افزودن مقصد تازه یک ارتقای پیشداد است.
              </Alert>
            </div>
          </details>

          <details open={result.errors.length > 0}>
            <summary style={{ cursor: "pointer", fontSize: 13, fontWeight: 600 }}>
              نقاط اتصال افزونه ({faNum(result.contract.extension_points.length)} نقطه)
            </summary>
            <div style={{ marginBlockStart: 8, display: "grid", gap: 8 }}>
              {result.contract.extension_points.map((p) => (
                <PointRow key={p.key} p={p} used={result.summary.extension_points.includes(p.key)} />
              ))}
            </div>
          </details>
        </div>
      ) : null}
    </section>
  );
}
