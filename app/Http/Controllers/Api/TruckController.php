<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Truck;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TruckController extends Controller
{
    public function index()
    {
        return Truck::with(['documents', 'trailer', 'driver'])->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reg_no' => 'required|string|unique:trucks,reg_no',
            'capacity' => 'required|numeric',
            'status' => 'in:active,maintenance,decommissioned',
            ...$this->assignmentRules(),
            'buying_price' => 'nullable|numeric',
            'trip_status' => 'nullable|in:go,return,off_duty',
        ], $this->assignmentMessages());

        $truck = Truck::create($validated);

        return response()->json($truck->load(['documents', 'trailer', 'driver']), 201);
    }

    public function show(Truck $truck)
    {
        return $truck->load(['documents', 'trailer', 'driver']);
    }

    public function update(Request $request, Truck $truck)
    {
        $validated = $request->validate([
            'reg_no' => 'sometimes|required|string|unique:trucks,reg_no,' . $truck->id,
            'capacity' => 'sometimes|required|numeric',
            'status' => 'in:active,maintenance,decommissioned',
            ...$this->assignmentRules($truck),
            'buying_price' => 'nullable|numeric',
            'trip_status' => 'nullable|in:go,return,off_duty',
        ], $this->assignmentMessages());

        $truck->update($validated);

        return $truck->load(['documents', 'trailer', 'driver']);
    }

    /** One trailer and one driver per truck: neither may already be on another truck. */
    private function assignmentRules(?Truck $truck = null): array
    {
        // Checked only when the trailer/driver changes, so trucks that already share one stay editable.
        $unique = fn (string $column) => Rule::when(
            fn ($input) => (string) $input->{$column} !== (string) $truck?->{$column},
            [Rule::unique('trucks', $column)->ignore($truck?->id)]
        );

        return [
            'trailer_id' => ['nullable', 'exists:trailers,id', $unique('trailer_id')],
            'driver_id' => ['nullable', 'exists:drivers,id', $unique('driver_id')],
        ];
    }

    private function assignmentMessages(): array
    {
        return [
            'driver_id.unique' => 'This driver is already assigned to another truck.',
            'trailer_id.unique' => 'This trailer is already assigned to another truck.',
        ];
    }
}
