@extends('install.layout')

@section('title', __('install.database.title'))

@section('content')
<h2>{{ __('install.database.heading') }}</h2>

{{-- E51 — داخلِ کانتینر، `127.0.0.1` یعنی خودِ کانتینر، نه دیتابیس. این راهنما
     فقط وقتی دیده می‌شود که پیش‌فرض‌های داکری اعمال شده باشند. --}}
@if (file_exists('/.dockerenv'))
  <div class="notice" role="note">{{ __('install.database.docker_notice') }}</div>
@endif

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif
@php
  $old = $old ?? [];
  $cfg = $config ?? [];
  $val = fn(string $k, string $d = '') => (string) ($old[$k] ?? $cfg[$k] ?? $d);
@endphp

<form method="post" action="{{ route('install.database.save') }}" id="db-form">
  <input type="hidden" name="install_token" value="{{ $token }}">

  <label class="f" for="db-host">{{ __('install.database.host') }}</label>
  <input class="f" dir="ltr" id="db-host" name="host" required maxlength="255" value="{{ $val('host', '127.0.0.1') }}">

  <label class="f" for="db-port">{{ __('install.database.port') }}</label>
  <input class="f" dir="ltr" id="db-port" name="port" required inputmode="numeric" value="{{ $val('port', '5432') }}">

  <label class="f" for="db-database">{{ __('install.database.dbname') }}</label>
  <input class="f" dir="ltr" id="db-database" name="database" required maxlength="63" value="{{ $val('database', 'Pishdad') }}">

  <label class="f" for="db-username">{{ __('install.database.username') }}</label>
  <input class="f" dir="ltr" id="db-username" name="username" required maxlength="63" value="{{ $val('username', 'Pishdad') }}">

  <label class="f" for="db-password">{{ __('install.database.password') }}</label>
  <input class="f" dir="ltr" id="db-password" name="password" type="password" autocomplete="new-password" value="{{ $val('password') }}">

  <div class="actions">
    <button class="btn" type="submit">{{ __('install.database.submit') }}</button>
  </div>
  <p class="hint">{{ __('install.database.hint') }}</p>
</form>

@php
  $g = fn(string $k) => __('install.guides.'.$k);
  $nums = app()->getLocale() === 'fa' ? ['۱)', '۲)', '۳)'] : ['1)', '2)', '3)'];

  // E28 — همهٔ فرمان‌ها **قالب** هستند و مقادیرشان با نشانه گذاشته شده.
  // قالب‌ها عمداً در متغیر پیاچپی‌اند (نه مستقیم در متن قالب) تا Blade
  // نشانه‌ها را به‌عنوان عبارت پیاچپی تفسیر نکند.
  $tplSql = "CREATE ROLE {{USER}} LOGIN PASSWORD '{{PASS}}';\nCREATE DATABASE {{DB}} OWNER {{USER}};";
  $tplDocker = "docker run --name pishdad-db -e POSTGRES_USER={{USER}} -e POSTGRES_PASSWORD={{PASS}} -e POSTGRES_DB={{DB}} -p {{PORT}}:5432 -v pishdad-pg:/var/lib/postgresql/data -d postgres:16";
  $tplVerify = "psql -h {{HOST}} -p {{PORT}} -U {{USER}} -d {{DB}} -c 'SELECT current_user, current_database();'";
  $tplTest = "psql -h {{HOST}} -p {{PORT}} -U {{USER}} -d {{DB}} -c '\\conninfo'";

  /**
   * رشتهٔ اتصال قالبِ نشانه‌دار ندارد: `:` و `@` و `/` باید از مقادیر بیایند،
   * پس صریح ساخته می‌شود. دو جا استفاده می‌شود (پنل تولیدکننده و بخش ابری) و
   * جاوااسکریپت هر دو را با `[data-conn]` به‌روز می‌کند.
   */
  $connStr = 'postgresql://'.$val('username', 'Pishdad').':'.$val('password')
    .'@'.$val('host', '127.0.0.1').':'.$val('port', '5432').'/'.$val('database', 'Pishdad');

  /**
   * پرکردنِ سمت‌سرور: قالب را با مقادیرِ فعلی پُر می‌کند تا اگر جاوااسکریپت
   * خاموش بود، کاربر همچنان فرمانِ کامل و کپی‌شدنی ببیند (نه خالی و نه `{{DB}}`).
   * جاوااسکریپت بعداً همین متن را با تغییر ورودی‌ها جایگزین می‌کند.
   */
  $fill = function (string $tpl) use ($val): string {
    $map = [
      '{{DB}}' => $val('database', 'Pishdad'),
      '{{USER}}' => $val('username', 'Pishdad'),
      '{{PASS}}' => $val('password'),
      '{{HOST}}' => $val('host', '127.0.0.1'),
      '{{PORT}}' => $val('port', '5432'),
    ];

    return strtr($tpl, $map);
  };
