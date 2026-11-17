{{--
    Lines grouped by currency (USD, ZMK, TSh) with subtotals; ZMK (and USD on form 129) converted to TSh
    with the order's rate written out, e.g. "145,350 X 138 = TSh20,058,300" (req. 15, 16, 19).
--}}
@php
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $symbol = ['USD' => '$', 'ZMK' => 'ZMK ', 'TZS' => 'TSh'];
    $sn = 0;
    // "-$300" rather than "$-300" for money returned on form 163.
    $money = fn (string $c, $v) => ((float) $v < 0 ? '-' : '') . $symbol[$c] . $fmt(abs((float) $v));
@endphp
<table>
    <thead>
        <tr>
            <th class="sn">S/N</th>
            <th>Details</th>
            <th class="num">Amount</th>
            <th class="num">No of trucks</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach (\App\Services\ExpenseAmountCalculator::CURRENCY_ORDER as $currency)
            @continue(!$print['blocks']->has($currency))
            @php $block = $print['blocks']->get($currency); @endphp
            @foreach ($block as $line)
                @if ($line['is_additional'] && $loop->index > 0 && !$block[$loop->index - 1]['is_additional'])
                    {{-- Form 165: route subtotal before the extra costs (req. 22). --}}
                    <tr class="subtotal">
                        <td></td>
                        <td class="num">TOTAL</td>
                        <td class="num">{{ $symbol[$currency] }}{{ $fmt($block->where('is_additional', false)->where('is_flat', false)->sum('per_truck')) }}</td>
                        <td></td>
                        <td class="num">{{ $symbol[$currency] }}{{ $fmt($block->where('is_additional', false)->sum('total')) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>{{ ++$sn }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['is_flat'] ? '' : $line['amount_text'] }}</td>
                    <td class="num">{{ $line['is_flat'] ? '' : $line['count'] }}</td>
                    <td class="num">{{ $money($currency, $line['total']) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td></td>
                <td class="num">TOTAL</td>
                <td class="num">{{ $currency === 'TZS' || $expense->form !== 'route' ? '' : $symbol[$currency] . $fmt($block->where('is_flat', false)->sum('per_truck')) }}</td>
                <td></td>
                <td class="num">{{ $money($currency, $block->sum('total')) }}</td>
            </tr>
            @if ($currency === 'ZMK' && $print['zmkConverted'])
                <tr class="conversion">
                    <td></td>
                    <td colspan="3" class="num">{{ $fmt($print['zmkTotal']) }} X {{ $fmt($expense->zmk_rate) }} = TSh{{ $fmt($print['zmkConverted']) }}</td>
                    <td class="num">TSh{{ $fmt($print['zmkConverted']) }}</td>
                </tr>
            @endif
            @if ($currency === 'USD' && $print['usdConverted'])
                <tr class="conversion">
                    <td></td>
                    <td colspan="3" class="num">{{ $fmt($print['usdTotal']) }} X {{ $fmt($expense->usd_rate) }} = TSh{{ $fmt($print['usdConverted']) }}</td>
                    <td class="num">TSh{{ $fmt($print['usdConverted']) }}</td>
                </tr>
            @endif
        @endforeach
        @php $usdOnly = !$print['blocks']->has('ZMK') && !$print['blocks']->has('TZS') && !$print['usdConverted']; @endphp
        @if (!$usdOnly)
        <tr class="grand">
            <td></td>
            <td colspan="3" class="num">
                GRAND TOTAL
                @php $parts = array_filter([$print['usdConverted'], $print['zmkConverted'], $print['tshTotal']]); @endphp
                @if (count($parts) > 1) {{ collect($parts)->map($fmt)->join('+') }}= @endif
            </td>
            <td class="num">TSh{{ $fmt($print['grandTotal']) }}</td>
        </tr>
        @endif
        @if ($print['usdTotal'] && !$print['usdConverted'] && !$usdOnly)
            <tr class="grand">
                <td></td>
                <td colspan="3" class="num">USD (not converted)</td>
                <td class="num">${{ $fmt($print['usdTotal']) }}</td>
            </tr>
        @endif
    </tbody>
</table>
