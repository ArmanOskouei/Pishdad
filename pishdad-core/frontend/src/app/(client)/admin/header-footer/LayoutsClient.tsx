"use client";
import { useCallback, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge } from "@/components/ui/primitives";
import { Tabs } from "@/components/ui/Tabs";
import { DragDropList } from "@/components/ui/DragDropList";
import { Modal } from "@/components/ui/Overlays";
import { SchemaForm } from "@/components/ui/SchemaForm";
import { LinkListEditor } from "@/components/admin/LinkListEditor";
import { ChromeWidget, SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { localRevalidate } from "@/lib/local-revalidate";
import type { SiteChrome } from "@/lib/site";
import { faNum } from "@/lib/fa";
import type { ChromeLinkItem, LayoutData, WidgetSchema, WidgetValue } from "@/lib/domain";
// E66 — منطقِ مشترکِ نواحیِ فوتر (بالاتر/ستون‌ها/پایین‌تر) با رندرِ سایتِ عمومی.
import {
  distributeFooter,
  flattenFooter,
  footerColumnCount,
  type FooterDropTarget,
  type FooterZones,
} from "@/lib/footer-layout";

type Area = "header" | "footer";
type WItem = WidgetValue & { id: string };

/**
 * ویرایشگر Widget Area (تسک ۶): تب + پالت از schema بک‌اند + بوم درگ‌اندروپ +
 * مودال تنظیمات با SchemaForm (نه JSON خام). type ناشناس (مثلاً از پلاگین
 * غیرفعال) fallback به JSON خام با هشدار می‌دهد — حذف نمی‌شود.
 */
export function LayoutsClient({ initial, tab, schemas, chromeBase }: { initial: { header: LayoutData; footer: LayoutData }; tab: string; schemas: WidgetSchema[]; chromeBase: SiteChrome | null }) {
  const toast = useToast();
  const router = useRouter();
  const area = (tab === "footer" ? "footer" : "header") as Area;
  const idSequence = useRef(0);
  const [items, setItems] = useState<Record<Area, WItem[]>>(() => ({
    header: initial.header.widgets.map((w, i) => ({ ...w, id: `header-initial-${i}-${w.type}` })),
    footer: initial.footer.widgets.map((w, i) => ({ ...w, id: `footer-initial-${i}-${w.type}` })),
  }));
  const [editing, setEditing] = useState<WItem | null>(null);
  const [busy, setBusy] = useState(false);
  // دسته UIUX (افزودنی): سوییچ پیش‌نمایش دسکتاپ/موبایل.
  const [previewMode, setPreviewMode] = useState<"desktop" | "mobile">("desktop");
  const [previewLayerOpen, setPreviewLayerOpen] = useState(true);
  // E66 — نوعِ ویجتی که کاربر از پالتِ فوتر زده و منتظرِ انتخابِ مقصد است.
  const [addingFooterType, setAddingFooterType] = useState<string | null>(null);
  // ستون‌بندی فوتر: layout.columns (۱ تا ۴ یا خالی = خودکار از تعداد ستون‌ها).
  const [layouts, setLayouts] = useState<Record<Area, Record<string, unknown>>>({
    header: initial.header.layout ?? {},
    footer: initial.footer.layout ?? {},
  });
  const dirty =
    JSON.stringify(items.header.map(({ id, ...r }) => r)) !== JSON.stringify(initial.header.widgets) ||
    JSON.stringify(items.footer.map(({ id, ...r }) => r)) !== JSON.stringify(initial.footer.widgets) ||
    JSON.stringify(layouts.header) !== JSON.stringify(initial.header.layout ?? {}) ||
    JSON.stringify(layouts.footer) !== JSON.stringify(initial.footer.layout ?? {});

  const schemaFor = (a: Area, type: string): WidgetSchema | null =>
    schemas.find((s) => s.area === a && s.type === type) ?? null;

  const palette = paletteFor(area, schemas, items[area]);

  const moveInArea = (targetArea: Area, from: number, to: number) => {
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || from >= items[targetArea].length) return;
    setItems((prev) => {
      const list = [...prev[targetArea]];
      const [moved] = list.splice(from, 1);
      if (!moved) return prev;
      const target = Math.max(0, Math.min(to, list.length));
      list.splice(target, 0, moved);
      return { ...prev, [targetArea]: list };
    });
  };

  const move = (from: number, to: number) => moveInArea(area, from, to);

  /**
   * E66 — افزودنِ ویجت. در **فوتر** می‌توان مقصد را هم داد (بالاتر از ستون‌ها،
   * ستونِ مشخص، پایین‌تر از ستون‌ها)؛ بدونِ مقصد رفتارِ قبلی است (انتهای لیست).
   */
  const add = (targetArea: Area, type: string, drop?: FooterDropTarget) => {
    const sequence = idSequence.current++;
    const item: WItem = { id: nextWidgetId(targetArea, sequence), type, settings: {} };
    setItems((prev) => {
      const list = [...prev[targetArea], item];
      if (targetArea !== "footer" || !drop) return { ...prev, [targetArea]: list };

      const cols = footerColumnCount(layouts.footer?.columns, list.filter((w) => w.type === "links").length);
      const zones = distributeFooter(list, cols);
      // ویجتِ تازه اول در ناحیهٔ پیش‌فرضش می‌نشیند؛ از همان‌جا برمی‌داریم و به مقصد می‌بریم.
      zones.above = zones.above.filter((w) => w.id !== item.id);
      zones.below = zones.below.filter((w) => w.id !== item.id);
      zones.columns = zones.columns.map((column) => column.filter((w) => w.id !== item.id));

      if (drop.zone === "column") {
        const target = Math.max(0, Math.min(cols - 1, drop.column));
        zones.columns[target]!.push({ ...item, place: undefined });
      } else {
        const zone = drop.zone === "above" ? zones.above : zones.below;
        zone.push({ ...item, place: drop.zone });
      }

      return { ...prev, footer: flattenFooter(zones) };
    });
  };

  const remove = (id: string) =>
    setItems((prev) => ({ ...prev, [area]: prev[area].filter((w) => w.id !== id) }));

  const save = async () => {
    setBusy(true);
    try {
      // E66 — `place` فقط برای فوتر معنا دارد؛ برای ویجت‌های داخلِ ستون‌ها فرستاده
      // نمی‌شود تا دادهٔ ذخیره‌شده همان شکلِ قبلی بماند.
      const clean = items[area].map(({ type, settings, place }) => ({
        type,
        settings,
        ...(area === "footer" && place ? { place } : null),
      }));
      await authed(`/v1/admin/layouts/${area}`, { method: "PUT", body: { widgets: clean, layout: layouts[area] } });
      await localRevalidate(["site-chrome", "pages"]);
      toast(area === "header" ? "چیدمان هدر ذخیره شد." : "چیدمان فوتر ذخیره شد.", "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  const list = items[area];
  // E66 — ستون‌ها و دو ناحیهٔ تمام‌عرض، از یک منبعِ مشترک با رندرِ سایتِ عمومی.
  const footerColumns = footerColumnCount(
    layouts.footer?.columns,
    items.footer.filter((w) => w.type === "links").length,
  );
  const footerZones = distributeFooter(items.footer, footerColumns);
  const footerColumnItems = footerZones.columns;
  const footerAboveItems = footerZones.above;
  const footerBelowItems = footerZones.below;

  /**
   * همهٔ تغییرهای فوتر از همین یک مسیر می‌گذرند: نواحی را می‌سازیم، دست می‌بریم،
   * و با `flattenFooter` برمی‌گردانیم. نتیجه: آرایهٔ ذخیره‌شده **همیشه** با
   * ترتیبِ DOM یکی است (لایهٔ ویرایشِ پیش‌نمایش بر ایندکس حساب می‌کند).
   */
  const updateFooterZones = (mutate: (zones: FooterZones<WItem>, cols: number) => void) => {
    setItems((prev) => {
      const cols = footerColumnCount(layouts.footer?.columns, prev.footer.filter((w) => w.type === "links").length);
      const zones = distributeFooter(prev.footer, cols);
      mutate(zones, cols);
      return { ...prev, footer: flattenFooter(zones) };
    });
  };

  /** جابه‌جایی درونِ یک ناحیهٔ تمام‌عرض (بالاتر/پایین‌تر). */
  const moveFooterZoneItem = (zone: "above" | "below", from: number, to: number) => {
    if (from === to) return;
    updateFooterZones((zones) => {
      const target = zone === "above" ? zones.above : zones.below;
      const [moved] = target.splice(from, 1);
      if (!moved) return;
      target.splice(Math.max(0, Math.min(to, target.length)), 0, moved);
    });
  };

  /** برداشتنِ ویجت از هر ناحیه‌ای (برای انتقال یا حذف). */
  const takeFooterWidget = (zones: FooterZones<WItem>, id: string): WItem | undefined => {
    for (const column of zones.columns) {
      const index = column.findIndex((w) => w.id === id);
      if (index >= 0) return column.splice(index, 1)[0];
    }
    for (const zone of [zones.above, zones.below]) {
      const index = zone.findIndex((w) => w.id === id);
      if (index >= 0) return zone.splice(index, 1)[0];
    }

    return undefined;
  };

  const moveFooterColumnItem = (columnIndex: number, from: number, to: number) => {
    if (from === to) return;
    updateFooterZones((zones) => {
      const column = zones.columns[columnIndex];
      if (!column) return;
      const [moved] = column.splice(from, 1);
      if (!moved) return;
      column.splice(Math.max(0, Math.min(to, column.length)), 0, moved);
    });
  };

  const transferFooterWidget = (id: string, targetColumn: number) => {
    if (area !== "footer" || !Number.isInteger(targetColumn)) return;
    updateFooterZones((zones, cols) => {
      const moved = takeFooterWidget(zones, id);
      if (!moved) return;
      const target = Math.max(0, Math.min(cols - 1, targetColumn));
      zones.columns[target]!.push({ ...moved, place: undefined });
    });
  };

  const renderWidgetRow = (
    w: WItem,
    i: number,
    helpers: { moveUp: () => void; moveDown: () => void },
    columnIndex?: number,
  ) => {
    const s = schemaFor(area, w.type);
    const currentColumn = columnIndex ?? 0;
    const heading = typeof w.settings?.heading === "string" ? w.settings.heading : null;
    return (
      <div
        className="ver-row layout-footer-widget-row"
        data-widget-id={w.id}
        data-column={columnIndex === undefined ? undefined : columnIndex + 1}
        data-position={i + 1}
      >
        <span aria-hidden style={{ cursor: "grab" }}>⠿</span>
        <b className="ver-title">{heading || s?.title || w.type}</b>
        {heading && s?.title ? <span className="layout-footer-widget-kind">{s.title}</span> : null}
        {s?.source && s.source !== "core" ? <Badge tone="violet">پلاگین</Badge> : null}
        {!s ? <Badge tone="gray">ناشناس</Badge> : null}
        {columnIndex !== undefined ? (
          <label className="layout-footer-transfer">
            <span>انتقال به ستون</span>
            <select
              className="select"
              value={String(currentColumn)}
              aria-label={`انتقال ${s?.title ?? w.type} به ستون`}
              onChange={(e) => transferFooterWidget(w.id, Number(e.target.value))}
            >
              {Array.from({ length: footerColumns }, (_, index) => (
                <option key={index} value={index}>
                  ستون {faNum(index + 1)}
                </option>
              ))}
            </select>
          </label>
        ) : null}
        <div className="ver-actions">
          <button className="btn btn-ghost btn-sm" onClick={helpers.moveUp} aria-label="بالا">↑</button>
          <button className="btn btn-ghost btn-sm" onClick={helpers.moveDown} aria-label="پایین">↓</button>
          <button className="btn btn-ghost btn-sm" onClick={() => setEditing(w)}>تنظیمات</button>
          <button className="btn btn-ghost btn-sm" onClick={() => remove(w.id)}>حذف</button>
        </div>
      </div>
    );
  };

  // نگاشت page_id → {title, url} از کروم resolve‌شده. لینک‌های draft خام‌اند و
  // title ندارند؛ بدون این نگاشت پیش‌نمایش/لایه تعاملی به‌جای عنوان صفحه «—»
  // نشان می‌داد. منبع: chromeBase که از endpoint عمومی (resolve‌شده) می‌آید.
  const pageTitles = useMemo(() => {
    const map = new Map<number, { title: string; url: string }>();
    if (!chromeBase) return map;
    for (const area of ["header", "footer"] as const) {
      for (const w of chromeBase[area]?.widgets ?? []) {
        const links = (w as { settings?: { links?: ChromeLinkItem[] } })?.settings?.links;
        for (const l of links ?? []) {
          if (l.kind === "page" && l.page_id && l.title) {
            map.set(l.page_id, { title: l.title, url: l.url ?? l.href ?? "/" });
          }
        }
      }
    }
    return map;
  }, [chromeBase]);

  /** پر کردن title/url لینک‌های page از نگاشت بالا (بازگشتی، شامل زیرمنو). */
  const enrichLinks = useCallback(
    (list: ChromeLinkItem[]): ChromeLinkItem[] =>
      list.map((l) => {
        const kids = Array.isArray(l.children) ? enrichLinks(l.children) : l.children;
        if (l.kind === "page" && l.page_id) {
          const hit = pageTitles.get(l.page_id);
          if (hit) {
            return {
              ...l,
              title: l.title ?? hit.title,
              url: l.url ?? hit.url,
              href: l.href ?? hit.url,
              label: l.label || hit.title,
              ...(kids ? { children: kids } : null),
            };
          }
        }
        return kids ? { ...l, children: kids } : l;
      }),
    [pageTitles],
  );

  /** غنی‌سازی یک ویجت خام برای رندر پیش‌نمایش/لایه تعاملی. */
  const enrichWidget = useCallback(
    (w: WidgetValue): WidgetValue => {
      if ((w.type === "nav" || w.type === "links") && w.settings && Array.isArray(w.settings.links)) {
        return {
          ...w,
          settings: { ...w.settings, links: enrichLinks(w.settings.links as ChromeLinkItem[]) },
        };
      }
      return w;
    },
    [enrichLinks],
  );

  // پیش‌نمایش زنده: همان کامپوننت‌های عمومی سایت با تنظیمات draft فعلی (بدون ذخیره).
  // تم فعال از کروم واقعی پاس داده می‌شود (themeVars) — مثل رندر عمومی.
  const draftChrome: SiteChrome | null = useMemo(() => {
    const base =
      chromeBase ??
      ({ title: "وب‌سایت من", description: "", header: { widgets: [], layout: {} }, footer: { widgets: [], layout: {} }, socials: [] } as unknown as SiteChrome);

    return {
      ...base,
      header: {
        widgets: items.header.map(enrichWidget),
        layout: layouts.header,
      },
      footer: {
        widgets: items.footer.map(enrichWidget),
        layout: layouts.footer,
      },
    };
  }, [chromeBase, items, layouts, enrichWidget]);

  return (
    <div>
      <Tabs
        active={area}
        tabs={[
          { key: "header", label: "هدر", href: "/admin/header-footer?tab=header", badge: faNum(items.header.length) },
          { key: "footer", label: "فوتر", href: "/admin/header-footer?tab=footer", badge: faNum(items.footer.length) },
        ]}
      />

      <div className="grid c2" style={{ alignItems: "start" }}>
        <div className="card card-pad">
          <div className="card-title">پالت ویجت‌ها ({area === "header" ? "هدر" : "فوتر"})</div>
          <p className="card-sub">
            {area === "footer"
              ? "برای افزودن کلیک کنید؛ بعد می‌پرسد در ستون کدام یا بالاتر/پایین‌تر از ستون‌ها."
              : "برای افزودن به بوم کلیک کنید."}
          </p>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {palette.map((p) => (
              <button
                key={p.type}
                className="btn btn-ghost btn-sm"
                title={p.description ?? p.type}
                onClick={() => (area === "footer" ? setAddingFooterType(p.type) : add(area, p.type))}
              >
                ＋                 {p.title}
                {p.source !== "core" ? <Badge tone="violet">پلاگین</Badge> : null}
              </button>
            ))}
          </div>
          {palette.length === 0 ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>ویجتی برای این ناحیه تعریف نشده.</p> : null}
        </div>

        <div className="card card-pad">
          <div className="card-title">بوم {area === "header" ? "هدر" : "فوتر"} {dirty ? <span style={{ color: "var(--warning)" }}>● ذخیره‌نشده</span> : null}</div>
          <div className="seg" role="group" aria-label="حالت پیش‌نمایش" style={{ maxInlineSize: 240, marginBlockEnd: 10 }}>
            <button className={previewMode === "desktop" ? "on" : ""} onClick={() => setPreviewMode("desktop")}>🖥 دسکتاپ</button>
            <button className={previewMode === "mobile" ? "on" : ""} onClick={() => setPreviewMode("mobile")}>📱 موبایل</button>
          </div>
          <div style={previewMode === "mobile" ? { maxInlineSize: 400, marginInline: "auto", border: "1px dashed var(--border)", borderRadius: 12, padding: 10 } : undefined}>
          {area === "footer" ? (
            <div className="layout-footer-canvas">
              <div className="field layout-footer-columns-control">
                <label htmlFor="footer-columns">تعداد ستون‌ها</label>
                <select
                  id="footer-columns"
                  className="select"
                  value={String(layouts.footer?.columns ?? "")}
                  onChange={(e) =>
                    setLayouts((prev) => ({
                      ...prev,
                      footer: { ...prev.footer, ...(e.target.value ? { columns: Number(e.target.value) } : { columns: undefined }) },
                    }))
                  }
                >
                  <option value="">خودکار ({faNum(footerColumns)} ستون)</option>
                  {[1, 2, 3, 4].map((n) => (
                    <option key={n} value={n}>{faNum(n)} ستون</option>
                  ))}
                </select>
              </div>
              {items.footer.length === 0 ? <p className="layout-footer-empty">بوم فوتر خالی است — از پالت ویجت اضافه کنید.</p> : null}
              {footerAboveItems.length > 0 ? (
                <section className="layout-footer-full-width" aria-label="ویجت‌های بالاتر از ستون‌ها">
                  <div className="layout-footer-column-head">
                    <strong>بالاتر از ستون‌ها</strong>
                    <span>تمام‌عرض</span>
                  </div>
                  <DragDropList
                    items={footerAboveItems}
                    onMove={(from, to) => moveFooterZoneItem("above", from, to)}
                    render={(w, i, helpers) => renderWidgetRow(w, i, helpers)}
                  />
                </section>
              ) : null}
              <div
                className="layout-footer-column-grid"
                dir="rtl"
                data-columns={footerColumns}
                aria-label="ستون‌های فوتر"
                style={{ gridTemplateColumns: `repeat(${footerColumns}, minmax(0, 1fr))` }}
              >
                {footerColumnItems.map((columnItems, columnIndex) => (
                  <section className="layout-footer-column" key={`footer-column-${columnIndex}`} aria-label={`ستون ${faNum(columnIndex + 1)}`}>
                    <div className="layout-footer-column-head">
                      <strong>ستون {faNum(columnIndex + 1)}</strong>
                      <span>{faNum(columnItems.length)} ویجت</span>
                    </div>
                    <div className="layout-footer-column-items">
                      <DragDropList
                        items={columnItems}
                        onMove={(from, to) => moveFooterColumnItem(columnIndex, from, to)}
                        render={(w, i, helpers) => renderWidgetRow(w, i, helpers, columnIndex)}
                      />
                      {columnItems.length === 0 ? <p className="layout-footer-column-empty">این ستون خالی است</p> : null}
                    </div>
                  </section>
                ))}
              </div>
              {footerBelowItems.length > 0 ? (
                <section className="layout-footer-full-width" aria-label="ویجت‌های پایین‌تر از ستون‌ها">
                  <div className="layout-footer-column-head">
                    <strong>پایین‌تر از ستون‌ها</strong>
                    <span>تمام‌عرض — مانند کپی‌رایت</span>
                  </div>
                  <DragDropList
                    items={footerBelowItems}
                    onMove={(from, to) => moveFooterZoneItem("below", from, to)}
                    render={(w, i, helpers) => renderWidgetRow(w, i, helpers)}
                  />
                </section>
              ) : null}
              <p className="layout-footer-hint">هر ستون راست‌به‌چپ نمایش داده می‌شود؛ برای جابه‌جایی بین ستون‌ها، «انتقال به ستون» را تغییر دهید. برای افزودن به نواحیِ تمام‌عرض، از پالت گزینهٔ «بالاتر/پایین‌تر از ستون‌ها» را بزنید.</p>
            </div>
          ) : null}
          {area === "header" ? (
            <>
              {list.length === 0 ? <p className="layout-footer-empty">بوم خالی است — از پالت اضافه کنید.</p> : null}
              <DragDropList
                items={list}
                onMove={move}
                render={(w, i, helpers) => renderWidgetRow(w, i, helpers)}
              />
            </>
          ) : null}
          <button className="btn btn-primary" onClick={() => void save()} disabled={busy || !dirty} style={{ marginBlockStart: 10 }}>
            {busy ? "…" : "ذخیره چیدمان"}
          </button>
          </div>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockStart: 14 }}>
        <div className="card-title">پیش‌نمایش زنده {dirty ? "(تنظیمات draft — ذخیره نشده)" : "(مطابق ذخیره‌شده)"}</div>
        <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
          <p className="card-sub" style={{ flex: "1 1 280px" }}>
            لایهٔ تعاملی روی رندر عمومی فعال است؛ ویجت را بکشید، حذف یا از پالت اضافه کنید. تغییر همان state بوم بالا را هم‌زمان عوض می‌کند.
          </p>
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={() => setPreviewLayerOpen((open) => !open)}
            aria-pressed={previewLayerOpen}
            style={{ minBlockSize: 44 }}
          >
            {previewLayerOpen ? "پنهان‌کردن لایهٔ ویرایش" : "نمایش لایهٔ ویرایش"}
          </button>
        </div>
        <div
          className="site"
          style={{
            ...(themeVars(draftChrome) as Record<string, string>),
            ...(previewMode === "mobile"
              ? { maxInlineSize: 400, marginInline: "auto", border: "1px dashed var(--border)", borderRadius: 12, overflow: "hidden" }
              : { border: "1px dashed var(--border)", borderRadius: 12, overflow: "hidden" }),
          }}
        >
          <div style={{ position: "relative" }}>
            <SiteHeader chrome={draftChrome} diagnostics />
            {previewLayerOpen && area === "header" ? (
              <PreviewWidgetLayer
                area="header"
                items={items.header}
                chrome={draftChrome}
                palette={palette}
                onMove={(from, to) => moveInArea("header", from, to)}
                onRemove={remove}
                onEdit={setEditing}
                onAdd={(type) => add("header", type)}
                enrichWidget={enrichWidget}
              />
            ) : null}
          </div>
          <main style={{ padding: 16, minBlockSize: 80 }}>
            <p style={{ fontSize: 12.5, color: "var(--text-muted)", margin: 0 }}>
              محتوای نمونه صفحه — فقط هدر و فوتر واقعی‌اند (با draft فعلی).
            </p>
          </main>
          <div style={{ position: "relative" }}>
            {previewLayerOpen && area === "footer" ? (
              <PreviewWidgetLayer
                area="footer"
                items={items.footer}
                chrome={draftChrome}
                palette={paletteFor("footer", schemas, items.footer)}
                onMove={(from, to) => moveInArea("footer", from, to)}
                onRemove={remove}
                onEdit={setEditing}
                onAdd={(type) => setAddingFooterType(type)}
                enrichWidget={enrichWidget}
              />
            ) : null}
            <SiteFooter chrome={draftChrome} diagnostics />
          </div>
        </div>
      </div>

      {editing ? (
        <WidgetSettingsModal
          widget={editing}
          area={area}
          schema={schemaFor(area, editing.type)}
          onSave={(settings) => {
            setItems((prev) => ({
              ...prev,
              [area]: prev[area].map((w) => (w.id === editing.id ? { ...w, settings } : w)),
            }));
            setEditing(null);
          }}
          onClose={() => setEditing(null)}
        />
      ) : null}

      {addingFooterType ? (
        <FooterAddModal
          title={paletteFor("footer", schemas, items.footer).find((p) => p.type === addingFooterType)?.title ?? addingFooterType}
          columns={footerColumns}
          onPick={(target) => {
            add("footer", addingFooterType, target);
            setAddingFooterType(null);
          }}
          onClose={() => setAddingFooterType(null)}
        />
      ) : null}
    </div>
  );
}

function PreviewWidgetLayer({
  area,
  items,
  chrome,
  palette,
  onMove,
  onRemove,
  onEdit,
  onAdd,
  enrichWidget,
}: {
  area: Area;
  items: WItem[];
  chrome: SiteChrome | null;
  palette: ReturnType<typeof paletteFor>;
  onMove: (from: number, to: number) => void;
  onRemove: (id: string) => void;
  onEdit: (item: WItem) => void;
  onAdd: (type: string) => void;
  enrichWidget: (w: WidgetValue) => WidgetValue;
}) {
  const [paletteChoice, setPaletteChoice] = useState("");
  const areaLabel = area === "header" ? "هدر" : "فوتر";

  return (
    <section
      aria-label={`لایه تعاملی ویجت‌های ${areaLabel}`}
      style={{
        position: "absolute",
        insetInline: 8,
        insetBlockStart: area === "header" ? 6 : undefined,
        insetBlockEnd: area === "footer" ? 6 : undefined,
        zIndex: 20,
        maxBlockSize: 260,
        overflowY: "auto",
        padding: 8,
        border: "1px solid var(--primary)",
        borderRadius: 10,
        background: "var(--surface)",
        boxShadow: "var(--shadow-lg)",
      }}
    >
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", marginBlockEnd: 6 }}>
        <b style={{ fontSize: 12.5 }}>لایه تعاملی {areaLabel}</b>
        <span style={{ color: "var(--text-muted)", fontSize: 11.5 }}>ویجت را بکشید یا از ↑/↓ جابه‌جا کنید.</span>
        <div style={{ display: "flex", alignItems: "center", gap: 6, marginInlineStart: "auto", flexWrap: "wrap" }}>
          <label style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 12 }}>
            <span>افزودن:</span>
            <select
              className="select"
              value={paletteChoice}
              onChange={(event) => {
                const type = event.target.value;
                if (type) onAdd(type);
                setPaletteChoice("");
              }}
              aria-label={`افزودن ویجت به ${areaLabel}`}
              style={{ minBlockSize: 44, minInlineSize: 130 }}
            >
              <option value="">انتخاب ویجت</option>
              {palette.map((item) => (
                <option key={item.type} value={item.type}>{item.title}</option>
              ))}
            </select>
          </label>
        </div>
      </div>
      {items.length === 0 ? (
        <p style={{ margin: 0, color: "var(--text-muted)", fontSize: 12 }}>برای شروع یک ویجت از پالت اضافه کنید.</p>
      ) : (
        <DragDropList
          items={items}
          onMove={onMove}
          render={(item, index, helpers) => {
            const title = palette.find((entry) => entry.type === item.type)?.title ?? item.type;
            return (
              <div
                className="ver-row"
                style={{ padding: 6, marginBlockEnd: 6, border: "1px solid var(--border)", borderRadius: 8, background: "var(--surface)" }}
              >
                <span aria-hidden title={`جابه‌جایی ${title}`} style={{ cursor: "grab", fontSize: 17 }}>⠿</span>
                <div style={{ flex: "1 1 170px", minInlineSize: 0, maxInlineSize: 300, overflow: "hidden" }}>
                  <small style={{ display: "block", color: "var(--text-muted)", fontSize: 11 }}>{faNum(index + 1)}. {title}</small>
                  <ChromeWidget w={enrichWidget({ type: item.type, settings: item.settings })} chrome={chrome} diagnostics />
                </div>
                <div className="ver-actions" style={{ gap: 4 }}>
                  <button type="button" className="icon-btn" onClick={helpers.moveUp} disabled={index === 0} aria-label={`انتقال ${title} به بالا`} style={{ minInlineSize: 44, minBlockSize: 44 }}>↑</button>
                  <button type="button" className="icon-btn" onClick={helpers.moveDown} disabled={index === items.length - 1} aria-label={`انتقال ${title} به پایین`} style={{ minInlineSize: 44, minBlockSize: 44 }}>↓</button>
                  <button type="button" className="icon-btn" onClick={() => onEdit(item)} aria-label={`تنظیمات ${title}`} style={{ minInlineSize: 44, minBlockSize: 44 }}>تنظیم</button>
                  <button type="button" className="icon-btn" onClick={() => onRemove(item.id)} aria-label={`حذف ${title}`} style={{ minInlineSize: 44, minBlockSize: 44 }}>✕</button>
                </div>
              </div>
            );
          }}
        />
      )}
    </section>
  );
}

