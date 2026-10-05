@extends('install.layout')

@section('title', 'گام ۶ — اتمام')

@section('content')
<h2>گام ۶ — بازبینی و اتمام</h2>

<p>همه گام‌ها انجام شده‌اند. با اتمام، قفل نصب (<code dir="ltr">install.lock</code>) نوشته می‌شود و مسیرهای <code dir="ltr">/install</code> برای همیشه ۴۰۴ می‌شوند.</p>

@if ($email !== '')
  <p>سوپرادمین: <strong dir="ltr">{{ $email }}</strong></p>
@endif
<p class="hint">اولین ورود این حساب، فعال‌سازی تأیید دومرحله‌ای را اجبار می‌کند.</p>

<form method="post" action="{{ route('install.finalize.run') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">اتمام نصب</button>
  </div>
</form>
@endsection
