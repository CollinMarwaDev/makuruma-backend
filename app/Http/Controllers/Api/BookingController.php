<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\Truck;
use App\Services\BookingNumberGenerator;
use App\Services\BookingTruckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;


class BookingController extends Controller
{
    public function __construct(private BookingTruckService $trucks)
    {
    }

    protected function eagerLoad()
    {
        return [
            'client',
            'creator',
            'sourceBooking:id,booking_number,direction',
            'bookingTrucks' => fn ($query) => $query->whereNull('removed_at'),
            'bookingTrucks.truck',
            'bookingTrucks.trailer',
            'bookingTrucks.driver.documents',
            'bookingTrucks.documents',
            'bookingTrucks.trip',
        ];
    }

    /** Per-truck fields shared by create, update and add-truck. */
    protected function truckDetailRules(string $prefix): array
    {
        return [
            "{$prefix}tons" => 'nullable|numeric|min:0',
            "{$prefix}cargo" => 'nullable|string|max:255',
            "{$prefix}rate" => 'nullable|numeric|min:0',
            "{$prefix}loading_point" => 'nullable|string|max:255',
            "{$prefix}offloading_point" => 'nullable|string|max:255',
            "{$prefix}eta" => 'nullable|date',
        ];
    }

    public function index()
    {
        return Booking::with($this->eagerLoad())->latest()->get();
    }

    public function show(Booking $booking)
    {
        return $booking->load($this->eagerLoad());
    }

    /**
     * Trucks for a booking of the given direction (Go: off duty; Return: on an open Go trip).
     * Trucks missing a trailer or driver are included so the form can show why they can't be picked.
     */
    public function eligibleTrucks(Request $request)
    {
        $validated = $request->validate(['direction' => 'required|in:go,return']);

        return Truck::with(['trailer', 'driver.documents'])
            ->where('trip_status', BookingTruckService::requiredTripStatus($validated['direction']))
            ->orderBy('reg_no')
            ->get()
            ->each(fn (Truck $truck) => $truck->setAttribute('is_bookable', $truck->trailer_id && $truck->driver_id));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'direction' => 'required|in:go,return',
            'client_id' => 'required|exists:clients,id',
            'source_booking_id' => 'nullable|exists:bookings,id',
            'description' => 'nullable|string',
            'trucks' => 'required|array|min:1',
            'trucks.*.truck_id' => 'required|distinct|exists:trucks,id',
            ...$this->truckDetailRules('trucks.*.'),
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $bookingNumber = BookingNumberGenerator::generate($client, count($validated['trucks']));

        $booking = DB::transaction(function () use ($validated, $bookingNumber, $request) {
            $booking = Booking::create([
                'booking_number' => $bookingNumber,
                'direction' => $validated['direction'],
                'client_id' => $validated['client_id'],
                'source_booking_id' => $validated['source_booking_id'] ?? null,
                'description' => $validated['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['trucks'] as $index => $truckData) {
                $truck = Truck::findOrFail($truckData['truck_id']);
                $this->withField("trucks.{$index}.truck_id", fn () => $this->trucks->attach($booking, $truck, $truckData));
            }

            $this->trucks->syncSummary($booking);

            return $booking;
        });

        return response()->json($booking->load($this->eagerLoad()), 201);
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'description' => 'nullable|string',
            'trucks' => 'required|array|min:1',
            'trucks.*.booking_truck_id' => 'required|exists:booking_trucks,id',
            'trucks.*.trailer_id' => 'nullable|exists:trailers,id',
            'trucks.*.driver_id' => 'nullable|exists:drivers,id',
            ...$this->truckDetailRules('trucks.*.'),
        ]);

        DB::transaction(function () use ($booking, $validated) {
            $booking->update(['description' => $validated['description'] ?? null]);

            foreach ($validated['trucks'] as $truckData) {
                $bookingTruck = BookingTruck::find($truckData['booking_truck_id']);
                if (!$bookingTruck || $bookingTruck->booking_id !== $booking->id) {
                    continue;
                }

                $bookingTruck->update([
                    'capacity' => $truckData['tons'] ?? $bookingTruck->capacity,
                    'cargo' => $truckData['cargo'] ?? null,
                    'rate' => $truckData['rate'] ?? null,
                    'loading_point' => $truckData['loading_point'] ?? null,
                    'offloading_point' => $truckData['offloading_point'] ?? null,
                    'eta' => $truckData['eta'] ?? null,
                    'trailer_id' => $truckData['trailer_id'] ?? $bookingTruck->trailer_id,
                    'driver_id' => $truckData['driver_id'] ?? $bookingTruck->driver_id,
                ]);
            }

            $this->trucks->syncSummary($booking);
        });

        return $booking->load($this->eagerLoad());
    }

