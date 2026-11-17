{{-- Vibali Tunduma route form, sample 123 (Expense UAT req. 15–17). --}}
@php
    $title = 'VIBALI TUNDUMA GARI ' . $print['allTrucks']->count() . '-' . $print['date']
        . ($print['clients'] ? ' (' . $print['clients'] . ')' : '')
        . ($expense->outbound_border ? ' KWENDA ' . $expense->outbound_border : '')
        . ($expense->return_border ? '-KURUDI ' . $expense->return_border : '');
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
