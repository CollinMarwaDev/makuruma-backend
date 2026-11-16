<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trip;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UAT Nov 2026: deleting a Go booking (or taking a truck off it) after its Return was booked left
 * trips with only a Return half, which the profit report skipped. Needs MySQL, like PartialInvoiceTest:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=HalfTripGuardTest
 */
class HalfTripGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
    }

    public function test_a_go_leg_with_a_return_booked_cannot_be_removed_swapped_or_deleted(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $trucks = Truck::orderBy('reg_no')->get();
        $truck = fn (int $i) => ['truck_id' => $trucks[$i]->id, 'tons' => 30, 'rate' => 100];

        $go = $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => [$truck(0), $truck(1)]])
            ->assertCreated()->json();
        $return = $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'return', 'trucks' => [$truck(0)]])
            ->assertCreated()->json();

        $legOf = fn (array $booking, int $i) => collect($booking['booking_trucks'])->firstWhere('truck_id', $trucks[$i]->id)['id'];
        $message = "{$trucks[0]->reg_no} already has Return booking {$return['booking_number']}.";

        $this->assertStringStartsWith($message, $this->deleteJson("/api/bookings/{$go['id']}")->assertStatus(422)->json('message'));
        $this->assertStringStartsWith($message, $this->deleteJson("/api/bookings/{$go['id']}/trucks/{$legOf($go, 0)}")->assertStatus(422)->json('message'));
        $this->assertStringStartsWith($message, $this->putJson("/api/bookings/{$go['id']}/trucks/{$legOf($go, 0)}", ['truck_id' => $trucks[2]->id])->assertStatus(422)->json('message'));

        // The trip is untouched; the truck without a Return can still be taken off.
        $this->assertSame(1, Trip::whereNotNull('go_booking_truck_id')->whereNotNull('return_booking_truck_id')->count());
        $this->deleteJson("/api/bookings/{$go['id']}/trucks/{$legOf($go, 1)}")->assertNoContent();

        // Remove the Return first, then the Go booking can go.
        $this->deleteJson("/api/bookings/{$return['id']}")->assertNoContent();
        $this->deleteJson("/api/bookings/{$go['id']}")->assertNoContent();
    }

    public function test_the_profit_report_still_lists_a_trip_that_has_only_a_return_half(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $truck = Truck::orderBy('reg_no')->firstOrFail();
        $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => [['truck_id' => $truck->id, 'tons' => 30]]])->assertCreated();
        $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'return', 'trucks' => [['truck_id' => $truck->id, 'tons' => 30]]])->assertCreated();

        // Older data: the Go half is gone.
        Trip::query()->update(['go_booking_truck_id' => null]);

        $sheets = (new \App\Exports\TruckProfitReportExport($truck, (int) now()->year))->sheets();
        $this->assertCount(2, $sheets, 'one trip sheet plus the annual sheet');
    }
}
