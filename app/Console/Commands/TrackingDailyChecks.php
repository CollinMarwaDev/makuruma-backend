<?php

namespace App\Console\Commands;

use App\Services\TrackingLegService;
use Illuminate\Console\Command;

/** Runs the 5-day tracking rules: auto-complete offloaded legs and alert on missing PODs. */
class TrackingDailyChecks extends Command
{
    protected $signature = 'tracking:daily-checks';

    protected $description = 'Auto-complete legs offloaded 5+ days ago (notify accountants) and alert on overdue PODs';

    public function handle(TrackingLegService $service): int
    {
        // POD check first so a leg that is auto-completed today still gets its POD alert.
        $pods = $service->alertOverduePods();
        $completed = $service->autoCompleteOffloaded();

        $this->info("POD alerts: {$pods}; auto-completed legs: {$completed}");

        return self::SUCCESS;
    }
}
