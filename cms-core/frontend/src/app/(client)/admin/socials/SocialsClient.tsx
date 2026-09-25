"use client";
import { useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, EmptyState } from "@/components/ui/primitives";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { SOCIAL_FA, mediaUrl, type MediaItem, type SocialItem } from "@/lib/domain";

const KEYS = ["instagram", "telegram", "x", "linkedin", "aparat", "youtube", "facebook", "whatsapp", "website"];

/** الگوی کلید آزاد — آینه اعتبارسنجی بک‌اند (SiteSettingsController::SOCIAL_KEY_PATTERN). */
const KEY_RE = /^[a-z0-9_-]{2,30}$/;

const displayName = (r: SocialItem) => r.label?.trim() || SOCIAL_FA[r.key] || r.key;

/** ویرایشگر لیست شبکه‌ها: افزودن (ثابت/سفارشی) + لوگو + حذف/فعال‌بودن + ذخیره یکجا. */
export function SocialsClient({ initial }: { initial: SocialItem[] }) {
  const toast = useToast();
  const [rows, setRows] = useState<SocialItem[]>(initial);
  const [busy, setBusy] = useState(false);
  const [customKey, setCustomKey] = useState("");
  const [customLabel, setCustomLabel] = useState("");
  const [customUrl, setCustomUrl] = useState("");
  const [logoFor, setLogoFor] = useState<number | null>(null);

  const used = new Set(rows.map((r) => r.key));
  const free = KEYS.filter((k) => !used.has(k));

  const add = (key: string) => setRows((prev) => [...prev, { key, url: "", active: true, label: null, icon_media_id: null, icon_url: null }]);
  const set = (i: number, patch: Partial<SocialItem>) =>
    setRows((prev) => prev.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const remove = (i: number) => setRows((prev) => prev.filter((_, j) => j !== i));

  const addCustom = () => {
    const key = customKey.trim().toLowerCase();
    if (!KEY_RE.test(key)) { toast("کلید سفارشی معتبر نیست (۲ تا ۳۰ نویسه: حروف کوچک انگلیسی، عدد، ـ و -).", "err"); return; }
    if (used.has(key)) { toast("این کلید قبلاً اضافه شده است.", "err"); return; }
    if (!/^https?:\/\/.+\..+/.test(customUrl.trim())) { toast("آدرس شبکه سفارشی معتبر نیست.", "err"); return; }
    setRows((prev) => [...prev, {
      key,
      url: customUrl.trim(),
      active: true,
      label: customLabel.trim() || null,
      icon_media_id: null,
      icon_url: null,
    }]);
    setCustomKey("");
    setCustomLabel("");
    setCustomUrl("");
  };

  const save = async () => {
    for (const r of rows) {
      if (!/^https?:\/\/.+\..+/.test(r.url)) { toast(`آدرس «${displayName(r)}» معتبر نیست.`, "err"); return; }
    }
    setBusy(true);
    try {
      const body = {
        socials: rows.map((r) => ({
          key: r.key,
          url: r.url,
          active: r.active,
          label: r.label?.trim() || null,
          icon_media_id: r.icon_media_id ?? null,
        })),
      };
      const data = await authed<{ socials: SocialItem[] }>(`/v1/admin/settings/socials`, { method: "PUT", body });
      // icon_url غنی‌شده سرور را جایگزین کن تا لوگو بلافاصله دیده شود.
      if (Array.isArray(data?.socials)) setRows(data.socials);
      toast("شبکه‌های اجتماعی ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      {rows.length === 0 ? (
        <div className="card card-pad" style={{ marginBlockEnd: 12 }}><EmptyState title="شبکه‌ای ثبت نشده" hint="از دکمه افزودن شروع کنید." /></div>
      ) : (
        <div className="table-wrap" style={{ marginBlockEnd: 12 }}>
          <table className="tbl tbl-cards">
            <thead><tr><th>لوگو</th><th>شبکه</th><th>آدرس</th><th>وضعیت</th><th>اکشن</th></tr></thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={`${r.key}-${i}`}>
                  <td data-label="لوگو">
                    <span style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
                      {r.icon_url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={r.icon_url} alt={`لوگوی ${displayName(r)}`} style={{ inlineSize: 28, blockSize: 28, objectFit: "contain", borderRadius: 6, border: "1px solid var(--border)" }} />
                      ) : (
                        <span className="chip">بدون لوگو</span>
                      )}
                      <button className="btn btn-ghost btn-sm" onClick={() => setLogoFor(i)}>انتخاب…</button>
                      {r.icon_media_id ? <button className="btn btn-ghost btn-sm" onClick={() => set(i, { icon_media_id: null, icon_url: null })}>حذف</button> : null}
                    </span>
                  </td>
                  <td data-label="شبکه">
                    <b>{displayName(r)}</b>
                    {r.label?.trim() && r.key ? <div style={{ fontSize: 11.5, color: "var(--text-muted)" }} dir="ltr">{r.key}</div> : null}
                  </td>
                  <td data-label="آدرس">
                    <input
                      className="input" dir="ltr" style={{ textAlign: "left" }} placeholder="https://…"
                      value={r.url} onChange={(e) => set(i, { url: e.target.value })}
                      aria-label={`آدرس ${displayName(r)}`}
                    />
                  </td>
                  <td data-label="وضعیت">
                    <label style={{ display: "inline-flex", gap: 6, alignItems: "center", cursor: "pointer" }}>
                      <input type="checkbox" checked={r.active} onChange={(e) => set(i, { active: e.target.checked })} />
                      <Badge tone={r.active ? "green" : "gray"}>{r.active ? "فعال" : "غیرفعال"}</Badge>
                    </label>
                  </td>
                  <td data-label="اکشن"><button className="btn btn-ghost btn-sm" onClick={() => remove(i)}>حذف</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="card card-pad" style={{ marginBlockEnd: 12 }}>
        <div className="card-title">افزودن شبکه سفارشی</div>
        <p className="card-sub">نام لاتین یکتا (مثل my-podcast) + برچسب نمایشی فارسی + آدرس.</p>
        <div className="grid c3">
          <div className="field"><label>کلید (لاتین)</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} placeholder="my-podcast" value={customKey} onChange={(e) => setCustomKey(e.target.value)} maxLength={30} />
          </div>
          <div className="field"><label>برچسب نمایشی</label>
            <input className="input" placeholder="پادکست من" value={customLabel} onChange={(e) => setCustomLabel(e.target.value)} maxLength={60} />
          </div>
          <div className="field"><label>آدرس</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} placeholder="https://…" value={customUrl} onChange={(e) => setCustomUrl(e.target.value)} />
          </div>
        </div>
        <div style={{ marginBlockStart: 8 }}>
          <button className="btn btn-ghost" onClick={addCustom}>＋ افزودن سفارشی</button>
        </div>
      </div>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
        {free.length > 0 ? (
          <select
            className="select" defaultValue="" aria-label="افزودن شبکه"
            onChange={(e) => { if (e.target.value) { add(e.target.value); e.target.value = ""; } }}
            style={{ maxInlineSize: 220 }}
          >
            <option value="">＋ افزودن شبکه…</option>
            {free.map((k) => <option key={k} value={k}>{SOCIAL_FA[k]}</option>)}
          </select>
        ) : <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>همه شبکه‌ها اضافه شده‌اند.</span>}
        <div style={{ flex: 1 }} />
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره شبکه‌ها"}</button>
      </div>

      {logoFor !== null && rows[logoFor] ? (
        <MediaPicker
          open
          selected={rows[logoFor].icon_media_id ? [rows[logoFor].icon_media_id as number] : []}
          onChange={(ids, items: MediaItem[]) => {
            const id = ids[0] ?? null;
            const item = (items as MediaItem[]).find((m) => m.id === id);
            set(logoFor, { icon_media_id: id, icon_url: item ? mediaUrl(item) : null });
            setLogoFor(null);
          }}
          onClose={() => setLogoFor(null)}
        />
      ) : null}
    </div>
  );
}
