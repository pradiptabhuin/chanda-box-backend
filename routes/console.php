<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Retry Route transfers that failed right after capture (e.g. Razorpay was briefly unreachable).
Schedule::command('payments:transfer')->everyTenMinutes()->withoutOverlapping();
