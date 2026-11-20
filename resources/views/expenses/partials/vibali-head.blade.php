{{-- Shared page head and letterhead for the Vibali printouts (Expense UAT req. 16–25). --}}
<head>
    <meta charset="utf-8">
    <style>
        /* Larger text (UAT 20 Nov) with tighter margins, so long forms still fit one page. */
        @page { margin: 24px 30px; }
        body { font-family: 'Helvetica', sans-serif; color: #111827; font-size: 12px; }
        .header { display: table; width: 100%; margin-bottom: 4px; }
        .logo-box { display: table-cell; width: 90px; vertical-align: middle; }
        .logo-img { height: 46px; }
        .company-info { display: table-cell; vertical-align: middle; padding-left: 10px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .number { text-align: center; font-size: 14.5px; font-weight: bold; margin-top: 10px; }
        .title { text-align: center; font-size: 13px; font-weight: bold; margin: 3px 0 10px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #9ca3af; padding: 3px 6px; font-size: 11.5px; }
        th { background: #f3f4f6; text-align: left; text-transform: uppercase; font-size: 10px; }
        .num { text-align: right; white-space: nowrap; }
        .sn { width: 26px; }
        .subtotal td { font-weight: bold; background: #f9fafb; }
        .conversion td { font-style: italic; }
        .grand td { font-weight: bold; background: #e5e7eb; }
        .payee td { font-weight: bold; }
        .lists { width: 100%; table-layout: fixed; }
        .lists-cell { border: none; vertical-align: top; padding: 0 8px 0 0; }
        .list { width: 100%; }
        .list .num { width: 50px; }
        .list td, .list th { font-size: 11px; }
    </style>
</head>