function nextWidgetId(area: Area, sequence: number): string {
  const random = typeof globalThis.crypto?.randomUUID === "function" ? globalThis.crypto.randomUUID() : `local-${sequence}`;
  return `${area}-${random}`;
}

/**
 * E66 — پنجرهٔ کوچکِ «کجا اضافه شود؟» برای فوتر.
 *
 * ستون‌ها بر اساسِ تعدادِ **فعال** ساخته می‌شوند (همان عددی که در بوم و سایت
 * رندر می‌شود) و دو گزینهٔ تمام‌عرض هم کنارش‌اند. بدونِ این پنجره، کاربر جای
 * ویجتِ تازه را انتخاب نمی‌کرد و همهٔ افزودنی‌ها در ستون‌ها می‌افتادند.
 *
 * تعدادِ ستونِ مؤثر از `lib/footer-layout.ts::footerColumnCount` می‌آید تا با
 * بک‌اند و رندرِ سایتِ عمومی **یک** قاعده بماند.
 */
function FooterAddModal({
  title,
  columns,
  onPick,
  onClose,
}: {
  title: string;
  columns: number;
  onPick: (target: FooterDropTarget) => void;
  onClose: () => void;
}) {
  return (
    <Modal title={`افزودن «${title}»`} onClose={onClose}>
      <p style={{ fontSize: 13, color: "var(--text-muted)" }}>کجا اضافه شود؟</p>
      <div style={{ display: "grid", gap: 12 }}>
        <div>
          <div style={{ fontSize: 12.5, fontWeight: 700, marginBlockEnd: 6 }}>در ستون‌ها</div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            {Array.from({ length: columns }, (_, index) => (
              <button
                key={index}
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => onPick({ zone: "column", column: index })}
              >
                ستون {faNum(index + 1)}
              </button>
            ))}
          </div>
        </div>
        <div>
          <div style={{ fontSize: 12.5, fontWeight: 700, marginBlockEnd: 6 }}>تمام‌عرض</div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => onPick({ zone: "above" })}>
              ⬆ بالاتر از ستون‌ها
            </button>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => onPick({ zone: "below" })}>
              ⬇ پایین‌تر از ستون‌ها
            </button>
          </div>
        </div>
      </div>
    </Modal>
  );
}

