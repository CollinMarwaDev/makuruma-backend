<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\Checkpoint;
use App\Models\TrackingStatus;
use App\Models\Truck;
use App\Models\TruckMilestone;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The client's tracking spreadsheet (contract 2.2 A/B): two sheets, GOING and RETURN, in the exact
 * layout of resources/templates/tracking-template.xlsx. Export fills it with the open legs; import
 * reads an edited copy back, previews every change, and applies only valid rows.
 *
 * Rows are matched by the hidden ID column (Y) the export writes; a row typed by hand (no ID) is
 * matched by truck number to that truck's latest Go or Return leg. Blank cells leave values as they are.
 */
class TrackingWorkbook
{
    public const TEMPLATE = 'templates/tracking-template.xlsx';
    private const FIRST_ROW = 3;
    private const ID_COLUMN = 'Y';
    private const MAX_ROWS = 2000;

    /** Leg date columns. */
    private const DATE_COLUMNS = [
        'I' => 'loading_point_arrival_date',
        'J' => 'loading_date',
        'K' => 'loading_dispatch_date',
        'W' => 'offloading_point_arrival_date',
        'X' => 'offloading_date',
    ];

    /** Border columns: [arrival, dispatch] pairs, in travel order. */
    private const BORDER_COLUMNS = [['M', 'N'], ['O', 'P'], ['Q', 'R'], ['S', 'T'], ['U', 'V']];

    /** Border headings on the GOING sheet; RETURN lists them in reverse. */
    private const BORDERS = ['TUNDUMA', 'NAKONDE', 'KASUMBALESA/SAKANIA ZAMBIA', 'KASUMBALESA/SAKANIA DRC', 'WHISKY'];

    private const SHEETS = ['go' => 'GOING', 'return' => 'RETURN'];

    /** Status names the client no longer uses (17 Nov 2026) and the ones that replace them. */
    private const RETIRED_STATUSES = [
        'LOADING' => 'WAITING TO LOAD or LOADED - WAITING FOR DOCUMENTS',
        'AT BORDER' => 'CLEARING or WAITING TO CROSS',
        'OFFLOADING' => 'WAITING TO OFFLOAD',
        'COMPLETED' => 'OFFLOADED',
    ];

    public function __construct(private TrackingLegService $legs)
    {
    }

    // --- Export ------------------------------------------------------------------------------

