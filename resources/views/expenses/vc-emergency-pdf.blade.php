{{-- Vibali Congo emergency expenses, sample 180: penalty, posho, visa; own trucks per line and a total per truck (req. 25). --}}
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

    <table class="lists">
        <tr>
            @foreach ($print['blocks']->flatten(1)->where('is_flat', false) as $line)
                <td class="lists-cell">
                    <table class="list">
                        <thead><tr><th class="sn">S/N</th><th>{{ $line['description'] }} ${{ $fmt($line['per_truck']) }}</th></tr></thead>
                        <tbody>
                            @foreach ($line['trucks'] as $i => $reg)
                                <tr><td>{{ $i + 1 }}</td><td>{{ $reg }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

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
