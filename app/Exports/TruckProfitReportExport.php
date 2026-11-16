<?php

namespace App\Exports;

use App\Models\Truck;
use App\Services\TripProfitCalculator;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TruckProfitReportExport implements WithMultipleSheets
{
    public function __construct(protected Truck $truck, protected int $year)
    {
    }

    public function sheets(): array
    {
        $trips = $this->truck->trips()
            // By the Go booking's year; a trip with only a Return half (older data) by the Return's year, so it is never hidden.
            ->where(fn ($q) => $q
                ->whereHas('goBookingTruck.booking', fn ($b) => $b->whereYear('created_at', $this->year))
                ->orWhere(fn ($r) => $r->whereNull('go_booking_truck_id')
                    ->whereHas('returnBookingTruck.booking', fn ($b) => $b->whereYear('created_at', $this->year))))
            ->with(['goBookingTruck.booking.client', 'returnBookingTruck.booking.client'])
            ->orderBy('id')
            ->get();

        $sheets = [];
        $tripProfits = [];

        foreach ($trips as $index => $trip) {
            $sheets[] = new TripSheetExport($trip, $index + 1);
            $tripProfits[] = TripProfitCalculator::tripProfit($trip);
        }

        $sheets[] = new AnnualSheetExport($this->truck, $this->year, $tripProfits);

        return $sheets;
    }
}