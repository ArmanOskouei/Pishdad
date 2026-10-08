{{--
    صفحهٔ موفقیتِ نصب — ریشهٔ بک‌اند.

    ## چرا یک سندِ کامل و مستقل است، نه `@extends('install.layout')`

    این صفحه بعد از نصب نمایش داده می‌شود و باید **همیشه** بالا بیاید، حتی اگر
    چیدمانِ نصب‌کننده یا دارایی‌هایش روزی تغییر کند. یک سندِ خودکفا با CSSِ درون‌خط
    هیچ وابستگی‌ای به راه‌اندازیِ قالبِ نصب‌کننده ندارد، پس نمی‌تواند بشکند.

    ## چرا **دوزبانه در یک صفحه** و نه بر اساسِ زبان

    کسی که تازه نصب کرده اولین چیزی است که می‌بیند؛ نباید مجبور شود زبان عوض
    کند تا بفهمد نصب موفق بوده. هر دو زبان با هم آمده‌اند.

    ## ⚠️ نشانیِ پنلِ ادمین اینجا نیست

    درخواستِ صریحِ کاربر و دلیلش امنیتی است: این صفحه عمومی است و آدرسِ پنل
    فقط در صفحهٔ پایانیِ نصب داده می‌شود.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- این صفحه هرگز نباید ایندکس شود: نشانیِ سرورِ بک‌اند است، نه سایت. --}}
<meta name="robots" content="noindex, nofollow">
<title>نصب موفق — Installation complete</title>
<style>
  :root {
    --bg: #f6f7fb;
    --card: #ffffff;
    --ink: #0f172a;
    --muted: #64748b;
    --line: #e2e8f0;
    --brand: #4f46e5;
    --ok-bg: #ecfdf5;
    --ok-ink: #047857;
    --ok-line: #a7f3d0;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    background: var(--bg);
    color: var(--ink);
    font: 15px/1.9 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  .card {
    width: 100%;
    max-width: 640px;
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 32px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
  }
  .mark {
    width: 56px; height: 56px;
    border-radius: 50%;
    display: grid; place-items: center;
    background: var(--ok-bg);
    border: 1px solid var(--ok-line);
    color: var(--ok-ink);
    font-size: 30px;
    margin-bottom: 18px;
  }
  h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: -.01em; }
  .lead { margin: 0 0 22px; color: var(--muted); }
  .banner {
    background: var(--ok-bg);
    border: 1px solid var(--ok-line);
    color: var(--ok-ink);
    border-radius: 12px;
    padding: 12px 16px;
    font-weight: 600;
    margin-bottom: 22px;
  }
  .site {
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 8px;
  }
  .site .label { color: var(--muted); font-size: 13px; margin: 0 0 6px; }
  .site a {
    color: var(--brand);
    font-size: 17px;
    font-weight: 600;
    text-decoration: none;
    word-break: break-all;
  }
  .site a:hover { text-decoration: underline; }
  .en { direction: ltr; text-align: left; margin-top: 28px; padding-top: 22px; border-top: 1px dashed var(--line); }
  .en h2 { margin: 0 0 6px; font-size: 18px; }
  .en p { margin: 0; color: var(--muted); }
  .en .banner { margin-top: 14px; margin-bottom: 14px; }
  footer { margin-top: 26px; color: var(--muted); font-size: 12px; }
</style>
</head>
<body>
  <main class="card">
    <div class="mark" aria-hidden="true">✓</div>

    <h1>نصب و راه‌اندازی با موفقیت انجام شد</h1>
    <p class="lead">سرورِ بک‌اند آمادهٔ استفاده است.</p>

    <div class="banner">می‌توانید از وب‌سایت استفاده کنید.</div>

    <div class="site">
      <p class="label">نشانیِ وب‌سایت</p>
      <a href="{{ $site }}" dir="ltr">{{ $site }}</a>
    </div>

    <section class="en">
      <h2>Installation complete</h2>
      <p>The backend server is ready.</p>
      <div class="banner">You can start using the website.</div>
      <div class="site">
        <p class="label">Website address</p>
        <a href="{{ $site }}" dir="ltr">{{ $site }}</a>
      </div>
    </section>

    <footer>
      {{-- ⚠️ نشانیِ پنل به‌عمد اینجا نیست؛ دلیلش در سرصفحهٔ همین فایل. --}}
      Pishdad CMS
    </footer>
  </main>
</body>
</html>
