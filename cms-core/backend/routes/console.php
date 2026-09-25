<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// طیف Billing مرکزی (PLAN.md): یادآوری روزانه D-7..D-0، تعلیق D-0، حذف D+7.
Schedule::command('billing:remind')->dailyAt('08:00');
Schedule::command('billing:suspend')->dailyAt('08:15');
Schedule::command('billing:purge')->dailyAt('08:30');
