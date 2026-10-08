"use client";
import { useMemo, useRef } from "react";
import dynamic from "next/dynamic";
import "jodit/es2021/jodit.min.css";

const JoditEditor = dynamic(() => import("jodit-react"), { ssr: false });

/**
 * ویرایشگر ریچ‌تکست مشترک (jodit-react + Jodit 4).
 * استفاده مجدد: هر رشته HTML (body بلوک متن، توضیحات بلند، …).
 *
 *   <RichText value={html} onChange={setHtml} label="متن" />
 *
 * - راست‌به‌چپ + فارسی (`language: "fa"`, `direction: "rtl"`).
 * - sanitize دو‌لایه: allowlist تگ‌ها در ادیتور + allowlist سرور/رندرر
 *   (BlockRenderer) هنگام نمایش — هرگز HTML خام رندر نکنید.
 * - کامیت در blur (نه هر کلید) تا والد ری‌رندر بیهوده نخورد.
 */
export function RichText({
  value, onChange, label, placeholder,
}: {
  value: string;
  onChange: (html: string) => void;
  label?: string;
  placeholder?: string;
}) {
  const ref = useRef(null);

  const config = useMemo(
    () => ({
      readonly: false,
      language: "fa",
      direction: "rtl" as const,
      placeholder: placeholder ?? "",
      height: 280,
      minHeight: 200,
      toolbarAdaptive: false,
      // نوار ابزار جمع‌وجور فارسی — بدون سورس/اسکریپت/آی‌فریم.
      buttons: [
        "bold", "italic", "underline", "|",
        "ul", "ol", "|",
        "font", "fontsize", "brush", "|",
        "align", "|",
        "link", "image", "|",
        "hr", "blockquote", "table", "|",
        "undo", "redo", "|",
        "eraser", "fullsize",
      ],
      // allowlist تگ‌ها (لایه اول sanitize — لایه دوم در BlockRenderer).
      allowTags: "p,br,b,strong,i,em,u,s,a,ul,ol,li,h2,h3,h4,blockquote,pre,hr,img,figure,figcaption,table,tbody,tr,td,th,span,div",
      denyTags: "script,style,iframe,object,embed,form,input,button,link,meta",
      link: { openInNewTabCheckbox: false },
      image: { openOnDblClick: true },
      uploader: { insertImageAsBase64URI: false },
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      askBeforePasteHTML: false,
      askBeforePasteFromWord: false,
    }),
    [placeholder],
  );

  return (
    <div className="field" dir="rtl">
      {label ? <label>{label}</label> : null}
      <div className="jodit-rtl">
        <JoditEditor
          ref={ref}
          value={value}
          config={config}
          onBlur={(html: string) => {
            if (typeof html === "string" && html !== value) onChange(html);
          }}
        />
      </div>
    </div>
  );
}
