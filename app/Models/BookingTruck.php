<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BookingTruck extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'trip_id',
        'truck_id',
        'trailer_id',
        'driver_id',
        'capacity',
        'cargo',
        'rate',
        'loading_point',
        'offloading_point',
        'eta',
        'current_status',
        'current_location',
        'route',
        'loading_point_arrival_date',
        'loading_date',
        'loading_dispatch_date',
        'offloading_point_arrival_date',
        'offloading_date',
        'removed_at',
        'completed_at',
        'auto_completed_at',
        'pod_overdue_notified_at',
    ];

    /** Days after offloading before a leg auto-completes and a missing POD is overdue (contract 2.2 D-6/7). */
    public const GRACE_DAYS = 5;

    protected $casts = [
        'completed_at' => 'datetime',
        'auto_completed_at' => 'datetime',
        'pod_overdue_notified_at' => 'datetime',
    ];

    protected $appends = ['is_overdue', 'pod_overdue'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Trailer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Checkpoint arrival/dispatch dates for this leg. */
    public function milestones(): HasMany
    {
        return $this->hasMany(TruckMilestone::class);
    }

    public function podDocuments(): MorphMany
    {
        return $this->documents()->where('document_type', 'POD');
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function getIsOverdueAttribute(): bool
    {
        $eta = $this->eta ?? $this->booking?->eta;
        if (!$eta) {
            return false;
        }

        $isFinished = $this->current_status === 'completed';

        return !$isFinished && now()->startOfDay()->greaterThan($eta);
    }

    /** Offloaded more than GRACE_DAYS ago and still no POD uploaded. */
    public function getPodOverdueAttribute(): bool
    {
        if (!$this->offloading_date || now()->startOfDay()->lt(\Carbon\Carbon::parse($this->offloading_date)->addDays(self::GRACE_DAYS))) {
            return false;
        }

        return !($this->relationLoaded('documents')
            ? $this->documents->contains('document_type', 'POD')
            : $this->podDocuments()->exists());
    }
}
