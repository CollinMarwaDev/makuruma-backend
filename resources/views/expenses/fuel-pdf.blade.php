@php
    // Expense UAT req. 7, 11: fuel order printout sent to the vendor; the price columns are optional.
    // Provisional layout until the client's fuel forms (with and without price) arrive.
    $lines = $expense->lines;
    $vendors = $lines->pluck('vendor.company_name')->filter()->unique()->join(', ');
    $truckOf = fn ($l) => $l->bookingTruck?->truck?->reg_no ?? $expense->truck?->reg_no ?? '—';
    $currency = $lines->pluck('currency')->unique()->count() === 1 ? $lines->first()?->currency : null;
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2);
    $date = optional($expense->created_at)->format('d/m/Y');
    $totalAmount = $lines->sum(fn ($l) => (float) $l->original_amount);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; color: #111827; font-size: 10px; }
        .header { display: table; width: 100%; margin-bottom: 6px; }
        .logo-box { display: table-cell; width: 90px; vertical-align: middle; }
        .logo-img { height: 50px; }
        .company-info { display: table-cell; vertical-align: middle; padding-left: 10px; }
        .company-name { font-size: 16px; font-weight: bold; }
        .number { text-align: center; font-size: 12px; font-weight: bold; margin-top: 12px; }
        .title { text-align: center; font-size: 12px; font-weight: bold; margin: 4px 0 12px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 5px 6px; font-size: 10px; }
        th { background: #f3f4f6; text-align: left; text-transform: uppercase; font-size: 9px; }
        .num { text-align: right; }
        .total td { font-weight: bold; background: #f9fafb; }
        .sign { margin-top: 30px; display: table; width: 100%; font-size: 10px; }
        .sign div { display: table-cell; width: 50%; }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo-box"><img src="{{ $logoBase64 }}" class="logo-img" alt="Makuruma Logistics"></div>
        <div class="company-info">
            <div class="company-name">MAKURUMA LOGISTICS LIMITED</div>
            <div>P.O.Box 31902 Dar es salaam-Tanzania, Tel:+255 710 001100, +255 713 013132.</div>
        </div>
    </div>

    <div class="number">NO: {{ $expense->serial ?? $expense->order_number }}</div>
    <div class="title">
        FUEL{{ $vendors ? ' - ' . $vendors : '' }} - GARI {{ $lines->map($truckOf)->unique()->count() }} - {{ $date }}
        @if ($expense->bookings->isNotEmpty())
            ({{ $expense->bookings->pluck('client.company_name')->unique()->join(', ') }})
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 30px">S/N</th>
                <th>Truck</th>
                <th>Driver's name</th>
                <th class="num">Litres</th>
                @if ($showPrice)
                    <th class="num">Price{{ $currency ? " ($currency)" : '' }}</th>
                    <th class="num">Amount{{ $currency ? " ($currency)" : '' }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $truckOf($line) }}</td>
                    <td>{{ $line->driver_name ?? $line->bookingTruck?->driver?->full_name ?? '' }}</td>
                    <td class="num">{{ number_format((float) $line->quantity, 2) }}</td>
                    @if ($showPrice)
                        <td class="num">{{ $line->unit_rate === null ? 'Pending' : $money($line->unit_rate) }}</td>
                        <td class="num">{{ $money($line->original_amount) }}</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">TOTAL</td>
                <td class="num">{{ number_format((float) $lines->sum('quantity'), 2) }}</td>
                @if ($showPrice)
                    <td></td>
                    <td class="num">{{ $currency ? $money($totalAmount) : 'TZS ' . $money($expense->total_amount) }}</td>
                @endif
            </tr>
        </tbody>
    </table>

    <div class="sign">
        <div>Prepared by: {{ $expense->initiated_by }}</div>
        <div>Approved by: {{ $expense->approver?->name ?? '________________' }}</div>
    </div>
</body>
</html>
