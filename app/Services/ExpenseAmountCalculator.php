<?php

namespace App\Services;

use App\Models\ExpenseLine;
use App\Models\ExpenseOrder;
use App\Models\UsdRate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Expense UAT req. 15–19, 21: currency rules of the Vibali forms and the figures their printouts show.
 * Lines are stored one per truck (same group_key); a "logical line" is one row of the paper form.
 */
class ExpenseAmountCalculator
{
    public const CURRENCY_ORDER = ['USD', 'ZMK', 'TZS'];

    /**
     * Set each line's exchange rate from the order: ZMK uses the order's on-spot rate; USD uses the order's
     * rate on form 129, otherwise the USD period rate (profit report only, never printed).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    public static function prepareLines(array $lines, array $header, string $type, ?string $form): array
    {
        if (!str_starts_with($type, 'vibali') || !$form) {
            return $lines;
        }

        $currencies = collect($lines)->pluck('currency')->unique();
        if ($currencies->contains('ZMK') && empty($header['zmk_rate'])) {
            throw ValidationException::withMessages(['zmk_rate' => 'Enter the ZMK → TSh rate used for this order (e.g. 138).']);
        }
        $convertsUsd = $form === 'general';
        if ($convertsUsd && $currencies->contains('USD') && empty($header['usd_rate'])) {
            throw ValidationException::withMessages(['usd_rate' => 'Enter the USD → TSh rate used for this order.']);
        }

        $usdRate = $convertsUsd
            ? (float) ($header['usd_rate'] ?? 0)
            : (UsdRate::forDate($header['payment_date'] ?? now()->toDateString()) ?? (float) ($header['usd_rate'] ?? 0));

        return array_map(fn (array $line) => [
            ...$line,
            'exchange_rate' => match ($line['currency']) {
                'ZMK' => $header['zmk_rate'],
                'USD' => $usdRate,
                default => 1,
            },
        ], $lines);
    }

    /**
     * One row per paper-form line, in currency order (USD, ZMK, TSh).
     *
     * @return Collection<int, array{amount_text: string, count: int, currency: string, description: string, is_flat: bool, payee: ?string, per_truck: float, total: float, trucks: array<int, string>}>
     */
    public static function logicalLines(ExpenseOrder $order): Collection
    {
        $order->loadMissing('lines.bookingTruck.truck');
        $truckOf = fn (ExpenseLine $l) => $l->bookingTruck?->truck?->reg_no ?? $order->truck?->reg_no;

        return $order->lines->sortBy('id')
            ->groupBy(fn (ExpenseLine $l) => $l->group_key ?? "line-{$l->id}")
            ->map(function (Collection $rows) use ($truckOf) {
                $first = $rows->first();
                $trucks = $rows->sortBy('booking_truck_id')->map($truckOf)->filter()->values()->all();
                $perTruck = (float) $first->original_amount;

                return [
                    'amount_text' => $first->amount_description ?: self::amountText($first),
                    'count' => $first->is_flat ? 0 : count($trucks),
                    'currency' => $first->currency,
                    'description' => $first->description,
                    'is_additional' => (bool) $first->is_additional,
                    'is_flat' => (bool) $first->is_flat,
                    'payee' => $first->payee,
                    'per_truck' => $perTruck,
                    'total' => (float) $rows->sum('original_amount'),
                    'trucks' => $trucks,
                ];
            })
            ->values()
            ->sortBy(fn ($l, $i) => [array_search($l['currency'], self::CURRENCY_ORDER), $l['is_additional'] ? 1 : 0, $i])
            ->values();
    }

