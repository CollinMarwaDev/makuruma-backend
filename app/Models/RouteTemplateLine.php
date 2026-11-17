<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteTemplateLine extends Model
{
    protected $fillable = ['currency', 'description', 'is_flat', 'multiplier', 'segment', 'sort_order', 'unit_amount'];

    protected $casts = ['is_flat' => 'boolean'];
}
