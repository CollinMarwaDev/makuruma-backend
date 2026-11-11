<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ExpenseOrder;
use App\Models\Invoice;
use App\Models\Trip;
use App\Models\Truck;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary()
    {
        return response()->json([
            'fleet' => $this->fleetSummary(),
            'compliance' => $this->complianceSummary(),
            'bookings' => $this->bookingsSummary(),
            'tracking' => $this->trackingSummary(),
            'expenses' => $this->expensesSummary(),
            'invoicing' => $this->invoicingSummary(),
            'finance_trend' => $this->financeTrend(),
        ]);
    }

    protected function fleetSummary(): array
    {
        return [
            'total' => Truck::count(),
            'active' => Truck::where('status', 'active')->count(),
            'maintenance' => Truck::where('status', 'maintenance')->count(),
            'decommissioned' => Truck::where('status', 'decommissioned')->count(),
        ];
    }

    protected function complianceSummary(): array
    {
        $now = Carbon::now();
        $soon = Carbon::now()->addDays(30);

        $expired = Document::whereNotNull('expiry_date')->where('expiry_date', '<', $now)->count();
        $expiringSoon = Document::whereNotNull('expiry_date')->whereBetween('expiry_date', [$now, $soon])->count();

        $upcoming = Document::whereNotNull('expiry_date')
            ->where('expiry_date', '>=', $now)
            ->orderBy('expiry_date')
            ->limit(5)
            ->with('documentable')
            ->get(['id', 'documentable_type', 'documentable_id', 'document_type', 'expiry_date'])
            // A readable owner (truck / trailer reg no, driver or staff name) so the dashboard row can say whose document it is.
            ->map(fn (Document $doc) => [
                ...$doc->only(['id', 'documentable_type', 'documentable_id', 'document_type', 'expiry_date']),
                'owner_name' => $doc->documentable?->reg_no ?? $doc->documentable?->full_name ?? $doc->documentable?->name,
            ]);

        return [
            'expired' => $expired,
            'expiring_soon' => $expiringSoon,
            'upcoming' => $upcoming,
        ];
    }

    protected function bookingsSummary(): array
    {
        $totalTrips = Trip::count();
        $completedTrips = Trip::whereNotNull('return_booking_truck_id')->count();
        $awaitingReturn = $totalTrips - $completedTrips;

        $thisMonth = \App\Models\Booking::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // Bookings created per month for the last 6 months, split by direction (Go / Return).
        $from = now()->subMonths(5)->startOfMonth();
        $monthly = \App\Models\Booking::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            'direction',
            DB::raw('count(*) as total')
        )->where('created_at', '>=', $from)->groupBy('month', 'direction')->get();

        $trend = collect(range(5, 0))->map(function (int $ago) use ($monthly) {
            $month = now()->subMonths($ago)->format('Y-m');
            $rows = $monthly->where('month', $month);

            return [
                'month' => $month,
                'go' => (int) ($rows->firstWhere('direction', 'go')->total ?? 0),
                'return' => (int) ($rows->firstWhere('direction', 'return')->total ?? 0),
            ];
        })->all();

        return [
            'total' => $totalTrips,
            'awaiting_return' => $awaitingReturn,
            'completed' => $completedTrips,
            'this_month' => $thisMonth,
            'monthly_trend' => $trend,
        ];
    }

    protected function trackingSummary(): array
    {
        $counts = Truck::select('current_status', DB::raw('count(*) as total'))
            ->groupBy('current_status')
            ->pluck('total', 'current_status');

        // Fixed keys keep the dashboard groups stable; custom statuses (Parked, Clearing, …) are added as-is.
        return $counts->map(fn ($n) => (int) $n)->all() + [
            'pending' => $counts['pending'] ?? 0,
            'on_route_to_loading' => $counts['on_route_to_loading'] ?? 0,
            'loading' => $counts['loading'] ?? 0,
            'in_transit' => $counts['in_transit'] ?? 0,
            'at_border' => $counts['at_border'] ?? 0,
            'offloading' => $counts['offloading'] ?? 0,
            'delayed' => $counts['delayed'] ?? 0,
            'breakdown' => $counts['breakdown'] ?? 0,
            'completed' => $counts['completed'] ?? 0,
        ];
    }

    protected function expensesSummary(): array
    {
        $pending = ExpenseOrder::where('status', 'pending')->count();
        $pendingTotal = ExpenseOrder::where('status', 'pending')->sum('total_amount');

        $paidThisMonth = ExpenseOrder::where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('total_amount');

        $byCategory = ExpenseOrder::select('category', DB::raw('sum(total_amount) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        return [
            'pending_count' => $pending,
            'pending_total' => (float) $pendingTotal,
            'paid_this_month' => (float) $paidThisMonth,
            'by_category' => [
                'trip' => (float) ($byCategory['trip'] ?? 0),
                'office' => (float) ($byCategory['office'] ?? 0),
                'truck' => (float) ($byCategory['truck'] ?? 0),
            ],
        ];
    }

    protected function invoicingSummary(): array
    {
        $totalInvoiced = Invoice::sum('total_amount');
        $thisMonth = Invoice::whereMonth('invoice_date', now()->month)->whereYear('invoice_date', now()->year)->sum('total_amount');

        $monthly = Invoice::select(
            DB::raw("DATE_FORMAT(invoice_date, '%Y-%m') as month"),
            DB::raw('sum(total_amount) as total')
        )
            ->where('invoice_date', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return [
            'total_invoiced' => (float) $totalInvoiced,
            'this_month' => (float) $thisMonth,
            'monthly_trend' => $monthly,
        ];
    }

    /**
     * Revenue vs expenses for the last 6 months, both in USD.
     * Revenue: invoice totals by invoice date. Expenses: non-rejected expense orders (recorded in TZS)
     * by payment date (or creation date), converted with that month's average invoice exchange rate
     * ($→TZS); months without invoices use the average rate of all invoices.
     */
    protected function financeTrend(): array
    {
        $from = now()->subMonths(5)->startOfMonth();

        $revenue = Invoice::select(
            DB::raw("DATE_FORMAT(invoice_date, '%Y-%m') as month"),
            DB::raw('sum(total_amount) as total'),
            DB::raw('avg(exchange_rate) as rate')
        )->where('invoice_date', '>=', $from)->groupBy('month')->get()->keyBy('month');

        $expenses = ExpenseOrder::select(
            DB::raw("DATE_FORMAT(COALESCE(payment_date, created_at), '%Y-%m') as month"),
            DB::raw('sum(total_amount) as total')
        )->where('status', '!=', 'rejected')
            ->whereRaw('COALESCE(payment_date, created_at) >= ?', [$from])
            ->groupBy('month')->pluck('total', 'month');

        $fallbackRate = (float) Invoice::where('exchange_rate', '>', 0)->avg('exchange_rate') ?: null;

        return collect(range(5, 0))->map(function (int $ago) use ($revenue, $expenses, $fallbackRate) {
            $month = now()->subMonths($ago)->format('Y-m');
            $rate = (float) ($revenue[$month]->rate ?? 0) ?: $fallbackRate;
            $expensesTzs = (float) ($expenses[$month] ?? 0);

            return [
                'month' => $month,
                'revenue' => round((float) ($revenue[$month]->total ?? 0), 2),
                'expenses' => $rate ? round($expensesTzs / $rate, 2) : null,
                'expenses_tzs' => round($expensesTzs, 2),
                'rate' => $rate ? round($rate, 2) : null,
            ];
        })->all();
    }
}
