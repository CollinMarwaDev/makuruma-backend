<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\ExpenseOrder;
use App\Models\Role;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ExpenseAlert;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense UAT req. 7–11: fuel price optional, price changes need a reason, pending-price alert, no payment
 * while a price is missing, download with or without price. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseFuelPriceTest
 */
class ExpenseFuelPriceTest extends TestCase
{
    use RefreshDatabase;

    private array $booking;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $trucks = Truck::orderBy('reg_no')->take(2)->get();
        $this->booking = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => $trucks->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 30, 'rate' => 100])->all(),
        ])->assertCreated()->json();
    }

    public function test_fuel_lines_need_truck_driver_and_litres_but_not_a_price(): void
    {
        // UAT 20 Nov: a blank driver is filled from the leg instead of being refused.
        $this->postJson('/api/expense-orders', $this->order([$this->line(0, ['driver_name' => ''])]))->assertCreated()
            ->assertJsonPath('lines.0.driver_name', BookingTruck::with('driver')->find($this->booking['booking_trucks'][0]['id'])->driver->full_name);
        $this->postJson('/api/expense-orders', $this->order([$this->line(0, ['quantity' => 0])]))->assertStatus(422);
        $this->postJson('/api/expense-orders', $this->order([$this->line(0)], ['category' => 'office', 'booking_ids' => []]))->assertStatus(422);

        $this->postJson('/api/expense-orders', $this->order([$this->line(0), $this->line(1)]))
            ->assertCreated()
            ->assertJsonPath('pending_price', true)
            ->assertJsonPath('total_amount', '0.00');
    }

    public function test_on_spot_fuel_needs_prices_and_vendor_sets_the_default_mode(): void
    {
        $zambia = Vendor::create(['company_name' => 'Zambia Fuel', 'vendor_type' => 'fuel', 'payment_mode' => 'on_spot']);

        $this->postJson('/api/expense-orders', $this->order([$this->line(0, ['vendor_id' => $zambia->id])]))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->postJson('/api/expense-orders', $this->order([$this->line(0, ['vendor_id' => $zambia->id, 'unit_rate' => 3200])]))
            ->assertCreated()->assertJsonPath('payment_mode', 'on_spot')->assertJsonPath('pending_price', false);
    }

    public function test_pending_price_blocks_payment_and_changing_a_set_price_needs_a_reason(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->order([$this->line(0), $this->line(1, ['unit_rate' => 3000])]))->json();
        $this->postJson("/api/expense-orders/{$order['id']}/approve")->assertOk();
        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => now()->toDateString()])->assertStatus(422);

        [$unpriced, $priced] = collect($order['lines'])->partition(fn ($l) => $l['unit_rate'] === null)->map->first()->values()->all();

        // Filling a missing price needs no reason; changing a set one does.
        $this->patchJson("/api/expense-orders/{$order['id']}/prices", ['prices' => [['line_id' => $unpriced['id'], 'unit_rate' => 3100]]])
            ->assertOk()->assertJsonPath('pending_price', false);
        $this->patchJson("/api/expense-orders/{$order['id']}/prices", ['prices' => [['line_id' => $priced['id'], 'unit_rate' => 3050]]])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $updated = $this->patchJson("/api/expense-orders/{$order['id']}/prices", ['prices' => [['line_id' => $priced['id'], 'unit_rate' => 3050]], 'reason' => 'Price board changed on Wednesday'])
            ->assertOk()->json();

        $this->assertSame((float) (100 * 3100 + 100 * 3050), (float) $updated['total_amount']);
        $this->assertCount(2, $updated['price_changes']);
        $this->assertSame('Price board changed on Wednesday', $updated['price_changes'][0]['reason']);
        $this->assertSame('System Admin', $updated['price_changes'][0]['user']['name']);

        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => now()->toDateString()])->assertOk();
    }

    public function test_editing_a_pending_order_logs_price_changes_with_a_reason(): void
    {
        $lines = [$this->line(0, ['group_key' => 'g1', 'unit_rate' => 3000])];
        $order = $this->postJson('/api/expense-orders', $this->order($lines))->json();
        $lines[0]['unit_rate'] = 3200;

        $this->putJson("/api/expense-orders/{$order['id']}", $this->order($lines, ['reference_no' => $order['reference_no']]))
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("/api/expense-orders/{$order['id']}", $this->order($lines, ['reference_no' => $order['reference_no'], 'price_change_reason' => 'Typo']))
            ->assertOk()->assertJsonPath('price_changes.0.old_unit_rate', '3000.00')->assertJsonPath('price_changes.0.new_unit_rate', '3200.00');
    }

    public function test_accountants_are_alerted_once_after_14_days(): void
    {
        Notification::fake();
        $accountant = User::factory()->create(['role_id' => Role::where('slug', 'accountant')->value('id')]);
        $order = $this->postJson('/api/expense-orders', $this->order([$this->line(0)]))->json();

        $this->artisan('expenses:pending-price-check')->assertSuccessful();
        Notification::assertNothingSent();

        ExpenseOrder::whereKey($order['id'])->update(['created_at' => now()->subDays(15)]);
        $this->artisan('expenses:pending-price-check');
        $this->artisan('expenses:pending-price-check');
        Notification::assertSentToTimes($accountant, ExpenseAlert::class, 1);
        $this->getJson("/api/expense-orders/{$order['id']}")->assertJsonPath('price_overdue', true);
    }

    /** Orders saved before this release (no series number) kept lump-sum lines without a unit price. */
    public function test_orders_saved_before_the_new_forms_are_never_price_pending(): void
    {
        Notification::fake();
        User::factory()->create(['role_id' => Role::where('slug', 'accountant')->value('id')]);
        $order = $this->postJson('/api/expense-orders', $this->order([$this->line(0)]))->json();
        ExpenseOrder::whereKey($order['id'])->update(['serial' => null, 'created_at' => now()->subDays(30)]);

        $this->getJson("/api/expense-orders/{$order['id']}")->assertJsonPath('pending_price', false);
        $this->artisan('expenses:pending-price-check')->assertSuccessful();
        Notification::assertNothingSent();

        $this->postJson("/api/expense-orders/{$order['id']}/approve")->assertOk();
        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => now()->toDateString()])->assertOk();
    }

    public function test_fuel_pdf_downloads_with_and_without_price(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->order([$this->line(0, ['unit_rate' => 3000])]))->json();

        $this->get("/api/expense-orders/{$order['id']}/download?show_price=1")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/api/expense-orders/{$order['id']}/download?show_price=0")->assertOk();
    }

    private int $ref = 0;

    private function order(array $lines, array $extra = []): array
    {
        return ['booking_ids' => [$this->booking['id']], 'category' => 'trip', 'expense_type' => 'fuel', 'lines' => $lines, 'reference_no' => 'F-' . ++$this->ref, ...$extra];
    }

    private function line(int $truck, array $extra = []): array
    {
        return [
            'booking_truck_id' => $this->booking['booking_trucks'][$truck]['id'],
            'currency' => 'TZS',
            'description' => 'Diesel',
            'driver_name' => 'Juma',
            'line_category' => 'fuel',
            'quantity' => 100,
            ...$extra,
        ];
    }
}
