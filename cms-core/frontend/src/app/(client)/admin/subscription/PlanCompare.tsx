"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Alert, Badge } from "@/components/ui/primitives";
import { faNum } from "@/lib/fa";
import { TIER_FA, type Invoice, type Plan } from "@/lib/domain";

/**
 * مقایسه پلن‌ها و ارتقا (شکاف ۱ subscription از گزارش برابری):
 * انتخاب پلن بالاتر → فاکتور مابه‌التفاوت → پرداخت با همان Stepper موجود (PayFlow).
 */
export function PlanCompare({ currentPlanId, currentMonthly }: { currentPlanId?: number | null; currentMonthly?: number | null }) {
  const toast = useToast();
  const router = useRouter();
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const list = await authed<Plan[] | { data: Plan[] }>("/v1/admin/subscription/plans");
        setPlans(Array.isArray(list) ? list : (list.data ?? []));
      } catch (e) {
        setError(e instanceof Error ? e.message : "خطا در بارگذاری پلن‌ها.");
      }
    })();
  }, []);

  const choose = async (p: Plan) => {
    setBusyId(p.id);
    try {
      const inv = await authed<Invoice>("/v1/admin/subscription/change-plan", { method: "POST", body: { plan_id: p.id } });
      toast(`فاکتور مابه‌التفاوت «${p.name}» (#${faNum(inv.id)}) ساخته شد — از Stepper پرداخت ادامه دهید.`, "ok");
      router.refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : "ارتقا ناموفق بود.", "err");
    } finally {
      setBusyId(null);
    }
  };

  if (error) return <Alert tone="red">{error}</Alert>;
  if (!plans) return <div className="card card-pad"><p style={{ color: "var(--text-muted)", fontSize: 13 }}>در حال بارگذاری پلن‌ها…</p></div>;
  if (plans.length === 0) return null;

  const current = plans.find((p) => p.id === currentPlanId) ?? null;
  const base = current?.price_monthly ?? currentMonthly ?? 0;

  return (
    <div className="card card-pad" style={{ marginBlockStart: 14 }}>
      <div className="card-title">مقایسه پلن‌ها و ارتقا</div>
      <p className="card-sub">انتخاب پلن بالاتر، فاکتور مابه‌التفاوت می‌سازد و با همان Stepper پرداخت تسویه می‌شود.</p>
      <div className="grid c3" style={{ marginBlockStart: 12 }}>
        {plans.map((p) => {
          const isCurrent = p.id === currentPlanId;
          const higher = (p.price_monthly ?? 0) > base;
          return (
            <div key={p.id} className="card card-pad" style={isCurrent ? { borderColor: "var(--success)" } : undefined}>
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <b>{p.name}</b>
                {isCurrent ? <Badge tone="green">پلن جاری</Badge> : <Badge tone="violet">{TIER_FA[p.tier] ?? p.tier}</Badge>}
              </div>
              <div style={{ fontSize: 19, fontWeight: 800, marginBlock: 6 }}>
                {p.price_monthly != null ? <>{faNum(p.price_monthly)} <small style={{ fontSize: 11.5, fontWeight: 400 }}>تومان/ماه</small></> : "—"}
              </div>
              {p.price_yearly ? <div style={{ fontSize: 12, color: "var(--text-muted)" }}>سالانه: {faNum(p.price_yearly)} تومان</div> : null}
              {(p.features ?? []).length > 0 ? (
                <ul style={{ fontSize: 12.5, color: "var(--text-muted)", paddingInlineStart: 18, marginBlock: 8 }}>
                  {(p.features ?? []).slice(0, 4).map((f) => <li key={f}>{f}</li>)}
                </ul>
              ) : null}
              <div style={{ marginBlockStart: 10 }}>
                {isCurrent ? (
                  <span style={{ fontSize: 12.5, color: "var(--success)" }}>✓ فعال است</span>
                ) : higher ? (
                  <button className="btn btn-soft btn-sm btn-block" disabled={busyId === p.id} onClick={() => void choose(p)}>
                    {busyId === p.id ? "…" : `ارتقا به ${p.name}`}
                  </button>
                ) : (
                  <span style={{ fontSize: 12, color: "var(--text-muted)" }}>پایین‌تر از پلن جاری</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
