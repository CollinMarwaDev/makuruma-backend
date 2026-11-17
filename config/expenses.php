<?php

/*
 * First serial of each expense number series (req. 2). Set before go-live to continue the paper
 * numbering, e.g. EXPENSE_START_VIBALI_TUNDUMA=130 after form 129.
 */
return [
    'series_start' => [
        'fuel' => (int) env('EXPENSE_START_FUEL', 1),
        'mengine' => (int) env('EXPENSE_START_OTHER', 1),
        'vibali_congo' => (int) env('EXPENSE_START_VIBALI_CONGO', 1),
        'vibali_tunduma' => (int) env('EXPENSE_START_VIBALI_TUNDUMA', 1),
    ],
];