@endphp

{{-- ══════════════════════════════════════════════════════════════════════
     E28 — تولیدکنندهٔ زندهٔ مقادیر.
     ورودی‌های این بخش و فیلدهای فرمِ بالا یک منبع دارند و دوطرفه هم‌خوان
     می‌شوند؛ فرمان‌ها فقط نمایشِ همین مقادیرند.
     ══════════════════════════════════════════════════════════════════════ --}}
<style>
  .db-gen { margin-top:22px; border:1px solid var(--line); border-radius:12px; background:#fbfdff; padding:16px; }
  .db-gen > h3 { font-size:16px; margin:0 0 4px; }
  .db-gen > p.lead { color:var(--muted); font-size:13px; margin:0 0 12px; }
  .sync-note { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:9px 12px; font-size:13px; margin:0 0 14px; }
  .gen-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; }
  .gen-grid label { display:block; font-size:12.5px; color:var(--muted); margin-bottom:4px; }
  .gen-grid input { width:100%; }
  .pw-row { display:flex; gap:6px; align-items:center; }
  .pw-row input { flex:1 1 auto; min-width:0; }
  .mini { font-size:12px; padding:6px 9px; white-space:nowrap; }
  .conn-box { margin-top:14px; }
  .cmd-wrap { position:relative; }
  .cmd-wrap .copy-btn { position:absolute; top:6px; inset-inline-end:6px; font-size:11px; padding:3px 9px;
    background:#374151; color:#f9fafb; border:1px solid #4b5563; border-radius:6px; cursor:pointer; }
  .cmd-wrap .copy-btn:hover { background:#4b5563; }
  .cmd-wrap .copy-btn.done { background:#065f46; border-color:#065f46; }
  .live-badge { font-size:11px; background:#eef2ff; color:#3730a3; border-radius:999px; padding:2px 10px; }
</style>

<section class="db-gen" aria-label="{{ $g('gen_title') }}">
  <h3>{{ $g('gen_title') }} <span class="live-badge">{{ $g('gen_live_badge') }}</span></h3>
  <p class="lead">{{ $g('gen_lead') }}</p>

  <div class="sync-note">{{ $g('gen_sync_note') }}</div>
  <noscript><div class="noscript-box">{{ $g('gen_noscript') }}</div></noscript>

  <div class="gen-grid">
    <div>
      <label for="g-database">{{ __('install.database.dbname') }}</label>
      <input class="f" dir="ltr" id="g-database" maxlength="63" value="{{ $val('database', 'Pishdad') }}" autocomplete="off">
    </div>
    <div>
      <label for="g-username">{{ __('install.database.username') }}</label>
      <input class="f" dir="ltr" id="g-username" maxlength="63" value="{{ $val('username', 'Pishdad') }}" autocomplete="off">
    </div>
    <div>
      <label for="g-host">{{ __('install.database.host') }}</label>
      <input class="f" dir="ltr" id="g-host" maxlength="255" value="{{ $val('host', '127.0.0.1') }}" autocomplete="off">
    </div>
    <div>
      <label for="g-port">{{ __('install.database.port') }}</label>
      <input class="f" dir="ltr" id="g-port" inputmode="numeric" value="{{ $val('port', '5432') }}" autocomplete="off">
    </div>
    <div>
      <label for="g-password">{{ __('install.database.password') }}</label>
      <div class="pw-row">
        <input class="f" dir="ltr" id="g-password" type="text" value="{{ $val('password') }}" autocomplete="off" spellcheck="false">
        <button type="button" class="btn mini" id="pw-toggle" data-show="{{ $g('gen_show') }}" data-hide="{{ $g('gen_hide') }}">{{ $g('gen_hide') }}</button>
      </div>
      <div class="pw-row" style="margin-top:6px">
        <button type="button" class="btn mini" id="pw-copy">{{ $g('gen_copy') }}</button>
        <button type="button" class="btn mini" id="pw-regen">{{ $g('gen_regenerate') }}</button>
      </div>
    </div>
  </div>

  <p class="note" style="margin-top:10px">{{ $g('gen_pass_note') }}</p>

  <div class="conn-box">
    <label class="f" for="g-conn">{{ $g('gen_conn_title') }}</label>
    <div class="cmd-wrap" dir="ltr">
      <code class="cmd" id="g-conn" dir="ltr">{{ $connStr }}</code>
      <button type="button" class="copy-btn" data-copy="#g-conn">{{ $g('gen_copy') }}</button>
    </div>
  </div>
</section>

{{-- ── E16: راهنمای ساخت دیتابیس، ویژهٔ هر مدل استقرار · E20: دوزبانه · E28: زنده ── --}}
<style>
  .db-guides { margin-top:28px; border-top:2px solid var(--line); padding-top:16px; }
  .db-guides > h3 { font-size:17px; margin:0 0 4px; }
  .db-guides > p.lead { color:var(--muted); font-size:13px; margin:0 0 12px; }
  .db-rules { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:13px; margin:0 0 12px; }
  html[dir="ltr"] .db-rules { background:#eff6ff; }
  .db-rules b { color:var(--brand); }
  .perm-box { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:12px 14px; font-size:13px; margin:0 0 12px; }
  .perm-box b { color:#065f46; }
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
    font-family:Consolas,"Courier New",monospace; padding-inline-end:64px; }
  table.map { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
  table.map th, table.map td { border:1px solid var(--line); padding:6px 8px; text-align:start; vertical-align:top; }
  table.map th { background:#f3f4f6; }
  table.map td code { direction:ltr; unicode-bidi:embed; background:#f3f4f6; border-radius:4px; padding:1px 6px; font-size:12px; }
  .note { color:var(--muted); font-size:12.5px; }
  .warnbox { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:8px 12px; font-size:13px; margin:8px 0; }
  .noscript-box { background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:9px 12px; font-size:13px; margin:10px 0; color:#991b1b; }
</style>

<section class="db-guides" aria-label="{{ $g('title') }}">
  <h3>{{ $g('title') }}</h3>
  <p class="lead">{{ $g('lead') }}</p>

  <div class="db-rules">
    <b>{{ $g('rules_title') }}</b>
    {{ $nums[0] }} {{ $g('rule1') }} <code dir="ltr">A-Z a-z 0-9 _</code> —
    {{ $nums[1] }} {{ $g('rule2') }} <code dir="ltr">{{ $g('rule_collation') }}</code> —
    {{ $nums[2] }} {{ $g('rule3') }}
  </div>

  {{-- E28 — یافتهٔ تجربی: superuser لازم نیست، مالکیت دیتابیس کافی است --}}
  <div class="perm-box">
    <b>{{ $g('perm_title') }} — {{ $g('perm_no_super') }}</b>
    <p style="margin:6px 0">{{ $g('perm_body') }}</p>
    <p style="margin:6px 0">{{ $g('perm_why') }}</p>
    <p style="margin:6px 0 0">{{ $g('perm_cloud') }}</p>
  </div>

  {{-- ── ویندوز ── --}}
  <details>
    <summary>{{ $g('win.title') }} <span class="tag">{{ $g('win.tag') }}</span></summary>
    <div class="body">
      <ol class="steps">
        <li><b>{{ $g('win.s1t') }}</b><p>{{ $g('win.s1h') }}</p></li>
        <li><b>{{ $g('win.s2t') }}</b><p>{{ $g('win.s2h') }}</p></li>
        <li><b>{{ $g('win.s3t') }}</b><p>{{ $g('win.s3h') }}</p>
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplSql }}">{{ $fill($tplSql) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div>
          
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
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplSql }}">{{ $fill($tplSql) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div>
          
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
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplSql }}">{{ $fill($tplSql) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div>
          </li>
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
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplDocker }}">{{ $fill($tplDocker) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div>
          
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
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-conn="1">{{ $connStr }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div>
          <p class="note">{{ $g('cloud.s2note') }}</p></li>
        <li><b>{{ $g('cloud.s3t') }}</b>
          <table class="map">
            <tr><th>{{ $g('map_field') }}</th><th>{{ $g('map_value_cloud') }}</th></tr>
            @foreach (__('install.guides.cloud.map') as $row)
              <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td></tr>
            @endforeach
          </table>
          <div class="warnbox">{{ $g('cloud.warn') }}</div></li>
        <li><b>{{ $g('remote_test') }}</b><p>{{ $g('remote_test_lead') }}</p>
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplTest }}">{{ $fill($tplTest) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div></li>
      </ol>
    </div>
  </details>

  {{-- ── E28: سرور دیتابیس روی ماشین دیگر ── --}}
  <details>
    <summary>{{ $g('remote_title') }} <span class="tag">{{ $g('remote_tag') }}</span></summary>
    <div class="body">
      <p>{{ $g('remote_same') }}</p>
      <ol class="steps">
        <li><b>{{ __('install.guides.remote_diff_1') }}</b>
          <p>{{ $g('remote_diff_2') }}</p>
          <div class="cmd-wrap" dir="ltr">
            <code class="cmd" data-tpl="{{ $tplVerify }}">{{ $fill($tplVerify) }}</code>
            <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
          </div></li>
        <li><b>{{ $g('remote_pg_listen') }}</b>
          <p>{{ $g('remote_pg_listen_h') }}</p>
          <code class="cmd">{{ $g('remote_pg_listen_cmd') }}</code>
          <p class="note">{{ $g('remote_pg_listen_note') }}</p></li>
        <li><b>{{ $g('remote_hba') }}</b>
          <p>{{ $g('remote_hba_h') }}</p>
          <code class="cmd">{{ $g('remote_hba_cmd') }}</code>
          <p>{{ $g('remote_hba_note') }}</p>
          <code class="cmd">{{ $g('remote_reload') }}</code></li>
        <li><b>{{ $g('remote_fw') }}</b>
          <p>{{ $g('remote_fw_h') }}</p></li>
      </ol>
      <div class="warnbox">{{ $g('remote_warn') }}</div>
    </div>
  </details>

  {{-- ── E28: آزمودن کاربری که ساخته شد ── --}}
  <details>
    <summary>{{ $g('gen_verify_title') }} <span class="tag">{{ $g('gen_live_badge') }}</span></summary>
    <div class="body">
      <p>{{ $g('gen_verify_lead') }}</p>
      <div class="cmd-wrap" dir="ltr">
        <code class="cmd" data-tpl="{{ $tplVerify }}">{{ $fill($tplVerify) }}</code>
        <button type="button" class="copy-btn" data-copy-closest>{{ $g('gen_copy') }}</button>
      </div>
    </div>
  </details>
