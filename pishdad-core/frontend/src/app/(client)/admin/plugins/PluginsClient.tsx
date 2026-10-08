"use client";
import { useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed, authedEnvelope, authedForm, ApiError } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { ConfirmDialog, Modal } from "@/components/ui/Overlays";
import { DeveloperGuide } from "@/components/DeveloperGuide";
import { PackageAnalyzer, type PackageAnalysis } from "@/components/PackageAnalyzer";
import { faNum, jalali } from "@/lib/fa";
import type { Paginator, PluginItem, ReviewStatus } from "@/lib/domain";
import { REVIEW_FA } from "@/lib/domain";

/** لیست پلاگین + آپلود + سوییچ فعال + ارتقا + حذف (پلاگین سیستمی محافظت‌شده). */
export function PluginsClient({ initial }: { initial: Paginator<PluginItem> }) {
  const toast = useToast();
  const router = useRouter();
  const fileRef = useRef<HTMLInputElement>(null);
  const upgradeRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [upgrading, setUpgrading] = useState<PluginItem | null>(null);
  const [uninstalling, setUninstalling] = useState<PluginItem | null>(null);
  // WF-H17: پیش از انتخاب فایل نسخه جدید، تغییرات (changelog) نشان داده می‌شود.
  const [changelogFor, setChangelogFor] = useState<PluginItem | null>(null);
  // نتیجهٔ تحلیل بسته پس از رد شدن آپلود — تا کاربر دلیل واقعی را ببیند.
  const [analysis, setAnalysis] = useState<PackageAnalysis | null>(null);
  // دسته UIUX (افزودنی): جستجو + فیلتر وضعیت.
  const [q, setQ] = useState("");
  const [filter, setFilter] = useState<"all" | "active" | "inactive" | "updates">("all");
  // B20: «فعال شد» بدون این یعنی UI کاری را تأیید می‌کند که انجام نشده. هشدار
  // مربوط به هوک‌ها فقط وقتی پیش می‌آید که بک‌اند خودش `warning` فرستاده باشد،
  // پس پایدار نگه داشته می‌شود نه فقط در toastی که ۳٫۵ ثانیه بعد گم می‌شود.
  const [hooksNotice, setHooksNotice] = useState<string | null>(null);

  const refresh = () => router.refresh();

  const toggle = async (p: PluginItem) => {
    if ((p.review_status ?? "approved") !== "approved") {
      toast(
        p.review_status === "rejected"
          ? "این پلاگین رد شده است. دلیل رد را ببینید و نسخه اصلاح‌شده آپلود کنید."
          : "این پلاگین هنوز تأیید نشده است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.",
        "err",
      );
      return;
    }
    try {
      if (p.active) {
        await authed(`/v1/admin/plugins/${p.id}/deactivate`, { method: "POST" });
        setHooksNotice(null);
        toast("پلاگین غیرفعال شد.", "ok");
        refresh();
        return;
      }

      // `authed` فقط `data` را برمی‌گرداند و `warning`/`hooks_dispatched` را
      // بی‌صدا دور می‌ریزد — پس برای مسیر فعال‌سازی باید پوشش کامل بخوانیم.
      const res = await authedEnvelope<PluginItem>(`/v1/admin/plugins/${p.id}/activate`, { method: "POST" });
      setHooksNotice(null);

      if (res.warning) {
        // بک‌اند این هشدار را فقط وقتی می‌فرستد که مانیفست واقعاً هوک اعلام کرده
        // باشد؛ خودِ `hooks_dispatched` همیشه `false` است و به‌تنهایی فرقی نمی‌کند.
        setHooksNotice(res.warning);
        toast("پلاگین فعال شد — ولی فیلد «hooks» در مانیفستش بی‌اثر است.", "info");
      } else {
        toast("پلاگین فعال شد.", "ok");
      }
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "تغییر وضعیت ناموفق بود.", "err");
    }
  };

  const upload = async (file: File | undefined, plugin?: PluginItem) => {
    if (!file) return;
    if (!file.name.endsWith(".zip")) { toast("پلاگین باید فایل ZIP باشد.", "err"); return; }
    const form = new FormData();
    form.append("file", file);
    setUploading(true);
    try {
      if (plugin) {
        await authedForm(`/v1/admin/plugins/${plugin.id}/upgrade`, form);
        toast("پلاگین به‌روزرسانی شد.", "ok");
      } else {
        await authedForm(`/v1/admin/plugins/upload`, form);
        toast("پلاگین آپلود شد و در انتظار تأیید است.", "ok");
      }
      setAnalysis(null);
      refresh();
    } catch (e) {
      // بک‌اند هنگام رد بسته، کل تحلیل را در بدنهٔ پاسخ می‌فرستد. آن را نشان
      // می‌دهیم نه پیام عمومی — وگرنه کاربر می‌فهمد «خطا شد» ولی نمی‌فهمد چرا.
      const analysis = e instanceof ApiError
        ? (e.payload as { analysis?: PackageAnalysis } | undefined)?.analysis
        : undefined;
      if (analysis && !analysis.ok) {
        setAnalysis(analysis);
        toast("بسته معتبر نیست؛ نتیجهٔ بررسی ساختار را ببینید.", "err");
      } else {
        toast(e instanceof Error ? e.message : "آپلود ناموفق بود.", "err");
      }
    } finally {
      setUploading(false);
      setUpgrading(null);
    }
  };

  const uninstall = async () => {
    if (!uninstalling) return;
    try {
      await authed(`/v1/admin/plugins/${uninstalling.id}/uninstall`, { method: "POST" });
      toast("پلاگین حذف شد.", "ok");
      setUninstalling(null);
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود.", "err");
    }
  };

  /**
   * WF-H17 — «آپدیت موجود» از فرانت درباره امنیت/نسخه حدس نمی‌زند؛ بک‌اند
   * هر دو نسخه را می‌دهد. fallbackِ مانیفست فقط برای payloadهای قدیمی است.
   */
  const hasUpdate = (p: PluginItem) => {
    if (typeof p.update_available === "boolean") return p.update_available;
    const manifest = p.manifest as { latest_version?: string } | null | undefined;
    const latest = typeof manifest?.latest_version === "string" ? manifest.latest_version : null;
    if (!latest) return false;
    try {
      return latest.localeCompare(p.version, undefined, { numeric: true }) > 0;
    } catch { return latest !== p.version; }
  };

  /** آخرین نسخهٔ بازار، فقط وقتی از نسخهٔ نصب‌شده جلوتر باشد. */
  const latestVersion = (p: PluginItem): string | null => {
    if (!hasUpdate(p)) return null;
    const latest = typeof p.latest_version === "string" ? p.latest_version : null;
    return latest && latest !== p.version ? latest : null;
  };

  /** پیش از انتخاب فایل، اگر متن تغییرات هست اول نشانش بده. */
  const startUpgrade = (p: PluginItem) => {
    if (hasUpdate(p) && typeof p.changelog === "string" && p.changelog.trim() !== "") {
      setChangelogFor(p);
      return;
    }
    setUpgrading(p);
  };

  const reviewTone = (s: ReviewStatus | undefined): "amber" | "green" | "red" =>
    s === "rejected" ? "red" : s === "pending" ? "amber" : "green";

  /** دسته UIUX (افزودنی): سلامت جدا از وضعیت فعال‌بودن. */
  const health = (p: PluginItem): { tone: "green" | "amber" | "red"; label: string } => {
    if (!p.signature_valid) return { tone: "red", label: "ناسالم (امضا)" };
    if ((p.review_status ?? "approved") === "rejected") return { tone: "red", label: "ردشده" };
    if ((p.review_status ?? "approved") === "pending") return { tone: "amber", label: "در انتظار بازبینی" };
    return { tone: "green", label: "سالم" };
  };

  const norm = (s: string) => s.replace(/[\u200c\s]+/g, " ").trim();
  const visible = initial.data.filter((p) => {
    if (filter === "active" && !p.active) return false;
    if (filter === "inactive" && p.active) return false;
    if (filter === "updates" && !hasUpdate(p)) return false;
    const needle = norm(q);
    if (!needle) return true;
    return norm(`${p.name} ${p.slug}`).includes(needle);
  });

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12, alignItems: "center", gap: 8, flexWrap: "wrap" }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.total)} پلاگین</span>
        <div style={{ flex: 1 }} />
        <input
          className="input" style={{ maxInlineSize: 220 }} placeholder="جستجوی نام پلاگین…"
          value={q} onChange={(e) => setQ(e.target.value)} aria-label="جستجوی پلاگین"
        />
        <select className="select" style={{ maxInlineSize: 170 }} value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)} aria-label="فیلتر وضعیت">
          <option value="all">همه</option>
          <option value="active">فعال</option>
          <option value="inactive">غیرفعال</option>
          <option value="updates">آپدیت‌دار</option>
        </select>
        <input ref={fileRef} type="file" accept=".zip" hidden onChange={(e) => void upload(e.target.files?.[0])} />
        <button className="btn btn-primary" disabled={uploading} onClick={() => fileRef.current?.click()}>
          {uploading ? "در حال آپلود…" : "＋ نصب پلاگین (ZIP امضاشده)"}
        </button>
      </div>

      <PackageAnalyzer initialAnalysis={analysis} />

      <Alert tone="blue">
        آپلود بدون <span dir="ltr">manifest.json</span> دارای امضای معتبر در ریشهٔ ZIP، یا با فایلی خارج از ساختار مجاز، رد می‌شود.
        برای دیدن دلیل دقیق، از «بررسی ساختار بسته» استفاده کنید.
      </Alert>

      {initial.data.some((p) => (p.review_status ?? "approved") === "pending") ? (
        <div style={{ marginBlockStart: 10 }}>
          <Alert tone="amber">
            پلاگین شما آپلود شد و در انتظار تأیید طراح و معمار CMS است — این بررسی دقیقاً برای امنیت سایت شماست:
            هر پلاگین پیش از فعال‌سازی از نظر امضا، دسترسی‌ها و سلامت کد بازبینی می‌شود تا سایت شما همیشه امن و پایدار بماند.
            نتیجه بررسی (تأیید یا دلیل رد) همین‌جا روی کارت نمایش داده می‌شود، معمولاً حداکثر ظرف ۳ روز کاری.
            اگر بررسی بیش از ۳ روز کاری طول کشید، از بخش تیکت‌ها با پشتیبانی در تماس باشید.
          </Alert>
        </div>
      ) : null}

      {hooksNotice ? (
        <Alert tone="amber">
          <span dir="ltr" style={{ fontFamily: "ui-monospace, SFMono-Regular, Consolas, monospace", fontSize: 11.5, marginInlineEnd: 6 }}>
            hooks_dispatched: false
          </span>
          — {hooksNotice}
        </Alert>
      ) : null}

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title="پلاگینی نصب نشده" hint="فایل ZIP امضاشده را آپلود کنید." /></div>
      ) : visible.length === 0 ? (
        <div className="card card-pad"><EmptyState title="موردی با این فیلتر نیست" hint="جستجو یا فیلتر را عوض کنید." /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>نام</th><th>نسخه</th><th>وضعیت</th><th>بازبینی</th><th>سلامت</th><th>امضا</th><th>اکشن</th></tr></thead>
            <tbody>
              {visible.map((p) => (
                <tr key={p.id}>
                  <td data-label="نام">
                    <b>{p.name}</b> <small dir="ltr" style={{ color: "var(--text-muted)" }}>{p.slug}</small>
                    {hasUpdate(p) ? <Badge tone="amber">آپدیت موجود</Badge> : null}
                    {p.system ? <Badge tone="gray">سیستمی</Badge> : null}
                    {/* B35 — کاربر باید بفهمد این افزونه چه می‌کند. نبودِ توضیح
                        (null) با توضیحِ خالی فرق دارد، پس هیچ متن جایگزینی
                        ساخته نمی‌شود: نبودِ واقعی همان «چیزی نیست» است. */}
                    {p.description ? (
                      <div style={{ marginBlockStart: 4, fontSize: 12, color: "var(--text-muted)" }}>
                        {p.description}
                      </div>
                    ) : null}
                    {p.review_status === "rejected" && p.review_note ? (
                      <div style={{ marginBlockStart: 6, fontSize: 12, color: "var(--danger)" }}>
                        دلیل رد: {p.review_note}
                      </div>
                    ) : null}
                  </td>
                  <td data-label="نسخه">
                    <span dir="ltr">{p.version}</span>
                    {latestVersion(p) ? (
                      <div style={{ marginBlockStart: 4, fontSize: 12, color: "var(--text-muted)" }}>
                        <span aria-label="آخرین نسخهٔ بازار">← <span dir="ltr">{latestVersion(p)}</span></span>
                      </div>
                    ) : null}
                  </td>
                  <td data-label="وضعیت">
                    <label style={{ display: "inline-flex", gap: 6, alignItems: "center", cursor: "pointer" }}>
                      <input type="checkbox" checked={p.active} onChange={() => void toggle(p)} aria-label={`فعال‌بودن ${p.name}`} />
                      <Badge tone={p.active ? "green" : "gray"}>{p.active ? "فعال" : "غیرفعال"}</Badge>
                    </label>
                  </td>
                  <td data-label="بازبینی">
                    <Badge tone={reviewTone(p.review_status)}>{REVIEW_FA[p.review_status ?? "approved"]}</Badge>
                  </td>
                  <td data-label="سلامت">
                    <Badge tone={health(p).tone}>{health(p).label}</Badge>
                  </td>
                  <td data-label="امضا">
                    <Badge tone={p.signature_valid ? "green" : "red"}>{p.signature_valid ? "معتبر" : "نامعتبر"}</Badge>
                  </td>
                  <td data-label="اکشن">
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => startUpgrade(p)}>ارتقا…</button>
                      {!p.system ? <button className="btn btn-ghost btn-sm" onClick={() => setUninstalling(p)} title="حذف برای آزادسازی فضا">حذف</button> : null}
                    </div>
                    <small style={{ color: "var(--text-muted)" }}>{jalali(p.created_at)}</small>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <DeveloperGuide kind="plugin" />

      <input ref={upgradeRef} type="file" accept=".zip" hidden onChange={(e) => void upload(e.target.files?.[0], upgrading ?? undefined)} />
      {upgrading ? (
        <ConfirmDialog
          title={`ارتقا: ${upgrading.name}`}
          text={`نسخه ZIP جدید «${upgrading.slug}» را انتخاب کنید. نسخه باید بالاتر از ${upgrading.version} و امضا معتبر باشد.`}
          confirmLabel="انتخاب فایل…"
          onConfirm={() => { upgradeRef.current?.click(); }}
          onCancel={() => setUpgrading(null)}
        />
      ) : null}
      {changelogFor ? (
        <Modal title={`تغییرات ${changelogFor.name}`} onClose={() => setChangelogFor(null)}>
          <div style={{ display: "grid", gap: 10 }}>
            <div style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
              نسخهٔ نصب‌شده <span dir="ltr">{changelogFor.version}</span>
              {latestVersion(changelogFor) ? <> و نسخهٔ بازار <span dir="ltr">{latestVersion(changelogFor)}</span></> : null}
            </div>
            <div
              style={{
                whiteSpace: "pre-wrap", fontSize: 13, lineHeight: 1.9,
                maxBlockSize: 320, overflowY: "auto",
                background: "var(--surface-2, rgba(127,127,127,.06))",
                borderRadius: 8, padding: 12,
              }}
            >
              {changelogFor.changelog}
            </div>
            <div style={{ fontSize: 12.5, color: "var(--text-muted)" }}>
              تنظیمات فعلی این افزونه در هنگام ارتقا حفظ می‌شود.
            </div>
            <div style={{ display: "flex", gap: 8, marginBlockStart: 4 }}>
              <button className="btn btn-ghost" onClick={() => setChangelogFor(null)}>انصراف</button>
              <button
                className="btn btn-primary"
                onClick={() => { const p = changelogFor; setChangelogFor(null); setUpgrading(p); }}
              >
                انتخاب فایل نسخه جدید…
              </button>
            </div>
          </div>
        </Modal>
      ) : null}
      {uninstalling ? (
        <ConfirmDialog
          title="حذف پلاگین"
          text={`«${uninstalling.name}» حذف شود؟ پلاگین سیستمی قابل حذف نیست.`}
          confirmLabel="حذف"
          onConfirm={() => void uninstall()}
          onCancel={() => setUninstalling(null)}
        />
      ) : null}
    </div>
  );
}
