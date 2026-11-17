<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One fuel price change on an expense line, with who changed it and why (Expense UAT req. 9). */
class ExpensePriceChange extends Model
{
    protected $fillable = ['changed_by', 'expense_line_id', 'expense_order_id', 'line_label', 'new_unit_rate', 'old_unit_rate', 'reason'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
