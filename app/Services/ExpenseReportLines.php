<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\ExpenseLine;
use App\Models\UsdRate;
use Illuminate\Support\Collection;

/**
 * Expense lines as the trip profit report counts them (Expense UAT req. 4, 18, 24, 26):
 * - USD kept unconverted on the form uses the USD rate of the period at report time (else the order's rate);
 * - a line without trucks (e.g. driver's fare on form 163) is split equally over the order's trucks;
 * - Office orders never reach a trip; rejected orders are left out.
 */
class ExpenseReportLines
{
    /**
     * @return Collection<int, array{amount: float, category: string, date: string, description: string, line: ExpenseLine, quantity: mixed, rate: mixed, share_of: ?int, subcategory: ?string, unit: string, value: mixed}>
     */
    public static function forLeg(BookingTruck $leg): Collection
    {
        $own = ExpenseLine::with('expenseOrder.subcategory')
            ->where('booking_truck_id', $leg->id)
            ->whereHas('expenseOrder', fn ($q) => $q->where('status', '!=', 'rejected'))
            ->get()
            ->map(fn (ExpenseLine $l) => self::row($l, self::tzs($l)));

        // Flat lines (no truck) on orders that include this leg, shared over the order's trucks.
        $orderIds = ExpenseLine::where('booking_truck_id', $leg->id)->distinct()->pluck('expense_order_id');
        $shared = ExpenseLine::with('expenseOrder.subcategory')
            ->whereIn('expense_order_id', $orderIds)
            ->whereNull('booking_truck_id')
            ->where('is_flat', true)
            ->whereHas('expenseOrder', fn ($q) => $q->where('status', '!=', 'rejected'))
            ->get()
            ->map(function (ExpenseLine $l) {
                $trucks = max(1, ExpenseLine::where('expense_order_id', $l->expense_order_id)->whereNotNull('booking_truck_id')->distinct()->count('booking_truck_id'));

                return [...self::row($l, round(self::tzs($l) / $trucks, 2)), 'share_of' => $trucks];
            });

        return $own->concat($shared)->sortBy('date')->values();
    }

    /** TZS value of a line for reports. */
    public static function tzs(ExpenseLine $line): float
    {
        $order = $line->expenseOrder;
        if ($line->currency !== 'USD' || !$order?->keepsUsdSeparate()) {
            return (float) $line->amount;
        }

        $date = $order->payment_date ?? $order->created_at?->toDateString() ?? now()->toDateString();
        $rate = (UsdRate::forDate((string) $date) ?? (float) ($order->usd_rate ?? 0)) ?: (float) $line->exchange_rate;

        return round((float) $line->original_amount * $rate, 2);
    }

    private static function row(ExpenseLine $line, float $amount): array
    {
        $isFuel = $line->line_category === 'fuel';

        return [
            'amount' => $amount,
            'category' => $line->line_category,
            'date' => $line->created_at->format('Y-m-d'),
            'description' => $line->description,
            'line' => $line,
            'quantity' => $line->quantity,
            'rate' => $isFuel ? $line->unit_rate : $line->exchange_rate,
            'share_of' => null,
            'subcategory' => $line->expenseOrder?->subcategory?->name,
            'unit' => $isFuel ? 'Ltr' : $line->currency,
            'value' => $isFuel ? $line->quantity : $line->original_amount,
        ];
    }
}
