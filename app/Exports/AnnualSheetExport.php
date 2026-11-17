<?php

namespace App\Exports;

use App\Models\ExpenseLine;
use App\Models\ExpenseOrder;
use App\Models\Trip;
use App\Models\Truck;
use App\Services\TripProfitCalculator;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AnnualSheetExport implements WithEvents, WithTitle
{
    public function __construct(protected Truck $truck, protected int $year, protected array $tripProfits)
    {
    }

    public function title(): string
    {
        return 'ANNUAL';
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
        $sheet->setCellValue("B{$row}", $this->truck->reg_no);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row += 2;

        // --- Truck-specific annual costs (Truck category expenses) ---
        $sheet->setCellValue("B{$row}", 'MATUMIZI YANAYOLIPWA KWA MWAKA');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row++;

        $headers = ['TAREHE', 'MAELEZO', 'KIASI', 'UNIT', 'RATE', 'AMOUNT'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('B') + $i) . $row, $h);
        }
        $sheet->getStyle("B{$row}:G{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("B{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
        $row++;

        // A Truck order may cover several trucks: each line counts for its own truck.
        $annualLines = ExpenseLine::where('truck_id', $this->truck->id)
            ->whereHas('expenseOrder', fn ($q) => $q->where('category', 'truck'))
            ->whereYear('created_at', $this->year)->get();

        foreach ($annualLines as $line) {
            $sheet->setCellValue("B{$row}", $line->created_at->format('Y-m-d'));
            $sheet->setCellValue("C{$row}", $line->description);
            $sheet->setCellValue("D{$row}", $line->original_amount);
            $sheet->setCellValue("E{$row}", $line->currency);
            $sheet->setCellValue("F{$row}", $line->exchange_rate);
            $sheet->setCellValue("G{$row}", $line->amount);
            $row++;
        }

        $annualCostsTotal = (float) $annualLines->sum('amount');
        $sheet->setCellValue("G{$row}", $annualCostsTotal);
        $sheet->getStyle("G{$row}")->getFont()->setBold(true);
        $row += 2;

        // --- Company overhead divided per truck ---
        $activeTruckCount = max(Truck::where('status', 'active')->count(), 1);

        $sheet->setCellValue("B{$row}", 'MATUMIZI YA JUMLA KISHA KUGAWANYWA KWA KILA GARI');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue("B{$row}", "NOTE: IDADI YA GARI NI {$activeTruckCount}");
        $row++;

        $overheadHeaders = ['JUMLA', 'MAELEZO', 'PER TRUCK', 'UNIT', 'RATE', 'AMOUNT'];
        foreach ($overheadHeaders as $i => $h) {
            $sheet->setCellValue(chr(ord('B') + $i) . $row, $h);
        }
        $sheet->getStyle("B{$row}:G{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("B{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
        $row++;

        $officeOrders = ExpenseOrder::where('category', 'office')
            ->whereYear('created_at', $this->year)
            ->with('lines')
            ->get();

        $overheadShareTotal = 0;
        $companyTotalAll = 0;

        foreach ($officeOrders as $order) {
            foreach ($order->lines as $line) {
                $companyWide = (float) $line->amount;
                $perTruck = round($companyWide / $activeTruckCount, 2);

                $sheet->setCellValue("B{$row}", $companyWide);
                $sheet->setCellValue("C{$row}", $line->description);
                $sheet->setCellValue("D{$row}", $perTruck);
                $sheet->setCellValue("E{$row}", $line->currency);
                $sheet->setCellValue("F{$row}", 1);
                $sheet->setCellValue("G{$row}", $perTruck);
                $row++;

                $overheadShareTotal += $perTruck;
                $companyTotalAll += $companyWide;
            }
        }

        $sheet->setCellValue("B{$row}", $companyTotalAll);
        $sheet->setCellValue("G{$row}", $overheadShareTotal);
        $sheet->getStyle("B{$row}:G{$row}")->getFont()->setBold(true);
        $row++;

        $grandOverheadAndAnnual = $annualCostsTotal + $overheadShareTotal;
        $sheet->setCellValue("F{$row}", 'JUMLA');
        $sheet->setCellValue("G{$row}", $grandOverheadAndAnnual);
        $sheet->getStyle("F{$row}:G{$row}")->getFont()->setBold(true);
        $row += 2;

        // --- Profit summary ---
        $sheet->setCellValue("E{$row}", "SUMMARY YA FAIDA {$this->year}");
        $sheet->getStyle("E{$row}")->getFont()->setBold(true);
        $row++;

        $totalProfit = 0;
        foreach ($this->tripProfits as $index => $profit) {
            $sheet->setCellValue("E{$row}", 'TRIP ' . ($index + 1));
            $sheet->setCellValue("G{$row}", $profit);
            $totalProfit += $profit;
            $row++;
        }

        $sheet->setCellValue("E{$row}", 'ANNUAL');
        $sheet->setCellValue("G{$row}", -$grandOverheadAndAnnual);
        $totalProfit -= $grandOverheadAndAnnual;
        $row++;

        $sheet->setCellValue("E{$row}", 'TOTAL');
        $sheet->setCellValue("G{$row}", $totalProfit);
        $sheet->getStyle("E{$row}:G{$row}")->getFont()->setBold(true);

        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(20);
        }
    }
}