    /**
     * Figures for a Vibali printout: currency blocks, conversions, totals, trucks.
     *
     * @return array<string, mixed>
     */
    public static function printData(ExpenseOrder $order): array
    {
        $order->loadMissing('bookings.client', 'lines.bookingTruck.truck');
        $lines = self::logicalLines($order);
        $blocks = $lines->groupBy('currency');
        $total = fn (string $c) => (float) ($blocks->get($c)?->sum('total') ?? 0);
        $converted = fn (string $c, $rate) => round($total($c) * (float) $rate, 2);

        $usdConverted = $order->keepsUsdSeparate() ? 0 : $converted('USD', $order->usd_rate);
        $zmkConverted = $converted('ZMK', $order->zmk_rate);

        $truckOf = fn (ExpenseLine $l) => $l->bookingTruck?->truck?->reg_no ?? $order->truck?->reg_no;
        $allTrucks = $order->lines->sortBy('booking_truck_id')->map($truckOf)->filter()->unique()->values();
        $perTruck = $allTrucks->mapWithKeys(fn ($reg) => [$reg => []])->all();
        foreach ($lines->where('is_flat', false) as $line) {
            foreach ($line['trucks'] as $reg) {
                $perTruck[$reg][$line['currency']] = ($perTruck[$reg][$line['currency']] ?? 0) + $line['per_truck'];
            }
        }

        return [
            'allTrucks' => $allTrucks,
            'blocks' => $blocks,
            'bookingNumbers' => $order->bookings->pluck('booking_number')->join(', '),
            'clients' => $order->bookings->pluck('client.company_name')->filter()->unique()->join(', '),
            'date' => optional($order->payment_date ? \Carbon\Carbon::parse($order->payment_date) : $order->created_at)->format('d/m/Y'),
            'grandTotal' => round($total('TZS') + $zmkConverted + $usdConverted, 2),
            // UAT 20 Nov: money handed to the driver vs kept by the agent, per currency (blank payee = older lines).
            'payeeTotals' => collect(['driver', 'agent'])->mapWithKeys(fn ($payee) => [$payee => $lines->where('payee', $payee)
                ->groupBy('currency')->map(fn ($l) => round($l->sum('total'), 2))->filter()->sortKeysUsing(fn ($a, $b) => array_search($a, self::CURRENCY_ORDER) <=> array_search($b, self::CURRENCY_ORDER))->all()])
                ->filter()->all(),
            'perTruck' => $perTruck,
            // Lines whose truck set differs from the order's trucks list them separately (req. 23).
            'subsetLines' => self::truckLists($lines->where('is_flat', false)->filter(fn ($l) => count($l['trucks']) !== $allTrucks->count())),
            // Form 180: every line's trucks, lines of the same kind in one list (UAT 20 Nov).
            'truckLists' => self::truckLists($lines->where('is_flat', false)),
            'tshTotal' => $total('TZS'),
            'usdConverted' => $usdConverted,
            'usdTotal' => $total('USD'),
            'zmkConverted' => $zmkConverted,
            'zmkTotal' => $total('ZMK'),
        ];
    }

    /**
     * Truck lists under a Vibali printout. Lines of the same kind (e.g. "POSHO(T646 DVW) 14/11–21/11" and
     * "POSHO(T143 ENH) 21/11") share one list; when their amounts differ the list shows each truck's amount
     * instead of one amount in the heading (UAT 20 Nov: one Posho table, not one per truck).
     *
     * @return Collection<int, array{title: string, currency: string, per_truck: ?float, rows: array<int, array{truck: string, amount: float}>}>
     */
    public static function truckLists(Collection $lines): Collection
    {
        return $lines->values()
            ->groupBy(fn ($l) => $l['currency'] . '|' . self::kindOf($l['description']))
            ->map(function (Collection $group) {
                $rows = [];
                foreach ($group as $line) {
                    foreach ($line['trucks'] as $reg) {
                        $rows[$reg] = ['truck' => $reg, 'amount' => ($rows[$reg]['amount'] ?? 0) + $line['per_truck']];
                    }
                }
                $amounts = collect($rows)->pluck('amount')->unique();

                return [
                    'title' => $group->count() === 1 ? $group->first()['description'] : self::kindOf($group->first()['description']),
                    'currency' => $group->first()['currency'],
                    'per_truck' => $amounts->count() === 1 ? (float) $amounts->first() : null,
                    'rows' => array_values($rows),
                ];
            })
            ->values();
    }

    /** "POSHO(T646 DVW) 14/11/2026---21/11/2026" → "POSHO": the text before any bracket or date. */
    public static function kindOf(string $description): string
    {
        $kind = preg_replace(['/\s*\(.*$/s', '/\s+\d{1,2}\/\d{1,2}.*$/s'], '', $description);
        $kind = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $kind)));

        return $kind !== '' ? $kind : mb_strtoupper(trim($description));
    }

    /** "300 X 10 = 3,000" when there is a multiplier, else the amount. */
    public static function amountText(ExpenseLine $line): string
    {
        $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
        if ($line->multiplier !== null && (float) $line->multiplier !== 1.0 && $line->unit_rate !== null) {
            return "{$fmt($line->unit_rate)} X {$fmt($line->multiplier)} = {$fmt($line->original_amount)}";
        }

        return $fmt($line->original_amount);
    }
}
