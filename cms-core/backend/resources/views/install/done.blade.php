@extends('install.layout')

@section('title', __('install.done.title'))

@section('content')
<h2>{{ __('install.done.heading') }}</h2>

<div class="okmsg">{{ __('install.done.locked') }}</div>

@if ($email !== '')
  <p>{{ __('install.done.superadmin_is') }} <strong dir="ltr">{{ $email }}</strong></p>
@endif
<p>{{ __('install.done.next') }}</p>

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

  {{-- E56 — اطلاعاتِ نصبِ فرانت، آمادهٔ کپی تا کاربر دنبالشان نگردد. --}}
  <h4>{{ __('install.done.frontend_title') }}</h4>
  <p class="hint">{{ __('install.done.frontend_lead') }}</p>
  <pre dir="ltr" style="overflow-x:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;font-size:12px;line-height:1.9;">{{ 'CMS_PUBLIC_API_URL=' . ($apiUrl ?? '') . "\n" . 'CMS_INTERNAL_API_URL=' . ($apiUrl ?? '') . "\n" . 'CMS_SITE_URL=' . $frontend . "\n" . 'CMS_SITE_LOCALES=fa' . "\n" . 'CMS_SITE_PRIMARY_LOCALE=fa' . "\n" . 'REVALIDATE_SECRET=' . ($revalidateSecret ?? '') }}</pre>
  <p class="hint">{{ __('install.done.frontend_setup_hint', ['url' => $frontend.'/setup']) }}</p>

  <div class="recap-links">
    <a class="btn" href="{{ $panel }}" target="_blank" rel="noopener">{{ __('install.done.panel') }}</a>
    <a class="btn ghost" href="{{ $frontend }}" target="_blank" rel="noopener">{{ __('install.done.website') }}</a>
    <button class="btn ghost no-print" type="button" onclick="window.print()">{{ __('install.done.print_pdf') }}</button>
  </div>
</section>
@endsection
