import { Skeleton } from "@/components/ui/primitives";

/** اسکلت بارگذاری سگمنت پنل مشتری. */
export default function Loading() {
  return (
    <div>
      <div className="page-head"><div><h1>در حال بارگذاری…</h1><p>لطفاً صبر کنید</p></div></div>
      <Skeleton lines={5} />
    </div>
  );
}
