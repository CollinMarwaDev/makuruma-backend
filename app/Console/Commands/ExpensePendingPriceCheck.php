<?php

namespace App\Console\Commands;

use App\Models\ExpenseOrder;
use App\Models\User;
use App\Notifications\ExpenseAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Expense UAT req. 10: alert Accountants once about fuel orders still without a price after 14 days. */
class ExpensePendingPriceCheck extends Command
{
    protected $signature = 'expenses:pending-price-check';

    protected $description = 'Notify accountants about fuel orders whose price is still missing after 14 days';

    public function handle(): int
    {
        $orders = ExpenseOrder::where('expense_type', 'fuel')
            ->whereNotNull('serial') // orders saved before the new forms have lump-sum lines, never a pending price
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('price_alerted_at')
            ->where('created_at', '<=', now()->subDays(ExpenseOrder::PRICE_GRACE_DAYS))
            ->whereHas('lines', fn ($q) => $q->where('line_category', 'fuel')->whereNull('unit_rate'))
            ->get();

        $accountants = User::whereHas('role', fn ($q) => $q->where('slug', 'accountant'))->get();
        foreach ($orders as $order) {
            Notification::send($accountants, new ExpenseAlert($order));
            $order->update(['price_alerted_at' => now()]);
        }

        $this->info("Pending-price alerts: {$orders->count()}");

        return self::SUCCESS;
    }
}
