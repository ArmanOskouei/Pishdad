@extends('install.layout')

@section('title', 'گام ۴ — مهاجرت‌ها')

@section('content')
<h2>گام ۴ — مهاجرت‌های دیتابیس</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

@if ($ran)
  <div class="okmsg">مهاجرت‌ها قبلاً اجرا شده‌اند (resume از ژورنال).</div>
@endif

<p>جدول‌های هسته با <code dir="ltr">php artisan migrate --force</code> ساخته می‌شوند. این کار ممکن است کمی طول بکشد.</p>

<form method="post" action="{{ route('install.migrate.run') }}" onsubmit="return confirm('مهاجرت‌ها اجرا شوند؟');">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">اجرای مهاجرت‌ها و ادامه</button>
  </div>
</form>
@endsection
