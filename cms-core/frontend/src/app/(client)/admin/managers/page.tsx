import { serverApi } from "@/lib/server-api";
import { asPaginator, asPermMatrix, type Manager, type RoleItem, type PermMatrix } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Tabs } from "@/components/ui/Tabs";
import { Pagination } from "@/components/ui/Pagination";
import { ManagersClient } from "./ManagersClient";
import { RolesClient } from "./RolesClient";

/** مدیران + نقش‌ها ۱.۳ — تب مدیران/نقش‌ها (حالت در URL) + ماتریس دسترسی. سوپرادمین همیشه مخفی (سمت بک‌اند). */
export default async function ManagersPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const tab = sp.tab === "roles" ? "roles" : "managers";
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let error: string | null = null;
  let managers = asPaginator<Manager>(null);
  let roles: RoleItem[] = [];
  let matrix: PermMatrix = { modules: [], actions: [] };

  try {
    const [mj, rj, pj] = await Promise.all([
      serverApi<unknown>(`/v1/admin/managers?per_page=20&page=${page}`),
      serverApi<unknown>(`/v1/admin/roles`),
      serverApi<unknown>(`/v1/admin/permissions`),
    ]);
    managers = asPaginator<Manager>(mj);
    const rd = (rj as { data?: unknown })?.data ?? rj;
    roles = Array.isArray(rd) ? (rd as RoleItem[]) : [];
    matrix = asPermMatrix(pj);
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
  }

  return (
    <div>
      <div className="page-head">
        <div><h1>مدیران و نقش‌ها</h1><p>ساخت مدیر، تخصیص نقش و ماتریس دسترسی ماژول‌ها</p></div>
      </div>

      <Tabs
        active={tab}
        tabs={[
          { key: "managers", label: "مدیران", href: "/admin/managers?tab=managers", badge: managers.total || "" },
          { key: "roles", label: "نقش‌ها و دسترسی‌ها", href: "/admin/managers?tab=roles", badge: roles.length || "" },
        ]}
      />

      {error ? <Alert tone="red">{error}</Alert> : null}

      {!error && tab === "managers" ? (
        <div>
          <ManagersClient initial={managers} />
          <Pagination page={managers.current_page} lastPage={managers.last_page} total={managers.total} base="/admin/managers" params={{ tab, page: String(page) }} />
        </div>
      ) : null}
      {!error && tab === "roles" ? <RolesClient initial={roles} matrix={matrix} /> : null}
    </div>
  );
}
