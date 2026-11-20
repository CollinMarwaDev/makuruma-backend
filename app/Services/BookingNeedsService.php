<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\TrackingStatus;
use App\Models\Truck;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * UAT 20 Nov 2026, Tracking tabs:
 * - Unbooked: active trucks with no open leg (never booked, or every leg Offloaded).
 * - Needs booking (urgent), for trucks still on the road:
 *   Go leg that reached Kasumbalesa Zambia / Sakania Zambia and whose trip has no Return booked;
 *   Return leg that reached Nakonde and whose truck has no later Go booking.
 * "Reached" = an arrival date at that checkpoint, or a current location naming it.
 */
class BookingNeedsService
{
    private const GO_PLACES = ['Kasumbalesa Zambia' => '/kasumbalesa|k\/?\s*lesa|sakania/i', 'Sakania Zambia' => '/kasumbalesa|k\/?\s*lesa|sakania/i'];

    private const RETURN_PLACES = ['Nakonde' => '/nakonde/i'];

    /** @return array{unbooked: array<int, array<string, mixed>>, urgent: array<int, array<string, mixed>>} */
    public function list(): array
    {
        return ['unbooked' => $this->unbooked()->all(), 'urgent' => $this->urgent()->all()];
    }

    public function unbooked(): Collection
    {
        $legs = BookingTruck::whereNull('removed_at')->with('booking:id,booking_number,direction')->orderBy('id')->get()->groupBy('truck_id');

        return Truck::with('driver:id,full_name,phone', 'trailer:id,reg_no')->where('status', 'active')->orderBy('reg_no')->get()
            ->filter(fn (Truck $truck) => ($legs[$truck->id] ?? collect())->every(fn (BookingTruck $l) => $l->current_status === TrackingStatus::OFFLOADED))
            ->map(function (Truck $truck) use ($legs) {
                $last = ($legs[$truck->id] ?? collect())->last();
                $since = $last?->offloading_date ?? $last?->completed_at;

                return [
                    'truck' => ['id' => $truck->id, 'reg_no' => $truck->reg_no],
                    'trailer' => $truck->trailer?->reg_no,
                    'driver' => $truck->driver?->full_name,
                    'driver_phone' => $truck->driver?->phone,
                    // A truck whose Go is finished waits for a Return; otherwise it needs a Go.
                    'needs' => $truck->trip_status === 'go' ? 'return' : 'go',
                    'last_leg' => $last ? [
                        'id' => $last->id,
                        'booking_number' => $last->booking?->booking_number,
                        'direction' => $last->booking?->direction,
                        'offloading_date' => $last->offloading_date,
                        'offloading_point' => $last->offloading_point,
                    ] : null,
                    'location' => $last?->current_location ?? $truck->current_location,
                    'days_idle' => $since ? (int) Carbon::parse($since)->startOfDay()->diffInDays(now()->startOfDay()) : null,
                ];
            })
            ->values();
    }

    public function urgent(): Collection
    {
        $open = BookingTruck::whereNull('removed_at')->where('current_status', '!=', TrackingStatus::OFFLOADED)
            ->with('booking:id,booking_number,direction,client_id', 'booking.client:id,company_name', 'truck:id,reg_no', 'driver:id,full_name,phone', 'trip', 'milestones.checkpoint')
            ->get();

        $goIdsAfter = BookingTruck::whereNull('removed_at')->whereHas('booking', fn ($q) => $q->where('direction', 'go'))
            ->get(['id', 'truck_id'])->groupBy('truck_id');

        return $open->map(function (BookingTruck $leg) use ($goIdsAfter) {
            $direction = $leg->booking?->direction;
            if ($direction === 'go' && $leg->trip?->return_booking_truck_id) {
                return null;
            }
            if ($direction === 'return' && ($goIdsAfter[$leg->truck_id] ?? collect())->contains(fn ($g) => $g->id > $leg->id)) {
                return null;
            }
            $reached = $this->reached($leg, $direction === 'go' ? self::GO_PLACES : self::RETURN_PLACES);

            return $reached ? [
                'leg_id' => $leg->id,
                'truck' => ['id' => $leg->truck?->id, 'reg_no' => $leg->truck?->reg_no],
                'driver' => $leg->driver?->full_name,
                'driver_phone' => $leg->driver?->phone,
                'booking_number' => $leg->booking?->booking_number,
                'client' => $leg->booking?->client?->company_name,
                'direction' => $direction,
                'needs' => $direction === 'go' ? 'return' : 'go',
                'status' => $leg->current_status,
                'location' => $leg->current_location,
                ...$reached,
            ] : null;
        })->filter()->sortBy('truck.reg_no')->values();
    }

    /** Where and when the leg reached one of the places: checkpoint arrival first, else its current location. */
    private function reached(BookingTruck $leg, array $places): ?array
    {
        foreach ($leg->milestones as $milestone) {
            if ($milestone->arrival_at && array_key_exists($milestone->checkpoint?->name, $places)) {
                return ['reached' => $milestone->checkpoint->name, 'reached_at' => $milestone->arrival_at->toDateString()];
            }
        }
        foreach ($places as $pattern) {
            if ($leg->current_location && preg_match($pattern, $leg->current_location)) {
                return ['reached' => $leg->current_location, 'reached_at' => null];
            }
        }

        return null;
    }
}
