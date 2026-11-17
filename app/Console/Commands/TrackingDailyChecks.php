<?php

namespace App\Console\Commands;

use App\Services\TrackingLegService;
use Illuminate\Console\Command;

/** Runs the 5-day tracking rules: ready-for-invoicing notice and alert on missing PODs. */
class TrackingDailyChecks extends Command
{
    protected $signature = 'tracking:daily-checks';

    protected $description = 'Tell accountants about legs offloaded 5+ days ago and alert on overdue PODs';

    public function handle(TrackingLegService $service): int
    {
        $pods = $service->alertOverduePods();
        $ready = $service->notifyReadyForInvoicing();

        $this->info("POD alerts: {$pods}; ready-for-invoicing notices: {$ready}");

        return self::SUCCESS;
    }
}
