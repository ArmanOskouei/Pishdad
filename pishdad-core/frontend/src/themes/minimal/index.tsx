import { ThemeShell } from "../shared";
import type { ThemeProps } from "../types";

/**
 * ECO1 — قالب «مینیمال».
 *
 * ستون‌های کناری **زیر** محتوا می‌آیند تا ستون خواندن تک‌تکه بماند. تزئین
 * حداقلی است و تفاوتِ رنگ/شعاع از `theme.globals` می‌آید، نه از این فایل.
 */
export function MinimalTheme(props: ThemeProps) {
  return <ThemeShell {...props} variant="minimal" layout={{ sidebarPlacement: "stacked" }} />;
}

export default MinimalTheme;
