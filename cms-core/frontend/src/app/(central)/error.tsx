"use client";

import { Alert } from "@/components/ui/primitives";

/** خطای سگمنت پنل مرکزی — فارسی + تلاش مجدد. */
export default function Error({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div>
      <div className="page-head"><div><h1>خطایی رخ داد</h1><p>پنل مرکزی</p></div></div>
      <Alert tone="red">
        {error.message || "خطایی رخ داد."}{" "}
        <button className="btn btn-ghost btn-sm" onClick={reset}>تلاش مجدد</button>
      </Alert>
    </div>
  );
}
