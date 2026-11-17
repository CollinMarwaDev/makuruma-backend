<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A subcategory of Other expenses (Weighbridge, GCLA, …); the profit report groups by it. */
class ExpenseSubcategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
