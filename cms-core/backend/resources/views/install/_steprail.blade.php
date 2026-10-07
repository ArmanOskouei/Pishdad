@php
  // StepRail: ترتیب از بالا (شروع) به پایین (پایان). برچسب‌ها از lang می‌آیند (E20).
  $labels = [
    'preflight'  => __('install.rail.preflight'),
    'database'   => __('install.rail.database'),
    'app_key'    => __('install.rail.app_key'),
    'migrate'    => __('install.rail.migrate'),
    'superadmin' => __('install.rail.superadmin'),
    'finalize'   => __('install.rail.finalize'),
  ];

  $order = \App\Http\Controllers\Install\InstallJournal::STEPS;
  $pos = array_search($current ?? 'preflight', $order, true);
  $pos = $pos === false ? 0 : $pos;

  /**
   * E34 — کدام گام‌ها قابل‌رفتن‌اند؟
   *
   * همان قاعده‌ای که `ensureStepReachable()` در کنترلر اعمال می‌کند: گامِ i باز
   * است اگر **همهٔ گام‌های پیش از آن** تمام شده باشند. گامِ اول (preflight)
   * همیشه باز است.
   *
   * چرا اینجا حساب می‌شود و نه در کنترلر: این نوار در `layout` رندر می‌شود و
   * هر شش صفحهٔ گام از همان استفاده می‌کنند. اگر فقط گامِ جاری را لینک می‌کردیم،
   * کاربر هیچ راهی برای **دیدنِ دوبارهٔ** گام‌های قبلی نداشت — و لینک‌کردنِ گامِ
   * دست‌نیافتنی یعنی فرستادنِ کاربر به یک ۴۰۴.
   */
  $reachable = [];
  $previousAllDone = true;
  foreach ($order as $key) {
      $reachable[$key] = $previousAllDone;
      if (! \App\Http\Controllers\Install\InstallJournal::isStepDone($key)) {
          $previousAllDone = false;
      }
  }
@endphp
<ol class="rail">
@foreach ($order as $i => $key)
  @php $state = $i < $pos ? 'done' : ($i === $pos ? 'current' : ''); @endphp
  <li class="{{ $state }}" @if($i === $pos) aria-current="step" @endif>
    @if (! empty($reachable[$key]))
      <a href="{{ route(\App\Http\Controllers\Install\InstallController::STEP_ROUTES[$key]) }}"
         class="rail-link" title="{{ __('install.rail.go_to', ['step' => $labels[$key] ?? $key]) }}">
        <span class="dot">@if($i < $pos) ✓ @else {{ $i + 1 }} @endif</span>
        <span>{{ $labels[$key] ?? $key }}</span>
      </a>
    @else
      <span class="rail-item">
        <span class="dot">@if($i < $pos) ✓ @else {{ $i + 1 }} @endif</span>
        <span>{{ $labels[$key] ?? $key }}</span>
      </span>
    @endif
  </li>
@endforeach
</ol>
