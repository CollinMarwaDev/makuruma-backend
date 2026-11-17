<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\Role;
use App\Models\TrackingStatus;
use App\Models\Truck;
use App\Models\User;
use App\Notifications\TrackingAlert;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tracking req. 1–9 (client request 17 Nov 2026): the client's status names, Offloaded as the final status,
 * and the 5-day ready-for-invoicing notice without a status change. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=TrackingStatusesTest
 */
class TrackingStatusesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());
    }

    public function test_statuses_use_the_clients_names_in_order(): void
    {
        $keys = TrackingStatus::orderBy('sort_order')->pluck('key')->all();

        $this->assertSame(['pending', 'on_route_to_loading', 'waiting_to_load', 'loaded_waiting_documents', 'in_transit', 'clearing', 'waiting_to_cross'], array_slice($keys, 0, 7));
        $this->assertSame(['waiting_to_offload', 'offloaded'], array_slice($keys, -2));
        $this->assertEmpty(array_intersect($keys, ['loading', 'at_border', 'offloading', 'completed']));
        $this->assertTrue(TrackingStatus::where('key', 'clearing')->value('is_system'));
    }

    public function test_offloaded_is_final_and_frees_a_return_truck(): void
    {
        $leg = $this->leg('return');

        $this->putJson("/api/tracking/{$leg->id}/status", ['current_status' => 'offloaded'])->assertOk();
        $leg->refresh();
        $this->assertSame('offloaded', $leg->current_status);
        $this->assertNotNull($leg->completed_at);
        $this->assertSame('off_duty', $leg->truck->fresh()->trip_status);
        $this->assertSame('offloaded', $leg->truck->fresh()->current_status);

        $this->getJson('/api/tracking?scope=active')->assertJsonMissing(['id' => $leg->id]);
        $this->assertSame([$leg->id], collect($this->getJson('/api/tracking?scope=offloaded')->json())->pluck('id')->all());
        $this->putJson("/api/tracking/{$leg->id}/status", ['current_status' => 'completed'])->assertStatus(422);
    }

    public function test_accountants_are_told_once_five_days_after_offloading_without_a_status_change(): void
    {
        Notification::fake();
        $accountant = User::factory()->create(['role_id' => Role::where('slug', 'accountant')->value('id')]);
        $leg = $this->leg('go');
        $leg->update(['current_status' => 'waiting_to_offload', 'offloading_date' => now()->subDays(6)->toDateString()]);

        $this->artisan('tracking:daily-checks')->assertSuccessful();
        $this->artisan('tracking:daily-checks')->assertSuccessful();

        Notification::assertSentToTimes($accountant, TrackingAlert::class, 2); // ready-for-invoicing + POD overdue
        Notification::assertSentTo($accountant, TrackingAlert::class, fn (TrackingAlert $n) => $n->toArray($accountant)['title'] === 'Ready for invoicing');
        $this->assertSame('waiting_to_offload', $leg->fresh()->current_status);
        $this->assertNotNull($leg->fresh()->invoice_ready_notified_at);
    }

    private function leg(string $direction): BookingTruck
    {
        $client = Client::firstOrCreate(['company_name' => 'Copper Co'], ['short_code' => 'CPR']);
        $truck = Truck::orderBy('reg_no')->firstOrFail();
        if ($direction === 'return') {
            $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => [['truck_id' => $truck->id, 'tons' => 30]]])->assertCreated();
        }
        $booking = $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => $direction, 'trucks' => [['truck_id' => $truck->id, 'tons' => 30]]])->assertCreated()->json();

        return BookingTruck::findOrFail($booking['booking_trucks'][0]['id']);
    }
}
