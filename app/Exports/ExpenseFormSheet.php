<?php

namespace App\Exports;

use App\Models\ExpenseOrder;
use App\Services\ExpensePrintout;
use DOMElement;
use DOMXPath;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * UAT 20 Nov 2026: the expense Excel is the order's printed form. The same Blade view as the PDF is
 * rendered and written to the sheet in order: letterhead, NO and title, the line table (subtotals,
 * conversions, totals), the truck lists side by side (four per row) and the per-truck table. Money
 * cells stay numbers with their currency sign as the number format, so they can be added up.
 */
class ExpenseFormSheet implements WithEvents, WithTitle
{
    private const LIST_WIDTH = 3;

    private const LISTS_PER_ROW = 4;

    private int $row = 1;

    public function __construct(private ExpenseOrder $expense, private bool $showPrice = true)
    {
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]+/', '-', 'NO ' . $this->expense->form_number), 0, 31);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $sheet): void
    {
        $html = view(ExpensePrintout::view($this->expense), ExpensePrintout::data($this->expense, $this->showPrice))->render();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $this->logo($sheet);
        foreach ($xpath->query('//body/*') as $node) {
            $this->block($sheet, $node, $xpath);
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(34);
        foreach (range(3, self::LIST_WIDTH * self::LISTS_PER_ROW) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(14);
        }
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
    }

    private function logo(Worksheet $sheet): void
    {
        $path = public_path('images/logo.png');
        if (!is_file($path)) {
            return;
        }
        $drawing = new Drawing();
        $drawing->setPath($path);
        $drawing->setHeight(48);
        $drawing->setCoordinates('A1');
        $drawing->setWorksheet($sheet);
    }

    private function block(Worksheet $sheet, DOMElement $node, DOMXPath $xpath): void
    {
        $class = ' ' . $node->getAttribute('class') . ' ';

        if (str_contains($class, ' header ')) {
            foreach ($xpath->query('.//div[contains(@class,"company-info")]/div', $node) as $i => $line) {
                $sheet->setCellValue("C{$this->row}", $this->text($line));
                $sheet->getStyle("C{$this->row}")->getFont()->setBold($i === 0)->setSize($i === 0 ? 15 : 10);
                $this->row++;
            }
            $this->row++;

            return;
        }

        if ($node->nodeName === 'table') {
            str_contains($class, ' lists ') ? $this->lists($sheet, $node, $xpath) : $this->table($sheet, $node, $xpath, 1);
            $this->row++;

            return;
        }

        if (str_contains($class, ' sign ')) {
            foreach ($xpath->query('./div', $node) as $i => $part) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex(1 + $i * 4) . $this->row, $this->text($part));
            }
            $this->row += 2;

            return;
        }

        $text = $this->text($node);
        if ($text === '') {
            return;
        }
        // NO, title and notes: centred over the line table; the title in capitals like the PDF.
        $sheet->setCellValue("A{$this->row}", str_contains($class, ' title ') ? mb_strtoupper($text) : $text);
        $sheet->mergeCells("A{$this->row}:E{$this->row}");
        $style = $sheet->getStyle("A{$this->row}");
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $style->getFont()->setBold(str_contains($class, ' number ') || str_contains($class, ' title '))->setSize(str_contains($class, ' number ') ? 13 : 11);
        $this->row++;
    }

    /** One HTML table from column $startCol; returns the number of rows written. */
    private function table(Worksheet $sheet, DOMElement $table, DOMXPath $xpath, int $startCol, ?int $startRow = null): int
    {
        $row = $startRow ?? $this->row;
        $first = $row;
        $lastCol = $startCol;
        foreach ($xpath->query('.//tr', $table) as $tr) {
            $col = $startCol;
            $trClass = ' ' . $tr->getAttribute('class') . ' ';
            foreach ($xpath->query('./th|./td', $tr) as $cell) {
                $span = max(1, (int) $cell->getAttribute('colspan'));
                $from = Coordinate::stringFromColumnIndex($col) . $row;
                $to = Coordinate::stringFromColumnIndex($col + $span - 1) . $row;
                $this->value($sheet, $from, $this->text($cell));
                if ($span > 1) {
                    $sheet->mergeCells("{$from}:{$to}");
                }
                $style = $sheet->getStyle("{$from}:{$to}");
                $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9CA3AF');
                $style->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                $style->getAlignment()->setHorizontal(str_contains(' ' . $cell->getAttribute('class') . ' ', ' num ') ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);
                if ($cell->nodeName === 'th') {
                    $style->getFont()->setBold(true);
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
                }
                if (preg_match('/ (subtotal|grand|total|payee) /', $trClass)) {
                    $style->getFont()->setBold(true);
                }
                if (str_contains($trClass, ' grand ')) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
                }
                $col += $span;
            }
            $lastCol = max($lastCol, $col - 1);
            $row++;
        }
        if ($startRow === null) {
            $this->row = $row;
        }

        return $row - $first;
    }

    /** A row of side-by-side lists (truck lists): each nested table gets LIST_WIDTH columns, four per row. */
    private function lists(Worksheet $sheet, DOMElement $table, DOMXPath $xpath): void
    {
        foreach ($xpath->query('./tr|./tbody/tr', $table) as $tr) {
            $cells = iterator_to_array($xpath->query('./td', $tr));
            foreach (array_chunk($cells, self::LISTS_PER_ROW) as $chunk) {
                $height = 0;
                foreach ($chunk as $i => $cell) {
                    $col = 1 + $i * self::LIST_WIDTH;
                    $inner = $xpath->query('.//table', $cell)->item(0);
                    if ($inner instanceof DOMElement) {
                        $height = max($height, $this->table($sheet, $inner, $xpath, $col, $this->row));
                    } elseif (($text = $this->text($cell)) !== '') {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $this->row, $text);
                        $sheet->getStyle(Coordinate::stringFromColumnIndex($col) . $this->row)->getFont()->setBold(true);
                        $height = max($height, 1);
                    }
                }
                $this->row += $height + 1;
            }
        }
    }

    /** Money and counts as numbers ("$1,250" → 1250 shown as $1,250); everything else as text. */
    private function value(Worksheet $sheet, string $cell, string $text): void
    {
        if (preg_match('/^(-?)(\$|TSh|ZMK )?(-?)([\d,]+(?:\.\d+)?)$/', $text, $m)) {
            $number = (float) str_replace(',', '', $m[4]) * (($m[1] === '-' || $m[3] === '-') ? -1 : 1);
            $decimals = str_contains($m[4], '.') ? '.00' : '';
            $prefix = $m[2] !== '' ? '"' . $m[2] . '"' : '';
            $sheet->setCellValue($cell, $number);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode("{$prefix}#,##0{$decimals};-{$prefix}#,##0{$decimals}");

            return;
        }
        $sheet->setCellValueExplicit($cell, $text, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    private function text(\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
