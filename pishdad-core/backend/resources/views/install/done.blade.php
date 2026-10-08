@extends('install.layout')

@section('title', __('install.done.title'))

@section('content')
<h2>{{ __('install.done.heading') }}</h2>

<div class="okmsg">{{ __('install.done.locked') }}</div>

@if ($email !== '')
  <p>{{ __('install.done.superadmin_is') }} <strong dir="ltr">{{ $email }}</strong></p>
@endif
<p>{{ __('install.done.next') }}</p>

{{-- E59 + E60 — دو نکته‌ای که کاربر باید همین حالا بداند، پیش از آنکه اطلاعات را
     ببیند: این صفحه خودش PDF می‌گیرد، و اطلاعاتش دو ساعت بعد پاک می‌شود. --}}
<div class="hint" style="border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:10px;padding:12px 14px;margin-top:14px;">
  <p style="margin:0 0 6px;"><strong>{{ __('install.done.expiry_title', ['hours' => $infoTtlHours ?? 2]) }}</strong></p>
  <p style="margin:0 0 6px;">{{ __('install.done.expiry_body') }}</p>
  <p style="margin:0;">{{ __('install.done.pdf_body') }}</p>
</div>

@if (! empty($infoExpired))
  <div class="hint" style="border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;padding:12px 14px;margin-top:14px;">
    {{ __('install.done.expired_notice', ['hours' => $infoTtlHours ?? 2]) }}
  </div>
@endif

