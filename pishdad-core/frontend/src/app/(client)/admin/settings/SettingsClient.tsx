"use client";
import { useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { useLang } from "@/lib/i18n";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { Modal } from "@/components/ui/Overlays";
import { LanguageSwitcher } from "@/components/admin/LanguageSwitcher";
import { PluginSettingsSection } from "@/components/admin/PluginSettingsSection";
import { MailSettingsSection } from "./MailSettingsSection";
import { RedirectsSection } from "./RedirectsSection";
import { ExportImportSection } from "./ExportImportSection";
import { BackupSection } from "./BackupSection";
import { SecuritySettingsSection } from "./SecuritySettingsSection";
import { PerformanceSettingsSection } from "./PerformanceSettingsSection";
import { asPaginator, type PageItem, type SiteSettings } from "@/lib/domain";
import { localRevalidate } from "@/lib/local-revalidate";

/** فرم کارتی تنظیمات سایت + MediaPicker برای لوگو/فاوآیکون. */
export function SettingsClient({ initial }: { initial: SiteSettings }) {
  const toast = useToast();
  const { t } = useLang();
  const [title, setTitle] = useState(initial.title);
  const [description, setDescription] = useState(initial.description ?? "");
  const [phone, setPhone] = useState(initial.phone ?? "");
  const [email, setEmail] = useState(initial.email ?? "");
  const [siteUrl, setSiteUrl] = useState(initial.site_url ?? "");
  const [aiSummary, setAiSummary] = useState(initial.ai_summary ?? "");
  const [robotsIndex, setRobotsIndex] = useState(initial.robots_index ?? true);
  const [robotsTxt, setRobotsTxt] = useState(initial.robots_txt ?? "");
  const [gscCode, setGscCode] = useState(initial.google_site_verification ?? "");
  const [address, setAddress] = useState(initial.address ?? "");
  const [privacyPolicy, setPrivacyPolicy] = useState(initial.privacy_policy ?? "");
  const [accessibilityStatement, setAccessibilityStatement] = useState(initial.accessibility_statement ?? "");
  const [logo, setLogo] = useState<number | null>(initial.logo_media_id ?? null);
  const [favicon, setFavicon] = useState<number | null>(initial.favicon_media_id ?? null);
  const [ogImage, setOgImage] = useState<number | null>(initial.og_image_media_id ?? null);
  // دسته UIUX (افزودنی): زبان پیش‌فرض / منطقه زمانی + صفحه خانه.
  const [locale, setLocale] = useState(initial.locale ?? "fa");
  // ECO2 — تک‌زبانه/دوزبانه (مدل a: زبان دوم زیر /en).
  const [languageMode, setLanguageMode] = useState<"single" | "dual">(initial.language_mode === "dual" ? "dual" : "single");
  const [secondaryLocale, setSecondaryLocale] = useState(initial.secondary_locale ?? (initial.locale === "en" ? "fa" : "en"));
  const [timezone, setTimezone] = useState(initial.timezone ?? "Asia/Tehran");
  const [homepageId, setHomepageId] = useState<number | null>(initial.homepage_page_id ?? null);
  const [pubPages, setPubPages] = useState<PageItem[]>([]);
  useEffect(() => {
    authed<unknown>(`/v1/admin/pages?status=published&per_page=100`)
      .then((j) => setPubPages(asPaginator<PageItem>(j).data))
      .catch(() => undefined);
  }, []);
  const [picker, setPicker] = useState<null | "logo" | "favicon" | "og">(null);
  const [busy, setBusy] = useState(false);
  // «کش سایت»: پیش‌فرض روشن.
  const [cacheEnabled, setCacheEnabled] = useState(initial.cache_enabled !== false);
  const [cacheInfo, setCacheInfo] = useState(false);
  const [cachePurgeBusy, setCachePurgeBusy] = useState(false);

  const save = async () => {
    if (title.trim().length < 2) { toast(t("settings.errTitleShort"), "err"); return; }
    if (siteUrl.trim() && !/^https?:\/\/.+\..+/.test(siteUrl.trim())) { toast(t("settings.errUrl"), "err"); return; }
    setBusy(true);
    try {
      await authed(`/v1/admin/settings/site`, {
        method: "PUT",
        body: {
          title: title.trim(),
          description: description.trim() || null,
          phone: phone.trim() || null,
          email: email.trim() || null,
          site_url: siteUrl.trim() || null,
          ai_summary: aiSummary.trim() || null,
          robots_index: robotsIndex,
          robots_txt: robotsTxt.trim() || null,
          google_site_verification: gscCode.trim() || null,
          address: address.trim() || null,
          privacy_policy: privacyPolicy.trim() || null,
          accessibility_statement: accessibilityStatement.trim() || null,
          logo_media_id: logo,
          favicon_media_id: favicon,
          og_image_media_id: ogImage,
          locale,
          language_mode: languageMode,
          secondary_locale: languageMode === "dual" ? secondaryLocale : null,
          timezone,
          homepage_page_id: homepageId,
          cache_enabled: cacheEnabled,
        },
      });
      await localRevalidate(["site-chrome", "pages"]);
      toast(t("settings.saved"), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : t("settings.errSave"), "err");
    } finally {
      setBusy(false);
    }
  };

  const purgeCache = async () => {
    setCachePurgeBusy(true);
    try {
      const res = await authed<{ tags?: string[] }>("/v1/admin/cache/purge", { method: "POST" });
      const tags = Array.isArray(res?.tags) && res.tags.length > 0 ? res.tags : ["pages", "site-chrome"];
      await localRevalidate(tags);
      toast(t("settings.cachePurged"), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : t("settings.cachePurgeErr"), "err");
    } finally {
      setCachePurgeBusy(false);
    }
  };

  return (
    <div>
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.identity")}</div>
        <div className="field"><label>{t("settings.fieldTitle")}</label><input className="input" value={title} onChange={(e) => setTitle(e.target.value)} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.fieldTitleHint")}</small></div>
        <div className="field"><label>{t("settings.fieldDescription")}</label><textarea className="input" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.fieldDescriptionHint")}</small></div>
        <div className="field"><label>{t("settings.fieldUrl")}</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={siteUrl} onChange={(e) => setSiteUrl(e.target.value)} placeholder="https://example.ir" />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.fieldUrlHint")}</small></div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.geoTitle")}</div>
        <div className="field"><label>{t("settings.fieldAiSummary")}</label>
          <textarea className="input" rows={2} value={aiSummary} onChange={(e) => setAiSummary(e.target.value)} maxLength={1000} placeholder={t("settings.aiSummaryPlaceholder")} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.fieldAiSummaryHint")}</small></div>
        <div className="field"><label style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input type="checkbox" checked={robotsIndex} onChange={(e) => setRobotsIndex(e.target.checked)} />
          {t("settings.allowIndex")}</label>
          <small style={{ color: "var(--text-muted)" }}>{t("settings.allowIndexHint")}</small></div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.technicalSeoTitle")}</div>
        <div className="field">
          <label>{t("settings.robotsTxtLabel")}</label>
          <textarea className="input" rows={7} dir="ltr" style={{ textAlign: "left", fontFamily: "monospace", fontSize: 12.5 }} value={robotsTxt} onChange={(e) => setRobotsTxt(e.target.value)} maxLength={5000} placeholder={t("settings.robotsTxtPlaceholder")} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.robotsTxtHint")}</small>
        </div>
        <div className="field">
          <label>{t("settings.gscLabel")}</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={gscCode} onChange={(e) => setGscCode(e.target.value)} maxLength={200} placeholder={t("settings.gscPlaceholder")} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.gscHint")}</small>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.privacyTitle")}</div>
        <div className="field">
          <label>{t("settings.privacyLabel")}</label>
          <textarea className="input" rows={8} value={privacyPolicy} onChange={(e) => setPrivacyPolicy(e.target.value)} maxLength={20000} placeholder={t("settings.privacyPlaceholder")} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.privacyHint")}</small>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.accessibilityTitle")}</div>
        <div className="field">
          <label>{t("settings.accessibilityLabel")}</label>
          <textarea className="input" rows={8} value={accessibilityStatement} onChange={(e) => setAccessibilityStatement(e.target.value)} maxLength={20000} placeholder={t("settings.accessibilityPlaceholder")} />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.accessibilityHint")}</small>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.brandTitle")}</div>
        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>{t("settings.brandHint")}</p>
        <div className="grid c2">
          <div className="field"><label>{t("settings.fieldLogo")}</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("logo")}>{logo ? t("settings.fileChosen", { n: logo }) : t("settings.pickFile")}</button>
              {logo ? <button className="btn btn-ghost btn-sm" onClick={() => setLogo(null)}>{t("common.remove")}</button> : null}
            </div>
          </div>
          <div className="field"><label>{t("settings.fieldFavicon")}</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("favicon")}>{favicon ? t("settings.fileChosen", { n: favicon }) : t("settings.pickFile")}</button>
              {favicon ? <button className="btn btn-ghost btn-sm" onClick={() => setFavicon(null)}>{t("common.remove")}</button> : null}
            </div>
          </div>
          <div className="field"><label>{t("settings.fieldOg")}</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("og")}>{ogImage ? t("settings.fileChosen", { n: ogImage }) : t("settings.pickFile")}</button>
              {ogImage ? <button className="btn btn-ghost btn-sm" onClick={() => setOgImage(null)}>{t("common.remove")}</button> : null}
            </div>
          </div>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.uiLangTitle")}</div>
        {/*
          * عمداً **بیرون** از فرمِ `save()` بالا: زبانِ رابط، ترجیحِ مرورگرِ
          * همین مدیر است (از راه `ui-settings` ذخیره می‌شود) نه تنظیمِ سایت. اگر
          * داخل همان فرم بود، تا زدنِ دکمهٔ «ذخیره تنظیمات» زبان را عوض می‌کرد —
          * یعنی کاربری که فقط عنوان سایت را عوض کرده بود، ناگهان پنلش انگلیسی
          * می‌شد. ذخیره‌سازیِ آن خودش و بی‌درنگ است.
          */}
        <div className="field">
          <label>{t("settings.language")}</label>
          <LanguageSwitcher />
          <small style={{ color: "var(--text-muted)" }}>{t("settings.languageHint")}</small>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.siteLangTitle")}</div>
        <div className="grid c2">
          <div className="field"><label>{t("settings.siteLangLabel")}</label>
            <select className="select" value={locale} onChange={(e) => setLocale(e.target.value)}>
              <option value="fa">{t("settings.siteLangFa")}</option>
              <option value="en">{t("settings.siteLangEn")}</option>
            </select>
          </div>
          <div className="field"><label>{t("settings.timezoneLabel")}</label>
            <select className="select" dir="ltr" style={{ textAlign: "left" }} value={timezone} onChange={(e) => setTimezone(e.target.value)}>
              <option value="Asia/Tehran">Asia/Tehran</option>
              <option value="Asia/Dubai">Asia/Dubai</option>
              <option value="Europe/London">Europe/London</option>
              <option value="UTC">UTC</option>
            </select>
          </div>
        </div>
        <div className="grid c2">
          <div className="field"><label>{t("settings.languageModeLabel")}</label>
            <select className="select" value={languageMode} onChange={(e) => setLanguageMode(e.target.value === "dual" ? "dual" : "single")}>
              <option value="single">{t("settings.languageModeSingle")}</option>
              <option value="dual">{t("settings.languageModeDual")}</option>
            </select>
            <small style={{ color: "var(--text-muted)" }}>{t("settings.languageModeHint")}</small>
          </div>
          {languageMode === "dual" ? (
            <div className="field"><label>{t("settings.secondaryLocaleLabel")}</label>
              <select className="select" value={secondaryLocale} onChange={(e) => setSecondaryLocale(e.target.value)}>
                {locale !== "fa" ? <option value="fa">{t("settings.siteLangFa")}</option> : null}
                {locale !== "en" ? <option value="en">{t("settings.siteLangEn")}</option> : null}
              </select>
            </div>
          ) : null}
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.homeTitle")}</div>
        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>{t("settings.homeHint")}</p>
        <div className="field"><label>{t("settings.homeLabel")}</label>
          <select className="select" value={homepageId ?? ""} onChange={(e) => setHomepageId(e.target.value ? Number(e.target.value) : null)}>
            <option value="">{t("settings.homeAuto")}</option>
            {pubPages.map((p) => <option key={p.id} value={p.id}>{p.title} ({p.slug})</option>)}
          </select>
        </div>
        <a className="btn btn-ghost btn-sm" href="/" target="_blank" rel="noreferrer">{t("settings.viewSite")}</a>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">{t("settings.contactTitle")}</div>        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>{t("settings.contactHint")}</p>
        <div className="grid c2">
          <div className="field"><label>{t("settings.fieldPhone")}</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="field"><label>{t("settings.fieldEmail")}</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <div className="field" style={{ gridColumn: "1 / -1" }}><label>{t("settings.fieldAddress")}</label>
            <input className="input" value={address} onChange={(e) => setAddress(e.target.value)} maxLength={500} placeholder={t("settings.addressPlaceholder")} />
          </div>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title" style={{ display: "flex", alignItems: "center", gap: 8 }}>
          {t("settings.cacheTitle")}
          <button
            type="button"
            className="icon-btn"
            aria-label={t("settings.cacheInfoAria")}
            title={t("settings.cacheInfoAria")}
            onClick={() => setCacheInfo(true)}
            style={{ minInlineSize: 30, minBlockSize: 30 }}
          >
            ℹ
          </button>
        </div>
        <label style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 13.5, cursor: "pointer" }}>
          <input type="checkbox" checked={cacheEnabled} onChange={(e) => setCacheEnabled(e.target.checked)} />
          {t("settings.cacheEnableLabel")}
        </label>
        <p className="card-sub" style={{ margin: "8px 0 0" }}>
          {cacheEnabled ? t("settings.cacheOnHint") : t("settings.cacheOffHint")}
        </p>
        <div style={{ display: "flex", alignItems: "center", gap: 10, marginBlockStart: 12, flexWrap: "wrap" }}>
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            onClick={() => void purgeCache()}
            disabled={cachePurgeBusy}
          >
            {cachePurgeBusy ? t("settings.cachePurging") : t("settings.cachePurge")}
          </button>
          <small style={{ color: "var(--text-muted)" }}>{t("settings.cachePurgeHint")}</small>
        </div>
      </div>

      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? t("settings.saving") : t("settings.save")}</button>

      {cacheInfo ? (
        <Modal title={t("settings.cacheTitle")} onClose={() => setCacheInfo(false)}>
          <p style={{ fontSize: 13.5, lineHeight: 2, marginBlockStart: 0 }}>{t("settings.cacheInfoBody")}</p>
          <button className="btn btn-primary" onClick={() => setCacheInfo(false)}>{t("settings.cacheInfoOk")}</button>
        </Modal>
      ) : null}

      <MediaPicker
        open={picker !== null}
        selected={[(picker === "logo" ? logo : picker === "favicon" ? favicon : ogImage) ?? -1].filter((x) => x > 0)}
        onChange={(ids) => {
          if (picker === "logo") setLogo(ids[0] ?? null);
          else if (picker === "favicon") setFavicon(ids[0] ?? null);
          else if (picker === "og") setOgImage(ids[0] ?? null);
        }}
        onClose={() => setPicker(null)}
      />

      {/* K6.7 — تنظیمات افزونه‌ها.
          *
          * عمداً **جدا** از فرم بالاست و state خودش را دارد. تنظیمات سایت با
          * دکمهٔ «ذخیره تنظیمات» بالا ذخیره می‌شود و اگر در همان `useState` بود،
          * هر تغییر افزونه فرم سایت را dirty می‌کرد و یک دکمه، دو منبع ذخیره
          * را کنترل می‌کرد. */}
      <div style={{ marginBlockStart: 28 }}>
        <SecuritySettingsSection />
        <MailSettingsSection />
        <RedirectsSection />
        <ExportImportSection />
        <BackupSection />
        <PerformanceSettingsSection />
        <PluginSettingsSection />
      </div>
    </div>
  );
}
