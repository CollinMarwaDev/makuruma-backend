<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTruck;
use App\Models\Invoice;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    protected function eagerLoad()
    {
        return ['booking.client', 'lines.bookingTruck.truck', 'lines.bookingTruck.trailer', 'lines.bookingTruck.trip', 'creator', 'payer'];
    }

    protected function lineRules(): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.booking_truck_id' => 'required|exists:booking_trucks,id',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.rate' => 'nullable|numeric|min:0',
            'lines.*.percentage' => 'nullable|numeric|min:0|max:100',
            'lines.*.is_flat_amount' => 'nullable|boolean',
            'lines.*.flat_amount' => 'required_if:lines.*.is_flat_amount,true|nullable|numeric|min:0',
            'lines.*.days' => 'nullable|integer|min:0',
        ];
    }

    public function index(Request $request)
    {
        $query = Invoice::with($this->eagerLoad());

        if ($request->filled('booking_number')) {
            $query->whereHas('booking', fn($q) => $q->where('booking_number', 'like', '%' . $request->booking_number . '%'));
        }

        return $query->latest()->get();
    }

    public function show(Invoice $invoice)
    {
        $invoice->load($this->eagerLoad());

        // Contract 2.4: a booking can be invoiced in parts; list the trucks still waiting for this invoice type.
        $invoice->setAttribute('uninvoiced_trucks', $this->uninvoicedQuery($invoice->booking_id, $invoice->invoice_type)
            ->with('truck:id,reg_no')
            ->get(['id', 'truck_id', 'current_status', 'offloading_date'])
            ->map(fn (BookingTruck $bt) => [
                'id' => $bt->id,
                'reg_no' => $bt->truck?->reg_no,
                'current_status' => $bt->current_status,
                'offloading_date' => $bt->offloading_date,
            ])
            ->values());

        return $invoice;
    }

    protected function computeLineAmount(string $invoiceType, array $lineData, BookingTruck $bookingTruck): float
    {
        $quantity = (float) ($lineData['quantity'] ?? 0);
        $rate = (float) ($lineData['rate'] ?? 0);

        return match ($invoiceType) {
            'advance' => !empty($lineData['is_flat_amount'])
            ? (float) $lineData['flat_amount']
            : $quantity * $rate * (((float) ($lineData['percentage'] ?? 100)) / 100),
            'settlement' => max(0, ($quantity * $rate) - $this->previousAdvanceTotal($bookingTruck->id)),
            'standing_time' => ((float) ($lineData['days'] ?? 0)) * $rate,
            'adjustment' => $quantity * $rate,
            default => 0,
        };
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'invoice_type' => 'required|in:advance,settlement,standing_time,adjustment',
            'booking_id' => 'required|exists:bookings,id',
            'invoice_date' => 'required|date',
            'exchange_rate' => 'required|numeric|min:0.0001',
            'deal_no' => 'nullable|string',
            'bivac_no' => 'nullable|string',
            'mode_of_payment' => 'nullable|string',
            'delivery_note_no' => 'nullable|string',
            'delivery_note_date' => 'nullable|date',
            'supplier_ref' => 'nullable|string',
            'other_ref' => 'nullable|string',
            'loading_con_no' => 'nullable|string',
            'settlement_no' => 'nullable|string',
            'dispatched_through' => 'nullable|string',
            'destination' => 'nullable|string',
            'terms_of_delivery' => 'nullable|string',
        ], $this->lineRules()));

        $booking = Booking::with('client')->findOrFail($validated['booking_id']);

        if ($validated['invoice_type'] === 'advance' && !$booking->client->allows_advance_invoice) {
            return response()->json(['message' => 'This client is not eligible for Advance invoices.'], 422);
        }

        $invoice = DB::transaction(function () use ($validated, $booking) {
            $invoice = Invoice::create([
                'invoice_number' => InvoiceNumberGenerator::generate(),
                'invoice_type' => $validated['invoice_type'],
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'invoice_date' => $validated['invoice_date'],
                'exchange_rate' => $validated['exchange_rate'],
                'deal_no' => $validated['deal_no'] ?? null,
                'bivac_no' => $validated['bivac_no'] ?? null,
                'mode_of_payment' => $validated['mode_of_payment'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'delivery_note_date' => $validated['delivery_note_date'] ?? null,
                'supplier_ref' => $validated['supplier_ref'] ?? null,
                'other_ref' => $validated['other_ref'] ?? null,
                'loading_con_no' => $validated['loading_con_no'] ?? null,
                'settlement_no' => $validated['settlement_no'] ?? null,
                'dispatched_through' => $validated['dispatched_through'] ?? null,
                'destination' => $validated['destination'] ?? null,
                'terms_of_delivery' => $validated['terms_of_delivery'] ?? null,
                'status' => 'pending',
                'created_by' => request()->user()->id,
            ]);

            $this->createLines($invoice, $validated['lines'], $validated['invoice_type']);
            $invoice->recalculateTotal();

            return $invoice;
        });

        return response()->json($invoice->load($this->eagerLoad()), 201);
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'Only unpaid invoices can be edited.'], 422);
        }

        $validated = $request->validate(array_merge([
            'invoice_date' => 'required|date',
            'exchange_rate' => 'required|numeric|min:0.0001',
            'deal_no' => 'nullable|string',
            'bivac_no' => 'nullable|string',
            'mode_of_payment' => 'nullable|string',
            'delivery_note_no' => 'nullable|string',
            'delivery_note_date' => 'nullable|date',
            'supplier_ref' => 'nullable|string',
            'other_ref' => 'nullable|string',
            'loading_con_no' => 'nullable|string',
            'settlement_no' => 'nullable|string',
            'dispatched_through' => 'nullable|string',
            'destination' => 'nullable|string',
            'terms_of_delivery' => 'nullable|string',
        ], $this->lineRules()));

        DB::transaction(function () use ($invoice, $validated) {
            $invoice->update([
                'invoice_date' => $validated['invoice_date'],
                'exchange_rate' => $validated['exchange_rate'],
                'deal_no' => $validated['deal_no'] ?? null,
                'bivac_no' => $validated['bivac_no'] ?? null,
                'mode_of_payment' => $validated['mode_of_payment'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'delivery_note_date' => $validated['delivery_note_date'] ?? null,
                'supplier_ref' => $validated['supplier_ref'] ?? null,
                'other_ref' => $validated['other_ref'] ?? null,
                'loading_con_no' => $validated['loading_con_no'] ?? null,
                'settlement_no' => $validated['settlement_no'] ?? null,
                'dispatched_through' => $validated['dispatched_through'] ?? null,
                'destination' => $validated['destination'] ?? null,
                'terms_of_delivery' => $validated['terms_of_delivery'] ?? null,
            ]);

            $invoice->lines()->delete();
            $this->createLines($invoice, $validated['lines'], $invoice->invoice_type);
            $invoice->recalculateTotal();
        });

        return $invoice->load($this->eagerLoad());
    }

    protected function createLines(Invoice $invoice, array $lines, string $invoiceType): void
    {
        foreach ($lines as $lineData) {
            $bookingTruck = BookingTruck::with('truck', 'trailer')->findOrFail($lineData['booking_truck_id']);
            $amount = $this->computeLineAmount($invoiceType, $lineData, $bookingTruck);

            $truckReg = $bookingTruck->truck->reg_no ?? '—';
            $trailerReg = $bookingTruck->trailer->reg_no ?? null;
            $description = $trailerReg ? "{$truckReg}/{$trailerReg}" : $truckReg;

            $invoice->lines()->create([
                'booking_truck_id' => $bookingTruck->id,
                'description' => $description,
                'quantity' => $lineData['quantity'] ?? null,
                'rate' => $lineData['rate'] ?? null,
                'percentage' => $lineData['percentage'] ?? null,
                'is_flat_amount' => $lineData['is_flat_amount'] ?? false,
                'days' => $lineData['days'] ?? null,
                'amount' => round($amount, 2),
            ]);
        }
    }

    protected function previousAdvanceTotal(int $bookingTruckId): float
    {
        return (float) DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoice_lines.booking_truck_id', $bookingTruckId)
            ->where('invoices.invoice_type', 'advance')
            ->sum('invoice_lines.amount');
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $userRole = $request->user()->role?->slug;
        if (!in_array($userRole, ['accountant', 'admin'])) {
            abort(403, 'You do not have permission to confirm payment.');
        }

        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'This invoice is already marked as paid.'], 422);
        }

        $invoice->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now()]);

        return $invoice->load($this->eagerLoad());
    }

    public function eligibleTrucks(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'invoice_type' => 'required|in:advance,settlement,standing_time,adjustment',
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);

        return $this->uninvoicedQuery($booking->id, $validated['invoice_type'])
            ->with(['truck', 'trailer', 'trip'])
            ->get();
    }

    /** Active trucks of a booking that are not yet on an invoice of this type (they can be invoiced later). */
    private function uninvoicedQuery(int $bookingId, string $invoiceType)
    {
        return BookingTruck::where('booking_id', $bookingId)
            ->whereNull('removed_at')
            ->whereDoesntHave('invoiceLines', fn ($query) => $query->whereHas('invoice', fn ($q) => $q->where('invoice_type', $invoiceType)));
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();

        return response()->json(null, 204);
    }

    public function download(Invoice $invoice)
    {
        $invoice->load($this->eagerLoad());

        $logoPath = public_path('images/logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $pdf = Pdf::loadView('invoices.invoice-pdf', ['invoice' => $invoice, 'logoBase64' => $logoBase64]);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}