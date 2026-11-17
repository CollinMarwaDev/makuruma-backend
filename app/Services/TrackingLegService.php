<?php

namespace App\Services;

use App\Models\BookingTruck;
use App\Models\TrackingStatus;
use App\Models\User;
use App\Notifications\TrackingAlert;
use Illuminate\Support\Facades\Notification;

/** Status changes on a tracking leg and the scheduled 5-day rules (contract 2.2 D-1, D-6, D-7). */
class TrackingLegService
{
    /** Roles told about overdue PODs. Accountants alone get the ready-for-invoicing notice. */
    public const POD_ALERT_ROLES = ['admin', 'manager', 'operator', 'accountant'];

    public function updateStatus(BookingTruck $leg, string $status, ?string $location, ?string $route): BookingTruck
    {
        $leg->fill(array_filter([
            'current_location' => $location,
            'route' => $route,
        ], fn ($value) => $value !== null));

        if ($status === TrackingStatus::OFFLOADED) {
            $this->markOffloaded($leg);
        } else {
            $leg->fill(['current_status' => $status, 'completed_at' => null])->save();
            $this->mirrorToTruck($leg);
        }

        return $leg;
    }

    /** Offloaded is the leg's final status; `completed_at` records when it was set. */
    public function markOffloaded(BookingTruck $leg): void
    {
        $leg->fill(['completed_at' => $leg->completed_at ?? now(), 'current_status' => TrackingStatus::OFFLOADED])->save();

        $truck = $leg->truck;
        if ($truck && $this->isLatestLeg($leg)) {
            // A finished Return leg frees the truck. A finished Go leg leaves it "on a Go trip awaiting
            // return" so it can still be put on a Return booking (contract 2.1 B: Return from Go).
            $leg->loadMissing('booking:id,direction');
            $truck->update(array_filter([
                'current_status' => TrackingStatus::OFFLOADED,
                'trip_status' => $leg->booking?->direction === 'return' ? 'off_duty' : null,
            ]));
        }
    }

    /**
     * Contract 2.2 D-6 as changed by the client (17 Nov 2026): GRACE_DAYS after the offloading date,
     * tell Accountants the leg is ready for invoicing — once per leg, without changing its status.
     */
    public function notifyReadyForInvoicing(): int
    {
        $legs = BookingTruck::query()
            ->whereNull('removed_at')
            ->whereNull('invoice_ready_notified_at')
            ->whereDate('offloading_date', '<=', now()->subDays(BookingTruck::GRACE_DAYS))
            ->get();

        $accountants = $this->usersWithRoles(['accountant']);
        foreach ($legs as $leg) {
            Notification::send($accountants, new TrackingAlert('ready_for_invoicing', $leg));
            $leg->update(['invoice_ready_notified_at' => now()]);
        }

        return $legs->count();
    }

    /** Contract 2.2 D-7: alert once per leg when no POD arrived GRACE_DAYS after offloading. */
    public function alertOverduePods(): int
    {
        $legs = BookingTruck::query()
            ->whereNull('removed_at')
            ->whereNull('pod_overdue_notified_at')
            ->whereDate('offloading_date', '<=', now()->subDays(BookingTruck::GRACE_DAYS))
            ->whereDoesntHave('podDocuments')
            ->get();

        $recipients = $this->usersWithRoles(self::POD_ALERT_ROLES);
        foreach ($legs as $leg) {
            Notification::send($recipients, new TrackingAlert('pod_overdue', $leg));
            $leg->update(['pod_overdue_notified_at' => now()]);
        }

        return $legs->count();
    }

    /** The truck shows the status of its newest active leg (lists, dashboard, Trucks page). */
    public function mirrorToTruck(BookingTruck $leg): void
    {
        if ($leg->truck && $this->isLatestLeg($leg)) {
            $leg->truck->update(['current_location' => $leg->current_location, 'current_status' => $leg->current_status]);
        }
    }

    private function isLatestLeg(BookingTruck $leg): bool
    {
        return BookingTruck::where('truck_id', $leg->truck_id)->whereNull('removed_at')->max('id') === $leg->id;
    }

    private function usersWithRoles(array $slugs)
    {
        return User::whereHas('role', fn ($q) => $q->whereIn('slug', $slugs))->get();
    }
}
