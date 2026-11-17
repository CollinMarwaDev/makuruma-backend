<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsdRate;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Expense UAT req. 18: USD→TZS rate per period, used only by the profit report. */
class UsdRateController extends Controller
{
    public function index()
    {
        return UsdRate::orderByDesc('starts_on')->get();
    }

    public function store(Request $request)
    {
        $this->authorizeEditor($request);
        $validated = $this->validated($request);

        return response()->json(UsdRate::create($validated), 201);
    }

    public function update(Request $request, UsdRate $usdRate)
    {
        $this->authorizeEditor($request);
        $usdRate->update($this->validated($request, $usdRate));

        return $usdRate;
    }

    public function destroy(Request $request, UsdRate $usdRate)
    {
        $this->authorizeEditor($request);
        $usdRate->delete();

        return response()->json(null, 204);
    }

    /** Periods may not overlap, so a date has at most one rate. */
    private function validated(Request $request, ?UsdRate $current = null): array
    {
        $validated = $request->validate([
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'rate' => 'required|numeric|min:0.0001',
            'starts_on' => 'required|date',
        ]);

        $overlap = UsdRate::whereDate('starts_on', '<=', $validated['ends_on'])
            ->whereDate('ends_on', '>=', $validated['starts_on'])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'This period overlaps another USD rate period.']);
        }

        return $validated;
    }

    private function authorizeEditor(Request $request): void
    {
        abort_unless(in_array($request->user()?->role?->slug, ['admin', 'manager', 'accountant'], true), 403, 'Only Admin, Manager or Accountant can change USD rates.');
    }
}
