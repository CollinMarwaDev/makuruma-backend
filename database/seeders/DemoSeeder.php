<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingTruck;
use App\Models\Checkpoint;
use App\Models\Client;
use App\Models\Document;
use App\Models\Driver;
use App\Models\ExpenseOrder;
use App\Models\Invoice;
use App\Models\OfficeAsset;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Trailer;
use App\Models\Truck;
use App\Models\TruckMilestone;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\BookingNumberGenerator;
use App\Services\BookingTruckService;
use App\Services\ExpenseNumberGenerator;
use App\Services\InvoiceNumberGenerator;
use App\Services\TrackingLegService;
use App\Services\TrackingTimeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo data for local testing: at most 5 records of each kind, covering every screen
 * (bookings with mixed routes, tracking legs in different states, PODs, invoices of every type,
 * expense orders in every status, compliance documents that are valid / expiring / expired).
 *
 * Local only. It refuses to run in production:
 *   php artisan db:seed --class=DemoSeeder
 * Safe to run twice: master data is matched by name, and the trip/money data is only created once.
 */
class DemoSeeder extends Seeder
{
    private User $admin;

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder only runs with APP_ENV=local (it would add test data to a live database).');
        }

        $this->call([RoleSeeder::class, CheckpointSeeder::class]);
        $this->admin = User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@makuruma.local')],
            ['name' => 'System Admin', 'password' => env('SEED_ADMIN_PASSWORD', 'password'), 'role_id' => Role::where('slug', 'admin')->value('id')],
        );

        $this->users();
        [$clients, $vendors] = $this->partners();
        $this->staff();
        $trucks = $this->fleet();
        $this->officeAssets();

        if (Booking::whereIn('client_id', $clients->pluck('id'))->exists()) {
            $this->command?->info('Demo bookings already exist — skipped trips, invoices and expenses.');

            return;
        }

        $legs = $this->bookingsAndTracking($clients, $trucks);
        $this->invoices($legs);
        $this->expenses($legs, $vendors, $trucks);
        $this->vendorPayments($vendors);

        $this->command?->info('Demo data ready. Users: manager@ / accountant@ / operator@ / driver@makuruma.local, password "password".');
    }

    private function users(): void
    {
        foreach (['manager', 'accountant', 'operator', 'driver'] as $slug) {
            User::firstOrCreate(
                ['email' => "{$slug}@makuruma.local"],
                ['name' => 'Demo ' . ucfirst($slug), 'phone' => '07550000' . strlen($slug), 'password' => 'password', 'role_id' => Role::where('slug', $slug)->value('id'), 'status' => 'active'],
            );
        }
    }

    private function partners(): array
    {
        $clients = collect([
            ['company_name' => 'Copper Co', 'short_code' => 'CPR', 'email' => 'ops@copperco.example', 'phone' => '0754100001', 'allows_advance_invoice' => true],
            ['company_name' => 'Katanga Mining', 'short_code' => 'KTM', 'email' => 'logistics@katanga.example', 'phone' => '0754100002', 'allows_advance_invoice' => true],
            ['company_name' => 'Lake Cement', 'short_code' => 'LKC', 'email' => 'supply@lakecement.example', 'phone' => '0754100003', 'allows_advance_invoice' => false],
            ['company_name' => 'Zambezi Fuels', 'short_code' => 'ZBF', 'email' => 'trade@zambezi.example', 'phone' => '0754100004', 'allows_advance_invoice' => true],
            ['company_name' => 'Tanga Grains', 'short_code' => 'TGG', 'email' => null, 'phone' => '0754100005', 'allows_advance_invoice' => false],
        ])->map(fn ($c) => Client::firstOrCreate(['company_name' => $c['company_name']], $c));

        $vendors = collect([
            ['company_name' => 'Puma Energy Tunduma', 'vendor_type' => 'fuel', 'phone' => '0765200001', 'location' => 'Tunduma'],
            ['company_name' => 'Oryx Kasumbalesa', 'vendor_type' => 'fuel', 'phone' => '0765200002', 'location' => 'Kasumbalesa'],
            ['company_name' => 'Lake Oil Dar', 'vendor_type' => 'fuel', 'phone' => '0765200003', 'location' => 'Dar es Salaam'],
            ['company_name' => 'SecureSeal Ltd', 'vendor_type' => 'e-seal', 'phone' => '0765200004', 'location' => 'Dar es Salaam'],
            ['company_name' => 'TrackSeal Africa', 'vendor_type' => 'e-seal', 'phone' => '0765200005', 'location' => 'Lubumbashi'],
        ])->map(fn ($v) => Vendor::firstOrCreate(['company_name' => $v['company_name']], $v));

        return [$clients, $vendors];
    }

    private function staff(): void
    {
        foreach ([
            ['full_name' => 'Neema Said', 'phone' => '0713300001', 'tin_number' => '123-456-001', 'payment_account' => 'CRDB 0150001'],
            ['full_name' => 'Joseph Mallya', 'phone' => '0713300002', 'tin_number' => '123-456-002', 'payment_account' => 'NMB 2010002'],
            ['full_name' => 'Rehema Juma', 'phone' => '0713300003', 'tin_number' => null, 'payment_account' => 'M-Pesa 0713300003'],
            ['full_name' => 'Baraka Mushi', 'phone' => '0713300004', 'tin_number' => '123-456-004', 'payment_account' => null],
            ['full_name' => 'Asha Omari', 'phone' => '0713300005', 'tin_number' => '123-456-005', 'payment_account' => 'CRDB 0150005'],
        ] as $i => $data) {
            $member = Staff::firstOrCreate(['full_name' => $data['full_name']], $data);
            if ($i < 2) {
                $this->document($member, 'other', "EMP-00{$i}", null);
            }
        }
    }

    /** 5 trucks, each with a trailer and a driver, and permits that are valid, expiring soon or expired. */
    private function fleet()
    {
        $people = [['John Mwita', '0712000001'], ['Peter Komba', '0712000002'], ['Ally Hassan', '0712000003'], ['Daudi Kweka', '0712000004'], ['Saidi Ngowi', '0712000005']];
        $trucks = collect();

        foreach (['T111AAA', 'T222BBB', 'T333CCC', 'T444DDD', 'T555EEE'] as $i => $reg) {
            $trailer = Trailer::firstOrCreate(['reg_no' => 'TR-100' . ($i + 1)]);
            $driver = Driver::firstOrCreate(['full_name' => $people[$i][0]], ['phone' => $people[$i][1]]);
            $truck = Truck::firstOrCreate(['reg_no' => $reg], [
                'buying_price' => 145_000_000 + $i * 5_000_000,
                'capacity' => [30, 28, 32, 30, 34][$i],
                'driver_id' => $driver->id,
                'trailer_id' => $trailer->id,
            ]);
            $trucks->push($truck);

            // Expiry mix: i=0 expired, 1 expires in 5 days, 2 in 20 days, others valid.
            $expiry = [now()->subDays(4), now()->addDays(5), now()->addDays(20), now()->addMonths(6), now()->addYear()][$i];
            $this->document($truck, 'LATRA', "LAT-{$reg}", $expiry);
            $this->document($truck, 'COMESA', "COM-{$reg}", now()->addMonths(3 + $i));
            $this->document($trailer, 'BIMA', "BIM-TR{$i}", [now()->addDays(12), now()->addMonths(4), now()->subDays(10), now()->addMonths(8), now()->addMonths(2)][$i]);
            $this->document($driver, 'license', "DL-{$i}0045", [now()->addMonths(9), now()->addDays(25), now()->addYear(), now()->subDays(2), now()->addMonths(5)][$i]);
            $this->document($driver, 'passport', "TZ{$i}88112", now()->addYears(3));
        }

        return $trucks;
    }

    private function officeAssets(): void
    {
        foreach ([
            ['name' => 'Office desk set', 'category' => 'furniture', 'buying_price' => 1_800_000, 'location' => 'Dar office', 'condition' => 'active'],
            ['name' => 'Dell laptop (accounts)', 'category' => 'electronics', 'serial_number' => 'DL-5521-ACC', 'buying_price' => 2_400_000, 'location' => 'Dar office', 'condition' => 'active'],
            ['name' => 'Tyre changer', 'category' => 'equipment', 'serial_number' => 'TC-0091', 'buying_price' => 6_500_000, 'location' => 'Yard', 'condition' => 'under_repair'],
            ['name' => 'Toyota Hilux (field)', 'category' => 'vehicle', 'serial_number' => 'T987XYZ', 'buying_price' => 68_000_000, 'location' => 'Tunduma', 'condition' => 'active'],
            ['name' => 'Old printer', 'category' => 'electronics', 'buying_price' => 450_000, 'location' => 'Store', 'condition' => 'disposed'],
        ] as $i => $data) {
            OfficeAsset::firstOrCreate(['name' => $data['name']], $data + ['purchase_date' => now()->subMonths(3 + $i * 4)->toDateString()]);
        }
    }

    /** Five bookings and their tracking legs in different states. Returns the legs by name. */
    private function bookingsAndTracking($clients, $trucks): array
    {
        $service = app(BookingTruckService::class);
        $legsService = app(TrackingLegService::class);
        [$c1, $c2, $c3, $c4, $c5] = $clients->all();
        [$t1, $t2, $t3, $t4, $t5] = $trucks->all();

        $book = function ($client, string $direction, array $trucks, string $createdAgo) use ($service) {
            $booking = Booking::create([
                'booking_number' => BookingNumberGenerator::generate($client, count($trucks)),
                'client_id' => $client->id,
                'created_by' => $this->admin->id,
                'direction' => $direction,
            ]);
            $booking->forceFill(['created_at' => Carbon::parse($createdAgo)])->save();
            $legs = [];
            foreach ($trucks as [$truck, $details]) {
                $legs[] = $service->attach($booking, $truck->fresh(), $details);
            }
            $service->syncSummary($booking);

            return $legs;
        };

        // 1. Go booking, two trucks with different routes, cargo and rates.
        [$a1, $a2] = $book($c1, 'go', [
            [$t1, ['tons' => 30, 'cargo' => 'Copper cathodes', 'rate' => 95, 'loading_point' => 'Dar es Salaam', 'offloading_point' => 'Lubumbashi', 'eta' => now()->addDays(2)->toDateString()]],
            [$t2, ['tons' => 28, 'cargo' => 'Sulphur', 'rate' => 105, 'loading_point' => 'Tanga', 'offloading_point' => 'Kolwezi', 'eta' => now()->addDays(4)->toDateString()]],
        ], '-25 days');
        $this->dates($a1, -20, -19, -18, -6, -3);
        $this->checkpoints($a1, -16, 4);
        $legsService->updateStatus($a1->fresh(), 'offloading', 'Lubumbashi', 'kasumbalesa');
        $this->dates($a2, -12, -11, -10);
        $this->checkpoints($a2, -8, 2);
        $legsService->updateStatus($a2->fresh(), 'in_transit', 'Nakonde', 'sakania');

        // 2. Go booking, delivered with POD, completed.
        [$b1] = $book($c2, 'go', [[$t3, ['tons' => 32, 'cargo' => 'Cement', 'rate' => 88, 'loading_point' => 'Dar es Salaam', 'offloading_point' => 'Kolwezi']]], '-50 days');
        $this->dates($b1, -45, -44, -43, -32, -30);
        $this->checkpoints($b1, -41, 5);
        Storage::disk('public')->put('documents/demo-pod.pdf', $this->tinyPdf());
        $this->document($b1, 'POD', 'POD-DEMO-1', null, 'documents/demo-pod.pdf');
        $legsService->updateStatus($b1->fresh(), 'completed', 'Kolwezi', 'kasumbalesa');

        // 3. Go booking, truck on the way to loading.
        [$c] = $book($c3, 'go', [[$t4, ['tons' => 30, 'cargo' => 'Clinker', 'rate' => 92, 'loading_point' => 'Mbeya', 'offloading_point' => 'Lubumbashi', 'eta' => now()->addDays(9)->toDateString()]]], '-2 days');
        $legsService->updateStatus($c->fresh(), 'on_route_to_loading', 'Morogoro', null);

        // 4. Return booking for the truck that delivered booking 2.
        [$d] = $book($c4, 'return', [[$t3->fresh(), ['tons' => 30, 'cargo' => 'Copper concentrate', 'rate' => 70, 'loading_point' => 'Kolwezi', 'offloading_point' => 'Dar es Salaam', 'eta' => now()->addDays(6)->toDateString()]]], '-20 days');
        $this->dates($d, -18, -17, -16);
        $legsService->updateStatus($d->fresh(), 'at_border', 'Kasumbalesa', 'kasumbalesa');

        // 5. Go booking, truck loading; offloaded with no POD 7 days ago would trigger alerts, so it stays at loading.
        [$e] = $book($c5, 'go', [[$t5, ['tons' => 34, 'cargo' => 'Maize', 'rate' => 60, 'loading_point' => 'Tanga', 'offloading_point' => 'Lusaka', 'eta' => now()->addDays(8)->toDateString()]]], '-1 day');
        $this->dates($e, -1);
        $legsService->updateStatus($e->fresh(), 'loading', 'Tanga port', null);

        return compact('a1', 'a2', 'b1', 'd', 'e');
    }

    private function invoices(array $legs): void
    {
        $make = function (BookingTruck $leg, string $type, array $line, float $amount, string $date, float $rate, bool $paid) {
            $leg->loadMissing('booking', 'truck', 'trailer');
            $invoice = Invoice::create([
                'booking_id' => $leg->booking_id,
                'client_id' => $leg->booking->client_id,
                'created_by' => $this->admin->id,
                'exchange_rate' => $rate,
                'invoice_date' => $date,
                'invoice_number' => InvoiceNumberGenerator::generate(),
                'invoice_type' => $type,
                'mode_of_payment' => 'Bank transfer',
                'status' => $paid ? 'paid' : 'pending',
                'paid_at' => $paid ? Carbon::parse($date)->addDays(10) : null,
                'paid_by' => $paid ? $this->admin->id : null,
                'total_amount' => $amount,
            ]);
            $invoice->lines()->create($line + [
                'amount' => $amount,
                'booking_truck_id' => $leg->id,
                'description' => $leg->truck->reg_no . ($leg->trailer ? '/' . $leg->trailer->reg_no : ''),
            ]);
        };

        $m = fn (int $monthsAgo, int $day) => now()->subMonthsNoOverflow($monthsAgo)->day($day)->toDateString();
        $make($legs['b1'], 'advance', ['quantity' => 32, 'rate' => 88, 'percentage' => 50], 1408, $m(2, 5), 2620, true);
        $make($legs['b1'], 'settlement', ['quantity' => 31.5, 'rate' => 88], 1364, $m(1, 12), 2650, true);
        $make($legs['b1'], 'standing_time', ['days' => 3, 'rate' => 150], 450, $m(1, 20), 2650, false);
        $make($legs['a1'], 'advance', ['quantity' => 30, 'rate' => 95, 'percentage' => 50], 1425, $m(0, 2), 2690, false);
        $make($legs['d'], 'adjustment', ['quantity' => 30, 'rate' => 10], 300, $m(0, 6), 2690, false);
    }

    private function expenses(array $legs, $vendors, $trucks): void
    {
        $m = fn (int $monthsAgo, int $day) => now()->subMonthsNoOverflow($monthsAgo)->day($day)->toDateString();
        $orders = [
            ['trip', $legs['b1'], null, 'paid', $m(2, 8), [
                ['fuel', $vendors[2], 'Diesel Dar', 'TZS', null, 400, 3150, null],
                ['vibali_tunduma', null, 'Border permits Tunduma', 'USD', 2620, null, null, 120],
                ['vibali_congo', null, 'DRC entry fees', 'USD', 2620, null, null, 350],
            ]],
            ['trip', $legs['a1'], null, 'approved', $m(0, 1), [
                ['fuel', $vendors[0], 'Diesel Tunduma', 'TZS', null, 350, 3200, null],
                ['fuel', $vendors[1], 'Diesel Kasumbalesa', 'USD', 2690, 150, 1.35, null],
                ['mengine', null, 'Driver allowance', 'TZS', null, null, null, 450000],
            ]],
            ['truck', null, $trucks[3], 'paid', $m(1, 15), [
                ['mengine', null, 'Brake pads and service', 'TZS', null, null, null, 1850000],
            ]],
            ['office', null, null, 'pending', $m(0, 4), [
                ['mengine', null, 'Office rent (November)', 'TZS', null, null, null, 1200000],
                ['mengine', $vendors[3], 'E-seal subscription', 'USD', 2690, null, null, 90],
            ]],
            ['trip', $legs['e'], null, 'rejected', $m(3, 10), [
                ['mengine', null, 'Duplicate request', 'TZS', null, null, null, 300000],
            ]],
        ];

        foreach ($orders as $i => [$category, $leg, $truck, $status, $paymentDate, $lines]) {
            $leg?->loadMissing('booking');
            $order = ExpenseOrder::create([
                'approved_at' => in_array($status, ['approved', 'paid']) ? $paymentDate : null,
                'approved_by' => in_array($status, ['approved', 'paid']) ? $this->admin->id : null,
                'booking_id' => $leg?->booking_id,
                'category' => $category,
                'created_by' => $this->admin->id,
                'initiated_by' => ['Neema Said', 'Joseph Mallya', 'Baraka Mushi', 'Asha Omari', 'Rehema Juma'][$i],
                'order_number' => $category === 'trip' ? $leg->booking->booking_number : ExpenseNumberGenerator::generate(),
                'paid_at' => $status === 'paid' ? $paymentDate : null,
                'paid_by' => $status === 'paid' ? $this->admin->id : null,
                'payment_account' => 'CRDB 0150001',
                'payment_date' => $paymentDate,
                'reference_no' => 'DEMO-REF-' . ($i + 1),
                'status' => $status,
                'truck_id' => $truck?->id,
            ]);
            if ($truck) {
                $order->trucks()->attach($truck->id);
            }
            foreach ($lines as [$lineCategory, $vendor, $description, $currency, $rate, $qty, $unit, $amount]) {
                $order->lines()->create([
                    'booking_truck_id' => $leg?->id,
                    'currency' => $currency,
                    'description' => $description,
                    'exchange_rate' => $rate ?? 1,
                    'line_category' => $lineCategory,
                    'original_amount' => $amount ?? null,
                    'quantity' => $qty,
                    'unit_rate' => $unit,
                    'vendor_id' => $vendor?->id,
                ]);
            }
            $order->recalculateTotal();
        }
    }

    private function vendorPayments($vendors): void
    {
        foreach ([[0, 900000, 40], [2, 1260000, 25], [1, 500000, 7]] as [$v, $amount, $daysAgo]) {
            VendorPayment::create([
                'amount' => $amount,
                'created_by' => $this->admin->id,
                'description' => 'Demo payment',
                'payment_date' => now()->subDays($daysAgo)->toDateString(),
                'vendor_id' => $vendors[$v]->id,
            ]);
        }
    }

    /** Leg dates in days relative to today, in trip order (arrival, loading, dispatch, offloading arrival, offloading). */
    private function dates(BookingTruck $leg, int ...$days): void
    {
        $keys = array_keys(TrackingTimeline::LEG_DATES);
        $leg->update(array_combine(array_slice($keys, 0, count($days)), array_map(fn ($d) => now()->addDays($d)->toDateString(), $days)));
    }

    /** Arrival/dispatch at the first $count checkpoints of the leg's route, a day apart from $startDay. */
    private function checkpoints(BookingTruck $leg, int $startDay, int $count): void
    {
        $leg->refresh()->loadMissing('booking');
        foreach (TrackingTimeline::checkpointsFor($leg)->take($count)->values() as $i => $checkpoint) {
            TruckMilestone::updateOrCreate(
                ['booking_truck_id' => $leg->id, 'checkpoint_id' => $checkpoint->id],
                [
                    'arrival_at' => now()->addDays($startDay + $i * 2)->setTime(8, 0),
                    'dispatch_at' => now()->addDays($startDay + $i * 2 + 1)->setTime(14, 0),
                    'truck_id' => $leg->truck_id,
                ],
            );
        }
    }

    private function document($owner, string $type, ?string $number, $expiry, ?string $path = null): void
    {
        Document::firstOrCreate(
            ['documentable_type' => $owner::class, 'documentable_id' => $owner->id, 'document_type' => $type],
            ['attachment_path' => $path, 'expiry_date' => $expiry ? Carbon::parse($expiry)->toDateString() : null, 'issue_date' => now()->subYear()->toDateString(), 'number' => $number],
        );
    }

    private function tinyPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj "
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 120]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj "
            . "4 0 obj<</Length 52>>stream\nBT /F1 14 Tf 20 60 Td (Demo proof of delivery) Tj ET\nendstream endobj "
            . "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
