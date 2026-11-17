<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: 'Helvetica', sans-serif;
            color: #1f2937;
            font-size: 10px;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 6px;
        }

        .logo-box {
            display: table-cell;
            width: 90px;
            vertical-align: middle;
        }

        .logo-img {
            height: 50px;
        }

        .company-info {
            display: table-cell;
            vertical-align: middle;
            padding-left: 10px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }

        .company-address {
            font-size: 10px;
            color: #374151;
        }

        .section-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            margin: 16px 0 8px;
            text-decoration: underline;
        }

        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 14px;
        }

        .info-cell {
            display: table-cell;
            width: 33%;
            padding: 4px 0;
            font-size: 10px;
        }

        .info-label {
            color: #6b7280;
            font-size: 8px;
            text-transform: uppercase;
        }

        .info-value {
            font-weight: bold;
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th {
            background: #1e3a8a;
            color: #fff;
            text-align: left;
            padding: 6px 4px;
            font-size: 8px;
            text-transform: uppercase;
        }

        td {
            padding: 6px 4px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }

        .total-row td {
            font-weight: bold;
            background: #f9fafb;
            border-top: 2px solid #1e3a8a;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .trucks-grid {
            display: table;
            width: 100%;
        }

        .truck-cell {
            display: table-cell;
            width: 20%;
            padding: 3px 4px;
            font-size: 9px;
            border-bottom: 1px solid #f3f4f6;
        }

        .audit-trail {
            margin-top: 16px;
            font-size: 9px;
            color: #4b5563;
        }

        .audit-trail div {
            margin-bottom: 3px;
        }

        .footer {
            margin-top: 20px;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="logo-box"><img src="{{ $logoBase64 }}" class="logo-img" alt="Makuruma Logistics"></div>
        <div class="company-info">
            <div class="company-name">MAKURUMA LOGISTICS LIMITED</div>
            <div class="company-address">P.O.Box 31902 Dar es salaam-Tanzania, Tel:+255 710 001100, +255 713 013132.
            </div>
        </div>
    </div>

    <div class="section-title">EXPENSE ORDER — {{ $expense->order_number }}</div>

    <div class="info-grid">
        <div class="info-cell">
            <div class="info-label">Category</div>
            <div class="info-value">{{ ucfirst($expense->category) }}</div>
        </div>
        <div class="info-cell">
            <div class="info-label">Reference</div>
            <div class="info-value">{{ $expense->booking->booking_number ?? ($expense->lines->pluck('truck.reg_no')->filter()->unique()->join(', ') ?: '—') }}</div>
        </div>
        <div class="info-cell">
            <div class="info-label">Status</div>
            <div class="info-value"><span
                    class="status-badge status-{{ $expense->status }}">{{ strtoupper($expense->status) }}</span></div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-cell">
            <div class="info-label">Payment Account</div>
            <div class="info-value">{{ $expense->payment_account ?? '—' }}</div>
        </div>
        <div class="info-cell">
            <div class="info-label">Initiated By</div>
            <div class="info-value">{{ $expense->initiated_by ?? '—' }}</div>
        </div>
        <div class="info-cell">
            <div class="info-label">Payment Date</div>
            <div class="info-value">
                {{ $expense->payment_date ? \Carbon\Carbon::parse($expense->payment_date)->format('d M Y') : '—' }}
            </div>
        </div>
    </div>

    @php
        // Group lines that share the same group_key (i.e. came from one
        // original entry, possibly expanded across multiple trucks).
        // Lines without a group_key (older data) are treated individually.
        $groups = collect();
        $ungroupedIndex = 0;

        foreach ($expense->lines as $line) {
            $key = $line->group_key ?? ('__single_' . $ungroupedIndex++);
            if (!$groups->has($key)) {
                $groups->put($key, collect());
            }
            $groups->get($key)->push($line);
        }

        $allTruckRegNos = $expense->lines
            ->pluck('bookingTruck.truck.reg_no')
            ->filter()
            ->unique()
            ->values();
    @endphp

    <table>
        <thead>
            <tr>
                <th>S/N</th>
                <th>Category</th>
                <th>Vendor</th>
                <th>Description</th>
                <th>Trip Code</th>
                <th style="text-align:right;">Amount (per truck)</th>
                <th style="text-align:center;">No. of Trucks</th>
                <th style="text-align:right;">Total (TZS)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $index => $group)
                @php
                    $first = $group->first();
                    $count = $group->count();
                    $groupTotal = $group->sum('amount') ?? 0;
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ str_replace('_', ' ', ucfirst($first->line_category)) }}</td>
                    <td>{{ $first->vendor->company_name ?? '—' }}</td>
                    <td>{{ $first->description }}</td>
                    <td>{{ $line->bookingTruck->trip->trip_code ?? '—' }}</td>
                    <td style="text-align:right;">{{ $first->currency }}
                        {{ number_format($first->original_amount ?? 0, 2) }}
                    </td>
                    <td style="text-align:center;">{{ $count }}</td>
                    <td style="text-align:right;">{{ number_format($groupTotal, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="6">Total (TZS)</td>
                <td style="text-align:right;">{{ number_format($expense->total_amount ?? 0, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($allTruckRegNos->count() > 0)
        <div class="section-title" style="margin-top:14px;">Trucks Covered ({{ $allTruckRegNos->count() }})</div>
        <div class="trucks-grid">
            @foreach ($allTruckRegNos as $index => $regNo)
                <div class="truck-cell">{{ $index + 1 }}. {{ $regNo }}</div>
            @endforeach
        </div>
    @endif

    <div class="audit-trail">
        <div><strong>Created:</strong> {{ $expense->creator->name }} on
            {{ \Carbon\Carbon::parse($expense->created_at)->format('d M Y, H:i') }}
        </div>
        @if ($expense->approver)
            <div><strong>{{ $expense->status === 'rejected' ? 'Rejected' : 'Approved' }}:</strong>
                {{ $expense->approver->name }} on {{ \Carbon\Carbon::parse($expense->approved_at)->format('d M Y, H:i') }}
            </div>
        @endif
        @if ($expense->payer)
            <div><strong>Paid:</strong> {{ $expense->payer->name }} on
                {{ \Carbon\Carbon::parse($expense->paid_at)->format('d M Y, H:i') }}
            </div>
        @endif
    </div>

    <div class="footer">Generated by Makuruma Logistics Management System · {{ now()->format('d M Y, H:i') }}</div>
</body>

</html>