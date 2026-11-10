<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: 'Helvetica', sans-serif;
            color: #1f2937;
            font-size: 9px;
        }

        table.header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.header-table td {
            border: 1px solid #9ca3af;
            padding: 5px 6px;
            vertical-align: top;
            font-size: 9px;
        }

        .logo-cell {
            width: 90px;
        }

        .logo-img {
            height: 45px;
        }

        .company-name {
            font-size: 15px;
            font-weight: bold;
        }

        .invoice-title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 10px 0;
            text-decoration: underline;
        }

        .label {
            font-size: 7px;
            color: #6b7280;
            text-transform: uppercase;
            display: block;
        }

        table.lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.lines-table th {
            background: #eef2ff;
            border: 1px solid #9ca3af;
            padding: 5px;
            font-size: 8px;
            text-transform: uppercase;
        }

        table.lines-table td {
            border: 1px solid #9ca3af;
            padding: 5px;
            font-size: 9px;
        }

        .line-details {
            margin-top: 2px;
            font-size: 9px;
            color: #555;
        }

        .total-row td {
            font-weight: bold;
        }

        .footer-table {
            width: 100%;
            margin-top: 10px;
        }

        .footer-table td {
            vertical-align: top;
            font-size: 8px;
            padding: 6px;
        }

        .bank-details {
            font-size: 8px;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <table class="header-table">
        <tr>
            <td class="logo-cell" rowspan="2">
                <img src="{{ $logoBase64 }}" class="logo-img" alt="Makuruma Logistics">
                <div class="company-name">MAKURUMA<br>LOGISTICS LTD</div>
            </td>
            <td colspan="2">
                <span class="label">P.O.Box</span>
                31902, Dar es Salaam, Tanzania<br>
                Tel: +255 710 001100 &nbsp; TIN: 125-593-445
            </td>
            <td>
                <span class="label">Invoice No</span>
                {{ $invoice->invoice_number }}
            </td>
            <td>
                <span class="label">Dated</span>
                {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="label">VRN</span> 40-035903-Q &nbsp;
                <span class="label" style="display:inline;">Email</span> info@makurumalogisticsltd.co.tz
            </td>
            <td>
                <span class="label">{{ strtoupper(str_replace('_', ' ', $invoice->invoice_type)) }}</span>
                {{ $invoice->deal_no ? '' : '' }}
            </td>
            <td>
                <span class="label">Mode/Terms of Payment</span>
                {{ $invoice->mode_of_payment ?? '—' }}
            </td>
        </tr>
        <tr>
            <td colspan="2" rowspan="3">
                <span class="label">Consignee</span>
                {{ $invoice->booking->client->company_name }}<br>
                {{ $invoice->booking->client->email ?? '' }}<br>
                {{ $invoice->booking->client->phone ?? '' }}
            </td>
            <td>
                <span class="label">Supplier's Ref</span>
                {{ $invoice->supplier_ref ?? '—' }}
            </td>
            <td>
                <span class="label">Other Reference(s)</span>
                {{ $invoice->other_ref ?? '—' }}
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Deal No</span>
                {{ $invoice->deal_no ?? '—' }}
            </td>
            <td>
                <span class="label">Dated</span>
                {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Bivac No</span>
                {{ $invoice->bivac_no ?? '—' }}
            </td>
            <td>
                <span class="label">Delivery Note Date</span>
                {{ $invoice->delivery_note_date ? \Carbon\Carbon::parse($invoice->delivery_note_date)->format('d M Y') : '—' }}
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="label">Despatched Through</span>
                {{ $invoice->dispatched_through ?? '—' }}
            </td>
            <td colspan="2">
                <span class="label">Destination</span>
                {{ $invoice->destination ?? '—' }}
            </td>
        </tr>
        <tr>
            <td colspan="4">
                <span class="label">Terms of Delivery</span>
                {{ $invoice->terms_of_delivery ?? '—' }}
            </td>
        </tr>
    </table>

    <table class="lines-table">
        <thead>
            <tr>
                <th>Description of Goods/Services</th>
                <th>{{ $invoice->invoice_type === 'standing_time' ? 'Days' : 'Quantity (Ton)' }}</th>
                <th>Rate ($)</th>
                <th>Amount ($)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    @php
                        $bt = $line->bookingTruck;
                        $from = $bt?->loading_point ?? $invoice->booking->loading_point;
                        $to = $bt?->offloading_point ?? $invoice->booking->offloading_point;
                        $eta = $bt?->eta ?? $invoice->booking->eta;
                        $details = array_filter([
                            ($from || $to) ? ($from ?? '—') . ' to ' . ($to ?? '—') : null,
                            $bt?->cargo,
                            $eta ? 'ETA ' . \Illuminate\Support\Carbon::parse($eta)->format('d.m.Y') : null,
                        ]);
                    @endphp
                    <td>
                        {{ $line->description }}
                        @if ($details)
                            <div class="line-details">{{ implode(' · ', $details) }}</div>
                        @endif
                    </td>
                    <td>{{ $invoice->invoice_type === 'standing_time' ? $line->days : number_format($line->quantity, 3) }}
                    </td>
                    <td>{{ number_format($line->rate, 2) }}</td>
                    <td>{{ number_format($line->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">Total Amount</td>
                <td>$ {{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="footer-table">
        <tr>
            <td style="width:60%;">
                <span class="label">Remarks</span>
                @php
                    // One route when every truck on the invoice shares it (contract 2.4), else point to the lines.
                    $routes = $invoice->lines
                        ->map(fn ($l) => [
                            $l->bookingTruck?->loading_point ?? $invoice->booking->loading_point,
                            $l->bookingTruck?->offloading_point ?? $invoice->booking->offloading_point,
                        ])
                        ->unique(fn ($r) => implode('|', $r));
                    $route = $routes->count() === 1 ? $routes->first() : null;
                @endphp
                @if ($route || $routes->isEmpty())
                    Being the freight charges from {{ $route[0] ?? $invoice->booking->loading_point ?? '—' }} to
                    {{ $invoice->destination ?? $route[1] ?? $invoice->booking->offloading_point ?? '—' }}.
                @else
                    Being the freight charges for multiple routes (see lines above).
                @endif
            </td>
            <td class="bank-details">
                E.&amp;O.E<br><br>
                <strong>Company's Bank Details</strong><br>
                Account Name: MAKURUMA LOGISTICS LTD<br>
                Account No: 24710025991 USD A/C<br>
                Swift Code: NMIBTZTZ<br>
                Branch: SINZA
            </td>
        </tr>
        <tr>
            <td></td>
            <td style="text-align:center; padding-top:20px;">
                For MAKURUMA LOGISTICS LTD<br><br>
                Authorised Signatory
            </td>
        </tr>
    </table>
</body>

</html>