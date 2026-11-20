<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * UAT 20 Nov 2026: a booking's month is the average ETA of its trucks; it sets the month in a new
 * booking number and the dashboard month, but never renumbers a booking. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=BookingDateTest
 */
class BookingDateTest extends TestCase
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
    }

    public function test_booking_date_is_the_average_eta_and_sets_the_number_month(): void
    {
        $trucks = Truck::orderBy('reg_no')->take(2)->get();
        $booking = $this->postJson('/api/bookings', [
            'client_id' => $this->client->id,
            'direction' => 'go',
            'trucks' => [
                ['truck_id' => $trucks[0]->id, 'eta' => '2027-01-01'],
                ['truck_id' => $trucks[1]->id, 'eta' => '2027-01-31'],
            ],
        ])->assertCreated()->json();

        $this->assertSame('2027-01-16', $booking['booking_date']);
        $this->assertSame('CPR02012027', $booking['booking_number']);

        // ETAs move to March: the month follows, the number does not (contract 2.1 B-4).
        $edited = $this->putJson("/api/bookings/{$booking['id']}", [
            'trucks' => collect($booking['booking_trucks'])->map(fn ($bt) => ['booking_truck_id' => $bt['id'], 'eta' => '2027-03-10'])->all(),
        ])->assertOk()->json();

        $this->assertSame('2027-03-10', $edited['booking_date']);
        $this->assertSame('CPR02012027', $edited['booking_number']);
    }

    public function test_without_etas_the_booking_date_is_today_and_counts_this_month(): void
    {
        $truck = Truck::orderBy('reg_no')->first();
        $booking = $this->postJson('/api/bookings', ['client_id' => $this->client->id, 'direction' => 'go', 'trucks' => [['truck_id' => $truck->id]]])
            ->assertCreated()->json();

        $this->assertSame(now()->toDateString(), $booking['booking_date']);
        $this->assertSame('CPR01' . now()->format('mY'), $booking['booking_number']);
        $this->assertSame(1, $this->getJson('/api/dashboard/summary')->json('bookings.this_month'));
    }
}
