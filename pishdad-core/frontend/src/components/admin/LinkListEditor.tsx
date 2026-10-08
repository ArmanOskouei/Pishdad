"use client";
import { useEffect, useMemo, useState } from "react";
import { authed } from "@/lib/auth";
import { asPaginator, validateLinkTree, LINK_LIMITS, type ChromeLinkItem, type PageItem } from "@/lib/domain";
import { faNum } from "@/lib/fa";

type Path = number[];

const pathKey = (p: Path) => p.join("-");
const samePath = (a: Path, b: Path) => a.length === b.length && a.every((v, i) => v === b[i]);

/** نرمال‌سازی بازگشتی هم‌خط LinkItems::normalize بک‌اند. */
function norm(x: ChromeLinkItem): ChromeLinkItem {
  const kind = x.kind === "page" || x.kind === "custom" ? x.kind : x.page_id ? "page" : "custom";
  const out: ChromeLinkItem = { ...x, kind };
  if (Array.isArray(x.children)) out.children = x.children.map(norm);
  return out;
}

function getAt(tree: ChromeLinkItem[], path: Path): ChromeLinkItem | undefined {
  let list = tree;
  let node: ChromeLinkItem | undefined;
  for (const i of path) {
    node = list[i];
    if (!node) return undefined;
    list = Array.isArray(node.children) ? node.children : [];
  }
  return node;
}

function setAt(tree: ChromeLinkItem[], path: Path, next: ChromeLinkItem): ChromeLinkItem[] {
  if (path.length === 0) return tree;
  const [head, ...rest] = path;
  return tree.map((node, i) => {
    if (i !== head) return node;
    if (rest.length === 0) return norm(next);
    return { ...node, children: setAt(Array.isArray(node.children) ? node.children : [], rest, next) };
  });
}

function removeAt(tree: ChromeLinkItem[], path: Path): ChromeLinkItem[] {
  if (path.length === 1) return tree.filter((_, i) => i !== path[0]);
  const [head, ...rest] = path;
  return tree.map((node, i) =>
    i !== head ? node : { ...node, children: removeAt(Array.isArray(node.children) ? node.children : [], rest) },
  );
}

function siblingsOf(tree: ChromeLinkItem[], path: Path): ChromeLinkItem[] {
  if (path.length <= 1) return tree;
  const parent = getAt(tree, path.slice(0, -1));
  return Array.isArray(parent?.children) ? parent.children : [];
}

/** برچسب موقعیت هم‌خط validateLinkTree (برای نمایش خطای هر سطر). */
function posLabel(path: Path): string {
  return path.map((idx, lvl) => (lvl === 0 ? `پیوند ${idx + 1}` : `زیرمنو ${idx + 1}`)).join(" / ");
}

/**
 * ویرایشگر تودرتوی لیست لینک nav هدر و links فوتر.
 * - children تا عمق ۲ (هم‌خط LinkItems::MAX_DEPTH)، هر سطح ≤۸، کل ≤۱۲.
 * - افزودن زیرمنو به هر سطر + indent/outdent + حذف بازگشتی + جابه‌جایی درون‌هم‌سطحی.
 * - اعتبارسنجی سمت کلاینت هم‌خط بک‌اند (validateLinkTree).
 */
