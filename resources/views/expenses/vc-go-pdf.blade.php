{{-- Vibali Congo going form, sample 165: USD only, route subtotal then extra costs (Expense UAT req. 21–23). --}}
@php
    $title = 'VIBALI CONGO KWENDA GARI ' . $print['allTrucks']->count() . ' - ' . $print['date']
        . ($print['clients'] ? ' - (' . $print['clients'] . ')' : '')
        . ($expense->outbound_border ? ' ' . $expense->outbound_border : '');
@endphp
<!DOCTYPE html>
<html>
@include('expenses.partials.vibali-head')
<body>
    @include('expenses.partials.vibali-letterhead', ['title' => $title])
    @include('expenses.partials.vibali-lines')
    @include('expenses.partials.vibali-trucks')
</body>
</html>
