<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkpoint extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sequence_order', 'route'];

    public function milestones(): HasMany
    {
        return $this->hasMany(TruckMilestone::class);
    }
}