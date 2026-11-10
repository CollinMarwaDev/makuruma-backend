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
            ->get(['id', 'documentable_type', 'documentable_id', 'document_type', 'expiry_date']);

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

        return [
            'total' => $totalTrips,
            'awaiting_return' => $awaitingReturn,
            'completed' => $completedTrips,
            'this_month' => $thisMonth,
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
}