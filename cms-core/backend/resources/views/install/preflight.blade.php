@extends('install.layout')

@section('title', __('install.preflight.title'))

@section('content')
<h2>{{ __('install.preflight.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

@include('install._preflight_table', ['checks' => $checks])

<div class="actions">
  <form method="post" action="{{ route('install.preflight.confirm') }}">
    <input type="hidden" name="install_token" value="{{ $token }}">
    <button class="btn" type="submit" {{ $blocking ? 'disabled' : '' }}>{{ __('install.preflight.continue') }}</button>
  </form>
  <form method="get" action="{{ route('install.preflight') }}">
    <button class="btn" type="submit" style="background:#4b5563">{{ __('install.preflight.recheck') }}</button>
  </form>
</div>
@if ($blocking)
  <p class="hint">{{ __('install.preflight.blocked_hint') }}</p>
@endif
@endsection
