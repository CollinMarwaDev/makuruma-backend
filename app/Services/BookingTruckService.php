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
    /** Trip status a truck must have to join a booking of this direction. */
    public static function requiredTripStatus(string $direction): string
    {
        return $direction === 'go' ? 'off_duty' : 'go';
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

        if ($truck->trip_status !== self::requiredTripStatus($direction)) {
            $expected = $direction === 'go' ? 'off duty' : 'on a Go trip awaiting return';
            throw ValidationException::withMessages([
                $field => "{$truck->reg_no} must be {$expected} to be added to a {$direction} booking.",
            ]);
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
            $oldTruck?->update(['trip_status' => 'off_duty', 'current_status' => 'pending']);
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

        $this->endLeg($booking, $bookingTruck, $bookingTruck->truck);
        $bookingTruck->update(['removed_at' => now()]);
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
        ]);
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
            $truck?->update(['trip_status' => 'off_duty', 'current_status' => 'pending']);

            return;
        }

        $trip?->update(['return_booking_truck_id' => null]);
        $truck?->update(['trip_status' => 'go']);
    }
}
