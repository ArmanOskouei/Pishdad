@extends('install.layout')

@section('title', 'گام ۱ — پیش‌نیازها')

@section('content')
<h2>گام ۱ — پیش‌نیازها</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

@include('install._preflight_table', ['checks' => $checks])

<div class="actions">
  <form method="post" action="{{ route('install.preflight.confirm') }}">
    <input type="hidden" name="install_token" value="{{ $token }}">
    <button class="btn" type="submit" {{ $blocking ? 'disabled' : '' }}>ادامه به پایگاه داده</button>
  </form>
  <form method="get" action="{{ route('install.preflight') }}">
    <button class="btn" type="submit" style="background:#4b5563">بررسی دوباره</button>
  </form>
</div>
@if ($blocking)
  <p class="hint">تا خطاها رفع نشوند ادامه ممکن نیست (دکمه غیرفعال می‌ماند). راه‌حل هر ردیف در ستون «راه‌حل» آمده است.</p>
@endif
@endsection
