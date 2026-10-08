import { serverApi } from "@/lib/server-api";
import { asPaginator, asPermMatrix, type ActivityItem, type Manager, type RoleItem, type PermMatrix } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Tabs } from "@/components/ui/Tabs";
import { Pagination } from "@/components/ui/Pagination";
import { ManagersClient } from "./ManagersClient";
import { RolesClient } from "./RolesClient";
import { ActivityLogClient, type ActivityFilters } from "./ActivityLogClient";

/** مدیران + نقش‌ها ۱.۳ + گزارش فعالیت WF-H9 — تب‌های لینک‌محور (حالت در URL). */
export default async function ManagersPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const tab = sp.tab === "roles" ? "roles" : sp.tab === "activities" ? "activities" : "managers";
  const page = Math.max(1, Number(sp.page ?? 1) || 1);
  const filters: ActivityFilters = {
    user_id: sp.user_id ?? "",
    action: sp.action ?? "",
    from: sp.from ?? "",
    to: sp.to ?? "",
  };

  let error: string | null = null;
  let managers = asPaginator<Manager>(null);
  let roles: RoleItem[] = [];
  let matrix: PermMatrix = { modules: [], actions: [] };
  let activities = asPaginator<ActivityItem>(null);

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

    if (tab === "activities") {
      const qs = new URLSearchParams({ per_page: "20", page: String(page) });
      if (filters.user_id) qs.set("user_id", filters.user_id);
      if (filters.action) qs.set("action", filters.action);
      if (filters.from) qs.set("from", filters.from);
      if (filters.to) qs.set("to", filters.to);
      activities = asPaginator<ActivityItem>(await serverApi<unknown>(`/v1/admin/activities?${qs.toString()}`));
    }
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری.";
  }

  const activityParams: Record<string, string> = { tab, page: String(page) };
  if (filters.user_id) activityParams.user_id = filters.user_id;
  if (filters.action) activityParams.action = filters.action;
  if (filters.from) activityParams.from = filters.from;
  if (filters.to) activityParams.to = filters.to;

  return (
    <div>
      <div className="page-head">
        <div><h1>مدیران و نقش‌ها</h1><p>ساخت مدیر، تخصیص نقش، ماتریس دسترسی و گزارش فعالیت محتوا</p></div>
      </div>

      <Tabs
        active={tab}
        tabs={[
          { key: "managers", label: "مدیران", href: "/admin/managers?tab=managers", badge: managers.total || "" },
          { key: "roles", label: "نقش‌ها و دسترسی‌ها", href: "/admin/managers?tab=roles", badge: roles.length || "" },
          { key: "activities", label: "گزارش فعالیت", href: "/admin/managers?tab=activities", badge: activities.total || "" },
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
      {!error && tab === "activities" ? (
        <div>
          <ActivityLogClient initial={activities} managers={managers.data} filters={filters} />
          <Pagination page={activities.current_page} lastPage={activities.last_page} total={activities.total} base="/admin/managers" params={activityParams} />
        </div>
      ) : null}
    </div>
  );
}
