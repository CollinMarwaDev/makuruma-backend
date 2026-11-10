<?php

namespace App\Notifications;

use App\Models\BookingTruck;
use Illuminate\Notifications\Notification;

/** In-app tracking alert (contract 2.2 D-6 auto-completion, D-7 overdue POD). */
class TrackingAlert extends Notification
{
    public function __construct(private string $kind, private BookingTruck $leg)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $leg = $this->leg->loadMissing('booking', 'truck');
        $offloaded = $leg->offloading_date ? \Carbon\Carbon::parse($leg->offloading_date)->format('d M Y') : '—';
        $truck = $leg->truck?->reg_no;
        $booking = $leg->booking?->booking_number;

        return [
            'booking_number' => $booking,
            'booking_truck_id' => $leg->id,
            'kind' => $this->kind,
            'message' => $this->kind === 'auto_completed'
                ? "{$truck} on {$booking} was marked Completed automatically (offloaded {$offloaded}). Ready for invoicing/accounts."
                : "{$truck} on {$booking} was offloaded {$offloaded} and still has no POD uploaded.",
            'title' => $this->kind === 'auto_completed' ? 'Trip auto-completed' : 'POD overdue',
            'truck' => $truck,
        ];
    }
}
