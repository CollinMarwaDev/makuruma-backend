<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TrackingSheetExport implements WithEvents, WithTitle
{
    public function __construct(
        protected Collection $trucks,
        protected Collection $checkpoints,
        protected string $sheetTitle
    ) {
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->build($event->sheet->getDelegate());
            },
        ];
    }

    protected function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    protected function build($sheet): void
    {
        $darkBlue = '1E3A8A';
        $white = 'FFFFFF';

        // Fixed columns before the checkpoint block
        $fixedGroups = [
            ['label' => 'S/N', 'span' => 1],
            ['label' => "TRUCK'S DETAILS", 'span' => 2, 'subs' => ['TRUCK', 'TRAILER']],
            ['label' => "DRIVER'S DETAILS", 'span' => 2, 'subs' => ["DRIVER'S NAME", 'CONTACTS']],
            ['label' => 'CURRENT LOCATION', 'span' => 1],
            ['label' => 'CURRENT STATUS', 'span' => 1],
            ['label' => 'LOADING POINT', 'span' => 1],
            ['label' => 'LOADING POINT ARRIVAL', 'span' => 1],
            ['label' => 'DATE OF LOADING', 'span' => 1],
            ['label' => 'DISPATCH', 'span' => 1],
            ['label' => 'OFFLOADING POINT', 'span' => 1],
        ];

        $headerRow1 = 1;
        $headerRow2 = 2;
        $colIndex = 1;

        foreach ($fixedGroups as $group) {
            $startCol = $this->col($colIndex);
            if ($group['span'] > 1) {
                $endCol = $this->col($colIndex + $group['span'] - 1);
                $sheet->mergeCells("{$startCol}{$headerRow1}:{$endCol}{$headerRow1}");
                $sheet->mergeCells("{$startCol}{$headerRow1}:{$endCol}{$headerRow1}");
                foreach ($group['subs'] as $i => $sub) {
                    $sheet->setCellValue($this->col($colIndex + $i) . $headerRow2, $sub);
                }
            } else {
                $sheet->mergeCells("{$startCol}{$headerRow1}:{$startCol}{$headerRow2}");
            }
            $sheet->setCellValue($startCol . $headerRow1, $group['label']);
            $colIndex += $group['span'];
        }

        // Checkpoint groups — each gets Arrival + Dispatch sub-columns
        foreach ($this->checkpoints as $checkpoint) {
            $startCol = $this->col($colIndex);
            $endCol = $this->col($colIndex + 1);
            $sheet->mergeCells("{$startCol}{$headerRow1}:{$endCol}{$headerRow1}");
            $sheet->setCellValue($startCol . $headerRow1, strtoupper($checkpoint->name));
            $sheet->setCellValue($startCol . $headerRow2, 'ARRIVAL');
            $sheet->setCellValue($endCol . $headerRow2, 'DISPATCH');
            $colIndex += 2;
        }

        // Trailing columns
        $trailingGroups = ['OFFLOADING POINT ARRIVAL', 'OFFLOADING DATE'];
        foreach ($trailingGroups as $label) {
            $startCol = $this->col($colIndex);
            $sheet->mergeCells("{$startCol}{$headerRow1}:{$startCol}{$headerRow2}");
            $sheet->setCellValue($startCol . $headerRow1, $label);
            $colIndex++;
        }

        $lastCol = $this->col($colIndex - 1);

        // Style the header block
        $sheet->getStyle("A{$headerRow1}:{$lastCol}{$headerRow2}")->getFont()->setBold(true)->getColor()->setRGB($white);
        $sheet->getStyle("A{$headerRow1}:{$lastCol}{$headerRow2}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($darkBlue);
        $sheet->getStyle("A{$headerRow1}:{$lastCol}{$headerRow2}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        // Data rows
        $row = 3;
        foreach ($this->trucks as $index => $truck) {
            $bt = $truck->bookingTrucks->first();
            $colIndex = 1;

            $sheet->setCellValue($this->col($colIndex++) . $row, $index + 1);
            $sheet->setCellValue($this->col($colIndex++) . $row, $truck->reg_no);
            $sheet->setCellValue($this->col($colIndex++) . $row, $truck->trailer->reg_no ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $truck->driver->full_name ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $truck->driver->phone ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $truck->current_location ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, strtoupper(str_replace('_', ' ', $truck->current_status)));
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->loading_point ?? $bt->booking->loading_point ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->loading_point_arrival_date ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->loading_date ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->loading_dispatch_date ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->offloading_point ?? $bt->booking->offloading_point ?? '—');

            foreach ($this->checkpoints as $checkpoint) {
                $milestone = $truck->milestones->firstWhere('checkpoint_id', $checkpoint->id);
                $sheet->setCellValue($this->col($colIndex++) . $row, $milestone?->arrival_at?->format('d.m.Y') ?? '');
                $sheet->setCellValue($this->col($colIndex++) . $row, $milestone?->dispatch_at?->format('d.m.Y') ?? '');
            }

            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->offloading_point_arrival_date ?? '—');
            $sheet->setCellValue($this->col($colIndex++) . $row, $bt->offloading_date ?? '—');

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');

            $row++;
        }

        for ($i = 1; $i < $colIndex; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(14);
        }

        if ($this->trucks->isEmpty()) {
            $sheet->setCellValue('A3', 'No trucks currently on a ' . strtolower($this->sheetTitle) . ' trip.');
        }
    }
}