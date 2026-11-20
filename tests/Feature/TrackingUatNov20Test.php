<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Checkpoint;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Trailer;
use App\Models\Truck;
use App\Models\TruckMilestone;
use App\Models\User;
use App\Services\TrackingWorkbook;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * UAT 20 Nov 2026 (Tracking): offloaded legs stay on Active for a while, status drop-down in the Excel,
 * Unbooked and Needs-booking lists. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=TrackingUatNov20Test
 */
class TrackingUatNov20Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());
        $this->client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        foreach (['T444DDD', 'T555EEE', 'T666FFF'] as $i => $reg) {
            Truck::create([
                'capacity' => 30,
                'driver_id' => Driver::create(['full_name' => "Driver {$reg}"])->id,
                'reg_no' => $reg,
                'trailer_id' => Trailer::create(['reg_no' => "TR-2{$i}"])->id,
            ]);
        }
    }

    public function test_an_offloaded_leg_stays_on_the_active_list_for_the_configured_days(): void
    {
        $leg = $this->book('go', 0);
        $leg->update(['current_status' => 'offloaded', 'offloading_date' => now()->subDay()->toDateString()]);
        $this->assertTrue($leg->fresh()->on_active_list);

        $leg->update(['offloading_date' => now()->subDays(2)->toDateString()]);
        $this->assertFalse($leg->fresh()->on_active_list);

        config(['tracking.offloaded_visible_days' => 2]);
        $this->assertTrue($leg->fresh()->on_active_list);
        $this->assertTrue($this->book('go', 1)->on_active_list); // open legs always
    }

    public function test_the_excel_status_column_is_a_drop_down_of_active_statuses(): void
    {
        $this->book('go', 0);
        $path = tempnam(sys_get_temp_dir(), 'trk') . '.xlsx';
        IOFactory::createWriter(app(TrackingWorkbook::class)->export(BookingTruck::all()), 'Xlsx')->save($path);

        $book = IOFactory::load($path);
        $statuses = array_column($book->getSheetByName('STATUSES')->toArray(), 0);
        $this->assertContains('WAITING TO LOAD', $statuses);
        $this->assertContains('OFFLOADED', $statuses);

        $sheets = collect($book->getAllSheets())->keyBy(fn ($sheet) => trim($sheet->getTitle())); // template title is "GOING   "
        foreach (['GOING', 'RETURN'] as $title) {
            $validation = $sheets[$title]->getDataValidation('G3');
            $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
            $this->assertSame(DataValidation::STYLE_STOP, $validation->getErrorStyle());
            $this->assertStringContainsString('STATUSES', $validation->getFormula1());
        }
        // The list covers 500 rows, not only the filled ones (the reader expands it only up to the last row).
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertStringContainsString('sqref="G3:G503"', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
    }

    public function test_unbooked_and_urgent_lists(): void
    {
        $trucks = Truck::orderBy('reg_no')->get();
        $goReached = $this->book('go', 0);       // reached Kasumbalesa Zambia, no Return → urgent
        $goByLocation = $this->book('go', 1);    // location says Sakania → urgent
        $goWithReturn = $this->book('go', 2);    // reached, but Return already booked → not urgent
        $goEarly = $this->book('go', 3);         // still in Tanzania → not urgent

        $kasumbalesa = Checkpoint::where('name', 'Kasumbalesa Zambia')->value('id');
        foreach ([$goReached, $goWithReturn] as $leg) {
            TruckMilestone::create(['truck_id' => $leg->truck_id, 'booking_truck_id' => $leg->id, 'checkpoint_id' => $kasumbalesa, 'arrival_at' => '2026-11-18 10:00:00']);
        }
        $goByLocation->update(['current_location' => 'Sakania border']);
        $goEarly->update(['current_location' => 'Tunduma']);

        // Truck 2's Go is offloaded and its Return booked; that Return reached Nakonde → urgent (needs a Go).
        $goWithReturn->update(['current_status' => 'offloaded', 'offloading_date' => now()->subDays(10)->toDateString()]);
        $return = $this->book('return', 2);
        TruckMilestone::create(['truck_id' => $return->truck_id, 'booking_truck_id' => $return->id, 'checkpoint_id' => Checkpoint::where('name', 'Nakonde')->value('id'), 'arrival_at' => '2026-11-19 08:00:00']);

        // Truck 4 finished a Go 3 days ago and waits for a Return → unbooked.
        $this->book('go', 4)->update(['current_status' => 'offloaded', 'offloading_date' => now()->subDays(3)->toDateString()]);

        $needs = $this->getJson('/api/tracking/booking-needs')->assertOk()->json();

        $urgent = collect($needs['urgent'])->keyBy('truck.reg_no');
        $this->assertEqualsCanonicalizing([$trucks[0]->reg_no, $trucks[1]->reg_no, $trucks[2]->reg_no], $urgent->keys()->all());
        $this->assertSame(['Kasumbalesa Zambia', '2026-11-18', 'return'], [$urgent[$trucks[0]->reg_no]['reached'], $urgent[$trucks[0]->reg_no]['reached_at'], $urgent[$trucks[0]->reg_no]['needs']]);
        $this->assertSame('Sakania border', $urgent[$trucks[1]->reg_no]['reached']);
        $this->assertSame(['Nakonde', 'go'], [$urgent[$trucks[2]->reg_no]['reached'], $urgent[$trucks[2]->reg_no]['needs']]);

        $unbooked = collect($needs['unbooked'])->keyBy('truck.reg_no');
        $this->assertSame(['return', 3], [$unbooked[$trucks[4]->reg_no]['needs'], $unbooked[$trucks[4]->reg_no]['days_idle']]);
        $this->assertSame('go', $unbooked[$trucks[5]->reg_no]['needs']); // never booked
        $this->assertNull($unbooked[$trucks[5]->reg_no]['last_leg']);
        foreach ([0, 1, 2, 3] as $i) {
            $this->assertFalse($unbooked->has($trucks[$i]->reg_no));
        }

        // Once the Return leg's truck gets its next Go booked it is no longer urgent.
        $this->putJson("/api/tracking/{$return->id}/status", ['current_status' => 'offloaded'])->assertOk();
        $this->book('go', 2);
        $this->assertFalse(collect($this->getJson('/api/tracking/booking-needs')->json('urgent'))->pluck('truck.reg_no')->contains($trucks[2]->reg_no));
    }

    private function book(string $direction, int $truckIndex): BookingTruck
    {
        $truck = Truck::orderBy('reg_no')->skip($truckIndex)->firstOrFail();
        $booking = $this->postJson('/api/bookings', ['client_id' => $this->client->id, 'direction' => $direction, 'trucks' => [['truck_id' => $truck->id, 'tons' => 30]]])
            ->assertCreated()->json();

        return BookingTruck::findOrFail($booking['booking_trucks'][0]['id']);
    }
}
