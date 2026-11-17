<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** USD→TZS rate for a date range; used only by the profit report, never printed. */
class UsdRate extends Model
{
    protected $fillable = ['starts_on', 'ends_on', 'rate'];

    protected $casts = [
        'ends_on' => 'date:Y-m-d',
        'starts_on' => 'date:Y-m-d',
    ];

    /** Rate covering the date, or null. */
    public static function forDate(string $date): ?float
    {
        $rate = static::whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