</section>

{{-- ══ E28 — موتورِ زندهٔ مقادیر ═══════════════════════════════════════════
     یک منبع حقیقت: پنج ورودیِ بخش تولیدکننده. فیلدهای فرمِ بالا آینهٔ آن‌اند و
     همهٔ [data-tpl]ها از همان مقادیر ساخته می‌شوند. تغییر در فرم هم به
     تولیدکننده برمی‌گردد تا کاربر گیج نشود.
     ══════════════════════════════════════════════════════════════════════ --}}
<script>
(function () {
  var ids = ['database', 'username', 'password', 'host', 'port'];
  var gen = {}, form = {};
  ids.forEach(function (k) {
    gen[k] = document.getElementById('g-' + k);
    form[k] = document.getElementById('db-' + k);
  });
  if (!gen.database) return;

  var TOKENS = { DB: 'database', USER: 'username', PASS: 'password', HOST: 'host', PORT: 'port' };

  // مقدارِ هر کلید از ورودیِ تولیدکننده (منبع حقیقتِ کاربر).
  function values() {
    var v = {};
    ids.forEach(function (k) { v[k] = gen[k].value.trim(); });
    return v;
  }

  function fill(tpl, v) {
    return tpl.replace(/\{\{(\w+)\}\}/g, function (m, name) {
      var key = TOKENS[name];
      return key && v[key] !== '' ? v[key] : m;
    });
  }

  function render() {
    var v = values();

    document.querySelectorAll('[data-tpl]').forEach(function (el) {
      el.textContent = fill(el.getAttribute('data-tpl'), v);
    });

    // رشتهٔ اتصال قالبِ نشانه‌دار ندارد؛ صریح ساخته می‌شود. دو عنصر آن را
    // نشان می‌دهند (پنل تولیدکننده و بخش ابری) و هر دو با هم به‌روز می‌شوند.
    var conn = 'postgresql://' + v.username + ':' + v.password
      + '@' + v.host + ':' + v.port + '/' + v.database;
    document.querySelectorAll('[data-conn], #g-conn').forEach(function (el) {
      el.textContent = conn;
    });
  }

  // تولیدکننده → فرم (و رندر). فرم → تولیدکننده.
  function pushToForm() {
    ids.forEach(function (k) { form[k].value = gen[k].value; });
    render();
  }
  function pullFromForm() {
    ids.forEach(function (k) { gen[k].value = form[k].value; });
    render();
  }

  ids.forEach(function (k) {
    gen[k].addEventListener('input', pushToForm);
    form[k].addEventListener('input', pullFromForm);
  });

  // ── نمایش/پنهانِ رمز ──
  var pwToggle = document.getElementById('pw-toggle');
  if (pwToggle) {
    pwToggle.addEventListener('click', function () {
      var showing = gen.password.type === 'text';
      gen.password.type = showing ? 'password' : 'text';
      pwToggle.textContent = showing ? pwToggle.dataset.show : pwToggle.dataset.hide;
    });
  }

  // ── رمزِ تازه (همان الفبای سمت سرور: بدون نویسهٔ مبهم و بدون کاراکتر خاص) ──
  var ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  var regen = document.getElementById('pw-regen');
  if (regen) {
    regen.addEventListener('click', function () {
      var out = '';
      var buf = new Uint32Array(20);
      (window.crypto || window.msCrypto).getRandomValues(buf);
      for (var i = 0; i < 20; i++) out += ALPHABET[buf[i] % ALPHABET.length];
      gen.password.value = out;
      gen.password.type = 'text';
      if (pwToggle) pwToggle.textContent = pwToggle.dataset.hide;
      pushToForm();
    });
  }

  // ── کپی ──
  function copyText(text, btn, okLabel, failLabel) {
    function done() {
      if (!btn) return;
      var before = btn.textContent;
      btn.textContent = okLabel;
      btn.classList.add('done');
      setTimeout(function () { btn.textContent = before; btn.classList.remove('done'); }, 1400);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(); });
      return;
    }
    fallback();

    function fallback() {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        ok ? done() : alert(failLabel);
      } catch (e) { alert(failLabel); }
    }
  }

  var COPY_OK = @json($g('gen_copied')), COPY_FAIL = @json($g('gen_copy_failed'));

  document.querySelectorAll('[data-copy-closest]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var code = btn.parentElement.querySelector('code.cmd');
      copyText(code ? code.textContent : '', btn, COPY_OK, COPY_FAIL);
    });
  });
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.querySelector(btn.getAttribute('data-copy'));
      copyText(el ? el.textContent : '', btn, COPY_OK, COPY_FAIL);
    });
  });
  var pwCopy = document.getElementById('pw-copy');
  if (pwCopy) {
    pwCopy.addEventListener('click', function () {
      copyText(gen.password.value, pwCopy, COPY_OK, COPY_FAIL);
    });
  }

  render();
})();
</script>
@endsection
