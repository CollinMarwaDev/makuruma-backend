<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A tracking status a leg can have. Admins add custom ones; system ones drive business rules. */
class TrackingStatus extends Model
{
    protected $fillable = ['key', 'label', 'color', 'sort_order', 'is_active', 'is_system'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** Final status of a leg (client request 17 Nov 2026: replaces Completed). */
    public const OFFLOADED = 'offloaded';
}
