<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A vendor type users manage (Fuel, ECIL …). Vendors store the slug; renaming keeps the slug. */
class VendorType extends Model
{
    public const FUEL = 'fuel';

    protected $fillable = ['slug', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