    /** Any legs, open or Offloaded, on their GOING / RETURN sheet in the order given. */
    public function export(Collection $legs): Spreadsheet
    {
        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $legs->loadMissing(['booking:id,direction,loading_point,offloading_point', 'truck:id,reg_no', 'trailer:id,reg_no', 'driver:id,full_name,phone', 'milestones']);
        $statuses = TrackingStatus::pluck('label', 'key');
        $checkpoints = Checkpoint::all();

        foreach (self::SHEETS as $direction => $title) {
            $sheet = $this->sheetFor($book, $title);
            $rows = $legs->filter(fn (BookingTruck $leg) => $leg->booking?->direction === $direction)->values();

            foreach ($rows as $i => $leg) {
                $row = self::FIRST_ROW + $i;
                foreach (range('A', 'X') as $column) {
                    $sheet->duplicateStyle($sheet->getStyle($column . self::FIRST_ROW), $column . $row);
                }

                $sheet->setCellValue("A{$row}", $i + 1);
                $text = [
                    'B' => $leg->truck?->reg_no,
                    'C' => $leg->trailer?->reg_no,
                    'D' => $leg->driver?->full_name,
                    'E' => $leg->driver?->phone,
                    'F' => $leg->current_location,
                    'G' => $statuses[$leg->current_status] ?? $leg->current_status,
                    'H' => $leg->loading_point ?? $leg->booking?->loading_point,
                    'L' => $leg->offloading_point ?? $leg->booking?->offloading_point,
                ];
                foreach (self::DATE_COLUMNS as $column => $field) {
                    $text[$column] = $this->formatDate($leg->{$field});
                }
                foreach ($this->borderCheckpoints($leg, $checkpoints) as $i2 => $checkpoint) {
                    $milestone = $checkpoint ? $leg->milestones->firstWhere('checkpoint_id', $checkpoint->id) : null;
                    [$arrival, $dispatch] = self::BORDER_COLUMNS[$i2];
                    $text[$arrival] = $this->formatDate($milestone?->arrival_at);
                    $text[$dispatch] = $this->formatDate($milestone?->dispatch_at);
                }
                foreach ($text as $column => $value) {
                    if ($value !== null && $value !== '') {
                        // Capitals, like the client's own sheet.
                        $sheet->setCellValueExplicit("{$column}{$row}", mb_strtoupper((string) $value), DataType::TYPE_STRING);
                    }
                }
                $sheet->setCellValue(self::ID_COLUMN . $row, $leg->id);
            }

            $sheet->setCellValue(self::ID_COLUMN . '1', 'SYSTEM ID');
            $sheet->getColumnDimension(self::ID_COLUMN)->setVisible(false);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // --- Import ------------------------------------------------------------------------------

    /**
     * Read the file and work out each row's changes. With $apply, save the valid rows (all in one
     * transaction). Rows with problems are never saved and come back with their reasons.
     *
     * @return array{applied: bool, summary: array{updated: int, unchanged: int, rejected: int}, rows: array}
     */
    public function import(string $path, bool $apply): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'This file could not be read. Upload the tracking Excel file (.xlsx).']);
        }

        $context = [
            'statuses' => TrackingStatus::all(),
            'checkpoints' => Checkpoint::all(),
            'trucks' => Truck::all(['id', 'reg_no'])->keyBy(fn (Truck $truck) => $this->plate($truck->reg_no)),
            'seen' => [],
        ];

        $rows = [];
        $found = false;
        foreach (self::SHEETS as $direction => $title) {
            $sheet = $this->findSheet($book, $title);
            if (!$sheet) {
                continue;
            }
            $found = true;
            $this->assertHeaders($sheet, $direction);

            $last = min($sheet->getHighestDataRow(), self::FIRST_ROW + self::MAX_ROWS);
            for ($r = self::FIRST_ROW; $r <= $last; $r++) {
                if (trim((string) $sheet->getCell("B{$r}")->getFormattedValue()) === '') {
                    continue;
                }
                $rows[] = $this->readRow($sheet, $r, $direction, $context);
            }
        }

        if (!$found) {
            throw ValidationException::withMessages(['file' => 'The file has no GOING or RETURN sheet. Download the tracking Excel and edit that file.']);
        }

        if ($apply) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    if ($row['result'] === 'update') {
                        $this->applyRow($row);
                    }
                }
            });
        }

        $count = fn (string $result) => count(array_filter($rows, fn ($row) => $row['result'] === $result));

        return [
            'applied' => $apply,
            'summary' => ['updated' => $count('update'), 'unchanged' => $count('unchanged'), 'rejected' => $count('rejected')],
            'rows' => array_map(fn ($row) => array_diff_key($row, ['apply' => true]), $rows),
        ];
    }

    private function readRow(Worksheet $sheet, int $r, string $direction, array &$context): array
    {
        $cell = fn (string $column) => $sheet->getCell("{$column}{$r}");
        $text = fn (string $column) => trim(preg_replace('/\s+/', ' ', (string) $cell($column)->getFormattedValue()));
        $plate = $text('B');
        $row = ['sheet' => self::SHEETS[$direction], 'row' => $r, 'truck' => $plate, 'leg_id' => null, 'booking_number' => null, 'trip_code' => null, 'changes' => [], 'errors' => []];
        $reject = function (string $error) use (&$row) {
            $row['errors'][] = $error;
            $row['result'] = 'rejected';

            return $row;
        };

        $leg = $this->findLeg($plate, $direction, $cell(self::ID_COLUMN)->getValue(), $context, $error);
        if (!$leg) {
            return $reject($error);
        }
        $row = array_merge($row, ['leg_id' => $leg->id, 'booking_number' => $leg->booking?->booking_number, 'trip_code' => $leg->trip?->trip_code]);
        if (isset($context['seen'][$leg->id])) {
            return $reject("This truck's {$row['sheet']} leg is already on row {$context['seen'][$leg->id]}.");
        }
        $context['seen'][$leg->id] = $r;

        $apply = ['dates' => [], 'milestones' => [], 'status' => null, 'location' => null];
        $change = function (string $label, $from, $to) use (&$row) {
            $row['changes'][] = ['field' => $label, 'from' => $from, 'to' => $to];
        };

        // Current location and status.
        $location = $text('F');
        if ($location !== '' && mb_strtoupper($location) !== mb_strtoupper((string) $leg->current_location)) {
            $apply['location'] = $location;
            $change('CURRENT LOCATION', $leg->current_location, $location);
        }
        $statusText = $text('G');
        if ($statusText !== '') {
            $status = $context['statuses']->first(fn (TrackingStatus $s) => in_array($this->words($statusText), [$this->words($s->label), $this->words($s->key)], true));
            $retired = self::RETIRED_STATUSES[$this->words($statusText)] ?? null;
            if (!$status && $retired) {
                $row['errors'][] = "CURRENT STATUS: \"{$statusText}\" is no longer used. Write {$retired}.";
            } elseif (!$status) {
                $row['errors'][] = "CURRENT STATUS: \"{$statusText}\" is not a status in the system. An admin can add it under Tracking › Manage statuses.";
            } elseif ($status->key !== $leg->current_status) {
                if (!$status->is_active) {
                    $row['errors'][] = "CURRENT STATUS: \"{$status->label}\" is switched off and can't be used for new updates.";
                } else {
                    $apply['status'] = $status->key;
                    $current = $context['statuses']->firstWhere('key', $leg->current_status);
                    $change('CURRENT STATUS', $current?->label ?? $leg->current_status, $status->label);
                }
            }
        }

        // Leg dates.
        foreach (self::DATE_COLUMNS as $column => $field) {
            $label = $this->heading($sheet, $column);
            $date = $this->readDate($cell($column), $label, $row['errors']);
            if ($date && $date !== $this->isoDate($leg->{$field})) {
                $apply['dates'][$field] = $date;
                $change($label, $this->formatDate($leg->{$field}), $this->formatDate($date));
            }
        }

        // Border dates, on the leg's route (Kasumbalesa or Sakania).
        foreach ($this->borderCheckpoints($leg, $context['checkpoints']) as $i => $checkpoint) {
            foreach (array_combine(['arrival_at', 'dispatch_at'], self::BORDER_COLUMNS[$i]) as $field => $column) {
                $label = $this->heading($sheet, $column);
                $date = $this->readDate($cell($column), $label, $row['errors']);
                if (!$date || !$checkpoint) {
                    continue;
                }
                $saved = $leg->milestones->firstWhere('checkpoint_id', $checkpoint->id)?->{$field};
                if ($date !== $this->isoDate($saved)) {
                    $apply['milestones'][$checkpoint->id][$field] = $date;
                    $change($label, $this->formatDate($saved), $this->formatDate($date));
                }
            }
        }

        if (!$row['errors'] && ($apply['dates'] || $apply['milestones'])) {
            try {
                TrackingTimeline::assertInOrder($leg, $apply['dates'], null, $apply['milestones']);
            } catch (ValidationException $e) {
                $row['errors'][] = collect($e->errors())->flatten()->first();
            }
        }

        $row['result'] = $row['errors'] ? 'rejected' : ($row['changes'] ? 'update' : 'unchanged');
        $row['apply'] = ['leg' => $leg] + $apply;

        return $row;
    }

    private function applyRow(array $row): void
    {
        ['leg' => $leg, 'dates' => $dates, 'milestones' => $milestones, 'status' => $status, 'location' => $location] = $row['apply'];

        if ($dates) {
            $leg->update($dates);
        }
        foreach ($milestones as $checkpointId => $fields) {
            TruckMilestone::updateOrCreate(
                ['booking_truck_id' => $leg->id, 'checkpoint_id' => $checkpointId],
                $fields + ['truck_id' => $leg->truck_id]
            );
        }
        if ($status) {
            $this->legs->updateStatus($leg, $status, $location, null);
        } elseif ($location !== null) {
            $leg->update(['current_location' => $location]);
            $this->legs->mirrorToTruck($leg);
        }
    }

    /** The row's leg: by hidden ID when present (and it must be this truck), else the truck's latest leg in that direction. */
    private function findLeg(string $plate, string $direction, mixed $id, array $context, ?string &$error): ?BookingTruck
    {
        $sheetName = self::SHEETS[$direction];
        $truck = $context['trucks'][$this->plate($plate)] ?? null;
        if (!$truck) {
            $error = "Truck \"{$plate}\" is not in the system.";

            return null;
        }

        $legs = BookingTruck::query()
            ->with(['booking:id,booking_number,direction', 'trip:id,trip_code', 'milestones'])
            ->whereNull('removed_at')
            ->where('truck_id', $truck->id)
            ->whereHas('booking', fn ($q) => $q->where('direction', $direction));

        if (is_numeric($id)) {
            $leg = (clone $legs)->find((int) $id);
            if (!$leg) {
                $error = $this->wrongIdReason((int) $id, $plate, $sheetName);
            }

            return $leg;
        }

        $leg = $legs->orderByRaw('current_status = ? asc', [TrackingStatus::OFFLOADED])->orderByDesc('id')->first();
        if (!$leg) {
            $error = "{$plate} has no " . ($direction === 'go' ? 'Go' : 'Return') . ' booking to update.';
        }

        return $leg;
    }

    /** Why a row's hidden ID does not fit its truck and sheet, so the user knows what to fix. */
    private function wrongIdReason(int $id, string $plate, string $sheetName): string
    {
        $other = BookingTruck::with(['booking:id,booking_number,direction', 'truck:id,reg_no'])->find($id);
        $fresh = 'Download a fresh copy of the tracking Excel and enter the change there.';

        return match (true) {
            !$other || !$other->booking => "The hidden ID on this row points to a trip that no longer exists (its booking was deleted). {$fresh}",
            $other->removed_at !== null => "{$plate} was taken off booking {$other->booking->booking_number} (truck removed or changed) after this file was downloaded. {$fresh}",
            $this->plate((string) $other->truck?->reg_no) !== $this->plate($plate) => "The hidden ID on this row belongs to {$other->truck?->reg_no} on {$other->booking->booking_number}, not {$plate}: the truck number was changed or the row was copied. {$fresh}",
            default => "The hidden ID on this row belongs to {$plate}'s " . self::SHEETS[$other->booking->direction] . " leg ({$other->booking->booking_number}), but the row is on the {$sheetName} sheet. Move it back to the " . self::SHEETS[$other->booking->direction] . " sheet or {$fresh}",
        };
    }

    /** The checkpoint behind each border column pair for this leg's route (null when not on its route). */
    private function borderCheckpoints(BookingTruck $leg, Collection $checkpoints): array
    {
        $route = $leg->route ?: 'kasumbalesa';
        $sequence = $leg->booking?->direction === 'return' ? [5, 4, 3, 2, 1] : [1, 2, 3, 4, 5];

        return array_map(fn (int $order) => $checkpoints->first(
            fn (Checkpoint $c) => $c->sequence_order === $order && ($c->route === null || $c->route === $route)
        ), $sequence);
    }

    private function assertHeaders(Worksheet $sheet, string $direction): void
    {
        $borders = $direction === 'go' ? self::BORDERS : array_reverse(self::BORDERS);
        $expected = [
            'B1' => 'TRUCKS DETAILS', 'D1' => "DRIVER'S DETAILS", 'F1' => 'CURRENT LOCATION', 'G1' => 'CURRENT STATUS',
            'H1' => 'LOADING POINT', 'I1' => 'LOADING POINT ARRIVAL', 'J1' => 'DATE OF LOADING', 'K1' => 'DISPATCH',
            'L1' => 'OFFLOADING POINT', 'W1' => 'OFFLOADING POINT ARRIVAL', 'X1' => 'OFFLOADING DATE', 'B2' => 'TRUCK',
        ];
        foreach (self::BORDER_COLUMNS as $i => [$arrival, $dispatch]) {
            $expected["{$arrival}1"] = $borders[$i];
            $expected["{$arrival}2"] = 'ARRIVAL';
            $expected["{$dispatch}2"] = 'DISPATCH';
        }

        foreach ($expected as $address => $heading) {
            $actual = $this->words((string) $sheet->getCell($address)->getValue());
            if ($actual !== $this->words($heading)) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Sheet %s: cell %s should be "%s" but is "%s". Use the tracking Excel layout without moving columns.', trim($sheet->getTitle()), $address, $heading, trim((string) $sheet->getCell($address)->getValue())),
                ]);
            }
        }
    }

    /** Accepts 29.08.2026 (also with , / or - between the parts) and real Excel dates. Returns Y-m-d. */
    private function readDate($cell, string $label, array &$errors): ?string
    {
        $value = $cell->getValue();
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value) && $value > 20000 && $value < 80000) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->toDateString();
        }
        if (preg_match('/^\s*(\d{1,2})\s*[.,\/-]\s*(\d{1,2})\s*[.,\/-]\s*(\d{4}|\d{2})\s*$/', (string) $value, $m)) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
            }
        }
        $errors[] = "{$label}: \"{$value}\" is not a valid date. Write it as day.month.year, e.g. 29.08.2026.";

        return null;
    }

    private function heading(Worksheet $sheet, string $column): string
    {
        $top = trim((string) $sheet->getCell("{$column}1")->getValue());
        $sub = trim((string) $sheet->getCell("{$column}2")->getValue());
        if ($top === '' && $sub !== '') {
            // Right half of a merged border heading.
            $top = trim((string) $sheet->getCell(chr(ord($column) - 1) . '1')->getValue());
        }

        return trim("{$top} {$sub}");
    }

    private function sheetFor(Spreadsheet $book, string $title): Worksheet
    {
        return $this->findSheet($book, $title) ?? throw new \RuntimeException("Template sheet {$title} is missing.");
    }

    private function findSheet(Spreadsheet $book, string $title): ?Worksheet
    {
        foreach ($book->getAllSheets() as $sheet) {
            if (mb_strtoupper(trim($sheet->getTitle())) === $title) {
                return $sheet;
            }
        }

        return null;
    }

    private function plate(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** "On Route to Loading", "on_route_to_loading" and "ON ROUTE  TO LOADING" all compare equal. */
    private function words(string $value): string
    {
        return trim(preg_replace('/[^A-Z0-9\/\']+/', ' ', mb_strtoupper($value)));
    }

    private function isoDate($value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function formatDate($value): ?string
    {
        return $value ? Carbon::parse($value)->format('d.m.Y') : null;
    }
}
