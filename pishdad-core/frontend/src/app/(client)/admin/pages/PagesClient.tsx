"use client";

/**
 * WF-M5 — عملیات گروهی صفحات.
 *
 * لیست از Server Component می‌آید و اینجا فقط تعاملِ انتخاب زندگی می‌کند:
 * چک‌باکسِ ردیف‌ها، «انتخاب همه» روی صفحهٔ جاریِ نتایج، و نوارِ اکشن که با انتخاب
 * ظاهر می‌شود. یک درخواستِ `POST /v1/admin/pages/bulk` کل دسته را می‌برد و چون
 * سرور نتیجهٔ هر شناسه را جدا برمی‌گرداند، شکستِ جزئی هم کلِ عملیات را از دست
 * نمی‌دهد: ردیف‌های ناموفق در همان انتخاب می‌مانند و به کاربر گفته می‌شود کدام.
 */

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { ConfirmDialog } from "@/components/ui/Overlays";
import { Badge } from "@/components/ui/primitives";
import { PAGE_STATUS_FA, type PageItem } from "@/lib/domain";
import { faNum, jalali } from "@/lib/fa";
import { PageDeleteButton } from "./PageDeleteButton";
import { PageDuplicateButton } from "./PageDuplicateButton";
import { PagePurgeButton } from "./PagePurgeButton";
import { PageTrashActions } from "./PageTrashActions";
import { PageUnpublishButton } from "./PageUnpublishButton";

export type PageRowItem = PageItem & {
  deleted_at?: string | null;
  /** WF-M2 — سلامت سئوی ردیف (خروجی افزودنیِ سرور). */
  seo?: {
    healthy: boolean;
    issues: string[];
    title_length?: number;
    description_length?: number;
  };
};

/** WF-M2 — برچسب فارسیِ هر ایراد سئو برای tooltip نشان. */
const SEO_ISSUE_FA: Record<string, string> = {
  title_empty: "عنوان سئو خالی است",
  description_empty: "توضیح متا خالی است",
  title_len: "طول عنوان نامناسب است",
  description_len: "طول توضیح نامناسب است",
  no_og: "تصویر OG ندارد",
};

type BulkAction = "publish" | "unpublish" | "trash";

type BulkMeta = { title: string; text: string; confirmLabel: string; done: string };

const BULK_META: Record<BulkAction, BulkMeta> = {
  publish: {
    title: "انتشار گروهی",
    text: "صفحات انتخاب‌شده منتشر شوند؟ برای هر صفحه یک نسخهٔ تازه ساخته و کش سایت باطل می‌شود.",
    confirmLabel: "انتشار",
    done: "صفحات منتشر شدند.",
  },
  unpublish: {
    title: "لغو انتشار گروهی",
    text: "انتشار صفحات انتخاب‌شده لغو شود؟ نسخهٔ منتشرشده و تاریخ انتشار برای بازگردانی حفظ می‌شود.",
    confirmLabel: "لغو انتشار",
    done: "انتشار صفحات لغو شد.",
  },
  trash: {
    title: "انتقال گروهی به سطل زباله",
    text: "صفحات انتخاب‌شده به سطل زباله منتقل شوند؟ این کار برگشت‌پذیر است و از سطل زباله می‌توانید برگردانید.",
    confirmLabel: "انتقال به سطل زباله",
    done: "صفحات به سطل زباله منتقل شدند.",
  },
};

type BulkResult = {
  message?: string;
  results?: { id: number; ok: boolean; message: string }[];
  succeeded?: number;
  failed?: number;
};

/** سقفِ نمایشِ ناموفق‌ها در یک پیام؛ بقیه فقط شمرده می‌شوند. */
const FAILURE_PREVIEW = 3;

