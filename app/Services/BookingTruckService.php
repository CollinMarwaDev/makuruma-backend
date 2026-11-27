<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingTruck;
use App\Models\Trip;
use App\Models\Truck;
use Illuminate\Validation\ValidationException;

/**
 * Rules for putting trucks on, swapping them on and taking them off a booking (contract 2.1 B).
 * Used by every write path so the rules hold no matter which screen calls the API.
 */
class BookingTruckService
{
    /**
     * Trip statuses that let a truck join a booking of this direction.
     *
     * Hotfix 27 Nov 2026 (truck T 270 ELE): a truck can be booked whatever its current status, e.g. a
     * Go can be booked while its Return is still in transit so it loads again once offloaded. The only
     * block is a second Go while a Go trip still has no Return booked (trip status "go").
     */
    public static function allowedTripStatuses(string $direction): array
    {
        return $direction === 'go' ? ['off_duty', 'return'] : ['go'];
    }

    /** Contract 2.1 B-9: a truck needs a trailer and a driver to be booked. */
    public function assertBookable(Truck $truck, string $direction, string $field = 'trucks'): void
    {
        $missing = array_filter([
            $truck->trailer_id ? null : 'trailer',
            $truck->driver_id ? null : 'driver',
        ]);

        if ($missing) {
            throw ValidationException::withMessages([
                $field => "{$truck->reg_no} has no " . implode(' or ', $missing) . ' assigned. Assign it on the Trucks page before booking.',
            ]);
        }

        if (!in_array($truck->trip_status, self::allowedTripStatuses($direction), true)) {
            $message = $direction === 'go'
                ? "{$truck->reg_no} already has a Go booking with no Return booked. Book its Return before booking another Go."
                : "{$truck->reg_no} must be on a Go trip awaiting return to be added to a return booking.";
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** Put a truck on a booking and open (Go) or extend (Return) its trip. */
    public function attach(Booking $booking, Truck $truck, array $details): BookingTruck
    {
        $this->assertBookable($truck, $booking->direction);

        $bookingTruck = $booking->bookingTrucks()->create([
            'truck_id' => $truck->id,
            'trailer_id' => $truck->trailer_id,
            'driver_id' => $truck->driver_id,
            'capacity' => $details['tons'] ?? $truck->capacity,
            'cargo' => $details['cargo'] ?? null,
            'rate' => $details['rate'] ?? null,
            'loading_point' => $details['loading_point'] ?? null,
            'offloading_point' => $details['offloading_point'] ?? null,
            'eta' => $details['eta'] ?? null,
            'current_status' => 'on_route_to_loading',
        ]);

        $this->startLeg($booking, $bookingTruck, $truck);

        return $bookingTruck;
    }

    /**
     * Correct a mistaken truck on a booking (contract 2.1 B-4). The booking-truck row is kept so
     * expenses and invoices linked to it now point at the right truck. Refused once the truck has
     * loaded or been invoiced, because those records describe the original truck.
     */
    public function swap(BookingTruck $bookingTruck, Truck $newTruck): BookingTruck
    {
        $booking = $bookingTruck->booking;

        if ($bookingTruck->truck_id === $newTruck->id) {
            return $bookingTruck;
        }
        if ($bookingTruck->loading_date || $bookingTruck->invoiceLines()->exists()) {
            throw ValidationException::withMessages([
                'truck_id' => 'This truck has already loaded or been invoiced on this booking. Remove it and add the correct truck instead.',
            ]);
        }

        $this->assertBookable($newTruck, $booking->direction, 'truck_id');
        $this->assertNoReturnBooked($bookingTruck, 'truck_id', ' Remove it from that return booking first.');

        $oldTruck = $bookingTruck->truck;
        $details = [
            'truck_id' => $newTruck->id,
            'trailer_id' => $newTruck->trailer_id,
            'driver_id' => $newTruck->driver_id,
        ];

        if ($booking->direction === 'go' && $bookingTruck->trip) {
            // Keep the trip (and anything linked to it); it now belongs to the correct truck.
            $bookingTruck->trip->update([
                'truck_id' => $newTruck->id,
                'trip_code' => TripCodeGenerator::generate($newTruck->reg_no),
            ]);
            $bookingTruck->update($details);
            $this->releaseAfterGoRemoved($oldTruck, $bookingTruck->id);
            $newTruck->update(['trip_status' => 'go', 'current_status' => 'on_route_to_loading']);

            return $bookingTruck;
        }

        $this->endLeg($booking, $bookingTruck, $oldTruck);
        $bookingTruck->update($details + ['trip_id' => null]);
        $this->startLeg($booking, $bookingTruck, $newTruck);

        return $bookingTruck;
    }

    /** Take a truck off a booking but keep the row (and its expense/invoice history). */
    public function remove(BookingTruck $bookingTruck): void
    {
        $booking = $bookingTruck->booking;
        $active = $booking->bookingTrucks()->whereNull('removed_at')->count();

        if ($active <= 1) {
            throw ValidationException::withMessages([
                'booking' => 'A booking needs at least one truck. Delete the booking instead.',
            ]);
        }

        $this->assertNoReturnBooked($bookingTruck, 'booking', ' Remove it from that return booking first, or use "Correct truck" to change the truck instead.');
        $this->endLeg($booking, $bookingTruck, $bookingTruck->truck);
        $bookingTruck->update(['removed_at' => now()]);
    }

    /**
     * A Go leg whose trip already has a Return booked cannot be removed, swapped or deleted: the
     * Return would be left without its Go half and drop out of the profit report (UAT, Nov 2026).
     */
    public function assertNoReturnBooked(BookingTruck $bookingTruck, string $field, string $hint): void
    {
        $bookingTruck->loadMissing('booking', 'truck', 'trip.returnBookingTruck.booking');
        $return = $bookingTruck->booking->direction === 'go' ? $bookingTruck->trip?->returnBookingTruck : null;

        if ($return) {
            throw ValidationException::withMessages([
                $field => sprintf('%s already has Return booking %s.%s', $bookingTruck->truck?->reg_no ?? 'This truck', $return->booking?->booking_number, $hint),
            ]);
        }
    }

    /** Booking-level summary: shared value when all trucks agree, earliest ETA. */
    public function syncSummary(Booking $booking): void
    {
        $trucks = $booking->bookingTrucks()->whereNull('removed_at')->get();
        $shared = fn (string $field) => $trucks->pluck($field)->unique()->count() === 1 ? $trucks->first()?->{$field} : null;

        $booking->update([
            'loading_point' => $shared('loading_point'),
            'offloading_point' => $shared('offloading_point'),
            'eta' => $trucks->pluck('eta')->filter()->min(),
            'booking_date' => self::averageDate($trucks->pluck('eta'), $booking->created_at)->toDateString(),
        ]);
    }

    /** UAT 20 Nov: the booking month is the average of the trucks' ETAs; no ETA → the fallback (creation date). */
    public static function averageDate(iterable $etas, $fallback): \Carbon\Carbon
    {
        $stamps = collect($etas)->filter()->map(fn ($eta) => \Carbon\Carbon::parse($eta)->startOfDay()->timestamp);

        return $stamps->isEmpty() ? \Carbon\Carbon::parse($fallback ?? now()) : \Carbon\Carbon::createFromTimestamp((int) round($stamps->avg()));
    }

    private function startLeg(Booking $booking, BookingTruck $bookingTruck, Truck $truck): void
    {
        if ($booking->direction === 'go') {
            $trip = Trip::create([
                'trip_code' => TripCodeGenerator::generate($truck->reg_no),
                'truck_id' => $truck->id,
                'go_booking_truck_id' => $bookingTruck->id,
            ]);
            $bookingTruck->update(['trip_id' => $trip->id]);
            $truck->update(['trip_status' => 'go', 'current_status' => 'on_route_to_loading']);

            return;
        }

        $openTrip = Trip::where('truck_id', $truck->id)
            ->whereNull('return_booking_truck_id')
            ->latest()
            ->first();

        if ($openTrip) {
            $openTrip->update(['return_booking_truck_id' => $bookingTruck->id]);
            $bookingTruck->update(['trip_id' => $openTrip->id]);
        }
        $truck->update(['trip_status' => 'return', 'current_status' => 'on_route_to_loading']);
    }

    private function endLeg(Booking $booking, BookingTruck $bookingTruck, ?Truck $truck): void
    {
        $trip = $bookingTruck->trip;

        if ($booking->direction === 'go') {
            if ($trip && !$trip->return_booking_truck_id) {
                $trip->delete();
            }
            $this->releaseAfterGoRemoved($truck, $bookingTruck->id);

            return;
        }

        $trip?->update(['return_booking_truck_id' => null]);
        $truck?->update(['trip_status' => 'go']);
    }

    /**
     * A Go leg was taken off (removed, swapped away or its booking deleted). Put the truck back where
     * it was before that Go: still on its earlier Return if that Return is not offloaded yet,
     * otherwise off duty. Its current status/location follow its newest remaining leg.
     */
    public function releaseAfterGoRemoved(?Truck $truck, int $removedLegId): void
    {
        if (!$truck) {
            return;
        }

        $latest = BookingTruck::where('truck_id', $truck->id)
            ->whereNull('removed_at')
            ->where('id', '!=', $removedLegId)
            ->with('booking:id,direction')
            ->orderByDesc('id')
            ->first();

        $onReturn = $latest
            && $latest->booking?->direction === 'return'
            && $latest->current_status !== \App\Models\TrackingStatus::OFFLOADED;

        $truck->update([
            'trip_status' => $onReturn ? 'return' : 'off_duty',
            'current_status' => $onReturn ? $latest->current_status : 'pending',
            'current_location' => $onReturn ? $latest->current_location : $truck->current_location,
        ]);
    }
}
