<?php

namespace App\Services;

use App\Models\ExpenseLine;
use App\Models\ExpenseOrder;
use App\Models\ExpensePriceChange;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Expense UAT req. 9: fuel prices may be filled in later; changing a price that was already set needs a
 * reason. Every fill or change is kept in expense_price_changes.
 */
class ExpensePriceService
{
    /**
     * Price changes between saved lines and the incoming ones, matched by group_key + truck.
     *
     * @param Collection<int, ExpenseLine> $oldLines
     * @param array<int, array<string, mixed>> $newLines
     * @return array<int, array{label: string, line: ?ExpenseLine, new: ?float, old: ?float}>
     */
    public static function diffLines(Collection $oldLines, array $newLines): array
    {
        $key = fn ($groupKey, $truckId) => ($groupKey ?? '') . '|' . ($truckId ?? '');
        $old = $oldLines->keyBy(fn (ExpenseLine $l) => $key($l->group_key, $l->booking_truck_id));

        $changes = [];
        foreach ($newLines as $line) {
            $previous = $old->get($key($line['group_key'] ?? null, $line['booking_truck_id'] ?? null));
            $new = isset($line['unit_rate']) ? (float) $line['unit_rate'] : null;
            if ($previous && self::differs($previous->unit_rate, $new)) {
                $changes[] = ['label' => self::label($previous), 'line' => null, 'new' => $new, 'old' => self::rate($previous->unit_rate)];
            }
        }

        return $changes;
    }

    /** Stop when an already-set price changes without a reason. */
    public static function assertReason(array $changes, ?string $reason): void
    {
        $editsSetPrice = collect($changes)->contains(fn ($c) => $c['old'] !== null);
        if ($editsSetPrice && !trim((string) $reason)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for changing a fuel price that was already set.']);
        }
    }

    public static function record(ExpenseOrder $order, array $changes, ?string $reason, int $userId): void
    {
        foreach ($changes as $change) {
            ExpensePriceChange::create([
                'changed_by' => $userId,
                'expense_line_id' => $change['line']?->id,
                'expense_order_id' => $order->id,
                'line_label' => $change['label'],
                'new_unit_rate' => $change['new'],
                'old_unit_rate' => $change['old'],
                'reason' => $change['old'] !== null ? trim((string) $reason) : null,
            ]);
        }
    }

    public static function label(ExpenseLine $line): string
    {
        $truck = $line->bookingTruck?->truck?->reg_no ?? $line->expenseOrder?->truck?->reg_no;

        return trim($line->description . ($truck ? " · {$truck}" : ''));
    }

    public static function differs(mixed $old, ?float $new): bool
    {
        $old = self::rate($old);

        return $old === null ? $new !== null : ($new === null || abs($old - $new) > 0.001);
    }

    private static function rate(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