    /** Contract 2.1 B-4: add a truck to an existing booking. */
    public function addTruck(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'truck_id' => 'required|exists:trucks,id',
            ...$this->truckDetailRules(''),
        ]);

        $alreadyOn = $booking->bookingTrucks()->whereNull('removed_at')->where('truck_id', $validated['truck_id'])->exists();
        if ($alreadyOn) {
            throw ValidationException::withMessages(['truck_id' => 'This truck is already on the booking.']);
        }

        DB::transaction(function () use ($booking, $validated) {
            $this->withField('truck_id', fn () => $this->trucks->attach($booking, Truck::findOrFail($validated['truck_id']), $validated));
            $this->trucks->syncSummary($booking);
        });

        return response()->json($booking->load($this->eagerLoad()), 201);
    }

    /** Contract 2.1 B-4: correct a mistaken truck on a booking. */
    public function swapTruck(Request $request, Booking $booking, BookingTruck $bookingTruck)
    {
        abort_unless($bookingTruck->booking_id === $booking->id && !$bookingTruck->removed_at, 404);

        $validated = $request->validate(['truck_id' => 'required|exists:trucks,id']);

        $alreadyOn = $booking->bookingTrucks()->whereNull('removed_at')->where('truck_id', $validated['truck_id'])
            ->whereKeyNot($bookingTruck->id)->exists();
        if ($alreadyOn) {
            throw ValidationException::withMessages(['truck_id' => 'This truck is already on the booking.']);
        }

        DB::transaction(fn () => $this->trucks->swap($bookingTruck, Truck::findOrFail($validated['truck_id'])));

        return $booking->load($this->eagerLoad());
    }

    /** Take a truck off a booking (accident, complication); its history is kept. */
    public function removeTruck(Booking $booking, BookingTruck $bookingTruck)
    {
        abort_unless($bookingTruck->booking_id === $booking->id && !$bookingTruck->removed_at, 404);

        DB::transaction(function () use ($booking, $bookingTruck) {
            $this->trucks->remove($bookingTruck);
            $this->trucks->syncSummary($booking);
        });

        return response()->json(null, 204);
    }

    public function download(Booking $booking)
    {
        $booking->load($this->eagerLoad());

        $logoPath = public_path('images/logo.png');
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));

        $pdf = Pdf::loadView('bookings.access-list-pdf', [
            'booking' => $booking,
            'logoBase64' => $logoBase64,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("booking-{$booking->booking_number}.pdf");
    }

    public function destroy(Booking $booking)
    {
        DB::transaction(function () use ($booking) {
            $bookingTrucks = $booking->bookingTrucks()->whereNull('removed_at')->with('trip', 'truck')->get();

            foreach ($bookingTrucks as $bookingTruck) {
                $truck = $bookingTruck->truck;
                $trip = $bookingTruck->trip;

                if ($booking->direction === 'go') {
                    // This was the Go leg — if no Return has happened yet,
                    // the whole Trip record is meaningless without it.
                    if ($trip && !$trip->return_booking_truck_id) {
                        $trip->delete();
                    }
                    $truck?->update(['trip_status' => 'off_duty']);
                } else {
                    // This was the Return leg — the truck goes back to
                    // "awaiting return," exactly as if it was never booked.
                    if ($trip) {
                        $trip->update(['return_booking_truck_id' => null]);
                    }
                    $truck?->update(['trip_status' => 'go']);
                }
            }

            $booking->delete();
        });

        return response()->json(null, 204);
    }

    /** Re-key a service validation error to the request field that caused it. */
    private function withField(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([$field => collect($e->errors())->flatten()->first()]);
        }
    }
}