export function PagesClient({ rows, trashed }: { rows: PageRowItem[]; trashed: boolean }) {
  const toast = useToast();
  const router = useRouter();
  const [selected, setSelected] = useState<number[]>([]);
  const [pending, setPending] = useState<BulkAction | null>(null);
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);
  const allRef = useRef<HTMLInputElement>(null);

  const selectable = trashed ? [] : rows.map((r) => r.id);
  const allSelected = selectable.length > 0 && selectable.every((id) => selected.includes(id));
  const someSelected = selectable.some((id) => selected.includes(id)) && !allSelected;

  /**
   * انتخابِ کهنه را با ردیف‌های همین صفحه ممیزان می‌کند (بعد از جابه‌جایی صفحه یا
   * `router.refresh()`). بدون این، شناسه‌ای که دیگر در جدول نیست در نوارِ اکشن
   * می‌ماند و کاربر عملیاتی روی چیزی می‌بیند که نمی‌بیند.
   */
  useEffect(() => {
    const present = new Set(rows.map((r) => r.id));
    setSelected((prev) => {
      const next = prev.filter((id) => present.has(id));
      return next.length === prev.length ? prev : next;
    });
  }, [rows]);

  /** حالتِ «نیمه‌انتخاب» فقط با DOM ممکن است (`indeterminate` ویژگیِ المان است). */
  useEffect(() => {
    if (allRef.current) allRef.current.indeterminate = someSelected;
  }, [someSelected]);

  const toggle = (id: number) => {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const toggleAll = () => {
    setSelected(allSelected ? [] : selectable);
  };

  const run = async (action: BulkAction) => {
    if (inFlight.current || selected.length === 0) return;
    inFlight.current = true;
    setBusy(true);
    try {
      const res = await authed<BulkResult>("/v1/admin/pages/bulk", {
        method: "POST",
        body: { ids: selected, action },
      });

      const results = res.results ?? [];
      const failed = results.filter((r) => !r.ok);

      if (failed.length === 0) {
        toast(res.message ?? BULK_META[action].done, "ok");
        setSelected([]);
      } else {
        const preview = failed
          .slice(0, FAILURE_PREVIEW)
          .map((r) => `${faNum(r.id)} (${r.message})`)
          .join("، ");
        const rest = failed.length - FAILURE_PREVIEW;
        toast(
          `${faNum(res.succeeded ?? results.length - failed.length)} مورد انجام شد؛ ${faNum(failed.length)} ناموفق: ${preview}${rest > 0 ? ` و ${faNum(rest)} مورد دیگر` : ""}`,
          "err",
        );
        setSelected(failed.map((r) => r.id));
      }

      setPending(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "عملیات گروهی ناموفق بود.", "err");
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };

  const confirmMeta = pending ? BULK_META[pending] : null;

  return (
    <>
      {selected.length > 0 ? (
        <div
          className="card card-pad"
          role="group"
          aria-label="عملیات گروهی صفحات"
          style={{ marginBlockEnd: 14, display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}
        >
          <b style={{ fontSize: 13 }}>{faNum(selected.length)} صفحه انتخاب شده</b>
          {busy ? <span style={{ fontSize: 12.5, color: "var(--text-muted)" }} role="status">در حال اجرا…</span> : null}
          <div style={{ flex: 1 }} />
          <button className="btn btn-primary btn-sm" type="button" onClick={() => setPending("publish")} disabled={busy}>
            انتشار
          </button>
          <button className="btn btn-ghost btn-sm" type="button" onClick={() => setPending("unpublish")} disabled={busy}>
            لغو انتشار
          </button>
          <button className="btn btn-ghost btn-sm" type="button" onClick={() => setPending("trash")} disabled={busy}>
            انتقال به سطل زباله
          </button>
          <button className="btn btn-ghost btn-sm" type="button" onClick={() => setSelected([])} disabled={busy}>
            پاک‌کردن انتخاب
          </button>
        </div>
      ) : null}

      <div className="table-wrap">
        <table className="tbl tbl-cards">
          <thead>
            <tr>
              {trashed ? null : (
                <th style={{ inlineSize: 42 }}>
                  <input
                    ref={allRef}
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleAll}
                    disabled={busy || rows.length === 0}
                    aria-label="انتخاب همهٔ صفحه‌های این فهرست"
                  />
                </th>
              )}
              <th>عنوان</th><th>اسلاگ</th><th>وضعیت</th>
              {trashed ? null : <th>سلامت سئو</th>}
              {trashed ? <th>تاریخ حذف</th> : <th>نسخه‌ها</th>}
              {trashed ? null : <th>آخرین ویرایش</th>}
              <th>اکشن</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((p) => (
              <tr key={p.id}>
                {trashed ? null : (
                  <td data-label="انتخاب">
                    <input
                      type="checkbox"
                      checked={selected.includes(p.id)}
                      onChange={() => toggle(p.id)}
                      disabled={busy}
                      aria-label={`انتخاب صفحه ${p.title}`}
                    />
                  </td>
                )}
                <td data-label="عنوان"><b>{p.title}</b></td>
                <td data-label="اسلاگ"><span dir="ltr">{p.slug}</span></td>
                <td data-label="وضعیت">
                  <span style={{ display: "inline-flex", gap: 6, flexWrap: "wrap" }}>
                    <Badge tone={p.status === "published" ? "green" : "gray"}>{PAGE_STATUS_FA[p.status] ?? p.status}</Badge>
                    {p.scheduled_at && new Date(p.scheduled_at).getTime() > Date.now() ? (
                      <Badge tone="amber">زمان‌بندی‌شده</Badge>
                    ) : null}
                  </span>
                </td>
                {trashed ? null : (
                  <td data-label="سلامت سئو">
                    {p.seo ? (
                      p.seo.healthy ? (
                        <Badge tone="green">سالم</Badge>
                      ) : (
                        <span title={p.seo.issues.map((i) => SEO_ISSUE_FA[i] ?? i).join("، ")}>
                          <Badge tone="red">ناسالم</Badge>
                        </span>
                      )
                    ) : (
                      <span style={{ color: "var(--text-muted)" }} aria-hidden>—</span>
                    )}
                  </td>
                )}
                {trashed ? (
                  <td data-label="تاریخ حذف">{jalali(p.deleted_at)}</td>
                ) : (
                  <td data-label="نسخه‌ها">نسخه {faNum(p.revisions_count ?? 0)}</td>
                )}
                {trashed ? null : <td data-label="آخرین ویرایش">{jalali(p.updated_at)}</td>}
                <td data-label="اکشن">
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    {trashed ? (
                      <PageTrashActions pageId={p.id} title={p.title} />
                    ) : (
                      <>
                        <Link className="btn btn-ghost btn-sm" href={`/admin/pages/${p.id}/edit`}>ویرایش</Link>
                        {p.status === "published" ? (
                          <a className="btn btn-ghost btn-sm" href={`/${p.slug}`} target="_blank" rel="noreferrer" title="مشاهده نسخه منتشرشده در سایت">پیش‌نمایش</a>
                        ) : (
                          <Link className="btn btn-ghost btn-sm" href={`/admin/pages/${p.id}/edit`} title="پیش‌نمایش پیش‌نویس در ویرایشگر">پیش‌نمایش</Link>
                        )}
                        <PageDuplicateButton pageId={p.id} title={p.title} />
                        {p.status === "published" ? <PageUnpublishButton pageId={p.id} title={p.title} /> : null}
                        <PagePurgeButton slug={p.slug} />
                        <PageDeleteButton pageId={p.id} title={p.title} />
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {pending && confirmMeta ? (
        <ConfirmDialog
          title={confirmMeta.title}
          text={`${confirmMeta.text} (${faNum(selected.length)} صفحه)`}
          confirmLabel={busy ? "در حال اجرا…" : confirmMeta.confirmLabel}
          onConfirm={() => void run(pending)}
          onCancel={() => { if (!busy) setPending(null); }}
        />
      ) : null}
    </>
  );
}