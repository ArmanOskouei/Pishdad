@extends('install.layout')

@section('title', __('install.finalize.title'))

@section('content')
<h2>{{ __('install.finalize.heading') }}</h2>

<p>{{ __('install.finalize.intro') }}</p>

@if ($email !== '')
  <p>{{ __('install.finalize.superadmin_is') }} <strong dir="ltr">{{ $email }}</strong></p>
@endif
<p class="hint">{{ __('install.finalize.hint') }}</p>

<form method="post" action="{{ route('install.finalize.run') }}">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">{{ __('install.finalize.submit') }}</button>
  </div>
</form>
@endsection
