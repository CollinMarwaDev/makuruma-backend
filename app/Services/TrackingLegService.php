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
    /** Roles told about overdue PODs. Accountants alone get auto-completion notices. */
    public const POD_ALERT_ROLES = ['admin', 'manager', 'operator', 'accountant'];

    public function updateStatus(BookingTruck $leg, string $status, ?string $location, ?string $route): BookingTruck
    {
        $leg->fill(array_filter([
            'current_location' => $location,
            'route' => $route,
        ], fn ($value) => $value !== null));

        if ($status === TrackingStatus::COMPLETED) {
            $this->complete($leg);
        } else {
            $leg->fill(['current_status' => $status, 'completed_at' => null, 'auto_completed_at' => null])->save();
            $this->mirrorToTruck($leg);
        }

        return $leg;
    }

    public function complete(BookingTruck $leg, bool $automatic = false): void
    {
        $leg->fill([
            'auto_completed_at' => $automatic ? now() : null,
            'completed_at' => now(),
            'current_status' => TrackingStatus::COMPLETED,
        ])->save();

        $truck = $leg->truck;
        if ($truck && $this->isLatestLeg($leg)) {
            // The truck's newest leg is done, so the truck is free again.
            $truck->update(['current_status' => TrackingStatus::COMPLETED, 'trip_status' => 'off_duty']);
        }
    }

    /** Contract 2.2 D-6: complete legs offloaded GRACE_DAYS ago and tell accountants. */
    public function autoCompleteOffloaded(): int
    {
        $legs = BookingTruck::query()
            ->whereNull('removed_at')
            ->where('current_status', '!=', TrackingStatus::COMPLETED)
            ->whereDate('offloading_date', '<=', now()->subDays(BookingTruck::GRACE_DAYS))
            ->get();

        $accountants = $this->usersWithRoles(['accountant']);
        foreach ($legs as $leg) {
            $this->complete($leg, automatic: true);
            Notification::send($accountants, new TrackingAlert('auto_completed', $leg));
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
