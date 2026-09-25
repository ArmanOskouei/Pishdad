import { Shell } from "@/components/layout/Shell";
import { CENTRAL_MENU } from "@/lib/menu";

/** لایه‌بندی پنل مرکزی (جهت عملیات). */
export default function CentralLayout({ children }: { children: React.ReactNode }) {
  return (
    <Shell
      groups={CENTRAL_MENU}
      brand={{ name: "پنل مرکزی", sub: "فقط کارفرما" }}
      crumbs={["مرکزی"]}
    >
      {children}
    </Shell>
  );
}
