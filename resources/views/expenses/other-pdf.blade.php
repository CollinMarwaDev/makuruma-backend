{{-- Other expense with a subcategory (GCLA, ECIL, salaries, …): description and amount per line (Expense UAT req. 26). --}}
@php
    $lines = $expense->lines->sortBy('id')->values();
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $truckOf = fn ($l) => $l->bookingTruck?->truck?->reg_no ?? $l->truck?->reg_no ?? $expense->truck?->reg_no ?? '';
    $date = optional($expense->payment_date ? \Carbon\Carbon::parse($expense->payment_date) : $expense->created_at)->format('d/m/Y');
    $title = mb_strtoupper($expense->subcategory?->name ?? 'Other') . ' - ' . $date;
    $hasTrucks = $lines->contains(fn ($l) => $truckOf($l) !== '');
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    <table>
        <thead>
            <tr>
                <th class="sn">S/N</th>
                <th>Details</th>
                @if ($hasTrucks)<th>Truck</th>@endif
                <th class="num">Amount</th>
                <th class="num">Total (TSh)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    @if ($hasTrucks)<td>{{ $truckOf($line) }}</td>@endif
                    <td class="num">{{ $line->currency }} {{ $fmt($line->original_amount) }}</td>
                    <td class="num">{{ $fmt($line->amount) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal"><td></td><td colspan="{{ $hasTrucks ? 3 : 2 }}">TOTAL</td><td class="num">TSh{{ $fmt($expense->total_amount) }}</td></tr>
        </tbody>
    </table>
    <p style="margin-top: 24px;">Prepared by: {{ $expense->initiated_by }} &nbsp;&nbsp;&nbsp; Approved by: {{ $expense->approver?->name ?? '________________' }}</p>
</body>
</html>
