@extends('install.layout')

@section('title', 'گام ۲ — پایگاه داده')

@section('content')
<h2>گام ۲ — پایگاه داده (Postgres)</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif
@php $old = $old ?? []; @endphp

<form method="post" action="{{ route('install.database.save') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">

  <label class="f" for="db-host">هاست</label>
  <input class="f" dir="ltr" id="db-host" name="host" required maxlength="255" value="{{ $old['host'] ?? $config['host'] ?? '' }}">

  <label class="f" for="db-port">پورت</label>
  <input class="f" dir="ltr" id="db-port" name="port" required inputmode="numeric" value="{{ $old['port'] ?? $config['port'] ?? '5432' }}">

  <label class="f" for="db-database">نام دیتابیس</label>
  <input class="f" dir="ltr" id="db-database" name="database" required maxlength="63" value="{{ $old['database'] ?? $config['database'] ?? '' }}">

  <label class="f" for="db-username">کاربر</label>
  <input class="f" dir="ltr" id="db-username" name="username" required maxlength="63" value="{{ $old['username'] ?? $config['username'] ?? '' }}">

  <label class="f" for="db-password">رمز</label>
  <input class="f" dir="ltr" id="db-password" name="password" type="password" autocomplete="new-password" value="">

  <div class="actions">
    <button class="btn" type="submit">آزمایش اتصال و ادامه</button>
  </div>
  <p class="hint">اتصال واقعی و مجوز واقعی CREATE COLLATION سنجیده می‌شود؛ رمز هرگز برنمی‌گردد.</p>
</form>
@endsection
