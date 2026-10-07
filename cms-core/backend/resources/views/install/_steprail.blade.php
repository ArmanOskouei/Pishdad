@php
  // StepRail: ترتیب از بالا (شروع) به پایین (پایان). برچسب‌ها از lang می‌آیند (E20).
  $steps = [
    'preflight'  => __('install.rail.preflight'),
    'database'   => __('install.rail.database'),
    'app_key'    => __('install.rail.app_key'),
    'migrate'    => __('install.rail.migrate'),
    'superadmin' => __('install.rail.superadmin'),
    'finalize'   => __('install.rail.finalize'),
  ];
  $order = array_keys($steps);
  $pos = array_search($current ?? 'preflight', $order, true);
  $pos = $pos === false ? 0 : $pos;
@endphp
<ol class="rail">
@foreach ($steps as $key => $label)
  @php $i = array_search($key, $order, true); @endphp
  <li class="{{ $i < $pos ? 'done' : ($i === $pos ? 'current' : '') }}" @if($i === $pos) aria-current="step" @endif>
    <span class="dot">@if($i < $pos) ✓ @else {{ $i + 1 }} @endif</span>
    <span>{{ $label }}</span>
  </li>
@endforeach
</ol>
