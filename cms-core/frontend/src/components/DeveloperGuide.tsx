"use client";

import { PLUGIN_PACKAGE_CONTRACT } from "../../scripts/generated/plugin-package-contract";

/**
 * راهنمای توسعه‌دهنده پلاگین/قالب (مورد ۶) — بخش جمع‌شونده در صفحات
 * admin/plugins و admin/themes: ساختار ZIP، نمونه manifest.json، امضای
 * Ed25519، چک‌لیست امنیتی، SLA سه‌روزه + چاپ PDF با
 * window.print (بدون lib اضافی). بخش ۶ فقط برای پلاگین است: `db.tables`.
 * فقط var()، فارسی و RTL.
 *
 * K6.11 — بخش‌های ۱ و ۷ **از قرارداد تولید شده‌اند**، دستی نوشته نشده‌اند.
 * منبع حقیقت `PluginPackageContract.php` است و داده از راه
 * `scripts/generated/plugin-package-contract.ts` می‌آید؛ آن فایل را با
 * `npm run gen:plugin-contract` بازتولید و با `…:check` می‌سنجی که drift
 * نکرده باشد. دست‌نویسی این دو بخش دقیقاً همان باگی بود که K6.11 برای
 * حلش وجود دارد: راهنمایی که به کاربر دستور غلط بدهد.
 *
 * بخش‌های دستی عمداً دستی مانده‌اند: امضای Ed25519، چک‌لیست امنیتی، SLA و
 * نمونهٔ مانیفست قراردادِ `PluginPackageContract` نیستند و به قضاوت
 * انسانی نیاز دارند. قالب هم در این قرارداد نیست، پس درختِ قالب دستی است.
 */

const CONTRACT = PLUGIN_PACKAGE_CONTRACT;

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

export function DeveloperGuide({ kind }: { kind: "plugin" | "theme" }) {
  const isPlugin = kind === "plugin";
  const title = isPlugin ? "راهنمای توسعه‌دهنده پلاگین" : "راهنمای توسعه‌دهنده قالب";

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
    <details className="card card-pad dev-guide" style={{ marginBlock: 12 }}>
      <summary style={{ cursor: "pointer", fontWeight: 700, fontSize: 14 }}>
        {title} <span style={{ color: "var(--text-muted)", fontWeight: 400, fontSize: 12.5 }}>— ساختار، مانیفست، امضا و بازبینی</span>
      </summary>

      <div className="print-guide" style={{ display: "grid", gap: 14, marginBlockStart: 12, fontSize: 13, lineHeight: 1.9 }}>
        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۱. ساختار ZIP</h3>
          {isPlugin ? (
            <>
              <p style={{ color: "var(--text-muted)", marginBlockEnd: 6 }}>
                بسته <b>دقیقاً</b> می‌تواند این مقصدها را داشته باشد (deny-by-default). هر مسیر
                دیگری — حتی یک پوشهٔ کاری — رد می‌شود.
              </p>
              <ul style={{ paddingInlineStart: 18, display: "grid", gap: 4 }}>
                {CONTRACT.allowedPaths.map((p) => (
                  <li key={p.path}>
                    <code dir="ltr" style={codeStyle}>{p.path}</code>{" — "}
                    {p.label_fa}
                  </li>
                ))}
              </ul>
              <p style={{ color: "var(--text-muted)" }}>
                سقف حجم ZIP: {fa(CONTRACT.limits.maxZipBytes / 1024 / 1024)} مگابایت
                {" "}({fa(CONTRACT.limits.maxZipBytes)} بایت) و حداکثر{" "}
                {fa(CONTRACT.limits.maxFiles)} فایل. نسبت فشرده‌سازی بالاتر از{" "}
                {fa(CONTRACT.limits.maxCompressionRatio)} یعنی zip bomb و رد می‌شود.
                {" "}<span dir="ltr">{CONTRACT.package.manifestName}</span> باید در ریشهٔ ZIP باشد.
                کلاس‌های افزونه باید داخل ریشهٔ{" "}
                <code dir="ltr" style={codeStyle}>{CONTRACT.package.pluginNamespacePrefix}…</code>{" "}
                بنشینند.
              </p>
            </>
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

        {isPlugin && (
        <section>
          <h3 style={{ fontSize: 13.5, marginBlockEnd: 6 }}>۷. نقاط اتصال — از قرارداد هسته</h3>
          <p style={{ color: "var(--text-muted)" }}>
            این فهرست دستی نوشته نشده؛ از{" "}
            <code dir="ltr" style={codeStyle}>{CONTRACT.source}</code> تولید شده
            {" "}(اثر انگشت <span dir="ltr">{CONTRACT.contractFingerprint}</span>).
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
                {STATUS_FA[status]}: {fa(CONTRACT.extensionPoints.filter((p) => p.status === status).length)}
              </span>
            ))}
          </p>

          <div style={{ display: "grid", gap: 8 }}>
            {CONTRACT.extensionPoints.map((point) => (
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
              <span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.reservedTypeNames.join(" · ")}</span>
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
              <b>رابط‌های قابل‌جایگزینی</b> (بسته، سه مورد — کلاس پیاده‌سازی خودت آزاد
              است ولی باید داخل ریشهٔ{" "}
              <code dir="ltr" style={codeStyle}>{CONTRACT.package.pluginNamespacePrefix}…</code>{" "}
              بنشیند):{" "}
              <span dir="ltr" style={{ display: "inline-block" }}>{CONTRACT.overridableInterfaces.join(" · ")}</span>
            </p>
            {Object.keys(CONTRACT.deferredExtensionPoints).length > 0 && (
              <p>
                <b>در نسخهٔ ۱ اصلاً پذیرفته نمی‌شوند</b> (وجودشان در مانیفست خطاست):{" "}
                {Object.entries(CONTRACT.deferredExtensionPoints).map(([key, why]) => (
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
  );
}
