<?php

namespace App\Services;

use App\Models\ExpenseOrder;

/** Next number in an expense type's own series (req. 2), e.g. VT-00130. Call inside the store transaction. */
class ExpenseNumberGenerator
{
    /** @return array{serial: int, order_number: string} */
    public static function next(string $type): array
    {
        $last = ExpenseOrder::where('expense_type', $type)->lockForUpdate()->max('serial');
        $serial = $last ? $last + 1 : max(1, (int) config("expenses.series_start.$type", 1));

        return [
            'order_number' => ExpenseOrder::TYPE_PREFIXES[$type] . '-' . str_pad((string) $serial, 5, '0', STR_PAD_LEFT),
            'serial' => $serial,
        ];
    }
}
