"use client";

import { useCallback, useEffect, useState } from "react";

import { authed } from "@/lib/auth";
import {
  PLUGIN_CONTRACT_PATH,
  parsePluginContract,
  type PluginPackageContract,
} from "@/lib/plugin-contract";
import { MarkdownDeveloperGuide } from "@/components/ThemeDeveloperGuide";

/**
 * راهنمای توسعه‌دهنده پلاگین/قالب (مورد ۶) — بخش جمع‌شونده در صفحات
 * admin/plugins و admin/themes: ساختار ZIP، نمونه manifest.json، امضای
 * Ed25519، چک‌لیست امنیتی، SLA سه‌روزه + چاپ PDF با
 * window.print (بدون lib اضافی). بخش ۶ فقط برای پلاگین است: `db.tables`.
 * فقط var()، فارسی و RTL.
 *
 * I2 — بخش‌های ۱ و ۷ دیگر `import` نمی‌شوند؛ **در runtime خوانده می‌شوند** از
 * `GET /v1/admin/plugins/contract`، از راه همان `authed`/`/api/proxy` که بقیهٔ
 * پنل ازش می‌خواند. قبلاً یک کپیِ تولیدشده
 * (`scripts/generated/plugin-package-contract.ts`) ایمپورت می‌شد، یعنی دو
 * نسخه از یک حقیقت و راهنمایی که تا بازتولیدِ دستی، دربارهٔ نسخهٔ کهنه حرف
 * می‌زد.
 *
 * ## چرا fallback به آن کپی ممنوع است
 *
 * اگر endpoint در دسترس نباشد و UI بی‌صدا به فایلِ تولیدشده برگردد، دقیقاً همان
 * باگی برمی‌گردد که I2 آمده تا حذفش کند — فقط حالا **پنهان‌تر**، چون کاربر فکر
 * می‌کند داده زنده است. پس سه حالتِ صریح داریم: «در حال خواندن»، «داده هست»، و
 * «نتوانستیم بخوانیم» — و حالتِ سوم نامِ کمبودها را نشان می‌دهد.
 *
 * بارگذاری در `useEffect` است، نه در بدنهٔ render (قانون ضد React #301 در
 * AGENTS.md) و نه در `useState` مقداردهی‌شده با `authed`.
 *
 * بخش‌های دستی عمداً دستی مانده‌اند: امضای Ed25519، چک‌لیست امنیتی، SLA و
 * نمونهٔ مانیفست قراردادِ `PluginPackageContract` نیستند و به قضاوت
 * انسانی نیاز دارند. قالب هم در این قرارداد نیست، پس درختِ قالب دستی است و
 * قالب اصلاً قرارداد نمی‌خواند.
 */

const STATUS_FA: Record<string, string> = {
  live: "زنده",
  declared_only: "فقط اعلام‌شده",
  deferred: "تعویق‌افتاده",
};

const OPENNESS_FA: Record<string, string> = {
  closed: "بسته",
  schema_defined: "نوع قفل، تنظیمات باز",
  open_vocabulary: "واژگان باز",
};

const STATUS_ORDER = ["live", "declared_only", "deferred"] as const;

const preStyle = {
  background: "var(--surface-2)",
  border: "1px solid var(--border)",
  borderRadius: 8,
  padding: 12,
  overflowX: "auto",
  fontSize: 12,
} as const;

const codeStyle = {
  background: "var(--surface-2)",
  border: "1px solid var(--border)",
  borderRadius: 4,
  padding: "1px 5px",
  fontSize: 12,
} as const;

const fa = (n: number) => n.toLocaleString("fa-IR");
const json = (value: unknown) => JSON.stringify(value, null, 2);

function fieldSignature(name: string, spec: { type: string; required: boolean }) {
  return `${name}${spec.required ? "" : "?"}: ${spec.type}`;
}

/** سه حالتِ صریح. هیچ‌کدام به دیگری برنمی‌گردد. */
type ContractState =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "ready"; value: PluginPackageContract }
  | { status: "error"; message: string; missing: string[] };

const errStyle = {
  border: "1px solid var(--danger, #c0392b)",
  borderRadius: 8,
  padding: 10,
  fontSize: 12.5,
  lineHeight: 1.9,
} as const;

