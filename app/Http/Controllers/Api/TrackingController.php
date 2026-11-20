<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingTruck;
use App\Models\TrackingStatus;
use App\Models\Truck;
use App\Models\TruckMilestone;
use App\Services\TrackingLegService;
use App\Services\TrackingTimeline;
use App\Services\BookingNeedsService;
use App\Services\TrackingWorkbook;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Tracking per booking leg (contract 2.2 D-1). A truck can have an open Go leg and an open Return
 * leg at the same time; each keeps its own status, location, border route, dates and checkpoints.
 */
class TrackingController extends Controller
{
    public function __construct(private TrackingLegService $legs)
    {
    }

    protected function eagerLoad(): array
    {
        return [
            'booking:id,booking_number,direction,client_id,loading_point,offloading_point,eta',
            'booking.client:id,company_name',
            'truck:id,reg_no,capacity,trip_status',
            'trailer:id,reg_no',
            'driver:id,full_name,phone',
            'trip:id,trip_code',
            'milestones',
            'documents',
        ];
    }

    /** Legs for the tracking list. scope: active (default, not offloaded), offloaded, all. `completed` is an old name for offloaded. */
    public function index(Request $request)
    {
        $validated = $request->validate(['scope' => 'nullable|in:active,offloaded,completed,all']);

        return $this->legQuery($validated['scope'] ?? 'active')->with($this->eagerLoad())->latest('id')->get();
    }

    public function show(BookingTruck $bookingTruck)
    {
        return $this->withTimeline($bookingTruck);
    }

    public function updateStatus(Request $request, BookingTruck $bookingTruck)
    {
        $validated = $request->validate([
            'current_location' => 'nullable|string|max:255',
            'current_status' => ['required', Rule::exists('tracking_statuses', 'key')->where('is_active', true)],
            'route' => 'nullable|in:kasumbalesa,sakania',
        ]);

        $this->legs->updateStatus($bookingTruck, $validated['current_status'], $validated['current_location'] ?? null, $validated['route'] ?? null);

        return $this->withTimeline($bookingTruck->fresh());
    }

    public function upsertMilestone(Request $request, BookingTruck $bookingTruck)
    {
        $validated = $request->validate([
            'checkpoint_id' => 'required|exists:checkpoints,id',
            'arrival_at' => 'nullable|date',
            'dispatch_at' => 'nullable|date',
        ]);

        $onRoute = TrackingTimeline::checkpointsFor($bookingTruck->loadMissing('booking'))->contains('id', (int) $validated['checkpoint_id']);
        abort_unless($onRoute, 422, 'This checkpoint is not on the selected border route.');

        TrackingTimeline::assertInOrder($bookingTruck, milestone: $validated);

        TruckMilestone::updateOrCreate(
            ['booking_truck_id' => $bookingTruck->id, 'checkpoint_id' => $validated['checkpoint_id']],
            [
                'arrival_at' => $validated['arrival_at'] ?? null,
                'dispatch_at' => $validated['dispatch_at'] ?? null,
                'truck_id' => $bookingTruck->truck_id,
            ]
        );

        return $this->withTimeline($bookingTruck->fresh());
    }

    public function updateTripDates(Request $request, BookingTruck $bookingTruck)
    {
        $validated = $request->validate(array_fill_keys(array_keys(TrackingTimeline::LEG_DATES), 'nullable|date'));
        $dates = array_merge(array_fill_keys(array_keys(TrackingTimeline::LEG_DATES), null), $validated);

        TrackingTimeline::assertInOrder($bookingTruck, $dates);
        $bookingTruck->update($dates);

        return $this->withTimeline($bookingTruck->fresh());
    }

    public function download(BookingTruck $bookingTruck)
    {
        $truck = $this->asTruckView($bookingTruck->load($this->eagerLoad()));
        $order = TrackingTimeline::checkpointsFor($bookingTruck)->pluck('id')->flip();

        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents(public_path('images/logo.png')));
        $pdf = Pdf::loadView('tracking.report-pdf', [
            'logoBase64' => $logoBase64,
            'milestones' => $bookingTruck->milestones->load('checkpoint')->sortBy(fn ($m) => $order[$m->checkpoint_id] ?? 99),
            'truck' => $truck,
        ]);

        return $pdf->download("tracking-{$truck->reg_no}-{$bookingTruck->booking->booking_number}.pdf");
    }

    /**
     * The client's GOING / RETURN tracking spreadsheet (contract 2.2 A; tracking req. 10): the one format for
     * every tracking download. POST `ids` = the legs on screen (current tab and filters); GET = every open leg.
     */
    public function downloadExcel(Request $request, TrackingWorkbook $workbook)
    {
        $validated = $request->validate(['ids' => 'sometimes|array|max:2000', 'ids.*' => 'integer']);
        $legs = isset($validated['ids'])
            ? $this->legQuery('all')->whereIn('id', $validated['ids'])->orderBy('id')->get()
            : $this->legQuery('active')->orderBy('id')->get();
        $book = $workbook->export($legs);

        return response()->streamDownload(
            fn () => IOFactory::createWriter($book, 'Xlsx')->save('php://output'),
            'MAKURUMA TRACKING ' . now()->format('d.m.Y') . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /**
     * Bulk update from the edited spreadsheet (contract 2.2 B). Without `apply` it only previews:
     * what each row would change and why rows are rejected. With `apply=1` it saves the valid rows.
     */
    public function import(Request $request, TrackingWorkbook $workbook)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'apply' => 'nullable|boolean',
        ]);

        return $workbook->import($validated['file']->getRealPath(), (bool) ($validated['apply'] ?? false));
    }

    /** UAT 20 Nov: trucks with no booking, and trucks that need their next booking soon. */
    public function bookingNeeds(BookingNeedsService $needs)
    {
        return $needs->list();
    }

    private function legQuery(string $scope): Builder
    {
        return BookingTruck::query()
            ->whereNull('removed_at')
            // Active also keeps legs offloaded in the last tracking.offloaded_visible_days days (UAT 20 Nov).
            ->when($scope === 'active', fn ($q) => $q->where(fn ($q) => $q->where('current_status', '!=', TrackingStatus::OFFLOADED)
                ->orWhereDate(DB::raw('COALESCE(offloading_date, completed_at)'), '>=', now()->subDays(config('tracking.offloaded_visible_days'))->toDateString())))
            ->when(in_array($scope, ['offloaded', 'completed'], true), fn ($q) => $q->where('current_status', TrackingStatus::OFFLOADED));
    }

    /** Leg with its route checkpoints in travel order (for the detail form). */
    private function withTimeline(BookingTruck $leg): array
    {
        $leg->load($this->eagerLoad());

        return [
            ...$leg->toArray(),
            'route_checkpoints' => TrackingTimeline::checkpointsFor($leg)->values(),
        ];
    }

    /** Present a leg as the truck-shaped object the existing PDF/Excel templates expect. */
    private function asTruckView(BookingTruck $leg): Truck
    {
        $truck = (clone $leg->truck)->forceFill([
            'current_location' => $leg->current_location,
            'current_status' => $leg->current_status,
        ]);
        $truck->setRelation('trailer', $leg->trailer);
        $truck->setRelation('driver', $leg->driver);
        $truck->setRelation('bookingTrucks', collect([$leg]));
        $truck->setRelation('milestones', $leg->milestones->load('checkpoint'));

        return $truck;
    }
}
