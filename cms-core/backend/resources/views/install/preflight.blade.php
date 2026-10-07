@extends('install.layout')

@section('title', __('install.preflight.title'))

@section('content')
<h2>{{ __('install.preflight.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

@include('install._preflight_table', ['checks' => $checks])

<div class="actions">
  <form method="post" action="{{ route('install.preflight.confirm') }}">
    <input type="hidden" name="install_token" value="{{ $token }}">
    <button class="btn" type="submit" {{ $blocking ? 'disabled' : '' }}>{{ __('install.preflight.continue') }}</button>
  </form>
  <form method="get" action="{{ route('install.preflight') }}">
    <button class="btn" type="submit" style="background:#4b5563">{{ __('install.preflight.recheck') }}</button>
  </form>
</div>
@if ($blocking)
  <p class="hint">{{ __('install.preflight.blocked_hint') }}</p>
@endif

{{-- ── E29: راهنمای جامعِ پیش‌نیازها برای همهٔ سیستم‌عامل‌ها و حالت‌های نصب ── --}}
<style>
  .pf-guide { margin-top:28px; border-top:2px solid var(--line); padding-top:16px; }
  .pf-guide > h3 { font-size:17px; margin:0 0 4px; }
  .pf-guide > p.lead { color:var(--muted); font-size:13px; margin:0 0 12px; }
  .pf-checklist { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:13px; margin:0 0 12px; }
  .pf-checklist b { color:var(--brand); }
  .pf-checklist ul { margin:6px 0 0; padding-inline-start:18px; }
  .pf-checklist li { margin:3px 0; }
  .pf-mismatch { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:9px 12px; font-size:13px; margin:0 0 12px; }
  .pf-guide details { border:1px solid var(--line); border-radius:10px; margin:0 0 10px; background:#fff; }
  .pf-guide summary { cursor:pointer; padding:12px 14px; font-weight:bold; font-size:14px; list-style:none; display:flex; gap:8px; align-items:center; }
  .pf-guide summary::-webkit-details-marker { display:none; }
  .pf-guide summary::before { content:"+"; color:var(--brand); font-size:18px; line-height:1; flex:none; }
  .pf-guide details[open] summary::before { content:"−"; }
  .pf-guide summary .tag { font-size:11px; font-weight:normal; background:#f3f4f6; border-radius:999px; padding:2px 10px; color:var(--muted); }
  .pf-guide .body { padding:0 14px 14px; font-size:13.5px; }
  .pf-item { margin:0 0 12px; padding:10px 12px; background:#f9fafb; border:1px solid var(--line); border-radius:8px; }
  .pf-item > b { color:var(--brand); }
  .pf-item p { margin:6px 0; }
  code.cmd { display:block; direction:ltr; text-align:left; background:#1c1c1e; color:#e5e7eb;
    border-radius:8px; padding:10px 12px; margin:8px 0; font-size:12.5px; white-space:pre-wrap; word-break:break-all;
    font-family:Consolas,"Courier New",monospace; }
  table.fix { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
  table.fix th, table.fix td { border:1px solid var(--line); padding:6px 8px; text-align:start; vertical-align:top; }
  table.fix th { background:#f3f4f6; }
  .note { color:var(--muted); font-size:12.5px; }
</style>

<section class="pf-guide" aria-label="{{ __('install.preflight.guide_title') }}">
  <h3>{{ __('install.preflight.guide_title') }}</h3>
  <p class="lead">{{ __('install.preflight.guide_lead') }}</p>

  <div class="pf-checklist">
    <b>{{ __('install.preflight.guide_checks_title') }}</b>
    <ul>
      <li>{{ __('install.preflight.guide_checks_php', ['min' => \App\Http\Controllers\Install\Preflight::PHP_MINIMUM]) }}</li>
      <li>{{ __('install.preflight.guide_checks_ext') }}</li>
      <li>{{ __('install.preflight.guide_checks_perm') }}</li>
      <li>{{ __('install.preflight.guide_checks_db') }}</li>
    </ul>
  </div>

  <div class="pf-mismatch">{{ __('install.preflight.guide_php_mismatch') }}</div>

  @foreach (__('install.preflight.platforms') as $platform)
    <details>
      <summary>{{ $platform['title'] }} <span class="tag">{{ $platform['tag'] }}</span></summary>
      <div class="body">
        @foreach ($platform['items'] as $item)
          <div class="pf-item">
            <b>{{ $item['t'] }}</b>
            <p>{{ $item['b'] }}</p>
            @if (! empty($item['cmd']))
              <code class="cmd">{{ $item['cmd'] }}</code>
            @endif
            @if (! empty($item['note']))
              <p class="note">{{ $item['note'] }}</p>
            @endif
          </div>
        @endforeach
      </div>
    </details>
  @endforeach

  {{-- نگاشتِ هر ردیفِ خطادار به راه‌حل --}}
  <details open>
    <summary>{{ __('install.preflight.fix_title') }}</summary>
    <div class="body">
      <table class="fix">
        <thead>
          <tr>
            <th scope="col">{{ __('install.preflight.fix_check') }}</th>
            <th scope="col">{{ __('install.preflight.fix_why') }}</th>
            <th scope="col">{{ __('install.preflight.fix_do') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach (__('install.preflight.fix_rows') as $row)
            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <p class="note" style="margin-top:10px">{{ __('install.preflight.guide_shared_note') }}</p>
    </div>
  </details>
</section>
@endsection