export function LinkListEditor({
  value, onChange,
}: {
  value: ChromeLinkItem[];
  onChange: (next: ChromeLinkItem[]) => void;
}) {
  const [pages, setPages] = useState<PageItem[]>([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(false);
  const [openFor, setOpenFor] = useState<Path | null>(null);

  const tree = useMemo(() => value.map(norm), [value]);
  const errors = useMemo(() => validateLinkTree(tree), [tree]);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    const qs = `/v1/admin/pages?status=published&per_page=30${q.trim() ? `&search=${encodeURIComponent(q.trim())}` : ""}`;
    authed<unknown>(qs)
      .then((json) => {
        if (alive) setPages(asPaginator<PageItem>(json).data);
      })
      .catch(() => {
        if (alive) setPages([]);
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => {
      alive = false;
    };
  }, [q, openFor]);

  const patch = (path: Path, p: Partial<ChromeLinkItem>) => {
    const cur = getAt(tree, path);
    if (!cur) return;
    onChange(setAt(tree, path, norm({ ...cur, ...p })));
  };

  const move = (path: Path, dir: -1 | 1) => {
    const idx = path[path.length - 1];
    const to = idx + dir;
    const sibs = siblingsOf(tree, path);
    if (to < 0 || to >= sibs.length) return;
    if (path.length === 1) {
      const next = [...tree];
      const [x] = next.splice(idx, 1);
      next.splice(to, 0, x);
      onChange(next);
      return;
    }
    const parentPath = path.slice(0, -1);
    const parent = getAt(tree, parentPath);
    if (!parent) return;
    const kids = [...(parent.children ?? [])];
    const [x] = kids.splice(idx, 1);
    kids.splice(to, 0, x);
    onChange(setAt(tree, parentPath, { ...parent, children: kids }));
  };

  /** indent: فرزند همسایه قبلی شود (تا عمق ۲). */
  const indent = (path: Path) => {
    const idx = path[path.length - 1];
    if (idx === 0 || path.length > LINK_LIMITS.MAX_DEPTH) return;
    const node = getAt(tree, path);
    if (!node) return;
    const without = removeAt(tree, path);
    const prevPath = [...path.slice(0, -1), idx - 1];
    const prev = getAt(without, prevPath);
    if (!prev) return;
    const kids = [...(prev.children ?? [])];
    if (kids.length >= LINK_LIMITS.MAX_CHILDREN) return;
    onChange(setAt(without, prevPath, { ...prev, children: [...kids, node] }));
    setOpenFor(null);
  };

  /** outdent: یک سطح به بیرون (هم‌سطح والد شود). */
  const outdent = (path: Path) => {
    if (path.length <= 1) return;
    const node = getAt(tree, path);
    if (!node) return;
    const without = removeAt(tree, path);
    const parentPath = path.slice(0, -1);
    const parentIdx = parentPath[parentPath.length - 1];
    const grandPath = parentPath.slice(0, -1);
    if (grandPath.length === 0) {
      const next = [...without];
      next.splice(parentIdx + 1, 0, node);
      onChange(next);
    } else {
      const grand = getAt(without, grandPath);
      if (!grand) return;
      const listPath = [...grandPath, parentIdx + 1];
      // درج بعد از والد در لیست والدِ والد:
      const holder = getAt(without, grandPath);
      if (!holder) return;
      const kids = [...(holder.children ?? [])];
      kids.splice(parentIdx + 1, 0, node);
      onChange(setAt(without, grandPath, { ...holder, children: kids }));
      void listPath;
    }
    setOpenFor(null);
  };

  const addChild = (path: Path, kind: "page" | "custom") => {
    const node = getAt(tree, path);
    if (!node || path.length > LINK_LIMITS.MAX_DEPTH) return;
    const kids = Array.isArray(node.children) ? node.children : [];
    if (kids.length >= LINK_LIMITS.MAX_CHILDREN) return;
    const fresh: ChromeLinkItem = kind === "page" ? { kind, page_id: undefined } : { kind, label: "", href: "" };
    onChange(setAt(tree, path, { ...node, children: [...kids, fresh] }));
  };

  const remove = (path: Path) => {
    onChange(removeAt(tree, path));
    if (openFor && (samePath(openFor, path) || openFor.length > path.length)) setOpenFor(null);
  };

  const addTop = (kind: "page" | "custom") => {
    if (tree.length >= LINK_LIMITS.MAX_ITEMS) return;
    onChange([...tree, kind === "page" ? { kind, page_id: undefined } : { kind, label: "", href: "" }]);
  };

  const nodeErrors = (path: Path): string[] => {
    const prefix = posLabel(path);
    return errors.filter((e) => e.startsWith(prefix));
  };

  return (
    <div>
      <div className="field">
        <label>پیوندها ({faNum(tree.length)} مورد)</label>
        {tree.length === 0 ? (
          <p style={{ fontSize: 13, color: "var(--text-muted)" }}>پیوندی نیست — از دکمه‌های زیر اضافه کنید.</p>
        ) : null}
        {errors.length > 0 ? (
          <div role="alert" style={{ border: "1px solid var(--danger)", background: "var(--danger-soft)", borderRadius: 8, padding: "8px 12px", fontSize: 12.5, marginBlockEnd: 8 }}>
            <b>پیش از ذخیره اصلاح کنید:</b>
            <ul style={{ margin: "4px 0 0", paddingInlineStart: 18 }}>
              {errors.slice(0, 8).map((e, i) => <li key={i}>{e}</li>)}
              {errors.length > 8 ? <li>…و {faNum(errors.length - 8)} مورد دیگر</li> : null}
            </ul>
          </div>
        ) : null}
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          {tree.map((n, i) => (
            <LinkNode
              key={pathKey([i])}
              node={n}
              path={[i]}
              depth={0}
              pages={pages}
              q={q}
              setQ={setQ}
              loading={loading}
              openFor={openFor}
              setOpenFor={setOpenFor}
              patch={patch}
              move={move}
              indent={indent}
              outdent={outdent}
              addChild={addChild}
              remove={remove}
              nodeErrors={nodeErrors}
              isFirst={i === 0}
              isLast={i === tree.length - 1}
            />
          ))}
        </div>
      </div>
      <div style={{ display: "flex", gap: 8 }}>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => addTop("page")} disabled={tree.length >= LINK_LIMITS.MAX_ITEMS}>＋ صفحه سایت</button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => addTop("custom")} disabled={tree.length >= LINK_LIMITS.MAX_ITEMS}>＋ لینک سفارشی</button>
      </div>
    </div>
  );
}

