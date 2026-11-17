<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\ExpenseOrder;
use Illuminate\Validation\ValidationException;

/** Expense UAT req. 1, 3, 4: rules every expense order write must pass. */
class ExpenseOrderRules
{
    private const TYPE_LABELS = [
        'fuel' => 'Fuel',
        'mengine' => 'Other',
        'vibali_congo' => 'Vibali Congo',
        'vibali_tunduma' => 'Vibali Tunduma',
    ];

    /**
     * @param array{booking_ids?: array<int>, category: string, expense_type: string, lines: array<array<string, mixed>>, truck_id?: ?int} $data
     */
    public static function assertValid(array $data, ?ExpenseOrder $existing = null): void
    {
        if ($existing?->is_legacy_mixed) {
            self::fail('lines', 'This order was saved before expense types were separated and mixes types. It can be viewed and downloaded but not edited.');
        }

        $type = $data['expense_type'];
        foreach ($data['lines'] as $line) {
            if ($line['line_category'] !== $type) {
                self::fail('lines', sprintf('A %s order can only contain %s lines. Create a separate order for other expense types.', self::TYPE_LABELS[$type], self::TYPE_LABELS[$type]));
            }
        }

        if ($type === 'fuel') {
            self::assertFuel($data);
        }
        // Orders saved before the forms existed (form = null) can still be edited as they are.
        $isNew = $existing === null;
        if ($type === 'vibali_tunduma' && $isNew && $data['category'] === 'trip' && !in_array($data['form'] ?? null, ['route', 'general'], true)) {
            self::fail('form', 'Choose the Vibali Tunduma form: route (123) or general (129).');
        }
        if ($type === 'vibali_tunduma' && !in_array($data['form'] ?? null, [null, 'route', 'general'], true)) {
            self::fail('form', 'Vibali Tunduma uses the route (123) or general (129) form.');
        }
        if ($type === 'mengine' && $isNew && empty($data['other_subcategory_id'])) {
            self::fail('other_subcategory_id', 'Choose the subcategory of this Other expense (e.g. Weighbridge, GCLA).');
        }
        if ($type === 'vibali_congo' && ($isNew || ($data['form'] ?? null))) {
            self::assertCongo($data);
        }

        $bookingIds = $data['booking_ids'] ?? [];
        $truckLineIds = collect($data['lines'])->pluck('booking_truck_id')->filter()->unique();

        match ($data['category']) {
            'trip' => self::assertTrip($bookingIds, $truckLineIds->all()),
            'truck' => empty($data['truck_id']) || $bookingIds || $truckLineIds->isNotEmpty()
                ? self::fail('truck_id', 'A Truck expense needs one truck and no bookings.')
                : null,
            'office' => !empty($data['truck_id']) || $bookingIds || $truckLineIds->isNotEmpty()
                ? self::fail('category', 'An Office expense cannot have a truck or booking.')
                : null,
        };
    }

    /** Req. 21–25: Vibali Congo is USD only, on a trip, as form 165 (go), 163 (return) or emergency (180). */
    private static function assertCongo(array $data): void
    {
        if ($data['category'] !== 'trip' || !in_array($data['form'] ?? null, ['go', 'return', 'emergency'], true)) {
            self::fail('form', 'Choose the Vibali Congo form: going (165), return settlement (163) or emergency (180).');
        }
        if (collect($data['lines'])->contains(fn ($l) => $l['currency'] !== 'USD')) {
            self::fail('lines', 'Vibali Congo amounts are in USD only.');
        }
        if ($data['form'] === 'return') {
            $parent = ExpenseOrder::find($data['parent_order_id'] ?? null);
            if (!$parent || $parent->expense_type !== 'vibali_congo' || $parent->form !== 'go') {
                self::fail('parent_order_id', 'Choose the Vibali Congo going order (165) this return settles.');
            }
        }
    }

    /** Req. 7, 8: a fuel line needs truck, driver and litres; the price may wait unless paid on the spot. */
    private static function assertFuel(array $data): void
    {
        if ($data['category'] === 'office') {
            self::fail('category', 'Fuel is for a truck: choose Trip or Truck, not Office.');
        }

        foreach ($data['lines'] as $line) {
            if ($data['category'] === 'trip' && empty($line['booking_truck_id'])) {
                self::fail('lines', 'Every fuel line needs a truck.');
            }
            if (!trim((string) ($line['driver_name'] ?? ''))) {
                self::fail('lines', 'Every fuel line needs the driver\'s name.');
            }
            if ((float) ($line['quantity'] ?? 0) <= 0) {
                self::fail('lines', 'Every fuel line needs the litres.');
            }
            if (($data['payment_mode'] ?? 'bill') === 'on_spot' && !isset($line['unit_rate'])) {
                self::fail('lines', 'Fuel paid on the spot needs the price per litre on every line.');
            }
        }
    }

    /** @param array<int> $bookingIds @param array<int> $bookingTruckIds */
    private static function assertTrip(array $bookingIds, array $bookingTruckIds): void
    {
        if (!$bookingIds) {
            self::fail('booking_ids', 'Select at least one booking for a Trip expense.');
        }

        $outside = BookingTruck::whereIn('id', $bookingTruckIds)->whereNotIn('booking_id', $bookingIds)->with('truck')->get();
        if ($outside->isNotEmpty()) {
            self::fail('lines', sprintf('%s is not on the selected booking(s).', $outside->map(fn ($l) => $l->truck?->reg_no ?? 'A truck')->join(', ')));
        }
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
