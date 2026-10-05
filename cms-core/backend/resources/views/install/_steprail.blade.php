@php
  // StepRail راست‌به‌چپ: ترتیب از بالا (شروع) به پایین (پایان).
  $steps = [
    'preflight'  => '۱. پیش‌نیازها',
    'database'   => '۲. پایگاه داده',
    'app_key'    => '۳. کلید برنامه',
    'migrate'    => '۴. مهاجرت‌ها',
    'superadmin' => '۵. سوپرادمین',
    'finalize'   => '۶. اتمام',
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
