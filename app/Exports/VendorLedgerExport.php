<?php

namespace App\Exports;

use App\Models\ExpenseLine;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class VendorLedgerExport implements WithEvents
{
    public function __construct(protected Vendor $vendor)
    {
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->build($event->sheet->getDelegate());
            },
        ];
    }

    protected function build($sheet): void
    {
        $sheet->setCellValue('B1', 'DENI ' . strtoupper($this->vendor->company_name));
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 3;
        $headers = ['S/N', 'DATE', 'ORDER NO.', 'T.NO', 'LTR', 'LOCATION', 'PRICE', 'AMOUNT', 'MALIPO', 'BALANCE'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('B') + $i) . $headerRow, $h);
        }
        $sheet->getStyle("B{$headerRow}:K{$headerRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("B{$headerRow}:K{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');

        // Merge fuel purchases (debits) and vendor payments (credits) into one
        // chronological ledger, exactly like the real vendor statement.
        $debits = ExpenseLine::where('vendor_id', $this->vendor->id)
            ->with('bookingTruck.truck', 'truck', 'expenseOrder')
            ->get()
            ->map(fn($line) => [
                // UAT 20 Nov: dated by the filling day when the vendor gave it (e.g. Mabena), else the order's date.
                'date' => \Carbon\Carbon::parse($line->filled_at ?? $line->expenseOrder?->payment_date ?? $line->created_at)->toDateString(),
                'order_no' => $line->expenseOrder?->form_number ?? $line->expense_order_id,
                'truck' => $line->bookingTruck?->truck?->reg_no ?? $line->truck?->reg_no,
                'ltr' => $line->quantity,
                'location' => $line->description,
                'price' => $line->unit_rate,
                'amount' => (float) $line->amount,
                'malipo' => null,
            ]);

        $credits = VendorPayment::where('vendor_id', $this->vendor->id)
            ->get()
            ->map(fn($payment) => [
                'date' => \Carbon\Carbon::parse($payment->payment_date)->toDateString(),
                'order_no' => null,
                'truck' => null,
                'ltr' => null,
                'location' => $payment->description,
                'price' => null,
                'amount' => null,
                'malipo' => (float) $payment->amount,
            ]);

        $ledger = $debits->concat($credits)->sortBy('date')->values();

        $row = $headerRow + 1;
        $balance = 0;
        $sn = 1;

        foreach ($ledger as $entry) {
            $balance += ($entry['amount'] ?? 0) - ($entry['malipo'] ?? 0);

            $sheet->setCellValue("B{$row}", $sn++);
            $sheet->setCellValue("C{$row}", \Carbon\Carbon::parse($entry['date'])->format('Y-m-d'));
            $sheet->setCellValue("D{$row}", $entry['order_no']);
            $sheet->setCellValue("E{$row}", $entry['truck']);
            $sheet->setCellValue("F{$row}", $entry['ltr']);
            $sheet->setCellValue("G{$row}", $entry['location']);
            $sheet->setCellValue("H{$row}", $entry['price']);
            $sheet->setCellValue("I{$row}", $entry['amount']);
            $sheet->setCellValue("J{$row}", $entry['malipo']);
            $sheet->setCellValue("K{$row}", $balance);
            $row++;
        }

        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(15);
        }
        $sheet->getColumnDimension('G')->setWidth(22);
    }
}