{{--
    One truck list under a Vibali printout ($list from ExpenseAmountCalculator::truckLists).
    Same amount for every truck → amount in the heading; different amounts (e.g. Posho for several days) → a Pesa column.
--}}
@php
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $sym = ['USD' => '$', 'ZMK' => 'ZMK ', 'TZS' => 'TSh'][$list['currency']] ?? '';
@endphp
<table class="list">
    <thead>
        <tr>
            <th class="sn">S/N</th>
            @if ($list['per_truck'] !== null)
                <th>{{ $list['title'] }} {{ $sym }}{{ $fmt($list['per_truck']) }}</th>
            @else
                <th>{{ $list['title'] }}</th>
                <th class="num">Pesa</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($list['rows'] as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $row['truck'] }}</td>
                @if ($list['per_truck'] === null)
                    <td class="num">{{ $sym }}{{ $fmt($row['amount']) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
