"use client";

import { useCallback, useEffect, useState } from "react";
import type { SiteThemeColorway } from "@/lib/site";

/**
 * F4.1.F — کنترل‌های **زندهٔ** پیش‌نمایش قالب.
 *
 * رنگ‌بندیِ پیشنهادی و حالتِ روشن/تیره را بدون بارگذاری مجدد عوض می‌کند: توکن‌های
 * قالب را روی همان عنصرِ `.site` می‌نویسد (`--theme-*`) و `data-mode` را ست
 * می‌کند. پس همان ساختارِ قالب می‌ماند و فقط رنگ/حالت عوض می‌شود — یعنی کاربر
 * تفاوتِ واقعیِ رنگ‌بندی‌ها و دارک/روشن را می‌بیند.
 */
export function PreviewControls({
  colorways,
  layoutTokens,
  defaultColorway,
}: {
  colorways: SiteThemeColorway[];
  layoutTokens: Record<string, string>;
  defaultColorway?: string | null;
}) {
  const [colorway, setColorway] = useState<string>(defaultColorway ?? colorways[0]?.key ?? "");
  const [mode, setMode] = useState<"light" | "dark">("light");

  const apply = useCallback(
    (key: string, m: "light" | "dark") => {
      const el = document.querySelector<HTMLElement>(".site");
      if (!el) return;
      const cw = colorways.find((c) => c.key === key);
      const tokens = { ...(cw?.[m] ?? {}), ...layoutTokens };
      for (const [k, v] of Object.entries(tokens)) {
        if (typeof v === "string" && /^[a-z0-9-]+$/i.test(k)) el.style.setProperty(`--theme-${k}`, v);
      }
      el.dataset.mode = m;
    },
    [colorways, layoutTokens],
  );

  useEffect(() => {
    apply(colorway, mode);
  }, [apply, colorway, mode]);

  const swatch = (c: SiteThemeColorway) => c.light.primary ?? c.dark.primary ?? "#888";

  return (
    <div
      style={{
        position: "sticky",
        insetBlockStart: 0,
        zIndex: 20,
        display: "flex",
        gap: 10,
        alignItems: "center",
        flexWrap: "wrap",
        padding: "8px 16px",
        background: "var(--surface-2)",
        borderBlockEnd: "1px solid var(--border)",
      }}
    >
      {colorways.length > 0 ? (
        <>
          <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>رنگ‌بندی:</span>
          {colorways.map((c) => (
            <button
              key={c.key}
              type="button"
              onClick={() => setColorway(c.key)}
              aria-pressed={colorway === c.key}
              title={c.name}
              aria-label={`رنگ‌بندی ${c.name}`}
              style={{
                inlineSize: 30,
                blockSize: 30,
                minInlineSize: 30,
                minBlockSize: 30,
                borderRadius: "50%",
                cursor: "pointer",
                padding: 0,
                border: colorway === c.key ? "2px solid var(--text)" : "2px solid var(--border)",
                outline: colorway === c.key ? "2px solid var(--surface-2)" : "none",
                outlineOffset: 1,
                background: swatch(c),
              }}
            />
          ))}
        </>
      ) : null}

      <div style={{ flex: 1 }} />

      <span style={{ fontSize: 12.5, color: "var(--text-muted)" }}>حالت:</span>
      <div className="seg" style={{ maxInlineSize: 200 }}>
        <button type="button" className={mode === "light" ? "on" : ""} onClick={() => setMode("light")}>
          روشن
        </button>
        <button type="button" className={mode === "dark" ? "on" : ""} onClick={() => setMode("dark")}>
          تیره
        </button>
      </div>
    </div>
  );
}