{{-- ── E22: برگهٔ اطلاعات + PDF + لینک‌ها ─────────────────────────── --}}
<style>
  #recap { border:2px solid var(--brand); border-radius:12px; padding:16px; margin-top:20px; }
  #recap h3 { margin:0 0 4px; font-size:16px; }
  #recap p.lead { color:var(--muted); font-size:13px; margin:0 0 12px; }
  #recap h4 { margin:14px 0 6px; font-size:14px; }
  .recap-links { display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
  .btn.ghost { background:#fff; color:var(--brand); border:1px solid var(--brand); }
  @media print {
    body { background:#fff; }
    header.top, nav.card, .recap-links, .no-print { display:none !important; }
    .wrap { max-width:none; padding:0; }
    .grid { display:block; }
    .card { border:0; padding:0; }
  }
</style>

<section id="recap" aria-label="{{ __('install.done.recap_title') }}">
  <h3>{{ __('install.done.recap_title') }}</h3>
  <p class="lead">{{ __('install.done.recap_lead') }}</p>

  <h4>{{ __('install.done.db_title') }}</h4>
  @php $db = $db ?? []; @endphp
  <table class="checks">
    <tr><th>{{ __('install.done.f_host') }}</th><td dir="ltr">{{ $db['host'] ?? '—' }}</td></tr>
    <tr><th>{{ __('install.done.f_port') }}</th><td dir="ltr">{{ $db['port'] ?? '—' }}</td></tr>
    <tr><th>{{ __('install.done.f_db') }}</th><td dir="ltr">{{ $db['database'] ?? '—' }}</td></tr>
    <tr><th>{{ __('install.done.f_user') }}</th><td dir="ltr">{{ $db['username'] ?? '—' }}</td></tr>
    <tr><th>{{ __('install.done.f_pass') }}</th><td dir="ltr">{{ $db['password'] ?? '—' }}</td></tr>
  </table>

  <h4>{{ __('install.done.admin_title') }}</h4>
  <table class="checks">
    <tr><th>{{ __('install.done.f_email') }}</th><td dir="ltr">{{ $email !== '' ? $email : '—' }}</td></tr>
  </table>

  {{-- E56 — رمز فقط یک بار اینجا نشان داده می‌شود: `done()` بعد از رندر پاکش
       می‌کند، پس با رفرش دیگر دیده نمی‌شود. --}}
  @if (($adminPassword ?? '') !== '')
    <p class="hint">{{ __('install.done.admin_pass_shown') }}</p>
    <p><code dir="ltr" style="display:inline-block;font-size:15px;padding:5px 12px;border-radius:8px;background:#0f172a;color:#e2e8f0;">{{ $adminPassword }}</code></p>
    <p class="hint">{{ __('install.done.admin_pass_once') }}</p>
  @else
    <p class="hint">{{ __('install.done.admin_pass_note') }}</p>
  @endif

  {{-- E56 + E61 — اطلاعاتِ نصبِ فرانت، آمادهٔ کپی و همراه با دکمهٔ کپی. --}}
  <h4>{{ __('install.done.frontend_title') }}</h4>
  <p class="hint">{{ __('install.done.frontend_lead') }}</p>

  @php
    /*
     * متنِ بلوک در یک متغیر ساخته میشود تا یک منبعِ واحد باشد: همان چیزی که
     * دیده میشود همان چیزی است که کپی میشود، بدونِ خواندنِ دوبارهٔ DOM.
     */
    $frontendEnv = implode("\n", [
      'PISHDAD_PUBLIC_API_URL=' . ($apiUrl ?? ''),
      'PISHDAD_INTERNAL_API_URL=' . ($apiUrl ?? ''),
      'PISHDAD_SITE_URL=' . $frontend,
      'PISHDAD_SITE_LOCALES=fa',
      'PISHDAD_SITE_PRIMARY_LOCALE=fa',
      'REVALIDATE_SECRET=' . ($revalidateSecret ?? ''),
    ]);
  @endphp

  <style>
    /*
     * ⚠️ `dir="ltr"` روی خودِ کادر است، نه فقط روی متن.
     *
     * متن لاتین است، پس همیشه چپ‌به‌راست خوانده می‌شود و دکمه باید **سمتِ
     * راستِ** کادر بماند — در فارسی و انگلیسی هر دو. اگر کادر RTL می‌ماند،
     * `inset-inline-end` در انگلیسی به سمتِ چپ می‌رفت و دکمه جابه‌جا می‌شد.
     */
    .frontend-copy { position: relative; margin-top: 6px; }
    .frontend-copy pre {
      margin: 0; padding: 14px 84px 14px 14px;
      overflow-x: auto; border-radius: 10px;
      background: #0f172a; color: #e2e8f0;
      font-size: 12px; line-height: 1.9;
    }
    .frontend-copy .copy-btn {
      position: absolute; top: 8px; inset-inline-end: 8px;
      border: 1px solid #334155; border-radius: 6px;
      background: #1e293b; color: #e2e8f0;
      font-size: 11px; padding: 4px 10px; cursor: pointer;
    }
    .frontend-copy .copy-btn:hover { background: #334155; }
    .frontend-copy .copy-btn.done { background: #065f46; border-color: #065f46; }
    @media print { .frontend-copy .copy-btn { display: none; } }
  </style>

  <div class="frontend-copy" dir="ltr">
    <pre id="frontend-env">{{ $frontendEnv }}</pre>
    <button type="button" class="copy-btn" id="frontend-copy-btn" data-copied="{{ __('install.done.copied') }}">{{ __('install.done.copy') }}</button>
  </div>

  <script>
    (function () {
      var btn = document.getElementById('frontend-copy-btn');
      var src = document.getElementById('frontend-env');
      if (!btn || !src) return;

      btn.addEventListener('click', function () {
        var text = src.textContent || '';
        var label = btn.textContent;
        var flash = function () {
          btn.textContent = btn.getAttribute('data-copied') || label;
          btn.classList.add('done');
          window.setTimeout(function () { btn.textContent = label; btn.classList.remove('done'); }, 1600);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(flash, function () { legacy(text, flash); });
        } else {
          legacy(text, flash);
        }
      });

      /*
       * کلیپ‌بوردِ مرورگر در بسترِ ناامن (http روی دامنهٔ غیرِ localhost) یا بدونِ
       * اجازه کار نمی‌کند. اینجا کاربر دارد نصب می‌کند؛ نباید دستش خالی بماند،
       * پس راهِ قدیمی را هم می‌رویم.
       */
      function legacy(text, flash) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* بی‌صدا: دکمه وضعیت را نشان می‌دهد */ }
        document.body.removeChild(area);
        flash();
      }
    })();
  </script>

  <p class="hint">{{ __('install.done.frontend_setup_hint', ['url' => $frontend.'/setup']) }}</p>

  <div class="recap-links">
    <a class="btn" href="{{ $panel }}" target="_blank" rel="noopener">{{ __('install.done.panel') }}</a>
    <a class="btn ghost" href="{{ $frontend }}" target="_blank" rel="noopener">{{ __('install.done.website') }}</a>
    <button class="btn ghost no-print" type="button" onclick="window.print()">{{ __('install.done.print_pdf') }}</button>
  </div>
</section>

{{--
    E60 — PDF خودکار.

    همان لحظه که این صفحه باز می‌شود، پنجرهٔ چاپ هم باز می‌شود تا کاربر اطلاعات
    را از دست ندهد. یک‌بار در هر نشست (`sessionStorage`) تا رفرش‌کردن، دیالوگ را
    مدام باز نکند.

    ⚠️ صادقانه: این «چاپ ← ذخیره به‌صورت PDF» است، نه دانلودِ بی‌صدای یک فایل.
    دانلودِ واقعیِ خودکار یعنی تولیدِ PDF در **سرور** (مثل dompdf) که یک وابستگیِ
    تازه به بستهٔ منتشرشده اضافه می‌کند. این راه صفر وابستگی دارد و همین کار را
    برای کاربر انجام می‌دهد.
--}}
<script>
  (function () {
    var key = 'pishdad.done.printed';
    try {
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, '1');
    } catch (e) {
      // حالتِ خصوصیِ مرورگر دسترسی نمی‌دهد؛ به‌جای چشم‌پوشی از چاپ، انجامش بده.
    }
    window.addEventListener('load', function () {
      window.setTimeout(function () { window.print(); }, 400);
    });
  })();
</script>
@endsection
