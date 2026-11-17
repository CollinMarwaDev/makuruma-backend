{{-- Other expense, Weighbridge (Mizani), sample No. 05: truck, driver's name and amount per truck (Expense UAT req. 26). --}}
@php
    $lines = $expense->lines->sortBy('id')->values();
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $truckOf = fn ($l) => $l->bookingTruck?->truck?->reg_no ?? $l->truck?->reg_no ?? $expense->truck?->reg_no ?? '';
    $driverOf = fn ($l) => $l->driver_name ?? $l->bookingTruck?->driver?->full_name ?? $l->truck?->driver?->full_name ?? $expense->truck?->driver?->full_name ?? '';
    $places = $lines->pluck('description')->unique()->join(', ');
    $date = optional($expense->payment_date ? \Carbon\Carbon::parse($expense->payment_date) : $expense->created_at)->format('d/m/Y');
    $title = 'MIZANI ' . $places . ' GARI ' . $lines->map($truckOf)->filter()->unique()->count() . ' -' . $date;
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    <table>
        <thead>
            <tr><th class="sn">S/N</th><th>Trucks</th><th>Drivers name</th><th class="num">Kiasi</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $truckOf($line) }}</td>
                    <td>{{ $driverOf($line) }}</td>
                    <td class="num">{{ $line->currency === 'TZS' ? '' : $line->currency . ' ' }}{{ $fmt($line->original_amount) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal"><td></td><td colspan="2">TOTAL</td><td class="num">{{ $fmt($expense->total_amount) }}</td></tr>
        </tbody>
    </table>
</body>
</html>
