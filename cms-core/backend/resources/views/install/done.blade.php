@extends('install.layout')

@section('title', 'نصب کامل شد')

@section('content')
<h2>نصب کامل شد ✓</h2>

<div class="okmsg">قفل نصب نوشته شد؛ مسیرهای نصب از این پس در دسترس نیستند.</div>

@if ($email !== '')
  <p>سوپرادمین: <strong dir="ltr">{{ $email }}</strong></p>
@endif
<p>قدم بعدی: ورود با همین حساب. در اولین ورود باید تأیید دومرحله‌ای (TOTP) را فعال کنید؛ تا قبلش هیچ API مدیریتی جواب نمی‌دهد.</p>
@endsection
