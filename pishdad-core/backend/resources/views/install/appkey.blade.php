@extends('install.layout')

@section('title', __('install.appkey.title'))

@section('content')
<h2>{{ __('install.appkey.heading') }}</h2>

@if ($valid)
  <div class="okmsg">{{ __('install.appkey.valid') }}</div>
@else
  <p>{{ __('install.appkey.missing') }} <code dir="ltr">.env</code></p>
@endif

<form method="post" action="{{ route('install.app-key.generate') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">{{ $valid ? __('install.appkey.continue_valid') : __('install.appkey.generate') }}</button>
  </div>
</form>
@endsection
