@extends('install.layout')

@section('title', __('install.database.title'))

@section('content')
<h2>{{ __('install.database.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif
@php $old = $old ?? []; @endphp

<form method="post" action="{{ route('install.database.save') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">

  <label class="f" for="db-host">{{ __('install.database.host') }}</label>
  <input class="f" dir="ltr" id="db-host" name="host" required maxlength="255" value="{{ $old['host'] ?? $config['host'] ?? '' }}">

  <label class="f" for="db-port">{{ __('install.database.port') }}</label>
  <input class="f" dir="ltr" id="db-port" name="port" required inputmode="numeric" value="{{ $old['port'] ?? $config['port'] ?? '5432' }}">

  <label class="f" for="db-database">{{ __('install.database.dbname') }}</label>
  <input class="f" dir="ltr" id="db-database" name="database" required maxlength="63" value="{{ $old['database'] ?? $config['database'] ?? '' }}">

  <label class="f" for="db-username">{{ __('install.database.username') }}</label>
  <input class="f" dir="ltr" id="db-username" name="username" required maxlength="63" value="{{ $old['username'] ?? $config['username'] ?? '' }}">

  <label class="f" for="db-password">{{ __('install.database.password') }}</label>
  <input class="f" dir="ltr" id="db-password" name="password" type="password" autocomplete="new-password" value="">

  <div class="actions">
    <button class="btn" type="submit">{{ __('install.database.submit') }}</button>
  </div>
  <p class="hint">{{ __('install.database.hint') }}</p>
</form>

{{-- ── E16: راهنمای ساخت دیتابیس، ویژهٔ هر مدل استقرار · E20: دوزبانه ── --}}
<style>
  .db-guides { margin-top:28px; border-top:2px solid var(--line); padding-top:16px; }
  .db-guides > h3 { font-size:17px; margin:0 0 4px; }
  .db-guides > p.lead { color:var(--muted); font-size:13px; margin:0 0 12px; }
  .db-rules { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:13px; margin:0 0 12px; }
  html[dir="ltr"] .db-rules { background:#eff6ff; }
  .db-rules b { color:var(--brand); }
  .db-guides details { border:1px solid var(--line); border-radius:10px; margin:0 0 10px; background:#fff; }
  .db-guides summary { cursor:pointer; padding:12px 14px; font-weight:bold; font-size:14px; list-style:none; display:flex; gap:8px; align-items:center; }
  .db-guides summary::-webkit-details-marker { display:none; }
  .db-guides summary::before { content:"+"; color:var(--brand); font-size:18px; line-height:1; flex:none; }
  .db-guides details[open] summary::before { content:"−"; }
  .db-guides summary .tag { font-size:11px; font-weight:normal; background:#f3f4f6; border-radius:999px; padding:2px 10px; color:var(--muted); }
  .db-guides .body { padding:0 14px 14px; font-size:13.5px; }
  ol.steps { margin:10px 0; padding:0; list-style:none; counter-reset:st; }
  ol.steps > li { counter-increment:st; margin:0 0 12px; padding:10px 12px; background:#f9fafb; border:1px solid var(--line); border-radius:8px; }
  ol.steps > li::before { content:"{{ __('install.guides.step_word') }} " counter(st) " — "; font-weight:bold; color:var(--brand); }
  html[dir="ltr"] ol.steps > li::before { content:"{{ __('install.guides.step_word') }} " counter(st) ": "; }
  ol.steps p { margin:6px 0; }
  code.cmd { display:block; direction:ltr; text-align:left; background:#1c1c1e; color:#e5e7eb;
    border-radius:8px; padding:10px 12px; margin:8px 0; font-size:12.5px; white-space:pre-wrap; word-break:break-all;
    font-family:Consolas,"Courier New",monospace; }
  table.map { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
  table.map th, table.map td { border:1px solid var(--line); padding:6px 8px; text-align:start; vertical-align:top; }
  table.map th { background:#f3f4f6; }
  table.map td code { direction:ltr; unicode-bidi:embed; background:#f3f4f6; border-radius:4px; padding:1px 6px; font-size:12px; }
  .note { color:var(--muted); font-size:12.5px; }
  .warnbox { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:8px 12px; font-size:13px; margin:8px 0; }
</style>

@php
  $g = fn(string $k) => __('install.guides.'.$k);
  $nums = app()->getLocale() === 'fa' ? ['۱)', '۲)', '۳)'] : ['1)', '2)', '3)'];
  $sqlRole = "CREATE ROLE pishdad LOGIN PASSWORD 'STRONG_PASSWORD';\nCREATE DATABASE pishdad OWNER pishdad;";
@endphp
<section class="db-guides" aria-label="{{ $g('title') }}">
  <h3>{{ $g('title') }}</h3>
  <p class="lead">{{ $g('lead') }}</p>

  <div class="db-rules">
    <b>{{ $g('rules_title') }}</b>
    {{ $nums[0] }} {{ $g('rule1') }} <code dir="ltr">A-Z a-z 0-9 _</code> —
    {{ $nums[1] }} {{ $g('rule2') }} <code dir="ltr">{{ $g('rule_collation') }}</code> —
    {{ $nums[2] }} {{ $g('rule3') }}
  </div>

  {{-- ── ویندوز ── --}}
  <details>
    <summary>{{ $g('win.title') }} <span class="tag">{{ $g('win.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('win.s1t') }}</b><p>{{ $g('win.s1h') }}</p></li>
        <li><b>{{ $g('win.s2t') }}</b><p>{{ $g('win.s2h') }}</p></li>
        <li><b>{{ $g('win.s3t') }}</b><p>{{ $g('win.s3h') }}</p>
          <code class="cmd">{{ $sqlRole }}</code>
          <p class="note">{{ $g('win.s3note') }}</p></li>
        <li><b>{{ $g('win.s4t') }}</b><p>{{ $g('win.s4h') }}</p>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value') }}</th></tr>
            @foreach (__('install.guides.win.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table></li>
      </ol>
    </div>
  </details>

  {{-- ── لینوکس ── --}}
  <details>
    <summary>{{ $g('linux.title') }} <span class="tag">{{ $g('linux.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('linux.s1t') }}</b>
          <p>{{ $g('linux.s1_ubuntu') }}</p>
          <code class="cmd">sudo apt update &amp;&amp; sudo apt install -y postgresql</code>
          <p>{{ $g('linux.s1_fedora') }}</p>
          <code class="cmd">sudo dnf install -y postgresql-server &amp;&amp; sudo postgresql-setup --initdb &amp;&amp; sudo systemctl enable --now postgresql</code>
          <p class="note">{{ $g('linux.s1h') }}</p></li>
        <li><b>{{ $g('linux.s2t') }}</b>
          <code class="cmd">sudo -u postgres psql</code>
          <p class="note">{{ $g('linux.s2h') }}</p></li>
        <li><b>{{ $g('linux.s3t') }}</b>
          <code class="cmd">{{ $sqlRole }}</code>
          <p class="note">{{ $g('linux.s3h') }}</p></li>
        <li><b>{{ $g('linux.s4t') }}</b><p>{{ $g('linux.s4h') }}</p>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value') }}</th></tr>
            @foreach (__('install.guides.linux.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table></li>
      </ol>
    </div>
  </details>

  {{-- ── مک ── --}}
  <details>
    <summary>{{ $g('mac.title') }} <span class="tag">{{ $g('mac.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('mac.s1t') }}</b>
          <code class="cmd">brew install postgresql@16
brew services start postgresql@16</code>
          <p class="note">{{ $g('mac.s1h') }}</p></li>
        <li><b>{{ $g('mac.s2t') }}</b>
          <code class="cmd">psql postgres</code>
          <p class="note">{{ $g('mac.s2h') }}</p></li>
        <li><b>{{ $g('mac.s3t') }}</b>
          <code class="cmd">{{ $sqlRole }}</code></li>
        <li><b>{{ $g('mac.s4t') }}</b><p>{{ $g('mac.s4h') }}</p>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value') }}</th></tr>
            @foreach (__('install.guides.mac.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table></li>
      </ol>
    </div>
  </details>

  {{-- ── داکر ── --}}
  <details>
    <summary>{{ $g('docker.title') }} <span class="tag">{{ $g('docker.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('docker.s1t') }}</b><p>{{ $g('docker.s1h') }}</p>
          <code class="cmd">docker run --name pishdad-db -e POSTGRES_USER=pishdad -e POSTGRES_PASSWORD=STRONG_PASSWORD -e POSTGRES_DB=pishdad -p 5432:5432 -v pishdad-pg:/var/lib/postgresql/data -d postgres:16</code>
          <p class="note">{{ $g('docker.s1note') }}</p></li>
        <li><b>{{ $g('docker.s2t') }}</b>
          <code class="cmd">docker logs pishdad-db</code>
          <p>{{ $g('docker.s2h') }}</p></li>
        <li><b>{{ $g('docker.s3t') }}</b>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value') }}</th></tr>
            @foreach (__('install.guides.docker.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table>
          <div class="warnbox">{{ $g('docker.warn') }}</div></li>
      </ol>
    </div>
  </details>

  {{-- ── ابری ── --}}
  <details>
    <summary>{{ $g('cloud.title') }} <span class="tag">{{ $g('cloud.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('cloud.s1t') }}</b><p>{{ $g('cloud.s1h') }}</p></li>
        <li><b>{{ $g('cloud.s2t') }}</b><p>{{ $g('cloud.s2h') }}</p>
          <code class="cmd">postgresql://USER:PASSWORD@HOST:5432/DATABASE</code>
          <p class="note">{{ $g('cloud.s2note') }}</p></li>
        <li><b>{{ $g('cloud.s3t') }}</b>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value_cloud') }}</th></tr>
            @foreach (__('install.guides.cloud.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table>
          <div class="warnbox">{{ $g('cloud.warn') }}</div></li>
      </ol>
    </div>
  </details>
</section>
@endsection
