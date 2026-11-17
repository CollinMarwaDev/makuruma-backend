<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExpenseSubcategory;
use App\Models\PaymentAccount;
use App\Models\Role;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense UAT req. 1–4, 6, 27, 28: one type per order, a number series per type, multiple bookings,
 * category rules, initiated by from the user, managed lists. Needs MySQL:
 *   DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=ExpenseOrderRulesTest
 */
class ExpenseOrderRulesTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_an_order_cannot_mix_expense_types(): void
    {
        $this->postJson('/api/expense-orders', $this->order('office', 'fuel', lines: [
            $this->line('fuel'),
            $this->line('vibali_tunduma'),
        ]))->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_each_type_has_its_own_number_series_and_initiated_by_is_the_user(): void
    {
        $first = $this->postJson('/api/expense-orders', $this->order('office', 'mengine'))->assertCreated();
        $this->postJson('/api/expense-orders', $this->order('office', 'mengine'))->assertCreated()->assertJsonPath('order_number', 'OTH-00002');
        $this->postJson('/api/expense-orders', $this->order('office', 'vibali_tunduma'))->assertCreated()->assertJsonPath('order_number', 'VT-00001');

        $first->assertJsonPath('order_number', 'OTH-00001')->assertJsonPath('initiated_by', 'System Admin');
    }

    public function test_the_series_continues_from_the_configured_start(): void
    {
        config(['expenses.series_start.vibali_tunduma' => 130]);

        $this->postJson('/api/expense-orders', $this->order('office', 'vibali_tunduma'))->assertCreated()->assertJsonPath('serial', 130);
    }

    public function test_a_trip_order_links_several_bookings_and_only_their_trucks(): void
    {
        [$a, $b] = [$this->booking(0), $this->booking(1)];
        $legA = $a['booking_trucks'][0]['id'];
        $legB = $b['booking_trucks'][0]['id'];

        $this->postJson('/api/expense-orders', $this->order('trip', 'vibali_tunduma', ['booking_ids' => [$a['id']], 'form' => 'general'], [
            $this->line('vibali_tunduma', ['booking_truck_id' => $legB]),
        ]))->assertStatus(422)->assertJsonValidationErrors('lines');

        $this->postJson('/api/expense-orders', $this->order('trip', 'vibali_tunduma', ['booking_ids' => [$a['id'], $b['id']], 'form' => 'general'], [
            $this->line('vibali_tunduma', ['booking_truck_id' => $legA]),
            $this->line('vibali_tunduma', ['booking_truck_id' => $legB]),
        ]))->assertCreated()->assertJsonCount(2, 'bookings')->assertJsonPath('booking_id', $a['id']);

        $this->postJson('/api/expense-orders', $this->order('trip', 'vibali_tunduma', ['form' => 'general']))->assertStatus(422)->assertJsonValidationErrors('booking_ids');
    }

    public function test_office_and_truck_category_rules(): void
    {
        $truck = Truck::first();

        $this->postJson('/api/expense-orders', $this->order('office', 'mengine', ['truck_id' => $truck->id]))->assertStatus(422);
        $this->postJson('/api/expense-orders', $this->order('truck', 'mengine'))->assertStatus(422)->assertJsonValidationErrors('truck_id');
        $this->postJson('/api/expense-orders', $this->order('truck', 'mengine', ['truck_id' => $truck->id]))->assertCreated();
    }

    public function test_type_cannot_change_on_edit(): void
    {
        $order = $this->postJson('/api/expense-orders', $this->order('office', 'vibali_tunduma'))->json();

        $this->putJson("/api/expense-orders/{$order['id']}", $this->order('office', 'vibali_tunduma', lines: [$this->line('mengine')]))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_users_manage_payment_accounts_and_vendor_types(): void
    {
        $account = $this->postJson('/api/payment-accounts', ['name' => 'NMB'])->assertCreated()->json();
        $this->postJson('/api/expense-orders', $this->order('office', 'mengine', ['payment_account_id' => $account['id']]))
            ->assertCreated()->assertJsonPath('payment_account', 'NMB');

        // A used account is deactivated, not deleted.
        $this->deleteJson("/api/payment-accounts/{$account['id']}")->assertOk()->assertJsonPath('is_active', false);
        $this->assertTrue(PaymentAccount::where('name', 'NMB')->exists());

        $type = $this->postJson('/api/vendor-types', ['name' => 'Vibali Agent'])->assertCreated()->assertJsonPath('slug', 'vibali_agent')->json();
        $this->postJson('/api/vendors', ['company_name' => 'Sakania Agent', 'vendor_type' => $type['slug']])->assertCreated();
        $this->postJson('/api/vendors', ['company_name' => 'Nobody', 'vendor_type' => 'e-seal'])->assertStatus(422);

        $operator = User::factory()->create(['role_id' => Role::where('slug', 'operator')->value('id')]);
        Sanctum::actingAs($operator);
        $this->postJson('/api/payment-accounts', ['name' => 'Bank X'])->assertForbidden();
        $this->getJson('/api/payment-accounts')->assertOk()->assertJsonCount(4);
    }

    public function test_usd_rate_periods_cannot_overlap(): void
    {
        $this->postJson('/api/usd-rates', ['starts_on' => '2026-02-10', 'ends_on' => '2026-02-20', 'rate' => 2600])->assertCreated();
        $this->postJson('/api/usd-rates', ['starts_on' => '2026-02-18', 'ends_on' => '2026-02-28', 'rate' => 2650])->assertStatus(422);
        $this->postJson('/api/usd-rates', ['starts_on' => '2026-02-21', 'ends_on' => '2026-02-28', 'rate' => 2650])->assertCreated();
    }

    private function order(string $category, string $type, array $extra = [], ?array $lines = null): array
    {
        return [
            'category' => $category,
            'expense_type' => $type,
            'lines' => $lines ?? [$this->line($type)],
            'other_subcategory_id' => $type === 'mengine' ? ExpenseSubcategory::value('id') : null,
            'reference_no' => 'REF-' . ++$this->ref,
            ...$extra,
        ];
    }

    private function line(string $type, array $extra = []): array
    {
        return ['currency' => 'TZS', 'description' => 'Test', 'line_category' => $type, 'original_amount' => 1000, ...$extra];
    }

    private function booking(int $truckIndex): array
    {
        $client = Client::firstOrCreate(['company_name' => 'Copper Co'], ['short_code' => 'CPR']);
        $truck = Truck::orderBy('reg_no')->skip($truckIndex)->firstOrFail();

        return $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => [['truck_id' => $truck->id, 'tons' => 30, 'rate' => 100]]])
            ->assertCreated()->json();
    }
}
