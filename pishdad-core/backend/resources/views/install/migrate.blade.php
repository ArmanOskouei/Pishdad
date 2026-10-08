@extends('install.layout')

@section('title', __('install.migrate.title'))

@section('content')
<h2>{{ __('install.migrate.heading') }}</h2>

@if (! empty($formError ?? null))
  <div class="err" role="alert">{{ $formError }}</div>
@endif

@if ($ran)
  <div class="okmsg">{{ __('install.migrate.ran') }}</div>
@endif

<p>{{ __('install.migrate.intro') }} <code dir="ltr">php artisan migrate --force</code></p>

<form method="post" action="{{ route('install.migrate.run') }}" onsubmit="return confirm('{{ __('install.migrate.confirm') }}');">
  <input type="hidden" name="install_token" value="{{ $token }}">
  <div class="actions">
    <button class="btn" type="submit">{{ __('install.migrate.submit') }}</button>
  </div>
</form>
@endsection
