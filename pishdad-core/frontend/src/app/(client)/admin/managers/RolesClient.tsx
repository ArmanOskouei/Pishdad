"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Badge, EmptyState, SaveBar } from "@/components/ui/primitives";
import { Modal, ConfirmDialog, Drawer } from "@/components/ui/Overlays";
import { faNum } from "@/lib/fa";
import { ROLE_FA, type RoleItem, type PermMatrix } from "@/lib/domain";

/** نقش‌ها + پنل چک‌باکس دسترسی (ماتریس ماژول × اکشن). */
export function RolesClient({ initial, matrix }: { initial: RoleItem[]; matrix: PermMatrix }) {
  const toast = useToast();
  const router = useRouter();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<RoleItem | null>(null);
  const [del, setDel] = useState<RoleItem | null>(null);

  const remove = async () => {
    if (!del) return;
    try {
      await authed(`/v1/admin/roles/${del.id}`, { method: "DELETE" });
      toast("نقش حذف شد.", "ok");
      setDel(null);
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "حذف ناموفق بود (نقش سیستمی یا تخصیص‌یافته).", "err");
    }
  };

  return (
    <div>
      <div style={{ display: "flex", marginBlockEnd: 12 }}>
        <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>{faNum(initial.length)} نقش (سوپرادمین مخفی است)</span>
        <div style={{ flex: 1 }} />
        <button className="btn btn-primary" onClick={() => setCreating(true)}>＋ نقش جدید</button>
      </div>

      {initial.length === 0 ? (
        <div className="card card-pad"><EmptyState title="نقشی نیست" /></div>
      ) : (
        <div className="table-wrap">
          <table className="tbl tbl-cards">
            <thead><tr><th>نقش</th><th>دسترسی‌ها</th><th>مدیران</th><th>۲FA اجباری</th><th>اکشن</th></tr></thead>
            <tbody>
              {initial.map((r) => (
                <tr key={r.id}>
                  <td data-label="نقش">
                    <b dir="ltr">{r.name}</b> <span style={{ color: "var(--text-muted)" }}>{ROLE_FA[r.name] ?? ""}</span>
                    {r.system ? <Badge tone="gray">سیستمی</Badge> : null}
                  </td>
                  <td data-label="دسترسی‌ها">{faNum(r.permissions.length)} دسترسی</td>
                  <td data-label="مدیران">{faNum(r.managers_count)}</td>
                  <td data-label="۲FA اجباری"><RoleTwoFactorToggle role={r} /></td>
                  <td data-label="اکشن">
                    <div style={{ display: "flex", gap: 6 }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => setEditing(r)}>دسترسی‌ها</button>
                      {!r.system ? <button className="btn btn-ghost btn-sm" onClick={() => setDel(r)}>حذف</button> : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {creating ? <RoleCreate matrix={matrix} onClose={() => { setCreating(false); router.refresh(); }} /> : null}
      {editing ? <RolePerms role={editing} matrix={matrix} onClose={() => { setEditing(null); router.refresh(); }} /> : null}
      {del ? (
        <ConfirmDialog
          title="حذف نقش"
          text={`نقش «${del.name}» حذف شود؟ نقش سیستمی یا تخصیص‌یافته قابل حذف نیست.`}
          confirmLabel="حذف"
          onConfirm={() => void remove()}
          onCancel={() => setDel(null)}
        />
      ) : null}
    </div>
  );
}

/** WF-M8 — سوییچ «۲FA اجباری» هر نقش؛ PUT با تنها همین فیلد تا دسترسی‌ها دست‌نخورده بمانند. */
function RoleTwoFactorToggle({ role }: { role: RoleItem }) {
  const toast = useToast();
  const router = useRouter();
  const [on, setOn] = useState(role.requires_2fa ?? false);
  const [busy, setBusy] = useState(false);

  const flip = async () => {
    const next = !on;
    setOn(next);
    setBusy(true);
    try {
      await authed(`/v1/admin/roles/${role.id}`, { method: "PUT", body: { requires_2fa: next } });
      toast(next ? "۲FA اجباری برای این نقش فعال شد." : "۲FA اجباری برای این نقش غیرفعال شد.", "ok");
      router.refresh();
    } catch (e) {
      setOn(!next);
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <label style={{ display: "inline-flex", gap: 6, alignItems: "center", cursor: "pointer" }}>
      <input
        type="checkbox"
        checked={on}
        disabled={busy}
        onChange={() => void flip()}
        aria-label={`۲FA اجباری برای نقش ${role.name}`}
      />
      <span style={{ color: on ? "var(--primary)" : "var(--text-muted)" }}>{on ? "فعال" : "خاموش"}</span>
    </label>
  );
}

function RoleCreate({ matrix, onClose }: { matrix: PermMatrix; onClose: () => void }) {
  const toast = useToast();
  const [name, setName] = useState("");
  const [perms, setPerms] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  const toggle = (p: string) => setPerms((prev) => (prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]));

  const save = async () => {
    if (!/^[a-z0-9-]+$/.test(name)) { toast("نام نقش فقط حروف کوچک انگلیسی، عدد و خط تیره.", "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/admin/roles`, { method: "POST", body: { name, permissions: perms } });
      toast("نقش ساخته شد.", "ok");
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ساخت نقش ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title="نقش جدید" onClose={onClose}>
      <div className="field"><label>نام انگلیسی * (مثل content-manager)</label>
        <input className="input" dir="ltr" style={{ textAlign: "left" }} value={name} onChange={(e) => setName(e.target.value)} autoFocus />
      </div>
      <PermGrid matrix={matrix} selected={perms} onToggle={toggle} />
      <div style={{ display: "flex", gap: 8, marginBlockStart: 12 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ساخت نقش"}</button>
      </div>
    </Modal>
  );
}

function RolePerms({ role, matrix, onClose }: { role: RoleItem; matrix: PermMatrix; onClose: () => void }) {
  const toast = useToast();
  const [perms, setPerms] = useState<string[]>(role.permissions);
  const [busy, setBusy] = useState(false);

  const toggle = (p: string) => setPerms((prev) => (prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]));

  // دسته UIUX (افزودنی): SaveBar در تب نقش‌ها (انصراف/ذخیره دسترسی‌ها).
  const dirty = JSON.stringify([...perms].sort()) !== JSON.stringify([...role.permissions].sort());

  const save = async () => {
    setBusy(true);
    try {
      await authed(`/v1/admin/roles/${role.id}`, { method: "PUT", body: { permissions: perms } });
      toast("دسترسی‌های نقش ذخیره شد.", "ok");
      onClose();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Drawer title={`دسترسی‌های نقش: ${role.name}`} onClose={onClose} wide>
      <p style={{ fontSize: 12.5, color: "var(--text-muted)" }}>ماتریس ماژول × اکشن — {faNum(perms.length)} دسترسی انتخاب‌شده</p>
      <PermGrid matrix={matrix} selected={perms} onToggle={toggle} />
      <div style={{ display: "flex", gap: 8, marginBlockStart: 14 }}>
        <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
        <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره دسترسی‌ها"}</button>
      </div>
      {dirty ? (
        <SaveBar
          dirtyText={`${faNum(perms.length)} دسترسی انتخاب‌شده (ذخیره‌نشده)`}
          onCancel={onClose}
          onSave={() => void save()}
        />
      ) : null}
    </Drawer>
  );
}

function PermGrid({ matrix, selected, onToggle }: { matrix: PermMatrix; selected: string[]; onToggle: (p: string) => void }) {
  if (matrix.modules.length === 0) {
    return <p style={{ fontSize: 13, color: "var(--text-muted)" }}>ماتریس دسترسی از سرور خالی برگشت.</p>;
  }
  return (
    <div className="table-wrap">
      <table className="tbl">
        <thead><tr><th>ماژول</th>{matrix.actions.map((a) => <th key={a.name}>{a.title_fa}</th>)}</tr></thead>
        <tbody>
          {matrix.modules.map((m) => (
            <tr key={m.name}>
              <td>
                <b>{m.title_fa}</b>
                {m.source !== "core" ? <Badge tone="violet">پلاگین</Badge> : null}
              </td>
              {matrix.actions.map((a) => {
                const key = `${m.name}.${a.name}`;
                const on = selected.includes(key);
                return (
                  <td key={a.name}>
                    <label style={{ display: "inline-flex", gap: 6, alignItems: "center", cursor: "pointer" }}>
                      <input type="checkbox" checked={on} onChange={() => onToggle(key)} aria-label={`${m.title_fa} — ${a.title_fa}`} />
                      <span style={{ color: on ? "var(--primary)" : "var(--text-muted)" }}>{on ? "✓" : "—"}</span>
                    </label>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
