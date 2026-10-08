"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, EmptyState } from "@/components/ui/primitives";
import { Modal, ConfirmDialog } from "@/components/ui/Overlays";
import { faNum, jalali } from "@/lib/fa";
import { ROLE_FA, type Manager, type Paginator } from "@/lib/domain";

const ROLE_OPTIONS = ["owner", "admin", "editor", "viewer"];

/** جدول مدیران + ساخت/ویرایش/حذف (داده اولیه از سرور). */
export function ManagersClient({ initial }: { initial: Paginator<Manager> }) {
  const toast = useToast();
  const router = useRouter();
  const [modal, setModal] = useState<null | { mode: "create" } | { mode: "edit"; m: Manager }>(null);
  const [del, setDel] = useState<Manager | null>(null);
  // دسته UIUX (افزودنی): جستجو + فیلتر نقش در تب مدیران.
  const [q, setQ] = useState("");
  const [roleFilter, setRoleFilter] = useState("");

  const visible = initial.data.filter((m) => {
    if (roleFilter && !m.roles.includes(roleFilter)) return false;
    const needle = q.replace(/[\u200c\s]+/g, " ").trim();
    if (!needle) return true;
    return `${m.name} ${m.email}`.includes(needle);
  });

  const remove = async () => {
    if (!del) return;
    try {
      await authed(`/v1/admin/managers/${del.id}`, { method: "DELETE" });
      toast("مدیر حذف شد.", "ok");
      setDel(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود.", "err");
    }
  };

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12, gap: 8, flexWrap: "wrap", alignItems: "center" }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.total)} مدیر</span>
        <div style={{ flex: 1 }} />
        <input
          className="input" style={{ maxInlineSize: 200 }} placeholder="جستجوی نام/ایمیل…"
          value={q} onChange={(e) => setQ(e.target.value)} aria-label="جستجوی مدیر"
        />
        <select className="select" style={{ maxInlineSize: 150 }} value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)} aria-label="فیلتر نقش">
          <option value="">همه نقش‌ها</option>
          {ROLE_OPTIONS.map((r) => <option key={r} value={r}>{ROLE_FA[r]}</option>)}
        </select>
        <button className="btn btn-primary" onClick={() => setModal({ mode: "create" })}>＋ مدیر جدید</button>
      </div>

      {initial.data.length === 0 ? (
        <div className="card card-pad"><EmptyState title="مدیری ثبت نشده" hint="اولین مدیر را بسازید." /></div>
      ) : visible.length === 0 ? (
        <div className="card card-pad"><EmptyState title="موردی با این فیلتر نیست" hint="جستجو یا فیلتر را عوض کنید." /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>نام</th><th>ایمیل</th><th>نقش‌ها</th><th>ساخته‌شده</th><th>اکشن</th></tr></thead>
            <tbody>
              {visible.map((m) => (
                <tr key={m.id}>
                  <td data-label="نام">
                    <span style={{ display: "inline-flex", gap: 8, alignItems: "center" }}>
                      {m.avatar_url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={m.avatar_url} alt="" style={{ inlineSize: 28, blockSize: 28, borderRadius: "50%", objectFit: "cover", border: "1px solid var(--border)" }} />
                      ) : (
                        <span className="brand-mark" style={{ inlineSize: 28, blockSize: 28, fontSize: 13, borderRadius: "50%" }} aria-hidden>
                          {(m.name ?? "م").slice(0, 1)}
                        </span>
                      )}
                      <b>{m.name}</b>
                    </span>
                  </td>
                  <td data-label="ایمیل"><span dir="ltr">{m.email}</span></td>
                  <td data-label="نقش‌ها">
                    {m.roles.length === 0 ? "—" : m.roles.map((r) => (
                      <Badge key={r} tone="violet">{ROLE_FA[r] ?? r}</Badge>
                    ))}
                  </td>
                  <td data-label="ساخته‌شده">{jalali(m.created_at)}</td>
                  <td data-label="اکشن">
                    <div style={{ display: "flex", gap: 6 }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => setModal({ mode: "edit", m })}>ویرایش</button>
                      <button className="btn btn-ghost btn-sm" onClick={() => setDel(m)}>حذف</button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {modal ? (
        <ManagerForm
          key={modal.mode === "edit" ? modal.m.id : "new"}
          initial={modal.mode === "edit" ? modal.m : null}
          onClose={() => { setModal(null); router.refresh(); }}
        />
      ) : null}
      {del ? (
        <ConfirmDialog
          title="حذف مدیر"
          text={`«${del.name}» حذف شود؟ حساب خودتان و سوپرادمین قابل حذف نیست.`}
          confirmLabel="حذف"
          onConfirm={() => void remove()}
          onCancel={() => setDel(null)}
        />
      ) : null}
    </div>
  );
}

function ManagerForm({ initial, onClose }: { initial: Manager | null; onClose: () => void }) {
  const toast = useToast();
  const [name, setName] = useState(initial?.name ?? "");
  const [email, setEmail] = useState(initial?.email ?? "");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState(initial?.roles[0] ?? "viewer");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!name.trim() || !email.trim()) { toast("نام و ایمیل الزامی است.", "err"); return; }
    if (!initial && password.length < 10) { toast("رمز عبور باید حداقل ۱۰ نویسه باشد.", "err"); return; }
    setBusy(true);
    try {
      if (initial) {
        await authed(`/v1/admin/managers/${initial.id}`, { method: "PUT", body: { name: name.trim(), email: email.trim(), role } });
        toast("مدیر به‌روزرسانی شد.", "ok");
      } else {
        await authed(`/v1/admin/managers`, { method: "POST", body: { name: name.trim(), email: email.trim(), password, role } });
        toast("مدیر ساخته شد.", "ok");
      }
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={initial ? `ویرایش: ${initial.name}` : "مدیر جدید"} onClose={onClose}>
      <div className="field"><label>نام *</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></div>
      <div className="field"><label>ایمیل *</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} /></div>
      {!initial ? (
        <div className="field"><label>رمز عبور * (حداقل ۱۰ نویسه + حروف/عدد/نویسه ویژه)</label>
          <input className="input" type="password" dir="ltr" style={{ textAlign: "left" }} value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
      ) : null}
      <div className="field"><label>نقش</label>
        <select className="select" value={role} onChange={(e) => setRole(e.target.value)}>
          {ROLE_OPTIONS.map((r) => <option key={r} value={r}>{ROLE_FA[r]}</option>)}
        </select>
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره"}</button>
      </div>
    </Modal>
  );
}
