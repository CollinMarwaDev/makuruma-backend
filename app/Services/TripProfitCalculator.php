<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\InvoiceLine;
use App\Models\Trip;

class TripProfitCalculator
{
    /**
     * Revenue summary for one leg (Go or Return), pulled from every
     * Invoice line ever attached to that specific booking_truck —
     * across Advance/Settlement/Standing Time/Adjustment combined.
     */
    public static function legRevenueSummary(?BookingTruck $bookingTruck): array
    {
        if (!$bookingTruck) {
            return ['uzito' => 0, 'bei' => 0, 'amount' => 0, 'exchange' => 0, 'jumla' => 0];
        }

        $lines = InvoiceLine::where('booking_truck_id', $bookingTruck->id)->with('invoice')->get();

        $amount = (float) $lines->sum('amount');
        $uzito = (float) $lines->filter(fn($l) => $l->invoice?->invoice_type !== 'standing_time')->sum('quantity');
        $jumla = (float) $lines->sum(fn($l) => $l->amount * ($l->invoice?->exchange_rate ?: 1));

        $bei = $uzito > 0 ? round($amount / $uzito, 2) : (float) ($bookingTruck->rate ?? 0);
        $exchange = $amount > 0 ? round($jumla / $amount, 4) : 0;

        return [
            'uzito' => round($uzito, 3),
            'bei' => $bei,
            'amount' => round($amount, 2),
            'exchange' => $exchange,
            'jumla' => round($jumla, 2),
        ];
    }

    public static function legExpenseTotal(?BookingTruck $bookingTruck): float
    {
        if (!$bookingTruck)
            return 0;

        return round((float) ExpenseReportLines::forLeg($bookingTruck)->sum('amount'), 2);
    }

    public static function tripProfit(Trip $trip): float
    {
        $goRevenue = self::legRevenueSummary($trip->goBookingTruck)['jumla'];
        $returnRevenue = self::legRevenueSummary($trip->returnBookingTruck)['jumla'];

        $expenses = self::legExpenseTotal($trip->goBookingTruck) + self::legExpenseTotal($trip->returnBookingTruck);

        return round(($goRevenue + $returnRevenue) - $expenses, 2);
    }
}