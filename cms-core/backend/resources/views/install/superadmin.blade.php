@extends('install.layout')

@section('title', 'گام ۵ — سوپرادمین')

@section('content')
<h2>گام ۵ — حساب سوپرادمین</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif
@php $old = $old ?? []; @endphp

<p>حساب مخفی و حذف‌نشدنی مدیر ارشد. تأیید دومرحله‌ای در <strong>اولین ورود</strong> اجباری می‌شود (هیچ سکرتی چاپ/نمایش داده نمی‌شود).</p>

<form method="post" action="{{ route('install.superadmin.save') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">

  <label class="f" for="sa-name">نام نمایشی</label>
  <input class="f" id="sa-name" name="name" required maxlength="255" value="{{ $old['name'] ?? 'سوپرادمین' }}">

  <label class="f" for="sa-email">ایمیل</label>
  <input class="f" dir="ltr" id="sa-email" name="email" type="email" required maxlength="255" value="{{ $old['email'] ?? '' }}">

  <label class="f" for="sa-password">رمز عبور (حداقل ۸ نویسه)</label>
  <input class="f" dir="ltr" id="sa-password" name="password" type="password" required minlength="8" autocomplete="new-password" value="">

  <label class="f" for="sa-password-confirm">تکرار رمز عبور</label>
  <input class="f" dir="ltr" id="sa-password-confirm" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" value="">

  <div class="actions">
    <button class="btn" type="submit">ساخت حساب و ادامه</button>
  </div>
</form>
@endsection
