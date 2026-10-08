@php
  $fa = ['pass' => __('install.status.pass'), 'warn' => __('install.status.warn'), 'pending' => __('install.status.pending'), 'fail' => __('install.status.fail')];
@endphp
<table class="checks">
  <thead>
    <tr>
      <th scope="col">{{ __('install.table.check') }}</th>
      <th scope="col">{{ __('install.table.status') }}</th>
      <th scope="col">{{ __('install.table.detail') }}</th>
      <th scope="col">{{ __('install.table.remedy') }}</th>
    </tr>
  </thead>
  <tbody>
  @foreach ($checks as $check)
    <tr>
      <td>{{ $check['label'] }}</td>
      <td><span class="badge {{ $check['status'] }}">{{ $fa[$check['status']] ?? $check['status'] }}</span></td>
      <td>{{ $check['detail'] }}</td>
      <td class="remedy">{{ $check['remedy'] !== '' ? $check['remedy'] : __('install.table.none') }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
