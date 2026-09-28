"use client";
import { useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { MediaPicker } from "@/components/ui/MediaPicker";
import { PluginSettingsSection } from "@/components/admin/PluginSettingsSection";
import { asPaginator, type PageItem, type SiteSettings } from "@/lib/domain";

/** فرم کارتی تنظیمات سایت + MediaPicker برای لوگو/فاوآیکون. */
export function SettingsClient({ initial }: { initial: SiteSettings }) {
  const toast = useToast();
  const [title, setTitle] = useState(initial.title);
  const [description, setDescription] = useState(initial.description ?? "");
  const [phone, setPhone] = useState(initial.phone ?? "");
  const [email, setEmail] = useState(initial.email ?? "");
  const [siteUrl, setSiteUrl] = useState(initial.site_url ?? "");
  const [aiSummary, setAiSummary] = useState(initial.ai_summary ?? "");
  const [robotsIndex, setRobotsIndex] = useState(initial.robots_index ?? true);
  const [address, setAddress] = useState(initial.address ?? "");
  const [logo, setLogo] = useState<number | null>(initial.logo_media_id ?? null);
  const [favicon, setFavicon] = useState<number | null>(initial.favicon_media_id ?? null);
  const [ogImage, setOgImage] = useState<number | null>(initial.og_image_media_id ?? null);
  // دسته UIUX (افزودنی): زبان پیش‌فرض / منطقه زمانی + صفحه خانه.
  const [locale, setLocale] = useState(initial.locale ?? "fa");
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

  const save = async () => {
    if (title.trim().length < 2) { toast("عنوان سایت خیلی کوتاه است.", "err"); return; }
    if (siteUrl.trim() && !/^https?:\/\/.+\..+/.test(siteUrl.trim())) { toast("نشانی سایت معتبر نیست (مثل https://example.ir).", "err"); return; }
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
          address: address.trim() || null,
          logo_media_id: logo,
          favicon_media_id: favicon,
          og_image_media_id: ogImage,
          locale,
          timezone,
          homepage_page_id: homepageId,
        },
      });
      toast("تنظیمات سایت ذخیره شد.", "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : "ذخیره ناموفق بود.", "err");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">هویت سایت</div>
        <div className="field"><label>عنوان سایت *</label><input className="input" value={title} onChange={(e) => setTitle(e.target.value)} />
          <small style={{ color: "var(--text-muted)" }}>در تب مرورگر و عنوان نتایج گوگل دیده می‌شود.</small></div>
        <div className="field"><label>توضیحات (سئو)</label><textarea className="input" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
          <small style={{ color: "var(--text-muted)" }}>زیر عنوان در نتایج گوگل + توضیح پیش‌فرض اشتراک‌گذاری. ۱۵۰–۱۶۰ نویسه بنویسید.</small></div>
        <div className="field"><label>نشانی پایه سایت (مثل https://example.ir)</label>
          <input className="input" dir="ltr" style={{ textAlign: "left" }} value={siteUrl} onChange={(e) => setSiteUrl(e.target.value)} placeholder="https://example.ir" />
          <small style={{ color: "var(--text-muted)" }}>آدرس canonical، لینک‌های sitemap/llms.txt و OG:url از همین ساخته می‌شود. خالی = حدس از هاست.</small></div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">موتورهای هوش مصنوعی (GEO)</div>
        <div className="field"><label>خلاصه سایت برای AI (۱–۲ جمله)</label>
          <textarea className="input" rows={2} value={aiSummary} onChange={(e) => setAiSummary(e.target.value)} maxLength={1000} placeholder="این سایت درباره… خدمات… مخاطب…" />
          <small style={{ color: "var(--text-muted)" }}>در بالای فایل llms.txt می‌آید؛ ChatGPT/Claude از همین برای معرفی سایت استفاده می‌کنند.</small></div>
        <div className="field"><label style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input type="checkbox" checked={robotsIndex} onChange={(e) => setRobotsIndex(e.target.checked)} />
          اجازه ایندکس به گوگل و AI (index)</label>
          <small style={{ color: "var(--text-muted)" }}>خاموش = کل سایت noindex می‌شود (از نتایج گوگل و robots.txt حذف).</small></div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">لوگو و فاوآیکون</div>
        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>لوگو در هدر سایت + تصویر Organization برای گوگل است. فاوآیکون در تب مرورگر دیده می‌شود. تصویر اشتراک‌گذاری (OG) همان عکسی است که در تلگرام/لینکدین کنار لینک می‌آید (۱۲۰۰×۶۳۰).</p>
        <div className="grid c2">
          <div className="field"><label>لوگو</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("logo")}>{logo ? `فایل #${logo} ✓` : "انتخاب از فایل‌ها"}</button>
              {logo ? <button className="btn btn-ghost btn-sm" onClick={() => setLogo(null)}>حذف</button> : null}
            </div>
          </div>
          <div className="field"><label>فاوآیکون</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("favicon")}>{favicon ? `فایل #${favicon} ✓` : "انتخاب از فایل‌ها"}</button>
              {favicon ? <button className="btn btn-ghost btn-sm" onClick={() => setFavicon(null)}>حذف</button> : null}
            </div>
          </div>
          <div className="field"><label>تصویر اشتراک‌گذاری (OG/Twitter)</label>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={() => setPicker("og")}>{ogImage ? `فایل #${ogImage} ✓` : "انتخاب از فایل‌ها"}</button>
              {ogImage ? <button className="btn btn-ghost btn-sm" onClick={() => setOgImage(null)}>حذف</button> : null}
            </div>
          </div>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">زبان و منطقه زمانی</div>
        <div className="grid c2">
          <div className="field"><label>زبان پیش‌فرض سایت</label>
            <select className="select" value={locale} onChange={(e) => setLocale(e.target.value)}>
              <option value="fa">فارسی (fa)</option>
              <option value="en">انگلیسی (en)</option>
            </select>
          </div>
          <div className="field"><label>منطقه زمانی</label>
            <select className="select" dir="ltr" style={{ textAlign: "left" }} value={timezone} onChange={(e) => setTimezone(e.target.value)}>
              <option value="Asia/Tehran">Asia/Tehran</option>
              <option value="Asia/Dubai">Asia/Dubai</option>
              <option value="Europe/London">Europe/London</option>
              <option value="UTC">UTC</option>
            </select>
          </div>
        </div>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">صفحه خانه</div>
        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>صفحه‌ای که در روت <span dir="ltr">/</span> سایت نمایش داده می‌شود. خالی = اسلاگ <span dir="ltr">home</span> وگرنه جدیدترین صفحه منتشرشده.</p>
        <div className="field"><label>صفحه خانه (از صفحات منتشرشده)</label>
          <select className="select" value={homepageId ?? ""} onChange={(e) => setHomepageId(e.target.value ? Number(e.target.value) : null)}>
            <option value="">— خودکار —</option>
            {pubPages.map((p) => <option key={p.id} value={p.id}>{p.title} ({p.slug})</option>)}
          </select>
        </div>
        <a className="btn btn-ghost btn-sm" href="/" target="_blank" rel="noreferrer">مشاهده سایت ←</a>
      </div>

      <div className="card card-pad" style={{ marginBlockEnd: 14 }}>
        <div className="card-title">تماس</div>        <p style={{ fontSize: 12, color: "var(--text-muted)", margin: "0 0 10px" }}>تلفن/ایمیل در فوتر + داده ساخت‌یافته Organization (کارت تماس گوگل و پاسخ AI) می‌آید.</p>
        <div className="grid c2">
          <div className="field"><label>موبایل (مثل 09123456789)</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="field"><label>ایمیل</label>
            <input className="input" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <div className="field" style={{ gridColumn: "1 / -1" }}><label>نشانی فیزیکی</label>
            <input className="input" value={address} onChange={(e) => setAddress(e.target.value)} maxLength={500} placeholder="تهران، …" />
          </div>
        </div>
      </div>

      <button className="btn btn-primary" onClick={() => void save()} disabled={busy}>{busy ? "…" : "ذخیره تنظیمات"}</button>

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
        <PluginSettingsSection />
      </div>
    </div>
  );
}
