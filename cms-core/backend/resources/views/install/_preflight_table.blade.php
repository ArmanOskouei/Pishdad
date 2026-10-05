@php
  $fa = ['pass' => 'قبول', 'warn' => 'هشدار', 'fail' => 'خطا'];
@endphp
<table class="checks">
  <thead>
    <tr>
      <th scope="col">بررسی</th>
      <th scope="col">وضعیت</th>
      <th scope="col">جزئیات</th>
      <th scope="col">راه‌حل</th>
    </tr>
  </thead>
  <tbody>
  @foreach ($checks as $check)
    <tr>
      <td>{{ $check['label'] }}</td>
      <td><span class="badge {{ $check['status'] }}">{{ $fa[$check['status']] ?? $check['status'] }}</span></td>
      <td>{{ $check['detail'] }}</td>
      <td class="remedy">{{ $check['remedy'] !== '' ? $check['remedy'] : '—' }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
