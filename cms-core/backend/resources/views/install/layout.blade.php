<!DOCTYPE html>
@php $installLocale = app()->getLocale(); $installDir = $installLocale === 'fa' ? 'rtl' : 'ltr'; @endphp
<html lang="{{ $installLocale }}" dir="{{ $installDir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('install.layout.doc_title') }} — @yield('title')</title>
<style>
  :root { --bg:#f7f7f8; --card:#fff; --ink:#1c1c1e; --muted:#6b7280; --line:#e5e7eb;
    --ok:#15803d; --warn:#b45309; --fail:#b91c1c; --brand:#1d4ed8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink);
    font-family:"Vazirmatn","IRANSans","Segoe UI",Tahoma,Arial,sans-serif; }
  .wrap { max-width:960px; margin:0 auto; padding:24px 16px 64px; }
  header.top { margin-bottom:20px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
  header.top h1 { font-size:22px; margin:0 0 4px; }
  header.top p { color:var(--muted); margin:0; font-size:14px; }
  .langswitch { flex:none; font-size:13px; }
  .langswitch a { color:var(--brand); text-decoration:none; border:1px solid var(--line);
    background:var(--card); border-radius:999px; padding:4px 14px; display:inline-block; }
  .grid { display:grid; grid-template-columns:220px 1fr; gap:16px; }
  @media (max-width:720px){ .grid{ grid-template-columns:1fr; } }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
  ol.rail { list-style:none; margin:0; padding:0; }
  ol.rail li { padding:0; border-bottom:1px dashed var(--line); font-size:14px; }
  ol.rail li:last-child{ border-bottom:0; }
  /* E34 — گامِ قابل‌رفتن لینک است، پس همان چیدمانِ قبلی را با <a> می‌سازیم. */
  ol.rail .rail-link, ol.rail .rail-item {
    display:flex; gap:10px; align-items:flex-start; padding:10px 4px;
    color:inherit; text-decoration:none; border-radius:6px; }
  ol.rail .rail-link:hover { background:#f3f4f6; }
  ol.rail .rail-link:focus-visible { outline:2px solid var(--brand); outline-offset:2px; }
  ol.rail li.current .rail-link { font-weight:bold; }
  .dot { width:22px; height:22px; border-radius:50%; border:2px solid var(--line); flex:none;
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; color:var(--muted); }
  li.done .dot { background:var(--ok); border-color:var(--ok); color:#fff; }
  li.current .dot { border-color:var(--brand); color:var(--brand); font-weight:bold; }
  li.current { font-weight:bold; }
  li.done { color:var(--muted); }
  table.checks { width:100%; border-collapse:collapse; font-size:14px; }
  table.checks th, table.checks td { border:1px solid var(--line); padding:8px 10px; text-align:start; vertical-align:top; }
  table.checks th { background:#f3f4f6; }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:bold; }
  .badge.pass { background:#dcfce7; color:var(--ok); }
  .badge.warn { background:#fef3c7; color:var(--warn); }
  .badge.fail { background:#fee2e2; color:var(--fail); }
  /* کاری نمی‌توان کرد تا گامی بعدی ورودی را بدهد — نه خطا، نه موفقیت. */
  .badge.pending { background:#e0e7ff; color:#4338ca; }
  .remedy { color:var(--muted); font-size:13px; }
  label.f { display:block; margin:12px 0 4px; font-size:14px; font-weight:bold; }
  input.f { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:15px; font-family:inherit; }
  input.f[dir="ltr"]{ text-align:left; }
  html[dir="ltr"] input.f:not([dir]){ text-align:left; }
  .btn { display:inline-block; margin-top:16px; padding:10px 22px; border:0; border-radius:8px;
    background:var(--brand); color:#fff; font-size:15px; font-family:inherit; cursor:pointer; }
  .btn:disabled{ background:#9ca3af; cursor:not-allowed; }
  .err { background:#fee2e2; border:1px solid #fecaca; color:var(--fail); border-radius:8px; padding:10px 12px; font-size:14px; margin:12px 0; }
  .okmsg { background:#dcfce7; border:1px solid #bbf7d0; color:var(--ok); border-radius:8px; padding:10px 12px; font-size:14px; margin:12px 0; }
  .notice { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:10px 12px; font-size:14px; margin:12px 0; line-height:1.9; }
  .hint { color:var(--muted); font-size:13px; margin-top:8px; }
  .actions { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <h1>{{ __('install.layout.heading') }}</h1>
      <p>{{ __('install.layout.sub') }}</p>
    </div>
    <div class="langswitch">
      @php $otherLang = $installLocale === 'fa' ? 'en' : 'fa'; @endphp
      <a href="{{ route('install.lang', ['lang' => $otherLang, 'back' => request()->path()]) }}" title="{{ __('install.layout.switch_label') }}">{{ __('install.layout.switch_to') }}</a>
    </div>
  </header>
  <div class="grid">
    <nav class="card" aria-label="{{ __('install.layout.steps_nav') }}">
      @include('install._steprail', ['current' => $step ?? 'preflight'])
    </nav>
    <main class="card">
      @yield('content')
    </main>
  </div>
</div>
</body>
</html>
