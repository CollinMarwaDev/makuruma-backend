<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Contract 2.2 D-6/D-7: auto-complete 5 days after offloading and alert on missing PODs.
// Runs hourly so a newly overdue leg is handled the same day; the checks are idempotent.
Schedule::command('tracking:daily-checks')->hourly()->withoutOverlapping();

// Expense UAT req. 10: fuel orders still without a price after 14 days alert the accountants once.
Schedule::command('expenses:pending-price-check')->hourly()->withoutOverlapping();
