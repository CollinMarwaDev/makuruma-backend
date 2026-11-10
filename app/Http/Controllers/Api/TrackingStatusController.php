<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingTruck;
use App\Models\TrackingStatus;
use App\Models\Truck;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Contract 2.2 D-4: admin-managed tracking statuses. Everyone can read; only admins change. */
class TrackingStatusController extends Controller
{
    public function index()
    {
        return TrackingStatus::orderBy('sort_order')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate($this->rules());

        $key = Str::of($validated['label'])->lower()->slug('_')->limit(45, '')->toString();
        if (TrackingStatus::where('key', $key)->exists()) {
            throw ValidationException::withMessages(['label' => 'A status with this name already exists.']);
        }

        return response()->json(TrackingStatus::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'is_system' => false,
            'key' => $key,
            'sort_order' => $validated['sort_order'] ?? (TrackingStatus::max('sort_order') + 10),
        ]), 201);
    }

    public function update(Request $request, TrackingStatus $trackingStatus)
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate($this->rules($trackingStatus));

        if ($trackingStatus->is_system && array_key_exists('is_active', $validated) && !$validated['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'System statuses are used by tracking rules and cannot be deactivated.']);
        }

        $trackingStatus->update($validated);

        return $trackingStatus;
    }

    /** Unused custom statuses are deleted; used ones are deactivated so history keeps its label. */
    public function destroy(Request $request, TrackingStatus $trackingStatus)
    {
        $this->authorizeAdmin($request);
        abort_if($trackingStatus->is_system, 422, 'System statuses cannot be removed.');

        $inUse = BookingTruck::where('current_status', $trackingStatus->key)->exists()
            || Truck::where('current_status', $trackingStatus->key)->exists();

        if ($inUse) {
            $trackingStatus->update(['is_active' => false]);

            return response()->json($trackingStatus);
        }

        $trackingStatus->delete();

        return response()->json(null, 204);
    }

    private function rules(?TrackingStatus $status = null): array
    {
        return [
            'color' => ['sometimes', Rule::in(['default', 'warning', 'danger', 'success', 'disabled'])],
            'is_active' => 'sometimes|boolean',
            'label' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('tracking_statuses', 'label')->ignore($status?->id)],
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->slug === 'admin', 403, 'Only administrators can manage tracking statuses.');
    }
}
