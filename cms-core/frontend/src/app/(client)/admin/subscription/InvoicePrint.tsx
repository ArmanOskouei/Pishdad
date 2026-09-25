"use client";
import { useState } from "react";
import { Modal } from "@/components/ui/Overlays";
import { faNum, jalali } from "@/lib/fa";
import type { Invoice } from "@/lib/domain";

/** رسید چاپی فاکتور (دمو subscription: دکمه «چاپ» per-row + «مشاهده رسید» پس از پرداخت). */
export function InvoicePrint({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  return (
    <Modal title={`فاکتور رسمی — #${faNum(invoice.id)}`} onClose={onClose}>
      <div className="print-invoice">
        <div style={{ textAlign: "center", marginBlockEnd: 14 }}>
          <div style={{ fontSize: 12.5, color: "var(--text-muted)" }}>خدمات میزبانی CMS</div>
        </div>
        <div className="kv"><span>مبلغ</span><span>{faNum(invoice.amount)} {invoice.currency ?? "تومان"}</span></div>
        <div className="kv"><span>وضعیت</span><span>{invoice.status === "paid" ? "پرداخت‌شده" : "در انتظار پرداخت"}</span></div>
        <div className="kv"><span>سررسید</span><span>{jalali(invoice.due_at)}</span></div>
        {invoice.paid_at ? <div className="kv"><span>تاریخ پرداخت</span><span>{jalali(invoice.paid_at)}</span></div> : null}
        {invoice.gateway_ref ? <div className="kv"><span>شناسه ارجاع</span><span dir="ltr">{invoice.gateway_ref}</span></div> : null}
      </div>
      <div style={{ display: "flex", gap: 8, marginBlockStart: 14 }} className="no-print">
        <button className="btn btn-primary btn-sm" onClick={() => window.print()}>چاپ</button>
        <button className="btn btn-ghost btn-sm" onClick={onClose}>بستن</button>
      </div>
    </Modal>
  );
}

/** دکمه چاپ per-row برای فاکتور پرداخت‌شده (بدون دست‌کاری جدول سرور). */
export function PrintInvoiceButton({ invoice }: { invoice: Invoice }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button className="btn btn-ghost btn-sm" onClick={() => setOpen(true)}>چاپ</button>
      {open ? <InvoicePrint invoice={invoice} onClose={() => setOpen(false)} /> : null}
    </>
  );
}
