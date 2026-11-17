<?php

namespace App\Notifications;

use App\Models\ExpenseOrder;
use Illuminate\Notifications\Notification;

/** In-app expense alert: a fuel order still has no price after the grace period (Expense UAT req. 10). */
class ExpenseAlert extends Notification
{
    public function __construct(private ExpenseOrder $order)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $days = ExpenseOrder::PRICE_GRACE_DAYS;

        return [
            'expense_order_id' => $this->order->id,
            'kind' => 'price_overdue',
            'message' => "{$this->order->order_number} has had no fuel price for {$days} days. Enter the price so the accounts can be closed.",
            'order_number' => $this->order->order_number,
            'title' => 'Fuel price pending',
        ];
    }
}
