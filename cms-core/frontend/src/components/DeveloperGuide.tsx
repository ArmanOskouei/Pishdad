"use client";

/**
 * راهنمای توسعه‌دهنده پلاگین/قالب (مورد ۶) — بخش جمع‌شونده در صفحات
 * admin/plugins و admin/themes: ساختار ZIP، نمونه manifest.json، امضای
 * Ed25519، چک‌لیست امنیتی، SLA سه‌روزه + چاپ PDF با
 * window.print (بدون lib اضافی). فقط var()، فارسی و RTL.
 */
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
          <pre dir="ltr" style={{ background: "var(--surface-2)", border: "1px solid var(--border)", borderRadius: 8, padding: 12, overflowX: "auto", fontSize: 12 }}>
{isPlugin
? `my-plugin.zip
├── manifest.json      ← اجباری، ریشه ZIP
├── bootstrap.php      ← نقطه ورود (اختیاری)
└── blocks/            ← اسکیمای بلوک‌ها (اختیاری)`
: `my-theme.zip
├── manifest.json      ← اجباری، ریشه ZIP
├── index.html         ← قالب پایه
└── assets/            ← استایل/اسکریپت/تصویر (اختیاری)`}
          </pre>
          <p style={{ color: "var(--text-muted)" }}>سقف حجم ۲۰ مگابایت. فقط فایل ZIP پذیرفته می‌شود؛ نبود manifest.json معتبر = خطای ۴۲۲.</p>
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
      </div>
    </details>
  );
}