/** پالت ناحیه از schema بک‌اند (+ typeهای موجود در چیدمان که در schema نیستند). */
function paletteFor(area: "header" | "footer", schemas: WidgetSchema[], current: WidgetValue[]) {
  const known = schemas.filter((s) => s.area === area);
  const extra = current.map((w) => w.type).filter((t) => !known.some((s) => s.type === t));
  return [
    ...known.map((s) => ({ type: s.type, title: s.title, description: s.description, source: s.source })),
    ...extra.map((t) => ({ type: t, title: t, description: null as string | null, source: "unknown" })),
  ];
}

function WidgetSettingsModal({ widget, area, schema, onSave, onClose }: { widget: WItem; area: "header" | "footer"; schema: WidgetSchema | null; onSave: (s: Record<string, unknown>) => void; onClose: () => void }) {
  const toast = useToast();
  const [value, setValue] = useState<Record<string, unknown>>(widget.settings ?? {});
  const [raw, setRaw] = useState(JSON.stringify(widget.settings ?? {}, null, 2));

  const saveForm = () => onSave(value);

  const saveRaw = () => {
    try {
      const parsed = JSON.parse(raw || "{}") as Record<string, unknown>;
      if (typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("bad");
      onSave(parsed);
    } catch {
      toast("تنظیمات باید JSON object معتبر باشد.", "err");
    }
  };

  const isLinkWidget = (area === "header" && widget.type === "nav") || (area === "footer" && widget.type === "links");
  const links = Array.isArray(value.links) ? (value.links as ChromeLinkItem[]) : [];

  return (
    <Modal title={`تنظیمات: ${schema?.title ?? widget.type}`} onClose={onClose}>
      {schema?.description ? <p style={{ fontSize: 13, color: "var(--text-muted)" }}>{schema.description}</p> : null}
      {schema ? (
        isLinkWidget ? (
          <div>
            {widget.type === "links" ? (
              <div className="field">
                <label>عنوان ستون</label>
                <input
                  className="input" dir="auto" maxLength={80}
                  value={typeof value.heading === "string" ? value.heading : ""}
                  onChange={(e) => setValue({ ...value, heading: e.target.value })}
                  placeholder="مثلاً دسترسی سریع"
                />
              </div>
            ) : null}
            {widget.type === "nav" ? (
              <div className="field">
                <label>سبک نمایش</label>
                <select
                  className="select"
                  value={typeof value.style === "string" ? value.style : "horizontal"}
                  onChange={(e) => setValue({ ...value, style: e.target.value })}
                >
                  <option value="horizontal">افقی</option>
                  <option value="mega">مگا</option>
                </select>
              </div>
            ) : null}
            <LinkListEditor value={links} onChange={(next) => setValue({ ...value, links: next })} />
            <div style={{ display: "flex", gap: 8, marginBlockStart: 12 }}>
              <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
              <button className="btn btn-primary" onClick={saveForm}>اعمال</button>
            </div>
          </div>
        ) : (
        <div>
          <SchemaForm schema={schema.schema} value={value} onChange={setValue} labels={schema.ui?.labels} />
          <div style={{ display: "flex", gap: 8, marginBlockStart: 12 }}>
            <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
            <button className="btn btn-primary" onClick={saveForm}>اعمال</button>
          </div>
        </div>
        )
      ) : (
        <div>
          <Alert tone="amber">این ویجت در رجیستری بک‌اند شناخته‌شده نیست (مثلاً از پلاگین غیرفعال). تنظیمات به‌صورت خام نگه داشته می‌شود — حذف نمی‌شود.</Alert>
          <div className="field"><label>settings (JSON)</label>
            <textarea className="input" dir="ltr" style={{ textAlign: "left", fontFamily: "monospace", minBlockSize: 140 }} value={raw} onChange={(e) => setRaw(e.target.value)} />
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" onClick={onClose}>انصراف</button>
            <button className="btn btn-primary" onClick={saveRaw}>اعمال</button>
          </div>
        </div>
      )}
    </Modal>
  );
}
