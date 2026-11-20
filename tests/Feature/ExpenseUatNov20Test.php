<?php

namespace Tests\Feature;

use App\Exports\VendorLedgerExport;
use App\Models\Client;
use App\Models\ExpenseOrder;
use App\Models\Role;
use App\Models\Truck;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ExpenseAmountCalculator;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * UAT 20 Nov 2026 (Expenses): reference no. as the form number, fuel driver filled in, paid date,
 * approver edits after approval, filling date in the vendor ledger, driver/agent money. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseUatNov20Test
 */
class ExpenseUatNov20Test extends TestCase
{
    use RefreshDatabase;

    private array $booking;

    private User $admin;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        $this->admin = User::where('email', 'admin@makuruma.local')->firstOrFail();
        Sanctum::actingAs($this->admin);

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $this->booking = $this->postJson('/api/bookings', [
            'client_id' => $client->id,
            'direction' => 'go',
            'trucks' => Truck::orderBy('reg_no')->take(2)->get()->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 30])->all(),
        ])->assertCreated()->json();
    }

    public function test_the_reference_number_is_the_form_number_and_unique_per_type(): void
    {
        $fuel = $this->postJson('/api/expense-orders', $this->fuel('0125'))->assertCreated()->json();
        $this->assertSame('0125', $fuel['form_number']);
        $this->assertStringStartsWith('FUEL-', $fuel['order_number']); // kept internally

        $this->postJson('/api/expense-orders', $this->fuel('0125'))->assertStatus(422)->assertJsonValidationErrors('reference_no');
        $this->postJson('/api/expense-orders', $this->congo('0125'))->assertCreated(); // other type: allowed

        $this->get("/api/expense-orders/{$fuel['id']}/download")->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=expense-0125.pdf');
        $slash = $this->postJson('/api/expense-orders', $this->fuel('VC/7'))->assertCreated()->json();
        $this->assertSame('expense-VC-7.xlsx', ExpenseOrder::find($slash['id'])->fileName('xlsx'));
    }

    public function test_paid_date_is_recorded_and_cannot_be_in_the_future(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->fuel('1', ['unit_rate' => 3000]))->json();
        $this->postJson("/api/expense-orders/{$order['id']}/approve")->assertOk();

        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", [])->assertStatus(422);
        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => '2026-11-02'])->assertOk();

        $this->assertSame('2026-11-02', ExpenseOrder::find($order['id'])->paid_at->toDateString());
    }

    public function test_the_approver_can_edit_an_approved_order_until_it_is_paid(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->fuel('2', ['unit_rate' => 3000]))->json();
        $this->postJson("/api/expense-orders/{$order['id']}/approve")->assertOk();
        $edit = $this->fuel('2', ['quantity' => 150, 'unit_rate' => 3000]);

        // Someone else (also allowed to approve) may not.
        Sanctum::actingAs(User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]));
        $this->putJson("/api/expense-orders/{$order['id']}", $edit)->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/expense-orders/{$order['id']}", $edit)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('lines.0.quantity', '150.00');

        $this->postJson("/api/expense-orders/{$order['id']}/mark-paid", ['paid_at' => now()->toDateString()])->assertOk();
        $this->putJson("/api/expense-orders/{$order['id']}", $edit)->assertStatus(422);
    }

    public function test_the_vendor_ledger_uses_the_filling_date_when_given(): void
    {
        $mabena = Vendor::create(['company_name' => 'Mabena', 'vendor_type' => 'fuel']);
        $this->postJson('/api/expense-orders', $this->fuel('3', ['filled_at' => '2026-11-03', 'unit_rate' => 3000, 'vendor_id' => $mabena->id], ['payment_date' => '2026-11-10']))->assertCreated();
        $this->postJson('/api/expense-orders', $this->fuel('4', ['unit_rate' => 3000, 'vendor_id' => $mabena->id], ['payment_date' => '2026-11-10']))->assertCreated();
        $this->postJson('/api/expense-orders', $this->fuel('5', ['filled_at' => now()->addDay()->toDateString()]))->assertStatus(422);

        $path = 'ledger-test.xlsx';
        Excel::store(new VendorLedgerExport($mabena), $path, 'local');
        $sheet = IOFactory::load(storage_path("app/private/{$path}"))->getActiveSheet();
        $rows = collect($sheet->toArray())->filter(fn ($r) => in_array($r[3] ?? null, ['3', '4', 3, 4], true))->mapWithKeys(fn ($r) => [(string) $r[3] => $r[2]]);

        $this->assertSame(['3' => '2026-11-03', '4' => '2026-11-10'], $rows->sortKeys()->all());
    }

    public function test_vibali_lines_say_who_gets_the_money_and_the_print_totals_it(): void
    {
        $lines = $this->congo('9')['lines'];
        $this->postJson('/api/expense-orders', $this->congo('9', [['payee' => null]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.payee');

        $lines[1]['payee'] = 'agent';
        $order = $this->postJson('/api/expense-orders', [...$this->congo('9'), 'lines' => $lines])->assertCreated()->json();

        $print = ExpenseAmountCalculator::printData(ExpenseOrder::findOrFail($order['id']));
        $this->assertEquals(['driver' => ['USD' => 300], 'agent' => ['USD' => 50]], $print['payeeTotals']);
        $this->get("/api/expense-orders/{$order['id']}/download")->assertOk();
    }

    public function test_the_excel_is_the_printed_form(): void
    {
        $lines = $this->congo('9')['lines'];
        $lines[1]['payee'] = 'agent';
        $order = $this->postJson('/api/expense-orders', [...$this->congo('9'), 'lines' => $lines])->assertCreated()->json();

        $response = $this->get("/api/expense-orders/{$order['id']}/download-excel")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        $cells = collect($sheet->toArray(null, false, false))->flatten()->filter(fn ($v) => $v !== null && $v !== '')->values();

        $this->assertContains('NO: 9', $cells->all());
        $this->assertContains('PEAGE', $cells->all());
        $this->assertContains('STAYS WITH AGENT', $cells->all());
        $this->assertContainsEquals(350, $cells->all()); // grand total, a number
        $this->assertContainsEquals(300, $cells->all()); // to driver
        $this->assertSame('NO 9', $sheet->getTitle());

        $this->get("/api/expense-orders/{$order['id']}/download-category/vibali_congo")->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=expense-9-vibali_congo.xlsx');
    }

    public function test_the_list_is_light_and_the_form_lists_are_light(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->fuel('7'))->assertCreated()->json();
        $this->postJson('/api/expense-orders', $this->congo('8'))->assertCreated();

        $list = $this->getJson('/api/expense-orders')->assertOk()->json();
        $this->assertCount(2, $list);
        $row = collect($list)->firstWhere('id', $order['id']);
        $this->assertArrayNotHasKey('lines', $row);
        $this->assertSame(['fuel'], $row['line_categories']);
        $this->assertSame([Truck::orderBy('reg_no')->first()->reg_no], $row['truck_reg_nos']);
        $this->assertSame('7', $row['form_number']);
        $this->assertTrue($row['pending_price']);
        $this->assertSame('Copper Co', $row['bookings'][0]['client']['company_name']);

        $this->assertCount(1, $this->getJson('/api/expense-orders?expense_type=vibali_congo&form=emergency')->json());
        $this->getJson("/api/expense-orders/{$order['id']}")->assertOk()->assertJsonPath('lines.0.quantity', '100.00');

        $booking = $this->getJson('/api/bookings?for=expense')->assertOk()->json('0');
        $this->assertSame(['booking_number', 'booking_trucks', 'client', 'client_id', 'created_at', 'direction', 'id'], collect($booking)->keys()->sort()->values()->all());
        $this->assertArrayHasKey('driver', $booking['booking_trucks'][0]['truck']);
        $this->assertArrayNotHasKey('is_overdue', $booking['booking_trucks'][0]);
        $this->assertArrayNotHasKey('documents', $this->getJson('/api/trucks?for=expense')->json('0'));
    }

    private function fuel(string $ref, array $line = [], array $header = []): array
    {
        return [
            'booking_ids' => [$this->booking['id']],
            'category' => 'trip',
            'expense_type' => 'fuel',
            'lines' => [[
                'booking_truck_id' => $this->booking['booking_trucks'][0]['id'],
                'currency' => 'TZS',
                'description' => 'Diesel',
                'line_category' => 'fuel',
                'quantity' => 100,
                ...$line,
            ]],
            'reference_no' => $ref,
            ...$header,
        ];
    }

    /** Emergency Congo order: PEAGE $150 × 2 trucks (driver) and PARKING $25 × 2 trucks. */
    private function congo(string $ref, array $override = []): array
    {
        $legs = collect($this->booking['booking_trucks'])->pluck('id');
        $line = fn (string $description, int $amount, int $i) => [
            'booking_truck_id' => $legs[$i], 'currency' => 'USD', 'description' => $description, 'group_key' => $description,
            'line_category' => 'vibali_congo', 'multiplier' => 1, 'payee' => 'driver', 'unit_rate' => $amount,
        ];
        $lines = [$line('PEAGE', 150, 0), $line('PARKING', 25, 0), $line('PEAGE', 150, 1), $line('PARKING', 25, 1)];
        foreach ($override as $i => $values) {
            $lines[$i] = [...$lines[$i], ...$values];
        }

        return ['booking_ids' => [], 'category' => 'trip', 'expense_type' => 'vibali_congo', 'form' => 'emergency', 'lines' => $lines, 'reference_no' => $ref];
    }
}