function LinkNode(props: {
  node: ChromeLinkItem;
  path: Path;
  depth: number;
  pages: PageItem[];
  q: string;
  setQ: (s: string) => void;
  loading: boolean;
  openFor: Path | null;
  setOpenFor: (p: Path | null) => void;
  patch: (path: Path, p: Partial<ChromeLinkItem>) => void;
  move: (path: Path, dir: -1 | 1) => void;
  indent: (path: Path) => void;
  outdent: (path: Path) => void;
  addChild: (path: Path, kind: "page" | "custom") => void;
  remove: (path: Path) => void;
  nodeErrors: (path: Path) => string[];
  isFirst: boolean;
  isLast: boolean;
}) {
  const { node: raw, path, depth } = props;
  const v = norm(raw);
  const open = props.openFor !== null && samePath(props.openFor, path);
  const page = v.kind === "page" && v.page_id ? props.pages.find((p) => p.id === v.page_id) : undefined;
  const kids = Array.isArray(v.children) ? v.children : [];
  const errs = props.nodeErrors(path).filter((e) => {
    // فقط خطای خود سطر (نه نوادگان) — نوادگان پیشوند طولانی‌تر دارند.
    const rest = e.slice(posLabel(path).length);
    return rest === "" || rest.startsWith(":");
  });
  const kidCount = kids.length;

  return (
    <div
      className="card"
      style={{
        padding: 10,
        marginInlineStart: depth === 0 ? 0 : depth * 22,
        borderInlineStart: depth === 0 ? undefined : "3px solid var(--primary-soft, var(--border))",
      }}
    >
      <div style={{ display: "flex", gap: 6, alignItems: "center", marginBlockEnd: 8, flexWrap: "wrap" }}>
        {depth > 0 ? <span aria-hidden style={{ color: "var(--text-muted)" }}>↳</span> : null}
        <div className="seg" role="group" aria-label={`نوع پیوند ${posLabel(path)}`} style={{ flex: 1, minInlineSize: 200 }}>
          <button
            type="button"
            className={v.kind === "page" ? "on" : ""}
            onClick={() => props.patch(path, { kind: "page", page_id: v.page_id ?? undefined, href: undefined })}
          >
            📄 صفحه سایت
          </button>
          <button
            type="button"
            className={v.kind !== "page" ? "on" : ""}
            onClick={() => props.patch(path, { kind: "custom", page_id: undefined, label: v.label ?? "", href: v.href ?? "" })}
          >
            🔗 لینک سفارشی
          </button>
        </div>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => props.move(path, -1)} aria-label="بالا" disabled={props.isFirst}>↑</button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => props.move(path, 1)} aria-label="پایین" disabled={props.isLast}>↓</button>
        <button
          type="button" className="btn btn-ghost btn-sm" onClick={() => props.indent(path)}
          aria-label="تورفتگی (زیرمنوی قبلی)" title="تورفتگی: زیرمنوی سطر قبلی شود"
          disabled={props.isFirst || depth >= LINK_LIMITS.MAX_DEPTH}
        >→</button>
        <button
          type="button" className="btn btn-ghost btn-sm" onClick={() => props.outdent(path)}
          aria-label="بیرون‌رفتگی (هم‌سطح والد)" title="بیرون‌رفتگی: هم‌سطح والد شود"
          disabled={depth === 0}
        >←</button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => props.remove(path)} aria-label="حذف (با زیرمنوها)">✕</button>
      </div>
      {v.kind === "page" ? (
        <div>
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            style={{ inlineSize: "100%", justifyContent: "space-between" }}
            onClick={() => props.setOpenFor(open ? null : path)}
            aria-expanded={open}
          >
            <span>{page ? `${page.title} (/${page.slug})` : v.page_id ? `صفحه #${faNum(v.page_id)}` : "انتخاب صفحه…"}</span>
            <span aria-hidden>{open ? "▴" : "▾"}</span>
          </button>
          {open ? (
            <div style={{ marginBlockStart: 6 }}>
              <input
                className="input"
                placeholder="جستجوی عنوان یا اسلاگ…"
                value={props.q}
                onChange={(e) => props.setQ(e.target.value)}
                aria-label="جستجوی صفحات منتشرشده"
              />
              <div style={{ maxBlockSize: 180, overflow: "auto", marginBlockStart: 6, display: "flex", flexDirection: "column", gap: 4 }}>
                {props.loading ? <span style={{ fontSize: 12, color: "var(--text-muted)" }}>در حال بارگذاری…</span> : null}
                {!props.loading && props.pages.length === 0 ? (
                  <span style={{ fontSize: 12, color: "var(--text-muted)" }}>صفحه منتشرشده‌ای یافت نشد.</span>
                ) : null}
                {props.pages.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    className="btn btn-ghost btn-sm"
                    style={{ justifyContent: "flex-start", fontWeight: v.page_id === p.id ? 700 : 400 }}
                    onClick={() => {
                      props.patch(path, { page_id: p.id });
                      props.setOpenFor(null);
                      props.setQ("");
                    }}
                  >
                    {p.title} <span dir="ltr" style={{ color: "var(--text-muted)" }}>/{p.slug}</span>
                  </button>
                ))}
              </div>
              <div className="field" style={{ marginBlockStart: 6 }}>
                <label>عنوان نمایشی (خالی = عنوان صفحه)</label>
                <input
                  className="input"
                  dir="auto"
                  maxLength={80}
                  value={typeof v.label === "string" ? v.label : ""}
                  onChange={(e) => props.patch(path, { label: e.target.value })}
                  placeholder="خالی بماند تا عنوان صفحه نمایش داده شود"
                />
              </div>
            </div>
          ) : null}
        </div>
      ) : (
        <div style={{ display: "grid", gap: 6 }}>
          <div className="field" style={{ margin: 0 }}>
            <label>عنوان</label>
            <input
              className="input"
              dir="auto"
              maxLength={80}
              value={typeof v.label === "string" ? v.label : ""}
              onChange={(e) => props.patch(path, { label: e.target.value })}
              placeholder="مثلاً تماس با ما"
            />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>نشانی {kidCount > 0 ? "(خالی مجاز — والد زیرمنو)" : ""}</label>
            <input
              className="input"
              dir="ltr"
              style={{ textAlign: "left" }}
              maxLength={2048}
              value={typeof v.href === "string" ? v.href : ""}
              onChange={(e) => props.patch(path, { href: e.target.value })}
              placeholder="/contact یا https://…"
            />
          </div>
        </div>
      )}
      {errs.length > 0 ? (
        <ul style={{ margin: "6px 0 0", paddingInlineStart: 18, fontSize: 12, color: "var(--danger)" }}>
          {errs.map((e, i) => <li key={i}>{e}</li>)}
        </ul>
      ) : null}
      <div style={{ display: "flex", gap: 6, marginBlockStart: 8, alignItems: "center", flexWrap: "wrap" }}>
        <span style={{ fontSize: 12, color: "var(--text-muted)" }}>{kidCount > 0 ? `${faNum(kidCount)} زیرمنو` : "بدون زیرمنو"}</span>
        <span style={{ flex: 1 }} />
        <button
          type="button" className="btn btn-ghost btn-sm"
          onClick={() => props.addChild(path, "page")}
          disabled={depth >= LINK_LIMITS.MAX_DEPTH || kidCount >= LINK_LIMITS.MAX_CHILDREN}
          title="افزودن زیرمنوی صفحه‌ای"
        >＋ زیرمنو (صفحه)</button>
        <button
          type="button" className="btn btn-ghost btn-sm"
          onClick={() => props.addChild(path, "custom")}
          disabled={depth >= LINK_LIMITS.MAX_DEPTH || kidCount >= LINK_LIMITS.MAX_CHILDREN}
          title="افزودن زیرمنوی سفارشی"
        >＋ زیرمنو (سفارشی)</button>
      </div>
      {kidCount > 0 ? (
        <div style={{ display: "flex", flexDirection: "column", gap: 8, marginBlockStart: 8 }}>
          {kids.map((k, j) => (
            <LinkNode
              key={pathKey([...path, j])}
              node={k}
              path={[...path, j]}
              depth={depth + 1}
              pages={props.pages}
              q={props.q}
              setQ={props.setQ}
              loading={props.loading}
              openFor={props.openFor}
              setOpenFor={props.setOpenFor}
              patch={props.patch}
              move={props.move}
              indent={props.indent}
              outdent={props.outdent}
              addChild={props.addChild}
              remove={props.remove}
              nodeErrors={props.nodeErrors}
              isFirst={j === 0}
              isLast={j === kidCount - 1}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}
