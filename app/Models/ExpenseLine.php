<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_order_id',
        'line_category',
        'vendor_id',
        'booking_truck_id',
        'truck_id',
        'driver_name',
        'filled_at',
        'group_key',
        'description',
        'currency',
        'exchange_rate',
        'quantity',
        'unit_rate',
        'original_amount',
        'amount',
        'amount_description',
        'is_additional',
        'is_flat',
        'multiplier',
        'payee',
    ];

    protected $casts = ['filled_at' => 'date:Y-m-d', 'is_additional' => 'boolean', 'is_flat' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (ExpenseLine $line) {
            if ($line->quantity !== null && $line->unit_rate !== null) {
                $line->original_amount = round($line->quantity * $line->unit_rate, 2);
            } elseif ($line->multiplier !== null && $line->unit_rate !== null) {
                // Vibali: unit amount × multiplier, e.g. 300 × 10 gates = 3,000 per truck (req. 15).
                $line->original_amount = round($line->unit_rate * $line->multiplier, 2);
            }

            if ($line->unit_rate === null && $line->line_category === 'fuel') {
                // Fuel price not known yet (req. 7): no amount until the price is entered.
                $line->original_amount = null;
            }

            if ($line->original_amount !== null) {
                $rate = $line->currency === 'TZS' ? 1 : (float) ($line->exchange_rate ?? 1);
                $line->amount = round($line->original_amount * $rate, 2);
            } else {
                $line->amount = 0;
            }
        });
    }

    public function expenseOrder(): BelongsTo
    {
        return $this->belongsTo(ExpenseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bookingTruck(): BelongsTo
    {
        return $this->belongsTo(BookingTruck::class);
    }

    /** The truck of a Truck-category line (Trip lines use bookingTruck). */
    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }
}
