<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingTruck;
use App\Models\ExpenseOrder;
use App\Models\Vendor;
use App\Services\ExpenseAmountCalculator;
use App\Services\ExpenseNumberGenerator;
use App\Services\ExpenseOrderRules;
use App\Services\ExpensePrintout;
use App\Services\ExpensePriceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ExpenseFormSheet;
use App\Exports\ExpenseOrderExport;
use App\Exports\FuelExpenseExport;
use App\Exports\LineCategoryExpenseExport;
use Maatwebsite\Excel\Facades\Excel;

class ExpenseOrderController extends Controller
{
    protected function eagerLoad()
    {
        return ['lines.vendor', 'lines.truck.driver', 'lines.bookingTruck.truck', 'lines.bookingTruck.driver', 'lines.bookingTruck.trip', 'booking.client', 'bookings.client', 'truck.driver', 'account', 'vendor', 'subcategory', 'priceChanges.user', 'parentOrder', 'creator', 'approver', 'payer'];
    }

    /** On the Vibali forms the exchange rates come from the order (req. 16), not from each line. */
    protected function lineRules(?string $form = null): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.line_category' => 'required|in:fuel,vibali_tunduma,vibali_congo,mengine',
            'lines.*.vendor_id' => 'nullable|exists:vendors,id',
            'lines.*.booking_truck_id' => 'nullable|exists:booking_trucks,id',
            'lines.*.truck_id' => 'nullable|exists:trucks,id',
            'lines.*.driver_name' => 'nullable|string|max:120',
            'lines.*.filled_at' => 'nullable|date|before_or_equal:today',
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
            // UAT 20 Nov: every Vibali line says whether the money goes to the driver or stays with the agent.
            'lines.*.payee' => 'nullable|required_if:lines.*.line_category,vibali_tunduma,vibali_congo|in:driver,agent',
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
            // A Truck order's trucks are on its lines; truck_id mirrors the first one for older exports.
            'truck_id' => collect($validated['lines'])->pluck('truck_id')->filter()->first(),
            'usd_rate' => $validated['usd_rate'] ?? null,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'zmk_rate' => $validated['zmk_rate'] ?? null,
        ];
    }

    /**
     * List rows only (UAT 20 Nov: the Expenses page loaded every order with all its lines, trucks, drivers
     * and trips). Each row carries what the list shows; the full order comes from `show` when opened.
     */
    public function index(Request $request)
    {
        $request->validate([
            'expense_type' => 'nullable|in:fuel,vibali_tunduma,vibali_congo,mengine',
            'form' => ['nullable', Rule::in(ExpenseOrder::FORMS)],
            'status' => 'nullable|in:pending,approved,rejected,paid',
        ]);

        $orders = ExpenseOrder::query()
            ->with([
                'bookings:id,booking_number,client_id,direction', 'bookings.client:id,company_name',
                'creator:id,name', 'approver:id,name', 'subcategory:id,name', 'account:id,name', 'truck:id,reg_no',
                'lines:id,expense_order_id,line_category,unit_rate,booking_truck_id,truck_id',
                'lines.bookingTruck:id,truck_id',
                'lines.bookingTruck.truck:id,reg_no', 'lines.truck:id,reg_no',
            ])
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('expense_type'), fn ($q) => $q->where('expense_type', $request->expense_type))
            ->when($request->filled('form'), fn ($q) => $q->where('form', $request->form))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->get();

        return $orders->map(fn (ExpenseOrder $order) => [
            ...$order->only([
                'id', 'category', 'created_at', 'expense_type', 'form', 'form_number', 'initiated_by', 'is_legacy_mixed',
                'order_number', 'paid_at', 'payment_date', 'pending_price', 'price_overdue', 'reference_no', 'status',
                'total_amount', 'usd_total',
            ]),
            'approver' => $order->approver?->only(['id', 'name']),
            'bookings' => $order->bookings->map(fn ($b) => [
                'booking_number' => $b->booking_number, 'client' => ['company_name' => $b->client?->company_name], 'direction' => $b->direction, 'id' => $b->id,
            ])->values(),
            'creator' => $order->creator?->only(['id', 'name']),
            'line_categories' => $order->lines->pluck('line_category')->unique()->values(),
            'subcategory' => $order->subcategory?->only(['id', 'name']),
            // A Truck order's trucks are on its lines; older orders kept one truck on the order.
            'truck_reg_nos' => $order->lines->map(fn ($l) => $l->truck?->reg_no ?? $l->bookingTruck?->truck?->reg_no)->filter()->unique()->values()
                ->whenEmpty(fn ($c) => $order->truck ? collect([$order->truck->reg_no]) : $c),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'reference_no' => ['required', 'string', 'max:50', Rule::unique('expense_orders', 'reference_no')->where('expense_type', $request->input('expense_type'))],
            'category' => 'required|in:trip,office,truck',
            'expense_type' => 'required|in:fuel,vibali_tunduma,vibali_congo,mengine',
            'form' => ['nullable', Rule::in(ExpenseOrder::FORMS)],
        ], $this->headerRules(), $this->lineRules($request->input('form'))));

        $validated['payment_mode'] ??= ($validated['form'] ?? null) === 'emergency' ? 'on_spot' : $this->defaultPaymentMode($validated);
        $validated['lines'] = $this->fillFuelDrivers($validated['lines']);
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

    /**
     * Type, category and system number never change; the rest of a pending order can be edited, and
     * the user who approved an order can still edit it until it is paid (UAT 20 Nov).
     */
    public function update(Request $request, ExpenseOrder $expenseOrder)
    {
        if (!$expenseOrder->canBeEditedBy($request->user())) {
            return response()->json(['message' => $expenseOrder->status === 'approved'
                ? 'Only the person who approved this order can edit it before it is paid.'
                : 'Only pending expense orders can be edited.'], 422);
        }

        $validated = $request->validate(array_merge([
            'price_change_reason' => 'nullable|string|max:255',
            'reference_no' => ['required', 'string', 'max:50', Rule::unique('expense_orders', 'reference_no')->where('expense_type', $expenseOrder->expense_type)->ignore($expenseOrder->id)],
        ], $this->headerRules(), $this->lineRules($expenseOrder->form)));

        $validated['payment_mode'] ??= $this->defaultPaymentMode($validated);
        $validated['lines'] = $this->fillFuelDrivers($validated['lines']);
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

    /**
     * UAT 20 Nov: a fuel line without a driver's name gets the driver of its leg (Trip order) or of its
     * truck (Truck order); the user can still type another name.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    protected function fillFuelDrivers(array $lines): array
    {
        $legs = BookingTruck::with('driver:id,full_name', 'truck.driver:id,full_name')
            ->whereIn('id', collect($lines)->pluck('booking_truck_id')->filter())->get()->keyBy('id');
        $trucks = \App\Models\Truck::with('driver:id,full_name')->whereIn('id', collect($lines)->pluck('truck_id')->filter())->get()->keyBy('id');

        return array_map(function (array $line) use ($legs, $trucks) {
            if (($line['line_category'] ?? null) !== 'fuel' || trim((string) ($line['driver_name'] ?? '')) !== '') {
                return $line;
            }
            $leg = $legs->get($line['booking_truck_id'] ?? null);
            $line['driver_name'] = $leg?->driver?->full_name ?? $leg?->truck?->driver?->full_name
                ?? $trucks->get($line['truck_id'] ?? null)?->driver?->full_name;

            return $line;
        }, $lines);
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

        // UAT 20 Nov: the accountant records the day it was actually paid.
        $validated = $request->validate(['paid_at' => 'required|date|before_or_equal:today']);
        $expenseOrder->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => $validated['paid_at']]);

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
        $pdf = Pdf::loadView(ExpensePrintout::view($expenseOrder), ExpensePrintout::data($expenseOrder, $request->boolean('show_price', true)));

        return $pdf->download($expenseOrder->fileName('pdf'));
    }

    /** UAT 20 Nov: the Excel is the order's printed form, cell by cell; orders without a typed form keep the old sheet. */
    public function downloadExcel(Request $request, ExpenseOrder $expenseOrder)
    {
        $expenseOrder->load($this->eagerLoad());
        $export = $expenseOrder->is_legacy_mixed || ExpensePrintout::view($expenseOrder) === ExpensePrintout::GENERIC_VIEW
            ? new ExpenseOrderExport($expenseOrder)
            : new ExpenseFormSheet($expenseOrder, $request->boolean('show_price', true));

        return Excel::download($export, $expenseOrder->fileName('xlsx'));
    }

    public function downloadCategory(Request $request, ExpenseOrder $expenseOrder, string $category)
    {
        $expenseOrder->load($this->eagerLoad());

        if (!in_array($category, ['fuel', 'vibali_tunduma', 'vibali_congo', 'mengine'])) {
            abort(404);
        }

        $hasLines = $expenseOrder->lines->where('line_category', $category)->isNotEmpty();
        if (!$hasLines) {
            return response()->json(['message' => 'No lines of this category on this order.'], 404);
        }

        // One type per order: its category sheet is the printed form. Older mixed orders keep the per-category sheets.
        $export = match (true) {
            !$expenseOrder->is_legacy_mixed && ExpensePrintout::view($expenseOrder) !== ExpensePrintout::GENERIC_VIEW
                => new ExpenseFormSheet($expenseOrder, $request->boolean('show_price', true)),
            $category === 'fuel' => new FuelExpenseExport($expenseOrder),
            default => new LineCategoryExpenseExport($expenseOrder, $category),
        };

        return Excel::download($export, $expenseOrder->fileName('xlsx', "-{$category}"));
    }

    protected function authorizeRole(Request $request, array $allowedSlugs): void
    {
        $userRole = $request->user()->role?->slug;

        if (!in_array($userRole, $allowedSlugs)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}