<?php

namespace App\Exports;

use App\Models\ExpenseOrder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class ExpenseOrderExport implements WithEvents
{
    public function __construct(protected ExpenseOrder $expense)
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
        $darkBlue = '1E3A8A';
        $lightGray = 'F9FAFB';
        $white = 'FFFFFF';

        // --- Logo ---
        $logoPath = public_path('images/logo.png');
        if (file_exists($logoPath)) {
            $drawing = new Drawing();
            $drawing->setPath($logoPath);
            $drawing->setHeight(50);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($sheet);
        }

        // --- Company header ---
        $sheet->setCellValue('C1', 'MAKURUMA LOGISTICS LIMITED');
        $sheet->mergeCells('C1:H1');
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('C2', 'P.O.Box 31902 Dar es salaam-Tanzania, Tel:+255 710 001100, +255 713 013132.');
        $sheet->mergeCells('C2:H2');
        $sheet->getStyle('C2')->getFont()->setSize(9)->setItalic(true);

        $sheet->setCellValue('A4', 'EXPENSE ORDER — ' . $this->expense->order_number);
        $sheet->mergeCells('A4:H4');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // --- Info block ---
        $row = 6;
        $infoPairs = [
            ['Category', ucfirst($this->expense->category) . ' · ' . (['fuel' => 'Fuel', 'vibali_tunduma' => 'Vibali Tunduma', 'vibali_congo' => 'Vibali Congo', 'mengine' => 'Other'][$this->expense->expense_type] ?? '') . ($this->expense->subcategory ? ' · ' . $this->expense->subcategory->name : '')],
            ['Reference', $this->expense->bookings->pluck('booking_number')->join(', ') ?: ($this->expense->truck->reg_no ?? '—')],
            ['Status', strtoupper($this->expense->status)],
        ];
        $col = 'A';
        foreach ($infoPairs as [$label, $value]) {
            $sheet->setCellValue($col . $row, $label);
            $sheet->getStyle($col . $row)->getFont()->setSize(8)->getColor()->setRGB('6B7280');
            $sheet->setCellValue($col . ($row + 1), $value);
            $sheet->getStyle($col . ($row + 1))->getFont()->setBold(true);
            $col = chr(ord($col) + 3);
        }

        $row += 3;
        $infoPairs2 = [
            ['Payment Account', $this->expense->payment_account ?? '—'],
            ['Initiated By', $this->expense->initiated_by ?? '—'],
            ['Payment Date', $this->expense->payment_date ? \Carbon\Carbon::parse($this->expense->payment_date)->format('d M Y') : '—'],
        ];
        $col = 'A';
        foreach ($infoPairs2 as [$label, $value]) {
            $sheet->setCellValue($col . $row, $label);
            $sheet->getStyle($col . $row)->getFont()->setSize(8)->getColor()->setRGB('6B7280');
            $sheet->setCellValue($col . ($row + 1), $value);
            $sheet->getStyle($col . ($row + 1))->getFont()->setBold(true);
            $col = chr(ord($col) + 3);
        }

        // --- Group lines by group_key (same logic as the PDF) ---
        $groups = collect();
        $ungroupedIndex = 0;
        foreach ($this->expense->lines as $line) {
            $key = $line->group_key ?? ('__single_' . $ungroupedIndex++);
            if (!$groups->has($key)) {
                $groups->put($key, collect());
            }
            $groups->get($key)->push($line);
        }

        $tableStartRow = $row + 3;
        $headers = ['S/N', 'Category', 'Vendor', 'Description', 'Trip Code', 'Amount (per truck)', 'No. of Trucks', 'Total (TZS)'];
        $headerRow = $tableStartRow;

        foreach ($headers as $i => $header) {
            $cell = chr(ord('A') + $i) . $headerRow;
            $sheet->setCellValue($cell, $header);
        }
        $sheet->getStyle('A' . $headerRow . ':H' . $headerRow)->getFont()->setBold(true)->getColor()->setRGB($white);
        $sheet->getStyle('A' . $headerRow . ':H' . $headerRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($darkBlue);

        $dataRow = $headerRow + 1;
        $sn = 1;
        foreach ($groups as $group) {
            $first = $group->first();
            $count = $group->count();
            $groupTotal = $group->sum('amount') ?? 0;
            $tripCode = $first->bookingTruck->trip->trip_code ?? '—';
            $tripCodeDisplay = $count > 1 ? $tripCode . ' (+' . ($count - 1) . ' more)' : $tripCode;

            $sheet->setCellValue('A' . $dataRow, $sn++);
            $sheet->setCellValue('B' . $dataRow, str_replace('_', ' ', ucfirst($first->line_category)));
            $sheet->setCellValue('C' . $dataRow, $first->vendor->company_name ?? '—');
            $sheet->setCellValue('D' . $dataRow, $first->description);
            $sheet->setCellValue('E' . $dataRow, $tripCodeDisplay);
            $sheet->setCellValue('F' . $dataRow, $first->currency . ' ' . number_format($first->original_amount ?? 0, 2));
            $sheet->setCellValue('G' . $dataRow, $count);
            $sheet->setCellValue('H' . $dataRow, number_format($groupTotal, 2));

            $sheet->getStyle('A' . $dataRow . ':H' . $dataRow)->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');

            $dataRow++;
        }

        // --- Total row ---
        $sheet->setCellValue('A' . $dataRow, 'Total (TZS)');
        $sheet->mergeCells('A' . $dataRow . ':G' . $dataRow);
        $sheet->setCellValue('H' . $dataRow, number_format($this->expense->total_amount ?? 0, 2));
        $sheet->getStyle('A' . $dataRow . ':H' . $dataRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $dataRow . ':H' . $dataRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($lightGray);

        // --- Trucks covered ---
        $truckRegNos = $this->expense->lines
            ->pluck('bookingTruck.truck.reg_no')
            ->filter()
            ->unique()
            ->values();

        if ($truckRegNos->count() > 0) {
            $trucksHeaderRow = $dataRow + 2;
            $sheet->setCellValue('A' . $trucksHeaderRow, 'Trucks Covered (' . $truckRegNos->count() . ')');
            $sheet->getStyle('A' . $trucksHeaderRow)->getFont()->setBold(true);

            $truckRow = $trucksHeaderRow + 1;
            foreach ($truckRegNos as $i => $regNo) {
                $sheet->setCellValue('A' . $truckRow, ($i + 1) . '. ' . $regNo);
                $truckRow++;
            }
        }

        // --- Column widths ---
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getColumnDimension($col)->setWidth($col === 'D' ? 30 : 18);
        }
    }
}