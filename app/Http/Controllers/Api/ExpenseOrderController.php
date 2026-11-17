<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingTruck;
use App\Models\ExpenseOrder;
use App\Models\Vendor;
use App\Services\ExpenseAmountCalculator;
use App\Services\ExpenseNumberGenerator;
use App\Services\ExpenseOrderRules;
use App\Services\ExpensePriceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ExpenseOrderExport;
use App\Exports\FuelExpenseExport;
use App\Exports\LineCategoryExpenseExport;
use Maatwebsite\Excel\Facades\Excel;

class ExpenseOrderController extends Controller
{
    protected function eagerLoad()
    {
        return ['lines.vendor', 'lines.bookingTruck.truck', 'lines.bookingTruck.driver', 'lines.bookingTruck.trip', 'booking.client', 'bookings.client', 'truck.driver', 'account', 'vendor', 'subcategory', 'priceChanges.user', 'parentOrder', 'creator', 'approver', 'payer'];
    }

    /** On the Vibali forms the exchange rates come from the order (req. 16), not from each line. */
    protected function lineRules(?string $form = null): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.line_category' => 'required|in:fuel,vibali_tunduma,vibali_congo,mengine',
            'lines.*.vendor_id' => 'nullable|exists:vendors,id',
            'lines.*.booking_truck_id' => 'nullable|exists:booking_trucks,id',
            'lines.*.driver_name' => 'nullable|string|max:120',
            'lines.*.group_key' => 'nullable|string',
            'lines.*.description' => 'required|string',
            'lines.*.currency' => 'required|in:TZS,USD,ZMK',
            'lines.*.exchange_rate' => $form ? 'nullable|numeric|min:0' : 'required_unless:lines.*.currency,TZS|numeric|min:0.0001',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            // Return settlements (163) may give money back: negative amounts (req. 24).
            'lines.*.unit_rate' => $form === 'return' ? 'nullable|numeric' : 'nullable|numeric|min:0',
            'lines.*.multiplier' => 'nullable|numeric|min:0',
            'lines.*.amount_description' => 'nullable|string|max:255',
            'lines.*.is_flat' => 'sometimes|boolean',
            'lines.*.payee' => 'nullable|in:driver,agent',
            'lines.*.original_amount' => $form === 'return' ? 'nullable|numeric' : 'nullable|numeric|min:0',
            'lines.*.is_additional' => 'sometimes|boolean',
        ];
    }

    /** Header fields shared by create and edit. */
    protected function headerRules(): array
    {
        return [
            'booking_ids' => 'sometimes|array',
            'booking_ids.*' => 'integer|exists:bookings,id',
            'other_subcategory_id' => 'nullable|exists:expense_subcategories,id',
            'outbound_border' => 'nullable|string|max:30',
            'parent_order_id' => 'nullable|exists:expense_orders,id',
            'payment_account_id' => 'nullable|exists:payment_accounts,id',
            'payment_date' => 'nullable|date',
            'payment_mode' => 'sometimes|in:on_spot,bill',
            'return_border' => 'nullable|string|max:30',
            'route_template_id' => 'nullable|exists:route_templates,id',
            'truck_id' => 'nullable|exists:trucks,id',
            'usd_rate' => 'nullable|numeric|min:0.0001',
            'vendor_id' => 'nullable|exists:vendors,id',
            'zmk_rate' => 'nullable|numeric|min:0.0001',
        ];
    }

    /** Header values to save; booking_id mirrors the first booking for older exports. */
    protected function headerValues(array $validated): array
    {
        return [
            'booking_id' => $validated['booking_ids'][0] ?? null,
            'other_subcategory_id' => $validated['other_subcategory_id'] ?? null,
            'outbound_border' => $validated['outbound_border'] ?? null,
            'parent_order_id' => $validated['parent_order_id'] ?? null,
            'payment_account_id' => $validated['payment_account_id'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'payment_mode' => $validated['payment_mode'],
            'return_border' => $validated['return_border'] ?? null,
            'route_template_id' => $validated['route_template_id'] ?? null,
            'truck_id' => $validated['truck_id'] ?? null,
            'usd_rate' => $validated['usd_rate'] ?? null,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'zmk_rate' => $validated['zmk_rate'] ?? null,
        ];
    }

    public function index(Request $request)
    {
        $query = ExpenseOrder::with($this->eagerLoad());

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }

        return $query->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'reference_no' => 'required|string|unique:expense_orders,reference_no',
            'category' => 'required|in:trip,office,truck',
            'expense_type' => 'required|in:fuel,vibali_tunduma,vibali_congo,mengine',
            'form' => ['nullable', Rule::in(ExpenseOrder::FORMS)],
        ], $this->headerRules(), $this->lineRules($request->input('form'))));

        $validated['payment_mode'] ??= ($validated['form'] ?? null) === 'emergency' ? 'on_spot' : $this->defaultPaymentMode($validated);
        $validated['booking_ids'] = $this->bookingIds($validated, $validated['form'] ?? null);
        ExpenseOrderRules::assertValid($validated);
        $validated['lines'] = ExpenseAmountCalculator::prepareLines($validated['lines'], $validated, $validated['expense_type'], $validated['form'] ?? null);

        $order = DB::transaction(function () use ($validated, $request) {
            $order = ExpenseOrder::create([
                ...ExpenseNumberGenerator::next($validated['expense_type']),
                ...$this->headerValues($validated),
                'category' => $validated['category'],
                'created_by' => $request->user()->id,
                'expense_type' => $validated['expense_type'],
                'form' => $validated['form'] ?? null,
                'initiated_by' => $request->user()->name,
                'reference_no' => $validated['reference_no'],
                'status' => 'pending',
            ]);

            $order->bookings()->sync($validated['booking_ids'] ?? []);
            foreach ($validated['lines'] as $line) {
                $order->lines()->create($line);
            }

            $order->recalculateTotal();

            return $order;
        });

        return response()->json($order->load($this->eagerLoad()), 201);
    }

    public function show(ExpenseOrder $expenseOrder)
    {
        return $expenseOrder->load($this->eagerLoad());
    }

    /** Type, category and number never change; the rest of a pending order can be edited. */
    public function update(Request $request, ExpenseOrder $expenseOrder)
    {
        if ($expenseOrder->status !== 'pending') {
            return response()->json(['message' => 'Only pending expense orders can be edited.'], 422);
        }

        $validated = $request->validate(array_merge([
            'price_change_reason' => 'nullable|string|max:255',
            'reference_no' => 'required|string|unique:expense_orders,reference_no,' . $expenseOrder->id,
        ], $this->headerRules(), $this->lineRules($expenseOrder->form)));

        $validated['payment_mode'] ??= $this->defaultPaymentMode($validated);
        $validated['booking_ids'] = $this->bookingIds($validated, $expenseOrder->form);
        ExpenseOrderRules::assertValid([
            ...$validated,
            'category' => $expenseOrder->category,
            'expense_type' => $expenseOrder->expense_type,
            'form' => $expenseOrder->form,
        ], $expenseOrder);
        $validated['lines'] = ExpenseAmountCalculator::prepareLines($validated['lines'], $validated, $expenseOrder->expense_type, $expenseOrder->form);

        $priceChanges = [];
        if ($expenseOrder->expense_type === 'fuel') {
            $priceChanges = ExpensePriceService::diffLines($expenseOrder->lines()->with('bookingTruck.truck')->get(), $validated['lines']);
            ExpensePriceService::assertReason($priceChanges, $validated['price_change_reason'] ?? null);
        }

        DB::transaction(function () use ($expenseOrder, $validated, $priceChanges, $request) {
            $expenseOrder->update([
                ...$this->headerValues($validated),
                'reference_no' => $validated['reference_no'],
            ]);

            $expenseOrder->bookings()->sync($validated['booking_ids'] ?? []);
            $expenseOrder->lines()->delete();
            foreach ($validated['lines'] as $line) {
                $expenseOrder->lines()->create($line);
            }
            $expenseOrder->recalculateTotal();
            ExpensePriceService::record($expenseOrder, $priceChanges, $validated['price_change_reason'] ?? null, $request->user()->id);
        });

        return $expenseOrder->load($this->eagerLoad());
    }

    /**
     * Req. 9: fill in or correct fuel prices on a pending or approved order (bill-based fuel is priced
     * when accounts are done). Changing a price that was already set needs a reason.
     */
    public function updatePrices(Request $request, ExpenseOrder $expenseOrder)
    {
        abort_unless($expenseOrder->expense_type === 'fuel', 422, 'Only fuel orders have prices per litre.');
        abort_unless(in_array($expenseOrder->status, ['pending', 'approved'], true), 422, 'Prices can only be changed before the order is paid.');

        $validated = $request->validate([
            'prices' => 'required|array|min:1',
            'prices.*.line_id' => 'required|integer',
            'prices.*.unit_rate' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $lines = $expenseOrder->lines()->with('bookingTruck.truck')->get()->keyBy('id');
        $changes = [];
        foreach ($validated['prices'] as $price) {
            $line = $lines->get($price['line_id']) ?? abort(422, 'A line does not belong to this order.');
            if (ExpensePriceService::differs($line->unit_rate, (float) $price['unit_rate'])) {
                $changes[] = ['label' => ExpensePriceService::label($line), 'line' => $line, 'new' => (float) $price['unit_rate'], 'old' => $line->unit_rate === null ? null : (float) $line->unit_rate];
            }
        }
        ExpensePriceService::assertReason($changes, $validated['reason'] ?? null);

        DB::transaction(function () use ($expenseOrder, $changes, $validated, $request) {
            foreach ($changes as $change) {
                $change['line']->update(['unit_rate' => $change['new']]);
            }
            $expenseOrder->recalculateTotal();
            ExpensePriceService::record($expenseOrder, $changes, $validated['reason'] ?? null, $request->user()->id);
        });

        return $expenseOrder->load($this->eagerLoad());
    }

    /** Emergency expenses (180) need no booking picked: the trucks' bookings are linked (req. 25). */
    protected function bookingIds(array $validated, ?string $form): array
    {
        if ($form !== 'emergency' || !empty($validated['booking_ids'])) {
            return $validated['booking_ids'] ?? [];
        }

        $legIds = collect($validated['lines'])->pluck('booking_truck_id')->filter()->unique();

        return BookingTruck::whereIn('id', $legIds)->distinct()->pluck('booking_id')->all();
    }

    /** Req. 8: the order's vendor (or its first line's vendor) decides On-spot or Bill-based by default. */
    protected function defaultPaymentMode(array $validated): string
    {
        $vendorId = $validated['vendor_id'] ?? collect($validated['lines'])->pluck('vendor_id')->filter()->first();

        return $vendorId ? (Vendor::whereKey($vendorId)->value('payment_mode') ?? 'bill') : 'bill';
    }

    public function approve(Request $request, ExpenseOrder $expenseOrder)
    {
        $this->authorizeRole($request, ['manager', 'admin']);

        if ($expenseOrder->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be approved.'], 422);
        }

        $expenseOrder->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return $expenseOrder->load($this->eagerLoad());
    }

    public function reject(Request $request, ExpenseOrder $expenseOrder)
    {
        $this->authorizeRole($request, ['manager', 'admin']);

        if ($expenseOrder->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be rejected.'], 422);
        }

        $expenseOrder->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return $expenseOrder->load($this->eagerLoad());
    }

    public function markPaid(Request $request, ExpenseOrder $expenseOrder)
    {
        $this->authorizeRole($request, ['accountant', 'admin']);

        if ($expenseOrder->status !== 'approved') {
            return response()->json(['message' => 'Only approved orders can be marked as paid.'], 422);
        }
        if ($expenseOrder->pending_price) {
            return response()->json(['message' => 'Enter the fuel price on every line before marking this order as paid.'], 422);
        }

        $expenseOrder->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now()]);

        return $expenseOrder->load($this->eagerLoad());
    }

    public function destroy(Request $request, ExpenseOrder $expenseOrder)
    {
        if ($expenseOrder->status !== 'pending') {
            $this->authorizeRole($request, ['manager', 'admin']);
        }

        $expenseOrder->delete();

        return response()->json(null, 204);
    }

    public function download(Request $request, ExpenseOrder $expenseOrder)
    {
        $expenseOrder->load($this->eagerLoad());

        $logoPath = public_path('images/logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $view = match (true) {
            $expenseOrder->expense_type === 'fuel' => 'expenses.fuel-pdf',
            $expenseOrder->expense_type === 'vibali_tunduma' && $expenseOrder->form === 'route' => 'expenses.vt-route-pdf',
            $expenseOrder->expense_type === 'vibali_tunduma' && $expenseOrder->form === 'general' => 'expenses.vt-general-pdf',
            $expenseOrder->expense_type === 'vibali_congo' && $expenseOrder->form === 'go' => 'expenses.vc-go-pdf',
            $expenseOrder->expense_type === 'vibali_congo' && $expenseOrder->form === 'return' => 'expenses.vc-return-pdf',
            $expenseOrder->expense_type === 'vibali_congo' && $expenseOrder->form === 'emergency' => 'expenses.vc-emergency-pdf',
            $expenseOrder->expense_type === 'mengine' && $expenseOrder->subcategory
                => preg_match('/mizani|weighbridge/i', $expenseOrder->subcategory->name) ? 'expenses.other-mizani-pdf' : 'expenses.other-pdf',
            default => 'expenses.order-pdf',
        };
        $data = ['expense' => $expenseOrder, 'logoBase64' => $logoBase64, 'showPrice' => $request->boolean('show_price', true)];
        if (str_starts_with($view, 'expenses.v')) {
            $data['print'] = ExpenseAmountCalculator::printData($expenseOrder);
        }
        $pdf = Pdf::loadView($view, $data);

        return $pdf->download("expense-{$expenseOrder->order_number}.pdf");
    }

    public function downloadExcel(ExpenseOrder $expenseOrder)
    {
        $expenseOrder->load($this->eagerLoad());

        return Excel::download(new ExpenseOrderExport($expenseOrder), "expense-{$expenseOrder->order_number}.xlsx");
    }

    public function downloadCategory(ExpenseOrder $expenseOrder, string $category)
    {
        $expenseOrder->load($this->eagerLoad());

        if (!in_array($category, ['fuel', 'vibali_tunduma', 'vibali_congo', 'mengine'])) {
            abort(404);
        }

        $hasLines = $expenseOrder->lines->where('line_category', $category)->isNotEmpty();
        if (!$hasLines) {
            return response()->json(['message' => 'No lines of this category on this order.'], 404);
        }

        $export = $category === 'fuel'
            ? new FuelExpenseExport($expenseOrder)
            : new LineCategoryExpenseExport($expenseOrder, $category);

        return Excel::download($export, "{$expenseOrder->order_number}-{$category}.xlsx");
    }

    protected function authorizeRole(Request $request, array $allowedSlugs): void
    {
        $userRole = $request->user()->role?->slug;

        if (!in_array($userRole, $allowedSlugs)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}