export function DeveloperGuide({ kind }: { kind: "plugin" | "theme" }) {
  const isPlugin = kind === "plugin";

  // قالب در قراردادِ بستهٔ پلاگین نیست ⇒ هیچ درخواستی هم نمی‌رود.
  const [contract, setContract] = useState<ContractState>({ status: "idle" });

  const load = useCallback(async () => {
    setContract({ status: "loading" });
    try {
      const raw = await authed<unknown>(PLUGIN_CONTRACT_PATH);
      const parsed = parsePluginContract(raw);
      if (parsed.ok) {
        setContract({ status: "ready", value: parsed.value });
      } else {
        setContract({
          status: "error",
          message: "پاسخِ سرور ناقص است — این راهنما قراردادِ کامل را برنمی‌گرداند.",
          missing: parsed.missing,
        });
      }
    } catch (e) {
      setContract({
        status: "error",
        message:
          e instanceof Error && e.message
            ? e.message
            : "دسترسی به قراردادِ بسته ممکن نشد.",
        missing: [],
      });
    }
  }, []);

  useEffect(() => {
    if (!isPlugin) return;
    void load();
  }, [isPlugin, load]);

  const CONTRACT = contract.status === "ready" ? contract.value : null;

  // قالب: راهنمای کاملِ دوزبانه با خروجی PDF (بخش‌های دستیِ زیر مخصوص پلاگین‌اند).
  if (!isPlugin) return <MarkdownDeveloperGuide kind="theme" />;

  const manifestSample = isPlugin
    ? `{
  "name": "نام نمایشی پلاگین",
  "slug": "my-plugin",
  "version": "1.0.0",
  "description": "توضیح کوتاه فارسی",
  "author": "نام شما",
  "requires": { "core": ">=1.6.0" },
  "blocks": {
    "my-block": {
      "title": "بلوک نمونه",
      "schema": { "type": "object", "properties": {} }
    }
  },
  "signature": "BASE64-امضای-Ed25519"
}`
    : `{
  "name": "نام نمایشی قالب",
  "slug": "my-theme",
  "version": "1.0.0",
  "description": "توضیح کوتاه فارسی",
  "author": "نام شما",
  "requires": { "core": ">=1.6.0" },
  "signature": "BASE64-امضای-Ed25519"
}`;

  return (
    <div style={{ display: "grid", gap: 12, marginBlock: 12 }}>
      {/*
       * E65 — سندِ کاملِ دوزبانه + PDF، دقیقاً هم‌ساختارِ راهنمای قالب
       * (`/admin/themes?tab=zip`). محتوا از `public/plugin-guide.*.md` می‌آید.
       */}
      <MarkdownDeveloperGuide kind="plugin" />

      {/*
       * و بخشِ **زندهٔ همین نصب**: قراردادِ runtime که هیچ سندِ استاتیکی نمی‌داند
       * (کدام نقاط اتصال در *این* نسخه واقعاً کار می‌کنند). شمارهٔ سقف‌ها هم از
       * قرارداد خوانده می‌شود تا با افزودن یک رابط، سندِ راهنما دروغ نگوید.
       */}
      <details className="card card-pad dev-guide" style={{ marginBlock: 0 }}>
      <summary style={{ cursor: "pointer", fontWeight: 700, fontSize: 14 }}>
        قراردادِ زندهٔ همین نصب <span style={{ color: "var(--text-muted)", fontWeight: 400, fontSize: 12.5 }}>— نقاط اتصال، سقف‌ها و اسکیمای بسته</span>
      </summary>

      <div className="print-guide" style={{ display: "grid", gap: 14, marginBlockStart: 12, fontSize: 13, lineHeight: 1.9 }}>
        {isPlugin && <ContractStatus state={contract} onRetry={load} />}

        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۱. ساختار ZIP</h3>
          {isPlugin ? (
            !CONTRACT ? (
              <p style={{ color: "var(--text-muted)" }}>
                این فهرست از قراردادِ زندهٔ هسته می‌آید. تا آن خوانده نشود چیزی
                نشان داده نمی‌شود — نسخهٔ کهنه جایش گذاشته نمی‌شود.
              </p>
            ) : (
            <>
              <p style={{ color: "var(--text-muted)", marginBlockEnd: 6 }}>
                بسته <b>دقیقاً</b> می‌تواند این مقصدها را داشته باشد (deny-by-default). هر مسیر
                دیگری — حتی یک پوشهٔ کاری — رد می‌شود.
              </p>
              <ul style={{ paddingInlineStart: 18, display: "grid", gap: 4 }}>
                {CONTRACT.allowed_paths.map((p) => (
                  <li key={p.path}>
                    <code dir="ltr" style={codeStyle}>{p.path}</code>{" — "}
                    {p.label_fa}
                  </li>
                ))}
              </ul>
              <p style={{ color: "var(--text-muted)" }}>
                سقف حجم ZIP: {fa(CONTRACT.limits.max_zip_bytes / 1024 / 1024)} مگابایت
                {" "}({fa(CONTRACT.limits.max_zip_bytes)} بایت) و حداکثر{" "}
                {fa(CONTRACT.limits.max_files)} فایل. نسبت فشرده‌سازی بالاتر از{" "}
                {fa(CONTRACT.limits.max_compression_ratio)} یعنی zip bomb و رد می‌شود.
                {" "}<span dir="ltr">{CONTRACT.package.manifest_name}</span> باید در ریشهٔ ZIP باشد.
                کلاس‌های افزونه باید داخل ریشهٔ{" "}
                <code dir="ltr" style={codeStyle}>{CONTRACT.package.plugin_namespace_prefix}…</code>{" "}
                بنشینند.
              </p>
            </>
            )
          ) : (
            <>
              <pre dir="ltr" style={{ ...preStyle }}>
{`my-theme.zip
├── manifest.json      ← اجباری، ریشه ZIP
├── index.html         ← قالب پایه
└── assets/            ← استایل/اسکریپت/تصویر (اختیاری)`}
              </pre>
              <p style={{ color: "var(--text-muted)" }}>قالب در قرارداد بستهٔ پلاگین نیست؛ سقف‌های بالا فقط برای پلاگین است.</p>
            </>
          )}
        </section>

        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۲. نمونه کامل manifest.json</h3>
          <pre dir="ltr" style={{ background: "var(--surface-2)", border: "1px solid var(--border)", borderRadius: 8, padding: 12, overflowX: "auto", fontSize: 12 }}>{manifestSample}</pre>
          <p style={{ color: "var(--text-muted)" }}>
            فیلدهای اجباری: <span dir="ltr">name</span>، <span dir="ltr">slug</span>، <span dir="ltr">signature</span>
            {isPlugin ? " (پلاگین بدون امضای معتبر با ۴۲۲ رد و در لاگ امنیتی ثبت می‌شود)" : " (قالب بدون امضا هم بارگذاری می‌شود ولی «راستی‌آزمایی‌نشده» می‌ماند)"}.
            نسخه از الگوی معنایی پیروی کند (<span dir="ltr">major.minor.patch</span>)؛ ارتقا فقط به نسخه بالاتر قبول است.
          </p>
        </section>

        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۳. امضای Ed25519</h3>
          <ol style={{ paddingInlineStart: 18, display: "grid", gap: 6 }}>
            <li><b>تولید کلید:</b> با PHP (sodium) یک جفت‌کلید بسازید — کلید محرمانه پیش شما می‌ماند، کلید عمومی را برای تیم CMS بفرستید تا در پیکربندی هسته (<span dir="ltr">PLUGIN_PUBLIC_KEY</span>) ثبت شود.</li>
            <li><b>دستور امضا:</b> مانیفست را <em>بدون</em> فیلد <span dir="ltr">signature</span> به JSON کانونیکال تبدیل کنید (کلیدها مرتب‌شده، بدون فاصله اضافی)، امضای detached بزنید و base64 آن را در فیلد <span dir="ltr">signature</span> بگذارید.</li>
            <li><b>کلید عمومی کجا می‌رود؟</b> کلید عمومی شما سمت سرور مرکزی ثبت می‌شود؛ هر آپلود با همان کلید راستی‌آزمایی می‌گردد. هر تغییری در مانیفست پس از امضا، امضا را باطل می‌کند.</li>
          </ol>
          <pre dir="ltr" style={{ background: "var(--surface-2)", border: "1px solid var(--border)", borderRadius: 8, padding: 12, overflowX: "auto", fontSize: 12 }}>
{`$kp  = sodium_crypto_sign_keypair();
$pub = base64_encode(sodium_crypto_sign_publickey($kp)); // ← ارسال به تیم CMS
$sec = base64_encode(sodium_crypto_sign_secretkey($kp)); // ← محرمانه، نزد شما

$manifest = [/* ... بدون signature ... */];
ksort($manifest);
$canonical = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$manifest['signature'] = base64_encode(
  sodium_crypto_sign_detached($canonical, base64_decode($sec))
);`}
          </pre>
        </section>

        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۴. چک‌لیست بازبینی امنیتی</h3>
          <ul style={{ paddingInlineStart: 18, display: "grid", gap: 4 }}>
            <li>بدون اجرای مستقیم ورودی کاربر (command injection / eval ممنوع).</li>
            <li>بدون خواندن/نوشتن خارج از مسیر بسته (path traversal و zip-slip کنترل شده باشد).</li>
            <li>بدون ارسال داده به بیرون (exfiltration) و بدون کلید/توکن هاردکدشده.</li>
            <li>اعتبارسنجی ورودی‌ها در سمت سرور؛ خروجی‌ها escape شده باشند (XSS).</li>
            <li>نسخه‌بندی معنایی رعایت شده و تغییرات ناسازگار مستند باشد.</li>
          </ul>
        </section>

        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۵. زمان‌بندی بررسی (SLA)</h3>
          <p>
            بررسی هر ارسال حداکثر <b>۳ روز کاری</b> طول می‌کشد. نتیجه (تأیید/رد با دلیل) همین‌جا روی کارت نمایش داده می‌شود.
            اگر بیش از ۳ روز کاری در انتظار ماند، از بخش تیکت‌ها با پشتیبانی در تماس باشید.
          </p>
          <button type="button" className="btn btn-ghost btn-sm no-print" onClick={() => window.print()}>
            چاپ / دانلود PDF این راهنما
          </button>
        </section>

        {isPlugin && (
        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۶. بخش <span dir="ltr">db</span> — جدول‌های دیتابیس</h3>
          <pre dir="ltr" style={{ background: "var(--surface-2)", border: "1px solid var(--border)", borderRadius: 8, padding: 12, overflowX: "auto", fontSize: 12 }}>
{`"db": {
  "tables": [
    { "name": "posts", "indexes": ["author_id", "slug"] },
    { "name": "comments" }
  ]
}`}
          </pre>
          <ul style={{ paddingInlineStart: 18, display: "grid", gap: 4 }}>
            <li>نام را <b>خام</b> بنویسید؛ هسته خودش پیشوند <span dir="ltr">slug_</span> را اضافه می‌کند. اگر پیشوند را خودتان بنویسید، خطا می‌خورید (<span dir="ltr">db.table_already_prefixed</span>).</li>
            <li>سقف ۲۰ جدول در هر افزونه، و ۱۰ ایندکس <b>در هر جدول</b> — نه در کل افزونه. نام نهایی هم حداکثر ۴۰ نویسه.</li>
            <li>اسلاگی که نقطه یا خط تیره دارد اصلاً نمی‌تواند جدول داشته باشد: نام نهایی شناسهٔ SQL است و فقط <span dir="ltr">a-z</span>، <span dir="ltr">0-9</span> و زیرخل را می‌پذیرد.</li>
            <li>هر جدولی که <span dir="ltr">migration</span> به آن دست می‌زند باید همین‌جا اعلام شود. ارجاع به جدولِ اعلام‌نشده کل نصب را رد می‌کند — از این رو در <span dir="ltr">migration</span> هم نام خام بنویسید، نه نهایی را.</li>
            <li>جدول ندارید؟ کل بخش <span dir="ltr">db</span> را حذف کنید؛ <span dir="ltr">db</span> بدون <span dir="ltr">tables</span> خطا می‌گیرد.</li>
          </ul>
        </section>
        )}

        {isPlugin && CONTRACT && (
        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۷. نقاط اتصال — از قرارداد هسته</h3>
          <p style={{ color: "var(--text-muted)" }}>
            این فهرست دستی نوشته نشده؛ همین حالا از{" "}
            <code dir="ltr" style={codeStyle}>GET {PLUGIN_CONTRACT_PATH}</code> خوانده
            شده — نسخهٔ{" "}
            <span dir="ltr">{CONTRACT.core_contract_version}</span> که{" "}
            <b>همین نصب</b> می‌فهمد.
            {" "}<b>وضعیت واقعی runtime</b> را می‌خوانی، نه وعدهٔ راهنما:{" "}
            <b>تعویق‌افتاده</b> یعنی اعلامش در مانیفست خطاست و بسته را سبز نشان
            می‌دهد ولی هیچ اتفاقی نمی‌افتد.
          </p>

          <p style={{ display: "flex", flexWrap: "wrap", gap: 6, marginBlock: 8 }}>
            {STATUS_ORDER.map((status) => (
              <span
                key={status}
                style={{
                  border: "1px solid var(--border)",
                  borderRadius: 999,
                  padding: "2px 10px",
                  fontSize: 12,
                }}
              >
                {STATUS_FA[status]}: {fa(CONTRACT.extension_points.filter((p) => p.status === status).length)}
              </span>
            ))}
          </p>

          <div style={{ display: "grid", gap: 8 }}>
            {CONTRACT.extension_points.map((point) => (
              <details key={point.key} style={{ border: "1px solid var(--border)", borderRadius: 8, padding: 10 }}>
                <summary style={{ cursor: "pointer", display: "flex", flexWrap: "wrap", gap: 8, alignItems: "baseline" }}>
                  <code dir="ltr" style={codeStyle}>{point.key}</code>
                  <b>{point.label_fa}</b>
                  <span style={{ color: "var(--text-muted)", fontSize: 12 }}>
                    {STATUS_FA[point.status]} · {OPENNESS_FA[point.openness]} · از نسخهٔ{" "}
                    <span dir="ltr">{point.since}</span>
                  </span>
                </summary>

                <div style={{ display: "grid", gap: 8, marginBlockStart: 8, fontSize: 12.5, lineHeight: 1.9 }}>
                  <p>{point.desc_fa}</p>
                  <p style={{ color: "var(--text-muted)" }}>
                    <b>چرا این درجهٔ بازی؟</b> {point.openness_why}
                  </p>
                  <p>
                    <b>فیلدهای بسته:</b>{" "}
                    {Object.keys(point.schema.fields).length === 0 ? (
                      <>هنوز نوشته نشده — و چون <span dir="ltr">open_schema</span> بسته است، هیچ اعلانی برای این نقطه قابل بررسی نیست.</>
                    ) : (
                      <span dir="ltr" style={{ display: "inline-block" }}>
                        {Object.entries(point.schema.fields)
                          .map(([name, spec]) => fieldSignature(name, spec))
                          .join(" · ")}
                      </span>
                    )}
                  </p>
                  <p>
                    <b>قواعد مقطعی:</b>{" "}
                    {point.schema.cross.length === 0 ? (
                      <>—</>
                    ) : (
                      <span dir="ltr" style={{ display: "inline-block" }}>{point.schema.cross.join(" · ")}</span>
                    )}
                    {" "}· <b>واژگان باز:</b> <span dir="ltr">{point.open_schema ? "بله" : "خیر"}</span>
                    {" "}· <b>سقف:</b> <span dir="ltr">{fa(point.max.declarations)}</span> اعلان،{" "}
                    <span dir="ltr">{fa(point.max.bytes)}</span> بایت،{" "}
                    <span dir="ltr">{fa(point.max.properties)}</span> فیلد
                  </p>

                  {point.example_ok.length > 0 && (
                    <>
                      <b>نمونهٔ درست</b>
                      <pre dir="ltr" style={preStyle}>{json(point.example_ok)}</pre>
                    </>
                  )}

                  {point.example_bad.length > 0 && (
                    <>
                      <b>نمونه‌های ردشده</b>
                      {point.example_bad.map((bad, idx) => (
                        <div key={idx} style={{ display: "grid", gap: 4 }}>
                          {bad.why && <p style={{ margin: 0 }}>{bad.why}</p>}
                          <pre dir="ltr" style={preStyle}>{json(bad.decl)}</pre>
                        </div>
                      ))}
                    </>
                  )}
                </div>
              </details>
            ))}
          </div>

          <div style={{ display: "grid", gap: 6, marginBlockStart: 12 }}>
            <p>
              <b>کلیدهای ممنوع در همهٔ نقاط</b> (سراسری، در هر عمقی):
              {" "}<span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.forbidden.join(" · ")}</span>
              {" "}— پلاگین در سطح ۰ کار می‌کند: داده و schema، نه کد.
            </p>
            <p>
              <b>نام‌های رزروشدهٔ هسته</b> (نام{" "}
              <span dir="ltr">type</span> یا <span dir="ltr">key</span> نباید یکی از این‌ها
              باشد، وگرنه رجیستری برخورد را بی‌صدا رد می‌کند):{" "}
              <span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.reserved_type_names.join(" · ")}</span>
            </p>
            <p>
              <b>واژگان micro-schema:</b>{" "}
              <span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.grammar.types.join(" · ")}</span>
              {" "}— سقف‌ها:{" "}
              <span dir="ltr" style={{ display: "inline-block" }}>
                {Object.entries(CONTRACT.grammar.limits)
                  .map(([k, v]) => `${k} ≤ ${fa(v)}`)
                  .join(" · ")}
              </span>
            </p>
            <p>
              {/* شمارش عمداً از داده خوانده می‌شود: عددِ دستی با افزوده‌شدن یک
                  رابط دروغ می‌گفت — و همین باگی بود که drift را پنهان می‌کرد. */}
              <b>رابط‌های قابل‌جایگزینی</b> (بسته،{" "}
              {fa(CONTRACT.overridable_interfaces.length)} مورد — کلاس پیاده‌سازی خودت آزاد
              است ولی باید داخل ریشهٔ{" "}
              <code dir="ltr" style={codeStyle}>{CONTRACT.package.plugin_namespace_prefix}…</code>{" "}
              بنشیند):{" "}
              <span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.overridable_interfaces.join(" · ")}</span>
            </p>
            {Object.keys(CONTRACT.deferred_extension_points).length > 0 && (
              <p>
                <b>در نسخهٔ ۱ اصلاً پذیرفته نمی‌شوند</b> (وجودشان در مانیفست خطاست):{" "}
                {Object.entries(CONTRACT.deferred_extension_points).map(([key, why]) => (
                  <span key={key}>
                    <code dir="ltr" style={codeStyle}>{key}</code>{" — "}{why}{" "}
                  </span>
                ))}
              </p>
            )}
          </div>
        </section>
        )}
      </div>
      </details>
    </div>
  );
}

