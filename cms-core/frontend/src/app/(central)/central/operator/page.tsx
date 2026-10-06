import { apiQs, serverApi } from "@/lib/server-api";
import { asPaginator, type OperatorActivityItem } from "@/lib/domain";
import { Alert } from "@/components/ui/primitives";
import { Pagination } from "@/components/ui/Pagination";
import { ActivityCsvButton, OperatorClient } from "./OperatorClient";

/** نامِ فیلترها — یک‌جا تا فرم، صفحه‌بندی و لینکِ CSV هرگز از هم جدا نیفتند. */
const FILTERS = ["search", "action", "user_id", "from", "to"] as const;

type ActivityFilterMeta = { default_days: number; actions: string[] };

/**
 * پروفایل اپراتور ۲.۷ — کلید 2FA + توکن نصب + Kill-Switch + لاگ فعالیت.
 *
 * WF-L4: نوارِ فیلتر اینجا (Server Component و فرمِ GET بدون JS) است تا «فیلتر»
 * یعنی یک URL قابل کپی و بازگشت با دکمهٔ Back — نه stateِ گم‌شده در کلاینت.
 */
export default async function OperatorPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const sp = await searchParams;
  const page = Math.max(1, Number(sp.page ?? 1) || 1);

  let activity = asPaginator<OperatorActivityItem>(null);
  let meta: ActivityFilterMeta = { default_days: 30, actions: [] };
  let error: string | null = null;
  try {
    const json = await serverApi<{ data?: unknown; filters?: Partial<ActivityFilterMeta> }>(
      `/v1/p/central/operator/activity${apiQs(sp, [...FILTERS], { per_page: 25, page })}`,
    );
    activity = asPaginator<OperatorActivityItem>(json);
    meta = {
      default_days: json.filters?.default_days ?? 30,
      actions: Array.isArray(json.filters?.actions) ? json.filters.actions : [],
    };
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری لاگ اپراتور.";
  }

  const applied: Record<string, string> = {};
  const exportQuery = new URLSearchParams();
  for (const key of FILTERS) {
    const value = sp[key];
    if (!value) continue;
    applied[key] = value;
    exportQuery.set(key, value);
  }
  applied.page = String(page);

  return (
    <div>
      <div className="page-head">
        <div><h1>اپراتور</h1><p>توکن نصب و توقف اضطراری نیازمند 2FA تازه (کمتر از ۵ دقیقه) هستند</p></div>
      </div>

      <form className="toolbar" method="get" action="/central/operator">
        <div className="field grow">
          <label htmlFor="oa-q">جستجو</label>
          <input id="oa-q" name="search" className="input" placeholder="اکشن، IP، متادیتا یا نام/ایمیل اپراتور…" defaultValue={sp.search ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="oa-a">اکشن</label>
          <select id="oa-a" name="action" className="select" defaultValue={sp.action ?? ""}>
            <option value="">همه</option>
            {meta.actions.map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="oa-u">شناسهٔ اپراتور</label>
          <input id="oa-u" name="user_id" className="input" inputMode="numeric" placeholder="مثلاً 12" defaultValue={sp.user_id ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="oa-f">از تاریخ</label>
          <input id="oa-f" name="from" type="date" className="input" dir="ltr" defaultValue={sp.from ?? ""} />
        </div>
        <div className="field">
          <label htmlFor="oa-t">تا تاریخ</label>
          <input id="oa-t" name="to" type="date" className="input" dir="ltr" defaultValue={sp.to ?? ""} />
        </div>
        <button className="btn btn-ghost" type="submit">اعمال</button>
        <ActivityCsvButton query={exportQuery.toString()} />
      </form>

      <p style={{ fontSize: 12.5, color: "var(--text-muted)", marginBlockEnd: 12 }}>
        بدون بازهٔ تاریخی، فقط {meta.default_days} روزِ اخیر نشان داده می‌شود. نگهداشت لاگ ۹۰ روز است (فرمان <code dir="ltr">operator:prune</code>) — پس «همه» یعنی همین پنجره، نه تاریخچهٔ نامحدود.
      </p>

      {error ? <Alert tone="red">{error}</Alert> : <OperatorClient initialActivity={activity} />}

      {!error ? (
        <Pagination
          page={activity.current_page}
          lastPage={activity.last_page}
          total={activity.total}
          base="/central/operator"
          params={applied}
        />
      ) : null}
    </div>
  );
}
