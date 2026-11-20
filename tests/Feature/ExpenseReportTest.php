<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\ExpenseSubcategory;
use App\Models\Truck;
use App\Models\User;
use App\Services\ExpenseReportLines;
use App\Services\TripProfitCalculator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense UAT req. 4, 18, 24, 26: what the trip profit report counts. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseReportTest
 */
class ExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    private array $booking;

    private int $ref = 0;

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
        $this->booking = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => Truck::orderBy('reg_no')->get()->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 30, 'rate' => 100])->all(),
        ])->assertCreated()->json();
    }

    public function test_usd_uses_the_period_rate_at_report_time_and_flat_lines_are_shared(): void
    {
        $go = $this->postJson('/api/expense-orders', $this->congo('go', [...$this->perTruck(300, [0, 1, 2])], ['payment_date' => '2026-02-15']))->assertCreated()->json();
        $this->postJson('/api/expense-orders', $this->congo('return', [
            ...$this->perTruck(-30, [0]),
            ['currency' => 'USD', 'description' => 'NAULI DEREVA', 'is_flat' => true, 'line_category' => 'vibali_congo', 'payee' => 'driver', 'multiplier' => 1, 'unit_rate' => 90],
        ], ['parent_order_id' => $go['id'], 'payment_date' => '2026-02-15']))->assertCreated();

        $leg = BookingTruck::findOrFail($this->booking['booking_trucks'][0]['id']);

        // No USD rate yet: nothing to convert with.
        $this->assertEquals(0, TripProfitCalculator::legExpenseTotal($leg));

        // A period rate entered later is used by the report: (300 − 30 + 90 / 1 truck on the return) × 2600.
        $this->postJson('/api/usd-rates', ['starts_on' => '2026-02-10', 'ends_on' => '2026-02-20', 'rate' => 2600])->assertCreated();
        $this->assertEquals((300 - 30 + 90) * 2600, TripProfitCalculator::legExpenseTotal($leg));

        $second = BookingTruck::findOrFail($this->booking['booking_trucks'][1]['id']);
        $this->assertEquals(300 * 2600, TripProfitCalculator::legExpenseTotal($second));
        $this->assertSame(1, ExpenseReportLines::forLeg($leg)->firstWhere('description', 'NAULI DEREVA')['share_of']);
    }

    public function test_other_expenses_need_a_subcategory_and_rejected_orders_do_not_count(): void
    {
        $weighbridge = ExpenseSubcategory::where('name', 'like', 'Weighbridge%')->value('id');
        $line = fn ($i) => ['booking_truck_id' => $this->booking['booking_trucks'][$i]['id'], 'currency' => 'TZS', 'description' => 'Mabena', 'exchange_rate' => 1, 'line_category' => 'mengine', 'original_amount' => 20000];
        $order = fn (array $extra = []) => ['booking_ids' => [$this->booking['id']], 'category' => 'trip', 'expense_type' => 'mengine', 'lines' => [$line(0), $line(1)], 'reference_no' => 'O-' . ++$this->ref, ...$extra];

        $this->postJson('/api/expense-orders', $order())->assertStatus(422)->assertJsonValidationErrors('other_subcategory_id');
        $mizani = $this->postJson('/api/expense-orders', $order(['other_subcategory_id' => $weighbridge]))->assertCreated()->json();
        $this->get("/api/expense-orders/{$mizani['id']}/download")->assertOk()->assertHeader('content-type', 'application/pdf');

        $leg = BookingTruck::findOrFail($this->booking['booking_trucks'][0]['id']);
        $this->assertSame('Weighbridge (Mizani)', ExpenseReportLines::forLeg($leg)->first()['subcategory']);
        $this->assertEquals(20000, TripProfitCalculator::legExpenseTotal($leg));

        $this->postJson("/api/expense-orders/{$mizani['id']}/reject")->assertOk();
        $this->assertEquals(0, TripProfitCalculator::legExpenseTotal($leg));
    }

    private function congo(string $form, array $lines, array $extra = []): array
    {
        return ['booking_ids' => [$this->booking['id']], 'category' => 'trip', 'expense_type' => 'vibali_congo', 'form' => $form, 'lines' => $lines, 'reference_no' => 'C-' . ++$this->ref, ...$extra];
    }

    private function perTruck(float $amount, array $idx): array
    {
        return collect($idx)->map(fn ($i) => [
            'booking_truck_id' => $this->booking['booking_trucks'][$i]['id'],
            'currency' => 'USD',
            'description' => 'PEAGE',
            'group_key' => "g{$amount}",
            'line_category' => 'vibali_congo', 'payee' => 'driver',
            'multiplier' => 1,
            'unit_rate' => $amount,
        ])->all();
    }
}
