<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TruckMilestone extends Model
{
    use HasFactory;

    protected $fillable = ['truck_id', 'booking_truck_id', 'checkpoint_id', 'arrival_at', 'dispatch_at'];

    protected $casts = [
        'arrival_at' => 'datetime',
        'dispatch_at' => 'datetime',
    ];

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }
}