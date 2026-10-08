"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

type SecurityData = {
  allowed_admin_ips: string[];
  login_attempt_cap: number;
  current_ip?: string;
  current_ip_added?: boolean;
};

/**
 * WF-M7 — تبِ «امنیت»: محدودکردن پنل به IPهای مجاز + سقف تلاش ورود.
 *
 * فهرست خالی یعنی «همهٔ نشانی‌ها مجازند». اگر فهرست پُر باشد و نشانی فعلیِ
 * مدیر در آن نباشد، سرور همان IP را خودکار اضافه می‌کند و پیام توضیح می‌دهد تا
 * مدیر با ذخیره‌کردن از پنل بیرون نیفتد.
 */
export function SecuritySettingsSection() {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [ipsText, setIpsText] = useState("");
  const [cap, setCap] = useState("20");
  const [currentIp, setCurrentIp] = useState<string | null>(null);

  const apply = useCallback((d: SecurityData) => {
    setIpsText((d.allowed_admin_ips ?? []).join("\n"));
    setCap(String(d.login_attempt_cap ?? 20));
    setCurrentIp(d.current_ip ?? null);
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await authed<SecurityData>("/v1/admin/settings/security"));
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری تنظیمات امنیتی ناموفق بود.", "err");
    } finally {
      setLoading(false);
    }
  }, [apply, toast]);

  useEffect(() => {
    void load();
  }, [load]);

  const save = async () => {
    const ips = ipsText
      .split(/[\n,]/)
      .map((s) => s.trim())
      .filter(Boolean);
    const capNum = Number(cap);
    if (!Number.isInteger(capNum) || capNum < 3 || capNum > 100) {
      toast("سقف تلاش ورود باید عددی بین ۳ تا ۱۰۰ باشد.", "err");
      return;
    }
    setBusy(true);
    try {
      const res = await authed<SecurityData>("/v1/admin/settings/security", {
        method: "PUT",
        body: { allowed_admin_ips: ips, login_attempt_cap: capNum },
      });
      apply(res);
      toast(
        res.current_ip_added
          ? "تنظیمات امنیتی ذخیره شد. نشانی فعلی شما برای جلوگیری از قفل‌شدن به فهرست اضافه شد."
          : "تنظیمات امنیتی ذخیره شد.",
        "ok",
      );
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">امنیت پنل</div>
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      </div>
    );
  }

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">امنیت پنل</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        دسترسی به پیشخوان را به نشانی‌های شبکهٔ مشخص محدود کنید و سقف تلاش ورود را تعیین کنید.
      </p>

      <div className="field">
        <label>نشانی‌های IP مجاز پنل</label>
        <textarea
          className="input"
          rows={4}
          dir="ltr"
          style={{ textAlign: "left", fontFamily: "monospace", fontSize: 12.5 }}
          value={ipsText}
          onChange={(e) => setIpsText(e.target.value)}
          placeholder={"203.0.113.4\n203.0.113.0/24\n2001:db8::/32"}
        />
        <small style={{ color: "var(--text-muted)" }}>
          هر نشانی در یک خط. خالی بگذارید تا همهٔ نشانی‌ها مجاز باشند. IPv4/IPv6 و محدودهٔ CIDR هم
          پذیرفته می‌شود.
        </small>
        {currentIp ? (
          <small style={{ color: "var(--text-muted)", marginBlockStart: 4 }}>
            نشانی فعلی شما: <code dir="ltr">{currentIp}</code>. اگر فهرست را پر کنید و این نشانی در آن
            نباشد، خودکار افزوده می‌شود تا از پنل بیرون نیفتید.
          </small>
        ) : null}
      </div>

      <div className="field" style={{ maxInlineSize: 320 }}>
        <label>سقف تلاش ورود (به‌ازای هر IP)</label>
        <input
          className="input"
          dir="ltr"
          type="number"
          min={3}
          max={100}
          style={{ textAlign: "left" }}
          value={cap}
          onChange={(e) => setCap(e.target.value)}
        />
        <small style={{ color: "var(--text-muted)" }}>
          بیشترین تلاش ورود از یک نشانی در ۱۵ دقیقه (علاوه بر سقفِ هر حساب). بین ۳ تا ۱۰۰.
        </small>
      </div>

      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>
        {busy ? "در حال ذخیره…" : "ذخیرهٔ تنظیمات امنیتی"}
      </button>
    </div>
  );
}
