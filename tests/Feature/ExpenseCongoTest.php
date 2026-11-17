<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Driver;
use App\Models\ExpenseOrder;
use App\Models\Trailer;
use App\Models\Truck;
use App\Models\User;
use App\Services\ExpenseAmountCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense UAT req. 21–25: Vibali Congo going (165), return settlement (163) and emergency (180) forms,
 * checked against samples 165 ($40,290) and 180 ($760). Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseCongoTest
 */
class ExpenseCongoTest extends TestCase
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
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $trucks = collect(range(1, 17))->map(fn ($i) => Truck::create([
            'capacity' => $i <= 3 ? 38 : 32,
            'driver_id' => Driver::create(['full_name' => "Driver {$i}"])->id,
            'reg_no' => sprintf('T%03d EGA', $i),
            'trailer_id' => Trailer::create(['reg_no' => "TR-{$i}"])->id,
        ]));
        $client = Client::create(['company_name' => 'Imperial', 'short_code' => 'IMP']);
        $this->booking = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => $trucks->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 32, 'rate' => 100])->all(),
        ])->assertCreated()->json();
    }

    /** Sample 165: 21 route costs ($2,340 per truck, $39,780) + pulling entry card $30 × 17 = $40,290. */
    public function test_going_form_165_matches_the_sample(): void
    {
        $route = [300, 100, 210, 200, 200, 220, 50, 25, 20, 5, 30, 150, 150, 160, 150, 150, 100, 30, 20, 20, 50];
        $lines = collect($route)->flatMap(fn ($amount, $i) => $this->perTruck("COST {$i}", $amount, range(0, 16)))
            ->merge($this->perTruck('PULLING ENTRY CARD', 30, range(0, 16), ['is_additional' => true]))->all();

        $order = $this->postJson('/api/expense-orders', $this->order('go', $lines, ['outbound_border' => 'SAKANIA', 'usd_rate' => 2600]))->assertCreated()->json();

        $this->assertSame('40290.00', $order['usd_total']);
        $this->assertSame('0.00', $order['total_amount']);
        $this->assertStringStartsWith('VC-', $order['order_number']);

        $print = ExpenseAmountCalculator::printData(ExpenseOrder::findOrFail($order['id']));
        $usd = $print['blocks']['USD'];
        $this->assertEquals(2340, $usd->where('is_additional', false)->sum('per_truck'));
        $this->assertEquals(39780, $usd->where('is_additional', false)->sum('total'));
        $this->assertTrue($usd->last()['is_additional']);
        $this->assertCount(0, $print['subsetLines']);

        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    /** Sample 180: $760; trucks picked without choosing a booking; Posho 5 × 11 days for one truck. */
    public function test_emergency_form_180_matches_the_sample(): void
    {
        $eleven = range(0, 10);
        $order = $this->postJson('/api/expense-orders', [
            ...$this->order('emergency', [
                ...$this->perTruck('PENALTY', 50, $eleven),
                ...$this->perTruck('PENALTY (T825 ETF)', 25, [5]),
                ...$this->perTruck('POSHO', 15, [0, 1]),
                ...$this->perTruck('POSHO', 20, [2, 3, 4, 5, 6], ['group_key' => 'posho-20']),
                ...$this->perTruck('POSHO (T825 ETF) 6/11-16/11', 5, [5], ['multiplier' => 11]),
            ]),
            'booking_ids' => [],
        ])->assertCreated()->json();

        $this->assertSame('760.00', $order['usd_total']);
        $this->assertSame('on_spot', $order['payment_mode']);
        $this->assertCount(1, $order['bookings']);

        $print = ExpenseAmountCalculator::printData(ExpenseOrder::findOrFail($order['id']));
        $this->assertSame('5 X 11 = 55', $print['blocks']['USD']->last()['amount_text']);
        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk();
    }

    /** Form 163: settles the going order with extra (+) and returned (−) money; a no-truck line is allowed. */
    public function test_return_settlement_163_allows_negative_lines_and_needs_a_going_order(): void
    {
        $go = $this->postJson('/api/expense-orders', $this->order('go', $this->perTruck('PEAGE', 300, [0, 1])))->assertCreated()->json();

        $settlement = [
            ...$this->perTruck('PARKING NOT USED (rudisha)', -30, [0, 1]),
            ['currency' => 'USD', 'description' => 'NAULI DEREVA KUFUATA PESA', 'is_flat' => true, 'line_category' => 'vibali_congo', 'multiplier' => 1, 'unit_rate' => 100],
        ];

        $this->postJson('/api/expense-orders', $this->order('return', $settlement))->assertStatus(422)->assertJsonValidationErrors('parent_order_id');
        $this->postJson('/api/expense-orders', $this->order('go', $this->perTruck('X', -30, [0])))->assertStatus(422);

        $order = $this->postJson('/api/expense-orders', $this->order('return', $settlement, ['parent_order_id' => $go['id']]))->assertCreated()->json();
        $this->assertSame('40.00', $order['usd_total']);
        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk();
    }

    public function test_congo_is_usd_only_and_needs_a_form(): void
    {
        $this->postJson('/api/expense-orders', $this->order('go', $this->perTruck('PEAGE', 300, [0], ['currency' => 'TZS'])))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->postJson('/api/expense-orders', [...$this->order('go', $this->perTruck('PEAGE', 300, [0])), 'form' => null])
            ->assertStatus(422);
    }

    private function order(string $form, array $lines, array $extra = []): array
    {
        return [
            'booking_ids' => [$this->booking['id']],
            'category' => 'trip',
            'expense_type' => 'vibali_congo',
            'form' => $form,
            'lines' => $lines,
            'reference_no' => 'VC-' . ++$this->ref,
            ...$extra,
        ];
    }

    private function perTruck(string $description, float $amount, array $truckIdx, array $extra = []): array
    {
        $legs = collect($this->booking['booking_trucks'])->pluck('id');

        return collect($truckIdx)->map(fn ($i) => [
            'booking_truck_id' => $legs[$i],
            'currency' => 'USD',
            'description' => $description,
            'group_key' => $description,
            'line_category' => 'vibali_congo',
            'multiplier' => 1,
            'unit_rate' => $amount,
            ...$extra,
        ])->all();
    }
}
