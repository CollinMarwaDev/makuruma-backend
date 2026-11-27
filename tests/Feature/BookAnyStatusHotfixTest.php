<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hotfix 27 Nov 2026 (truck T 270 ELE): a truck can be booked whatever its current status. A Go can be
 * booked while its Return is still in transit; only a second Go before a Return is booked is refused.
 * Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=BookAnyStatusHotfixTest
 */
class BookAnyStatusHotfixTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Truck $truck;

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
        $this->truck = Truck::orderBy('reg_no')->firstOrFail();
    }

    private function book(string $direction): array
    {
        return $this->postJson('/api/bookings', ['client_id' => $this->client->id, 'direction' => $direction, 'trucks' => [['truck_id' => $this->truck->id, 'tons' => 30]]])
            ->assertCreated()->json();
    }

    private function goList(): array
    {
        return collect($this->getJson('/api/bookings/eligible-trucks?direction=go')->assertOk()->json())->pluck('id')->all();
    }

    public function test_a_truck_in_transit_on_its_return_can_get_its_next_go(): void
    {
        $this->book('go');

        // Open Go with no Return yet: a second Go is still refused.
        $this->assertNotContains($this->truck->id, $this->goList());
        $this->postJson('/api/bookings', ['client_id' => $this->client->id, 'direction' => 'go', 'trucks' => [['truck_id' => $this->truck->id]]])
            ->assertStatus(422)->assertJsonFragment(["{$this->truck->reg_no} already has a Go booking with no Return booked. Book its Return before booking another Go."]);

        $return = $this->book('return');
        $returnLeg = BookingTruck::findOrFail($return['booking_trucks'][0]['id']);
        $this->putJson("/api/tracking/{$returnLeg->id}/status", ['current_status' => 'in_transit'])->assertOk();

        // Return in transit: the truck is on the Go list and can be booked.
        $this->assertContains($this->truck->id, $this->goList());
        $nextGo = $this->book('go');
        $this->assertSame('go', $this->truck->fresh()->trip_status);

        // Offloading the older Return must not free the truck: it is on its new Go.
        $this->putJson("/api/tracking/{$returnLeg->id}/status", ['current_status' => 'offloaded'])->assertOk();
        $this->assertSame('go', $this->truck->fresh()->trip_status);
        $this->assertNotContains($this->truck->id, $this->goList());

        // Deleting the new Go puts the truck back to off duty (its Return is offloaded).
        $this->deleteJson("/api/bookings/{$nextGo['id']}")->assertNoContent();
        $this->assertSame('off_duty', $this->truck->fresh()->trip_status);
    }

    public function test_deleting_a_go_booked_during_a_return_puts_the_truck_back_on_that_return(): void
    {
        $this->book('go');
        $return = $this->book('return');
        $this->putJson("/api/tracking/{$return['booking_trucks'][0]['id']}/status", ['current_status' => 'in_transit'])->assertOk();

        $nextGo = $this->book('go');
        $this->deleteJson("/api/bookings/{$nextGo['id']}")->assertNoContent();

        $truck = $this->truck->fresh();
        $this->assertSame('return', $truck->trip_status);
        $this->assertSame('in_transit', $truck->current_status);
    }
}
