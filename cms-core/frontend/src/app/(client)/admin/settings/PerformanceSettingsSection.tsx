"use client";
import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";

type PerformanceData = {
  asset_domain: string | null;
  cdn_purge_token_set: boolean;
  lazy_load_enabled: boolean;
  font_preload_enabled: boolean;
};

/**
 * WF-M13 — تبِ «کارایی»: دامنهٔ دارایی/CDN + توکن پاک‌سازی + lazy-load/preload.
 *
 * همراستا با `docs/CDN-ASSESSMENT.md`: CDN روی HTML توصیه نمی‌شود، پس دامنهٔ
 * دارایی فقط برای **فایل‌های ایستا** است. توکن پاک‌سازی هرگز از سرور نمی‌آید؛
 * پرکردنش یعنی «عوض کن»، خالی‌گذاشتنش یعنی «همان قبلی بماند».
 */
export function PerformanceSettingsSection() {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [assetDomain, setAssetDomain] = useState("");
  const [token, setToken] = useState("");
  const [clearToken, setClearToken] = useState(false);
  const [tokenSet, setTokenSet] = useState(false);
  const [lazyLoad, setLazyLoad] = useState(true);
  const [fontPreload, setFontPreload] = useState(true);

  const apply = useCallback((d: PerformanceData) => {
    setAssetDomain(d.asset_domain ?? "");
    setTokenSet(Boolean(d.cdn_purge_token_set));
    setLazyLoad(d.lazy_load_enabled !== false);
    setFontPreload(d.font_preload_enabled !== false);
  }, []);

  const load = useCallback(async () => {
    try {
      apply(await authed<PerformanceData>("/v1/admin/settings/performance"));
    } catch (e) {
      toast(e instanceof Error ? e.message : "بارگذاری تنظیمات کارایی ناموفق بود.", "err");
    } finally {
      setLoading(false);
    }
  }, [apply, toast]);

  useEffect(() => {
    void load();
  }, [load]);

  const save = async () => {
    const domain = assetDomain.trim();
    if (domain && !/^https?:\/\/.+\..+/.test(domain)) {
      toast("دامنهٔ دارایی معتبر نیست (مثل https://cdn.example.ir).", "err");
      return;
    }
    setBusy(true);
    try {
      const body: Record<string, unknown> = {
        asset_domain: domain || null,
        clear_cdn_purge_token: clearToken,
        lazy_load_enabled: lazyLoad,
        font_preload_enabled: fontPreload,
      };
      if (token) body.cdn_purge_token = token;
      const data = await authed<PerformanceData>("/v1/admin/settings/performance", { method: "PUT", body });
      apply(data);
      setToken("");
      setClearToken(false);
      toast("تنظیمات کارایی ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">کارایی و CDN</div>
        <p style={{ fontSize: 13, color: "var(--text-muted)" }}>در حال بارگذاری…</p>
      </div>
    );
  }

  return (
    <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
      <div className="card-title">کارایی و CDN</div>
      <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>
        بهینه‌سازی تحویل فایل‌های ایستا. طبق ارزیابی، CDN روی HTML توصیه نمی‌شود (انتشار آنی را خراب
        می‌کند)؛ این تنظیمات فقط برای دارایی‌های ایستا کاربرد دارند. فونت همیشه محلی است و هرگز به CDN
        خارجی فرستاده نمی‌شود.
      </p>

      <div className="field">
        <label>دامنهٔ دارایی / CDN</label>
        <input
          className="input"
          dir="ltr"
          style={{ textAlign: "left" }}
          value={assetDomain}
          onChange={(e) => setAssetDomain(e.target.value)}
          placeholder="https://cdn.example.ir"
        />
        <small style={{ color: "var(--text-muted)" }}>
          دارایی‌های محلیِ سایت (لوگو/فاوآیکون/OG) از این دامنه سرو می‌شوند. خالی بگذارید تا همه‌چیز از
          دامنهٔ خود سایت بیاید. برای فایل‌های <code dir="ltr">/_next/static</code> متغیر ساختِ{" "}
          <code dir="ltr">NEXT_PUBLIC_ASSET_PREFIX</code> را تنظیم کنید.
        </small>
      </div>

      <div className="field">
        <label>توکن پاک‌سازی CDN</label>
        <input
          className="input"
          dir="ltr"
          style={{ textAlign: "left" }}
          type="password"
          value={token}
          onChange={(e) => setToken(e.target.value)}
          autoComplete="new-password"
          placeholder={tokenSet ? "ذخیره شده — برای تغییر وارد کنید" : "—"}
        />
        <small style={{ color: "var(--text-muted)" }}>
          رمزنگاری‌شده ذخیره می‌شود و هرگز نمایش داده نمی‌شود؛ فقط برای پاک‌سازی کش CDN در آینده به کار
          می‌رود.
        </small>
      </div>

      {tokenSet ? (
        <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, marginBlockEnd: 10 }}>
          <input type="checkbox" checked={clearToken} onChange={(e) => setClearToken(e.target.checked)} />
          پاک‌کردن توکن ذخیره‌شده
        </label>
      ) : null}

      <label style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 13.5, cursor: "pointer" }}>
        <input type="checkbox" checked={lazyLoad} onChange={(e) => setLazyLoad(e.target.checked)} />
        بارگذاری تنبل تصاویر سایت (lazy-load)
      </label>
      <small style={{ color: "var(--text-muted)", display: "block", margin: "4px 0 10px" }}>
        خاموش‌کردن یعنی همهٔ تصاویر صفحه بی‌درنگ بارگذاری شوند — فقط برای صفحهٔ سبک مناسب است.
      </small>

      <label style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 13.5, cursor: "pointer" }}>
        <input type="checkbox" checked={fontPreload} onChange={(e) => setFontPreload(e.target.checked)} />
        preload فونت وزیرمتن
      </label>
      <small style={{ color: "var(--text-muted)", display: "block", margin: "4px 0 10px" }}>
        با <code dir="ltr">font-display: swap</code>، خاموش‌کردن preload فقط اولین رنگ‌آمیزی متن را کمی
        دیرتر می‌کند؛ فونت از حذف نمی‌شود.
      </small>

      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>
        {busy ? "در حال ذخیره…" : "ذخیرهٔ تنظیمات کارایی"}
      </button>
    </div>
  );
}
