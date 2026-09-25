"use client";
import { useState } from "react";

/** دکمه کپی در کلیپ‌بورد با فیدبک فارسی (۱.۶ نشانی فایل، ۲.۲ توکن پشتیبانی). */
export function CopyToClipboard({ text, label = "کپی" }: { text: string; label?: string }) {
  const [ok, setOk] = useState(false);
  return (
    <button
      type="button"
      className="btn btn-ghost btn-sm"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(text);
        } catch {
          const ta = document.createElement("textarea");
          ta.value = text;
          document.body.appendChild(ta);
          ta.select();
          document.execCommand("copy");
          ta.remove();
        }
        setOk(true);
        setTimeout(() => setOk(false), 1800);
      }}
    >
      {ok ? "✓ کپی شد" : label}
    </button>
  );
}
