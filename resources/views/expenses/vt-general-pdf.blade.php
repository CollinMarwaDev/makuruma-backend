{{-- Vibali Tunduma general form, sample 129 (Expense UAT req. 19, 20); truck lists four per row, lines of one kind together (UAT 20 Nov). --}}
@php
    $title = 'VIBALI TUNDUMA - ' . $print['date'];
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $symbol = ['USD' => '$', 'ZMK' => 'ZMK', 'TZS' => 'TSh'];
    $currencies = collect($print['perTruck'])->flatMap(fn ($c) => array_keys($c))->unique()
        ->sortBy(fn ($c) => array_search($c, \App\Services\ExpenseAmountCalculator::CURRENCY_ORDER))->values();
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    @include('expenses.partials.vibali-lines')

    @include('expenses.partials.vibali-truck-lists', ['lists' => $print['truckLists']])

    <table class="list" style="width: auto;">
        <thead>
            <tr>
                <th class="sn">S/N</th>
                <th>Truck</th>
                @foreach ($currencies as $c)
                    <th class="num">Pesa ({{ $symbol[$c] }})</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($print['perTruck'] as $reg => $amounts)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $reg }}</td>
                    @foreach ($currencies as $c)
                        <td class="num">{{ isset($amounts[$c]) ? $symbol[$c] . $fmt($amounts[$c]) : '' }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="subtotal">
                <td></td>
                <td>TOTAL</td>
                @foreach ($currencies as $c)
                    <td class="num">{{ $symbol[$c] }}{{ $fmt(collect($print['perTruck'])->sum(fn ($a) => $a[$c] ?? 0)) }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
</body>
</html>
