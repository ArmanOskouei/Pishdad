@extends('install.layout')

@section('title', 'گام ۳ — کلید برنامه')

@section('content')
<h2>گام ۳ — کلید برنامه (APP_KEY)</h2>

@if ($valid)
  <div class="okmsg">کلید معتبر از قبل تنظیم شده است؛ می‌توانید بدون تغییر ادامه دهید.</div>
@else
  <p>کلیدی تنظیم نشده است. با دکمه زیر یک کلید تصادفی ۳۲بایتی ساخته و در <code dir="ltr">.env</code> ذخیره می‌شود.</p>
@endif

<form method="post" action="{{ route('install.app-key.generate') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">{{ $valid ? 'ادامه با همین کلید' : 'ساخت کلید و ادامه' }}</button>
  </div>
</form>
@endsection
