<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An account expenses are paid from (Cash, M-Pesa, CRDB …). Users add more. */
class PaymentAccount extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
