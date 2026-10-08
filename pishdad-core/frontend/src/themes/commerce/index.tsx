import { ThemeShell } from "../shared";
import type { ThemeProps } from "../types";

/**
 * ECO1 — قالب «فروشگاهی».
 *
 * چیدمان شبکه‌ایِ تمام‌عرض با ستون‌های کناریِ چسبیده به محتوا، برای صفحه‌های
 * پرتراکم. تراکم/شعاع از توکن‌های پوسته می‌آید.
 */
export function CommerceTheme(props: ThemeProps) {
  return <ThemeShell {...props} variant="commerce" layout={{ sidebarPlacement: "columns" }} />;
}

export default CommerceTheme;
