import { ThemeShell } from "../shared";
import type { ThemeProps } from "../types";

/**
 * ECO1 — قالب «تحریری».
 *
 * ستون محتوا به عرض خواندنی محدود می‌شود و ستون‌های کناری **کنار** آن
 * می‌مانند. توکن‌های تایپوگرافیِ درشت‌ترِ این قالب در `theme.json`/seed است.
 */
export function EditorialTheme(props: ThemeProps) {
  return (
    <ThemeShell
      {...props}
      variant="editorial"
      layout={{ sidebarPlacement: "columns", mainMaxInlineSize: 720 }}
    />
  );
}

export default EditorialTheme;
