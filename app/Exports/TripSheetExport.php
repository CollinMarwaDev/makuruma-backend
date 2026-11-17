<?php

namespace App\Exports;

use App\Models\Trip;
use App\Services\ExpenseReportLines;
use App\Services\TripProfitCalculator;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TripSheetExport implements WithEvents, WithTitle
{
    public function __construct(protected Trip $trip, protected int $index)
    {
    }

    public function title(): string
    {
        return "TRIP {$this->index}";
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
        $row = 2;
        $sheet->setCellValue("B{$row}", "TRIP {$this->index}, ");
        $sheet->setCellValue("D{$row}", $this->trip->truck->reg_no);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row += 1;

        $row = $this->buildLeg($sheet, $row, 'MZIGO WA KWENDA', $this->trip->goBookingTruck);
        $row = $this->buildLeg($sheet, $row, 'MZIGO WA KURUDI', $this->trip->returnBookingTruck);

        $this->buildExpenses($sheet, $row);
    }

    protected function buildLeg($sheet, int $row, string $label, $bookingTruck): int
    {
        $sheet->setCellValue("C{$row}", $label);
        $sheet->getStyle("C{$row}")->getFont()->setBold(true);
        $row++;

        if (!$bookingTruck) {
            $sheet->setCellValue("C{$row}", 'No data yet.');
            return $row + 2;
        }

        $booking = $bookingTruck->booking;

        $sheet->setCellValue("C{$row}", 'ALIFIKA KUPAKIA');
        $sheet->setCellValue("D{$row}", $bookingTruck->loading_point_arrival_date);
        $sheet->setCellValue("E{$row}", $booking->loading_point);
        $row++;

        $sheet->setCellValue("C{$row}", 'ALISHUSHA');
        $sheet->setCellValue("D{$row}", $bookingTruck->offloading_date);
        $sheet->setCellValue("E{$row}", $booking->offloading_point);
        $row++;

        $sheet->setCellValue("C{$row}", 'MTEJA');
        $sheet->setCellValue("D{$row}", $booking->client->company_name ?? '—');
        $row++;

        $sheet->setCellValue("C{$row}", 'MZIGO');
        $sheet->setCellValue("D{$row}", $bookingTruck->cargo ?? '—');
        $row++;

        $headers = ['BEI', 'UZITO', 'AMOUNT', 'EXCHANGE', 'JUMLA'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('E') + $i) . $row, $h);
        }
        $sheet->getStyle("E{$row}:I{$row}")->getFont()->setBold(true);
        $row++;

        $summary = TripProfitCalculator::legRevenueSummary($bookingTruck);
        $sheet->setCellValue("D{$row}", 'TRANSPORT CHARGE');
        $sheet->setCellValue("E{$row}", $summary['bei']);
        $sheet->setCellValue("F{$row}", $summary['uzito']);
        $sheet->setCellValue("G{$row}", $summary['amount']);
        $sheet->setCellValue("H{$row}", $summary['exchange']);
        $sheet->setCellValue("I{$row}", $summary['jumla']);
        $row += 2;

        return $row;
    }

    protected function buildExpenses($sheet, int $row): void
    {
        $sheet->setCellValue("B{$row}", 'MATUMIZI');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row++;

        $headers = ['TAREHE', 'PG', 'MAELEZO', 'VALUE', 'UNIT', 'RATE', 'AMOUNT'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('B') + $i) . $row, $h);
        }
        $sheet->getStyle("B{$row}:H{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("B{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
        $row++;

        $lines = collect([$this->trip->goBookingTruck, $this->trip->returnBookingTruck])
            ->filter()
            ->flatMap(fn ($leg) => ExpenseReportLines::forLeg($leg));

        $categories = [
            'fuel' => 'MAFUTA',
            'vibali_tunduma' => 'VIBALI TUNDUMA',
            'vibali_congo' => 'VIBALI CONGO',
            'mengine' => 'MENGINE',
        ];

        foreach ($categories as $key => $label) {
            // Other expenses are grouped by subcategory (Expense UAT req. 26).
            $groups = $lines->where('category', $key)->groupBy(fn ($l) => $key === 'mengine' ? ($l['subcategory'] ?? '') : '');

            foreach ($groups as $subcategory => $group) {
                $sheet->setCellValue("B{$row}", $subcategory !== '' ? "{$label} — " . mb_strtoupper($subcategory) : $label);
                $sheet->getStyle("B{$row}")->getFont()->setBold(true);
                $row++;

                foreach ($group as $l) {
                    $sheet->setCellValue("B{$row}", $l['date']);
                    $sheet->setCellValue("C{$row}", $l['line']->expenseOrder?->order_number ?? $l['line']->expense_order_id);
                    $sheet->setCellValue("D{$row}", $l['description'] . ($l['share_of'] ? " (1/{$l['share_of']})" : ''));
                    $sheet->setCellValue("E{$row}", $l['value']);
                    $sheet->setCellValue("F{$row}", $l['unit']);
                    $sheet->setCellValue("G{$row}", $l['rate']);
                    $sheet->setCellValue("H{$row}", $l['amount']);
                    $row++;
                }
            }
        }

        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('D')->setWidth(24);
    }
}