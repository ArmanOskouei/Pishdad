import Link from "next/link";
import { faNum } from "@/lib/fa";

/** صفحه‌بندی سرورساید لینک‌محور (بدون JS — مناسب Server Components). */
export function Pagination({
  page, lastPage, total, base, params,
}: {
  page: number; lastPage: number; total: number;
  base: string; params: Record<string, string>;
}) {
  const href = (p: number) => {
    const q = new URLSearchParams({ ...params, page: String(p) });
    return `${base}?${q.toString()}`;
  };
  if (lastPage <= 1) {
    return <div className="pager"><span className="pg-info">{faNum(total)} مورد</span></div>;
  }
  return (
    <div className="pager">
      {page > 1
        ? <Link className="btn btn-ghost btn-sm" href={href(page - 1)}>قبلی</Link>
        : <button className="btn btn-ghost btn-sm" disabled>قبلی</button>}
      <span className="pg-info">صفحه {faNum(page)} از {faNum(lastPage)} — {faNum(total)} مورد</span>
      {page < lastPage
        ? <Link className="btn btn-ghost btn-sm" href={href(page + 1)}>بعدی</Link>
        : <button className="btn btn-ghost btn-sm" disabled>بعدی</button>}
    </div>
  );
}
