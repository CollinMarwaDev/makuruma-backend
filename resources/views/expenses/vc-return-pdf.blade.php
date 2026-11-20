{{--
    Vibali Congo return settlement, form 163 (Expense UAT req. 24): extra money spent (+) and money returned (−)
    against the going order. Lines without trucks (e.g. fare for the driver collecting money) are listed as one amount.
--}}
@php
    $parent = $expense->parentOrder;
    $fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1) ? 2 : 0);
    $title = 'VIBALI CONGO KURUDI GARI ' . $print['allTrucks']->count() . ' - ' . $print['date']
        . ($print['clients'] ? ' - (' . $print['clients'] . ')' : '')
        . ($expense->return_border ? ' ' . $expense->return_border : '');
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    @if ($parent)
        <p style="text-align: center; margin: -4px 0 8px;">Settles going order NO: {{ $parent->form_number }} (${{ $fmt($parent->usd_total) }})</p>
    @endif
    @include('expenses.partials.vibali-lines')
    @if ($parent)
        <table style="width: 50%; margin-left: auto;">
            <tr><td>Going order (NO: {{ $parent->form_number }})</td><td class="num">${{ $fmt($parent->usd_total) }}</td></tr>
            <tr><td>Settlement (this form)</td><td class="num">${{ $fmt($print['usdTotal']) }}</td></tr>
            <tr class="grand"><td>Trip total</td><td class="num">${{ $fmt((float) $parent->usd_total + $print['usdTotal']) }}</td></tr>
        </table>
    @endif
    @include('expenses.partials.vibali-trucks')
</body>
</html>
