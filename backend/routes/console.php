<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deliver member SMS from the outbox (runs under `php artisan schedule:work`).
Schedule::command('sms:send')->everyMinute()->withoutOverlapping();