/**
 * نوارِ وضعیتِ قرارداد: «در حال خواندن» / «خطا با دکمهٔ تلاش دوباره».
 *
 * حالتِ «آماده» چیزی رندر نمی‌کند — نبودِ نوار یعنی قرارداد هست.
 *
 * در حالتِ خطا **دقیقاً** می‌گوید چه چیزی کم است یا چه چیزی شکست خورد، و دکمهٔ
 * تلاش دوباره می‌دهد. جایگزینِ این، برگشتنِ خاموش به یک کپیِ کهنه است که راهنمایی
 * می‌سازد که در حالی که با هستهٔ نصب‌شده نمی‌خواند، سبز به نظر می‌رسد.
 */
function ContractStatus({ state, onRetry }: { state: ContractState; onRetry: () => void }) {
  if (state.status === "ready" || state.status === "idle") return null;

  if (state.status === "loading") {
    return (
      <p className="no-print" style={{ color: "var(--text-muted)", fontSize: 12.5 }}>
        در حال خواندن قراردادِ بسته از هسته…
      </p>
    );
  }

  return (
    <div className="no-print" style={errStyle}>
      <b>قراردادِ بسته خوانده نشد.</b> {state.message}
      {state.missing.length > 0 && (
        <p style={{ margin: "6px 0 0" }}>
          کلیدهای ناقص:{" "}
          <span dir="ltr" style={{ display: "inline-block" }}>{state.missing.join(" · ")}</span>
        </p>
      )}
      <p style={{ margin: "6px 0 0", color: "var(--text-muted)" }}>
        بخش‌های ۱ و ۷ تا وقتی این خوانده نشود نشان داده نمی‌شوند؛ نسخهٔ ذخیره‌شدهٔ
        قدیمی جایشان گذاشته نمی‌شود چون ممکن است با همین نصب نخواند.
      </p>
      <button type="button" className="btn btn-ghost btn-sm" onClick={onRetry}>
        تلاش دوباره
      </button>
    </div>
  );
}
