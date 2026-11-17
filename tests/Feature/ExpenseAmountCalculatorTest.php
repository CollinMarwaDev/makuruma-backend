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
 * Expense UAT req. 12–20: route templates and the Vibali Tunduma forms, checked against the client's
 * samples 123 (17 trucks, Sakania) and 129. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseAmountCalculatorTest
 */
class ExpenseAmountCalculatorTest extends TestCase
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
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $trucks = collect(range(1, 17))->map(fn ($i) => Truck::create([
            'capacity' => 32,
            'driver_id' => Driver::create(['full_name' => "Driver {$i}"])->id,
            'reg_no' => sprintf('T%03d EKT', $i),
            'trailer_id' => Trailer::create(['reg_no' => "TR-{$i}"])->id,
        ]));
        $client = Client::create(['company_name' => 'Imperial', 'short_code' => 'IMP']);
        $this->booking = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => $trucks->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 32, 'rate' => 100])->all(),
        ])->assertCreated()->json();
    }

    /** Sample 123: TSh 21,519,300 grand total and $7,206 kept in USD. */
    public function test_route_form_123_matches_the_sample(): void
    {
        $template = $this->postJson('/api/route-templates', [
            'expense_type' => 'vibali_tunduma',
            'lines' => self::SAMPLE_123,
            'name' => 'Sakania - Sakania',
            'outbound_border' => 'SAKANIA',
            'return_border' => 'SAKANIA',
        ])->assertCreated()->json();

        $order = $this->postJson('/api/expense-orders', [
            'booking_ids' => [$this->booking['id']],
            'category' => 'trip',
            'expense_type' => 'vibali_tunduma',
            'form' => 'route',
            'lines' => $this->linesFrom($template['lines']),
            'outbound_border' => 'SAKANIA',
            'reference_no' => 'VT-123',
            'return_border' => 'SAKANIA',
            'route_template_id' => $template['id'],
            'zmk_rate' => 138,
        ])->assertCreated()->json();

        $this->assertSame('21519300.00', $order['total_amount']);
        $this->assertSame('7206.00', $order['usd_total']);

        $print = ExpenseAmountCalculator::printData(ExpenseOrder::findOrFail($order['id']));
        $this->assertEquals(145350, $print['zmkTotal']);
        $this->assertEquals(20058300, $print['zmkConverted']);
        $this->assertEquals(1461000, $print['tshTotal']);
        $this->assertEquals(7206, $print['usdTotal']);
        $this->assertSame('300 X 10 = 3,000', $print['blocks']['ZMK'][0]['amount_text']);
        $this->assertCount(17, $print['allTrucks']);
        $this->assertCount(0, $print['subsetLines']);

        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk()->assertHeader('content-type', 'application/pdf');

        // Editing the template later does not change the saved order.
        $this->putJson("/api/route-templates/{$template['id']}", ['expense_type' => 'vibali_tunduma', 'lines' => [self::SAMPLE_123[0]], 'name' => 'Sakania - Sakania'])->assertOk();
        $this->getJson("/api/expense-orders/{$order['id']}")->assertJsonPath('total_amount', '21519300.00')->assertJsonCount(17 * 12 + 2, 'lines');
    }

    /** Sample 129: per-line trucks, ZMK converted at 138, total TSh 352,300. */
    public function test_general_form_129_matches_the_sample(): void
    {
        $legs = collect($this->booking['booking_trucks'])->pluck('id');
        $line = fn (string $description, string $currency, float $unit, array $truckIdx, array $extra = []) => collect($truckIdx)->map(fn ($i) => [
            'booking_truck_id' => $legs[$i],
            'currency' => $currency,
            'description' => $description,
            'group_key' => $description,
            'line_category' => 'vibali_tunduma',
            'multiplier' => 1,
            'unit_rate' => $unit,
            ...$extra,
        ])->all();

        $order = $this->postJson('/api/expense-orders', [
            'booking_ids' => [$this->booking['id']],
            'category' => 'trip',
            'expense_type' => 'vibali_tunduma',
            'form' => 'general',
            'lines' => [
                ...$line('MKOPO', 'ZMK', 550, [0, 4], ['payee' => 'driver']),
                ...$line('AGENT SAKANIA', 'ZMK', 100, [0, 1, 2, 3, 4], ['payee' => 'agent']),
                ...$line('CHANJO', 'TZS', 10000, [0, 1, 2, 3, 4]),
                ...$line('KUPUSHI BRAVO', 'TZS', 15000, [0, 1, 2, 3, 4]),
                ['currency' => 'TZS', 'description' => 'PESA YA KUTOLEA', 'is_flat' => true, 'line_category' => 'vibali_tunduma', 'original_amount' => 6500],
            ],
            'reference_no' => 'VT-129',
            'zmk_rate' => 138,
        ])->assertCreated()->json();

        $this->assertSame('352300.00', $order['total_amount']);

        $print = ExpenseAmountCalculator::printData(ExpenseOrder::findOrFail($order['id']));
        $this->assertEquals(1600, $print['zmkTotal']);
        $this->assertEquals(220800, $print['zmkConverted']);
        $first = $print['allTrucks'][0];
        $this->assertEquals(650, $print['perTruck'][$first]['ZMK']);
        $this->assertCount(1, $print['subsetLines']); // only MKOPO is on fewer trucks than the order

        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk();
    }

    public function test_zmk_rate_is_required_and_templates_need_an_editor_role(): void
    {
        $this->postJson('/api/expense-orders', [
            'booking_ids' => [$this->booking['id']], 'category' => 'trip', 'expense_type' => 'vibali_tunduma', 'form' => 'route', 'reference_no' => 'X',
            'lines' => $this->linesFrom([self::SAMPLE_123[3] + ['is_flat' => false]]),
        ])->assertStatus(422)->assertJsonValidationErrors('zmk_rate');

        $this->postJson('/api/expense-orders', [
            'booking_ids' => [$this->booking['id']], 'category' => 'trip', 'expense_type' => 'vibali_tunduma', 'reference_no' => 'Y',
            'lines' => array_map(fn ($l) => [...$l, 'exchange_rate' => 2600], $this->linesFrom([self::SAMPLE_123[0] + ['is_flat' => false]])),
        ])->assertStatus(422)->assertJsonValidationErrors('form');
    }

    /** Template lines → order lines: "same for all" puts every booking truck on non-flat lines. */
    private function linesFrom(array $templateLines): array
    {
        $legs = collect($this->booking['booking_trucks'])->pluck('id');

        return collect($templateLines)->values()->flatMap(function ($t, $i) use ($legs) {
            $base = [
                'currency' => $t['currency'],
                'description' => $t['description'],
                'group_key' => "g{$i}",
                'line_category' => 'vibali_tunduma',
                'multiplier' => $t['multiplier'] ?? 1,
                'unit_rate' => $t['unit_amount'],
            ];
            if ($t['is_flat'] ?? false) {
                return [[...$base, 'is_flat' => true]];
            }

            return $legs->map(fn ($id) => [...$base, 'booking_truck_id' => $id])->all();
        })->all();
    }

    private const SAMPLE_123 = [
        ['currency' => 'USD', 'description' => 'ROAD TOLL SAKANIA', 'segment' => 'zambia', 'unit_amount' => 338],
        ['currency' => 'USD', 'description' => 'BOND', 'segment' => 'zambia', 'unit_amount' => 85],
        ['currency' => 'USD', 'description' => 'YA KUTOLEA', 'is_flat' => true, 'unit_amount' => 15],
        ['currency' => 'ZMK', 'description' => 'TOLL GATE (kwenda 5, kurudi 5)', 'multiplier' => 10, 'segment' => 'zambia', 'unit_amount' => 300],
        ['currency' => 'ZMK', 'description' => 'TOLL GATE SAKANIA', 'multiplier' => 2, 'segment' => 'zambia', 'unit_amount' => 1350],
        ['currency' => 'ZMK', 'description' => 'CITY COUNCIL', 'segment' => 'zambia', 'unit_amount' => 450],
        ['currency' => 'ZMK', 'description' => 'CHEMICAL ZAMBIA', 'segment' => 'zambia', 'unit_amount' => 1350],
        ['currency' => 'ZMK', 'description' => 'COUNCIL NAKONDE & KAPIRI', 'segment' => 'zambia', 'unit_amount' => 950],
        ['currency' => 'ZMK', 'description' => 'MIZANI', 'segment' => 'zambia', 'unit_amount' => 100],
        ['currency' => 'TZS', 'description' => 'MKEMIA TANZANIA', 'segment' => 'dar_tunduma', 'unit_amount' => 20000],
        ['currency' => 'TZS', 'description' => 'UKAGUZI', 'segment' => 'dar_tunduma', 'unit_amount' => 30000],
        ['currency' => 'TZS', 'description' => 'CITY COUNCIL TDM', 'segment' => 'dar_tunduma', 'unit_amount' => 15000],
        ['currency' => 'TZS', 'description' => 'PETRO POSHO', 'segment' => 'dar_tunduma', 'unit_amount' => 20000],
        ['currency' => 'TZS', 'description' => 'PESA YA KUTOLEA', 'is_flat' => true, 'unit_amount' => 16000],
    ];
}
