<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract 2.4: trucks on one booking may have different routes/cargo/rates, and the booking can be
 * invoiced in parts. Older migrations use MySQL-only SQL, so run against a MySQL test database:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=PartialInvoiceTest
 */
class PartialInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Skip before RefreshDatabase migrates: the default phpunit connection is SQLite.
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
    }

    public function test_a_booking_can_be_invoiced_in_parts_with_per_truck_details(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR', 'allows_advance_invoice' => true]);
        $trucks = \App\Models\Truck::orderBy('reg_no')->pluck('id');

        $bookingId = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => [
                ['truck_id' => $trucks[0], 'tons' => 30, 'rate' => 100, 'cargo' => 'Copper', 'loading_point' => 'Dar', 'offloading_point' => 'Kolwezi', 'eta' => '2026-11-20'],
                ['truck_id' => $trucks[1], 'tons' => 28, 'rate' => 110, 'cargo' => 'Fuel', 'loading_point' => 'Dar', 'offloading_point' => 'Lubumbashi', 'eta' => '2026-11-22'],
                ['truck_id' => $trucks[2], 'tons' => 32, 'rate' => 100, 'cargo' => 'Copper', 'loading_point' => 'Tanga', 'offloading_point' => 'Kolwezi'],
            ],
        ])->assertCreated()->json('id');

        $eligible = fn () => collect($this->getJson("/api/invoices/eligible-trucks?booking_id={$bookingId}&invoice_type=settlement")->assertOk()->json());
        $line = fn (array $bt) => ['booking_truck_id' => $bt['id'], 'quantity' => $bt['capacity'], 'rate' => $bt['rate'], 'percentage' => 100];
        $invoice = fn (array $lines) => $this->postJson('/api/invoices', [
            'booking_id' => $bookingId,
            'exchange_rate' => 2500,
            'invoice_date' => '2026-11-25',
            'invoice_type' => 'settlement',
            'lines' => $lines,
        ])->assertCreated()->json('id');

        // Invoice only the first truck while the others are still on the road.
        $all = $eligible();
        $this->assertCount(3, $all);
        $this->assertArrayHasKey('current_status', $all[0]);
        $firstId = $invoice([$line($all[0])]);

        $this->assertCount(2, $eligible());
        $this->assertCount(2, $this->getJson("/api/invoices/{$firstId}")->assertOk()->json('uninvoiced_trucks'));

        // Invoice the rest later.
        $invoice($eligible()->map($line)->all());
        $this->assertCount(0, $eligible());
        $this->assertCount(0, $this->getJson("/api/invoices/{$firstId}")->json('uninvoiced_trucks'));

        $this->get("/api/invoices/{$firstId}/download")->assertOk();
    }
}
