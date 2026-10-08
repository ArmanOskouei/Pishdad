@extends('install.layout')

@section('title', __('install.superadmin.title'))

@section('content')
<h2>{{ __('install.superadmin.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif
@php $old = $old ?? []; @endphp

<p>{{ __('install.superadmin.intro') }}</p>

<form method="post" action="{{ route('install.superadmin.save') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">

  <label class="f" for="sa-name">{{ __('install.superadmin.name') }}</label>
  <input class="f" id="sa-name" name="name" required maxlength="255" value="{{ $old['name'] ?? __('install.superadmin.default_name') }}">

  <label class="f" for="sa-email">{{ __('install.superadmin.email') }}</label>
  <input class="f" dir="ltr" id="sa-email" name="email" type="email" required maxlength="255" value="{{ $old['email'] ?? '' }}">

  {{-- E57 — سروری که همین حالا در آن هستید فقط بک‌اند است، پس نشانیِ سایتِ
       فرانت را کاربر می‌دهد. همین مقدار است که لینک‌های صفحهٔ پایانی را
       درست می‌کند («ورود به سایت» و «ورود به پنل»). --}}
  <label class="f" for="sa-frontend">{{ __('install.superadmin.frontend_url') }}</label>
  <input class="f" dir="ltr" id="sa-frontend" name="frontend_url" type="url" maxlength="255" placeholder="https://example.com" value="{{ $old['frontend_url'] ?? ($frontendUrl ?? '') }}">
  <p class="hint">{{ __('install.superadmin.frontend_url_hint') }}</p>

  <label class="f" for="sa-password">{{ __('install.superadmin.password') }}</label>
  <input class="f" dir="ltr" id="sa-password" name="password" type="password" required minlength="8" autocomplete="new-password" value="">

  <label class="f" for="sa-password-confirm">{{ __('install.superadmin.password_confirm') }}</label>
  <input class="f" dir="ltr" id="sa-password-confirm" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" value="">

  <div class="actions">
    <button class="btn" type="submit">{{ __('install.superadmin.submit') }}</button>
  </div>
</form>
@endsection
