@extends('install.layout')

@section('title', __('install.prepare.title'))

@section('content')
<h2>{{ __('install.prepare.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

<div class="okmsg" role="status">{{ __('install.prepare.success') }}</div>

@php $db = $db ?? []; @endphp
<table class="checks">
  <tr><th>{{ __('install.prepare.th_host') }}</th><td dir="ltr">{{ $db['host'] ?? '—' }}:{{ $db['port'] ?? '—' }}</td></tr>
  <tr><th>{{ __('install.prepare.th_db') }}</th><td dir="ltr">{{ $db['database'] ?? '—' }}</td></tr>
  <tr><th>{{ __('install.prepare.th_user') }}</th><td dir="ltr">{{ $db['username'] ?? '—' }}</td></tr>
</table>

<p>{{ __('install.prepare.intro') }}</p>
<ol>
  <li><b>{{ __('install.prepare.item_key') }}</b> {{ __('install.prepare.item_key_note') }}</li>
  <li><b>{{ __('install.prepare.item_migrate') }}</b> {{ __('install.prepare.item_migrate_note') }}</li>
</ol>

<style>
  #prepProgress { display:none; margin-top:16px; }
  .pbar-shell { background:#e5e7eb; border-radius:999px; overflow:hidden; height:14px; }
  .pbar-fill { height:100%; width:0%; background:var(--brand); border-radius:999px;
    transition:width .4s ease; }
  .pbar-fill.err { background:var(--fail); }
  .pbar-top { display:flex; justify-content:space-between; font-size:13px; color:var(--muted); margin-bottom:6px; }
  ol.phases { list-style:none; margin:12px 0 0; padding:0; font-size:13.5px; }
  ol.phases li { padding:6px 0 6px 26px; position:relative; color:var(--muted); }
  html[dir="ltr"] ol.phases li { padding:6px 26px 6px 0; }
  ol.phases li::before { content:"○"; position:absolute; inset-inline-start:4px; }
  ol.phases li.active { color:var(--ink); font-weight:bold; }
  ol.phases li.active::before { content:"◌"; color:var(--brand); }
  ol.phases li.done { color:var(--ok); }
  ol.phases li.done::before { content:"✓"; color:var(--ok); }
  ol.phases li.fail { color:var(--fail); font-weight:bold; }
  ol.phases li.fail::before { content:"✗"; color:var(--fail); }
  #prepError { display:none; margin-top:12px; }
  #prepError pre.log { direction:ltr; text-align:left; background:#1c1c1e; color:#e5e7eb;
    border-radius:8px; padding:10px 12px; font-size:11.5px; max-height:220px; overflow:auto;
    white-space:pre-wrap; word-break:break-all; font-family:Consolas,"Courier New",monospace; }
  #prepError details { margin-top:8px; font-size:13px; }
  #prepError summary { cursor:pointer; color:var(--brand); }
</style>

<div id="prepBox"
  data-key-url="{{ route('install.prepare.key') }}"
  data-migrate-url="{{ route('install.prepare.migrate') }}">
  <form id="prepForm" method="post" action="{{ route('install.prepare.run') }}">
    <input type="hidden" name="install_token" id="prepToken" value="{{ $token }}">
    <div class="actions">
      <button class="btn" id="prepBtn" type="submit">{{ __('install.prepare.submit') }}</button>
    </div>
    <p class="hint">{{ __('install.prepare.hint') }}</p>
  </form>

  <div id="prepProgress" aria-live="polite">
    <div class="pbar-top"><span>{{ __('install.prepare.progress_label') }}</span><span id="prepPct">0%</span></div>
    <div class="pbar-shell"><div class="pbar-fill" id="prepFill"></div></div>
    <ol class="phases">
      <li id="phKey">{{ __('install.prepare.phase_key') }}</li>
      <li id="phMigrate">{{ __('install.prepare.phase_migrate') }}</li>
      <li id="phDone">{{ __('install.prepare.phase_redirect') }}</li>
    </ol>
  </div>

  <div id="prepError" role="alert">
    <div class="err"><b>{{ __('install.prepare.error_title') }}</b><br><span id="prepDiag"></span></div>
    <details>
      <summary>{{ __('install.prepare.error_log') }}</summary>
      <pre class="log" id="prepLog"></pre>
    </details>
    <div class="actions">
      <button class="btn" id="prepRetry" type="button">{{ __('install.prepare.retry') }}</button>
    </div>
  </div>
</div>

<script>
(function () {
  var box = document.getElementById('prepBox');
  var form = document.getElementById('prepForm');
  var btn = document.getElementById('prepBtn');
  var prog = document.getElementById('prepProgress');
  var fill = document.getElementById('prepFill');
  var pct = document.getElementById('prepPct');
  var errBox = document.getElementById('prepError');
  var diag = document.getElementById('prepDiag');
  var log = document.getElementById('prepLog');
  var token = document.getElementById('prepToken').value;
  var phKey = document.getElementById('phKey');
  var phMigrate = document.getElementById('phMigrate');
  var phDone = document.getElementById('phDone');
  var tick = null;

  function setBar(n) { fill.style.width = n + '%'; pct.textContent = n + '%'; }
  function mark(el, st) { el.className = st; }

  function post(url) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ install_token: token })
    }).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    });
  }

  function fail(message, diagnosis, logText) {
    if (tick) clearInterval(tick);
    fill.classList.add('err');
    mark(phKey, phKey.className === 'done' ? 'done' : 'fail');
    if (phMigrate.className === 'active') mark(phMigrate, 'fail');
    diag.textContent = (diagnosis || message || '');
    log.textContent = logText || '';
    errBox.style.display = 'block';
    btn.disabled = false;
  }

  function run() {
    errBox.style.display = 'none';
    form.style.display = 'none';
    prog.style.display = 'block';
    btn.disabled = true;
    setBar(5);
    mark(phKey, 'active');

    post(box.dataset.keyUrl).then(function (res) {
      if (res.status !== 200) { fail(res.body.message, res.body.diagnosis, res.body.log); return; }
      mark(phKey, 'done');
      setBar(30);
      mark(phMigrate, 'active');
      var n = 30;
      tick = setInterval(function () { if (n < 88) { n += 2; setBar(n); } }, 900);
      return post(box.dataset.migrateUrl).then(function (res2) {
        if (tick) clearInterval(tick);
        if (res2.status !== 200) { fail(res2.body.message, res2.body.diagnosis, res2.body.log); return; }
        mark(phMigrate, 'done');
        mark(phDone, 'active');
        setBar(100);
        window.location = res2.body.data.next;
      });
    }).catch(function (e) {
      fail(String((e && e.message) || e), '', '');
    });
  }

  document.getElementById('prepRetry').addEventListener('click', run);
  form.addEventListener('submit', function (ev) { ev.preventDefault(); run(); });
})();
</script>
@endsection
