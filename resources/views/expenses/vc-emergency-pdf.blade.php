{{-- Vibali Congo emergency expenses, sample 180: penalty, posho, visa; own trucks per line and a total per truck (req. 25);
    lines of the same kind (e.g. several Posho lines) share one list (UAT 20 Nov). --}}
@php
    $title = 'VIBALI CONGO - ' . $print['date'];
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    @include('expenses.partials.vibali-lines')

    @include('expenses.partials.vibali-truck-lists', ['lists' => $print['truckLists']])

    <table class="list" style="width: auto;">
        <thead><tr><th class="sn">S/N</th><th>Truck</th><th class="num">Pesa</th></tr></thead>
        <tbody>
            @foreach ($print['perTruck'] as $reg => $amounts)
                <tr><td>{{ $loop->iteration }}</td><td>{{ $reg }}</td><td class="num">${{ $fmt($amounts['USD'] ?? 0) }}</td></tr>
            @endforeach
            <tr class="subtotal"><td></td><td>TOTAL</td><td class="num">${{ $fmt(collect($print['perTruck'])->sum(fn ($a) => $a['USD'] ?? 0)) }}</td></tr>
        </tbody>
    </table>
</body>
</html>
