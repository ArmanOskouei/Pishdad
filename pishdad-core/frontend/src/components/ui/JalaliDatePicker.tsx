"use client";
import { useMemo, useState } from "react";
import jalaali from "jalaali-js";
import { faNum } from "@/lib/fa";

/**
 * انتخاب تاریخ شمسی ساده و کاربردی: سه سلکت سال/ماه/روز → خروجی ISO میلادی.
 * خروجی null یعنی «بدون تاریخ».
 */
export function JalaliDatePicker({
  value, onChange, label = "تاریخ",
}: {
  value: string | null;
  onChange: (iso: string | null) => void;
  label?: string;
}) {
  const nowJ = jalaali.toJalaali(new Date());
  const init = useMemo(() => {
    if (!value) return { y: nowJ.jy, m: nowJ.jm, d: nowJ.jd, empty: true };
    const g = new Date(value);
    if (Number.isNaN(g.getTime())) return { y: nowJ.jy, m: nowJ.jm, d: nowJ.jd, empty: true };
    const j = jalaali.toJalaali(g);
    return { y: j.jy, m: j.jm, d: j.jd, empty: false };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);
  const [y, setY] = useState(init.y);
  const [m, setM] = useState(init.m);
  const [d, setD] = useState(init.d);

  const years: number[] = [];
  for (let yy = nowJ.jy - 5; yy <= nowJ.jy + 5; yy++) years.push(yy);
  const months = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];
  const dim = jalaali.jalaaliMonthLength(y, m);
  const days = Array.from({ length: dim }, (_, i) => i + 1);

  const emit = (yy: number, mm: number, dd: number) => {
    const g = jalaali.toGregorian(yy, mm, Math.min(dd, jalaali.jalaaliMonthLength(yy, mm)));
    const pad = (x: number) => String(x).padStart(2, "0");
    onChange(`${g.gy}-${pad(g.gm)}-${pad(g.gd)}`);
  };

  return (
    <div className="field" style={{ marginBlockEnd: 0 }}>
      <label>{label}</label>
      <div style={{ display: "flex", gap: 8 }}>
        <select className="select" aria-label="روز" value={Math.min(d, dim)} onChange={(e) => { const v = Number(e.target.value); setD(v); emit(y, m, v); }} style={{ flex: 1 }}>
          {days.map((dd) => <option key={dd} value={dd}>{faNum(dd)}</option>)}
        </select>
        <select className="select" aria-label="ماه" value={m} onChange={(e) => { const v = Number(e.target.value); setM(v); emit(y, v, d); }} style={{ flex: 1.4 }}>
          {months.map((mm, i) => <option key={mm} value={i + 1}>{mm}</option>)}
        </select>
        <select className="select" aria-label="سال" value={y} onChange={(e) => { const v = Number(e.target.value); setY(v); emit(v, m, d); }} style={{ flex: 1 }}>
          {years.map((yy) => <option key={yy} value={yy}>{faNum(yy)}</option>)}
        </select>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => onChange(null)} title="پاک کردن تاریخ">✕</button>
      </div>
    </div>
  );
}
