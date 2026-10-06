"use client";
import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { authed, authedForm } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge, EmptyState } from "@/components/ui/primitives";
import { ConfirmDialog, Modal } from "@/components/ui/Overlays";
import { DeveloperGuide } from "@/components/DeveloperGuide";
import { faNum, jalali } from "@/lib/fa";
import type { ReviewStatus, ThemeItem } from "@/lib/domain";
import { REVIEW_FA } from "@/lib/domain";

/** گالری قالب: کارت + فعال‌سازی + پیش‌نمایش + آپلود ZIP + حذف. */
export function ThemesClient({ initial }: { initial: ThemeItem[] }) {
  const toast = useToast();
  const router = useRouter();
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [activating, setActivating] = useState<ThemeItem | null>(null);
  const [deleting, setDeleting] = useState<ThemeItem | null>(null);
  const [preview, setPreview] = useState<{ theme: ThemeItem; url: string } | null>(null);

  const refresh = () => router.refresh();

  const upload = async (file: File | undefined) => {
    if (!file) return;
    if (!file.name.endsWith(".zip")) { toast("قالب باید فایل ZIP باشد.", "err"); return; }
    const form = new FormData();
    form.append("file", file);
    setUploading(true);
    try {
      await authedForm(`/v1/admin/themes/upload`, form);
      toast("قالب آپلود شد و در انتظار تأیید است.", "ok");
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "آپلود ناموفق بود.", "err");
    } finally {
      setUploading(false);
    }
  };

  const reviewTone = (s: ReviewStatus | undefined): "amber" | "green" | "red" | "gray" =>
    s === "rejected" ? "red" : s === "pending" ? "amber" : s === "approved" ? "green" : "gray";

  const askActivate = (t: ThemeItem) => {
    if ((t.review_status ?? "approved") !== "approved") {
      toast(
        t.review_status === "rejected"
          ? "این قالب رد شده است. دلیل رد را ببینید و نسخه اصلاح‌شده آپلود کنید."
          : "این قالب هنوز تأیید نشده است. پس از تأیید طراح و معمار CMS فعال‌سازی ممکن می‌شود.",
        "err",
      );
      return;
    }
    setActivating(t);
  };

  const activate = async () => {
    if (!activating) return;
    try {
      await authed(`/v1/admin/themes/${activating.id}/activate`, { method: "POST" });
      // تعویض ظاهر سایت عمومی: ابطال تگ ISR + purge لبه (بک‌اند purge آروان را خودش می‌زند).
      await fetch("/api/revalidate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tags: ["pages", "theme"], local: true }),
      }).catch(() => undefined);
      toast(`قالب «${activating.name}» فعال شد.`, "ok");
      setActivating(null);
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "فعال‌سازی ناموفق بود.", "err");
    }
  };

  const showPreview = async (t: ThemeItem) => {
    try {
      const r = await authed<{ theme: { id: number; name: string; slug: string; version: string }; preview_url: string }>(
        `/v1/admin/themes/${t.id}/preview`,
        { method: "POST" },
      );
      setPreview({ theme: t, url: r.preview_url });
    } catch (e) {
      toast(e instanceof Error ? e.message : "پیش‌نمایش ناموفق بود.", "err");
    }
  };

  const remove = async () => {
    if (!deleting) return;
    try {
      await authed(`/v1/admin/themes/${deleting.id}`, { method: "DELETE" });
      toast("قالب حذف شد.", "ok");
      setDeleting(null);
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود (قالب فعال؟).", "err");
    }
  };

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12, alignItems: "center", gap: 8 }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.length)} قالب</span>
        <div style={{ flex: 1 }} />
        <input ref={fileRef} type="file" accept=".zip" hidden onChange={(e) => void upload(e.target.files?.[0])} />
        <button className="btn btn-primary" disabled={uploading} onClick={() => fileRef.current?.click()}>
          {uploading ? "در حال آپلود…" : "＋ آپلود قالب (ZIP)"}
        </button>
      </div>

      <Alert tone="blue">
        قالب فعال، ظاهر سایت عمومی را تعیین می‌کند. فعال‌سازی = revalidate تگ‌های ISR + purge CDN.
        <span> راهنما: «قالب سایت» ظاهر وب‌سایت عمومی است؛ «قالب پنل» ظاهر همین پنل مدیریت است و از <Link href="/admin/appearance">قالب پنل ←</Link> تنظیم می‌شود.</span>
      </Alert>

      {initial.some((t) => (t.review_status ?? "approved") === "pending") ? (
        <div style={{ marginBlockStart: 10 }}>
          <Alert tone="amber">
            قالب شما آپلود شد و در انتظار تأیید طراح و معمار CMS است — این بررسی دقیقاً برای امنیت سایت شماست:
            هر قالب پیش از فعال‌سازی از نظر سلامت کد و سازگاری بازبینی می‌شود تا ظاهر سایت شما همیشه پایدار بماند.
            نتیجه بررسی (تأیید یا دلیل رد) همین‌جا روی کارت نمایش داده می‌شود، معمولاً حداکثر ظرف ۳ روز کاری.
            اگر بررسی بیش از ۳ روز کاری طول کشید، از بخش تیکت‌ها با پشتیبانی در تماس باشید.
          </Alert>
        </div>
      ) : null}

      {initial.length === 0 ? (
        <div className="card card-pad"><EmptyState title="قالب دیگری نیست" hint="ZIP قالب را آپلود کنید." /></div>
      ) : (
        <div className="tgrid">
          {initial.map((t) => (
            <div key={t.id} className={`theme-card${t.active ? " selected st-active" : ""}`}>
              <div className="mini" style={{ "--pv-bg": "var(--bg)", "--pv-side": "var(--surface)", "--pv-card": "var(--surface)", "--pv-line": "var(--border)", "--pv-pri": "var(--primary)" } as React.CSSProperties}>
                <div className="ms"><i /><i /><i /><i /></div>
                <div className="mb">
                  <div style={{ blockSize: 11, borderRadius: 99, background: "var(--pv-side)", opacity: 0.7, inlineSize: "60%" }} />
                  <div style={{ display: "flex", gap: 5 }}>
                    <i style={{ flex: 1, blockSize: 26, borderRadius: 6, background: "var(--pv-card)", border: "1px solid var(--pv-line)" }} />
                    <i style={{ flex: 1, blockSize: 26, borderRadius: 6, background: "var(--pv-card)", border: "1px solid var(--pv-line)" }} />
                  </div>
                </div>
                {t.active ? <Badge tone="green">✔ فعال</Badge> : null}
              </div>
              <div className="theme-meta">
                <b>{t.name}</b> <small dir="ltr" style={{ color: "var(--text-muted)" }}>{t.slug} · {t.version}</small>
                <div style={{ marginBlockStart: 4, display: "flex", gap: 6, flexWrap: "wrap" }}>
                  <Badge tone={t.signature_valid ? "green" : "gray"}>{t.signature_valid ? "امضا معتبر" : "بدون امضا"}</Badge>
                  <Badge tone={reviewTone(t.review_status)}>{REVIEW_FA[t.review_status ?? "approved"]}</Badge>
                </div>
                {t.review_status === "rejected" && t.review_note ? (
                  <div style={{ marginBlockStart: 6, fontSize: 12, color: "var(--danger)" }}>
                    دلیل رد: {t.review_note}
                  </div>
                ) : null}
                <div className="card-sub">{jalali(t.created_at)}</div>
                <div style={{ display: "flex", gap: 6, marginBlockStart: 8, flexWrap: "wrap" }}>
                  {!t.active ? <button className="btn btn-primary btn-sm" onClick={() => askActivate(t)}>فعال‌سازی</button> : null}
                  <button className="btn btn-ghost btn-sm" onClick={() => void showPreview(t)}>پیش‌نمایش</button>
                  {!t.active ? <button className="btn btn-ghost btn-sm" onClick={() => setDeleting(t)} title="حذف برای آزادسازی فضا">حذف</button> : null}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {activating ? (
        <ConfirmDialog
          title={`فعال‌سازی قالب «${activating.name}»`}
          text="ظاهر سایت عمومی عوض می‌شود و کش ISR باطل می‌گردد. ادامه می‌دهید؟"
          confirmLabel="فعال‌سازی"
          onConfirm={() => void activate()}
          onCancel={() => setActivating(null)}
        />
      ) : null}
      {deleting ? (
        <ConfirmDialog
          title="حذف قالب"
          text={`«${deleting.name}» حذف شود؟ قالب فعال قابل حذف نیست.`}
          confirmLabel="حذف"
          onConfirm={() => void remove()}
          onCancel={() => setDeleting(null)}
        />
      ) : null}
      {preview ? (
        <Modal title={`پیش‌نمایش: ${preview.theme.name}`} onClose={() => setPreview(null)}>
          <p style={{ fontSize: 13, color: "var(--text-muted)" }}>
            همین قالب روی سایتِ اصلی، با ساختار و رنگِ خودش و محتوای واقعیِ صفحهٔ خانه:
          </p>
          <iframe
            src={preview.url}
            title={`پیش‌نمایش ${preview.theme.name}`}
            style={{ inlineSize: "100%", blockSize: "62vh", border: "1px solid var(--border)", borderRadius: 12, background: "var(--surface)" }}
          />
          <div style={{ marginBlockStart: 10 }}>
            <a className="btn btn-primary" href={preview.url} target="_blank" rel="noreferrer">باز کردن در تبِ جدید ←</a>
          </div>
        </Modal>
      ) : null}

      <div style={{ marginBlockStart: 12 }}>
        <DeveloperGuide kind="theme" />
      </div>
    </div>
  );
}
