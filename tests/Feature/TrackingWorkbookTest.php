<?php

namespace Tests\Feature;

use App\Models\BookingTruck;
use App\Models\Client;
use App\Models\Truck;
use App\Models\User;
use App\Services\TrackingWorkbook;
use Database\Seeders\CheckpointSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FleetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Contract 2.2 A/B: export the client's GOING/RETURN sheet, edit it, preview and apply the import.
 * Needs MySQL:  DB_CONNECTION=mysql DB_DATABASE=makuruma_test php artisan test --filter=TrackingWorkbookTest
 */
class TrackingWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'mysql') {
            $this->markTestSkipped('Needs MySQL (see class docblock).');
        }

        parent::setUp();
    }

    public function test_an_exported_sheet_can_be_edited_and_imported_back(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        $this->seed(CheckpointSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $trucks = Truck::orderBy('reg_no')->take(2)->get();
        $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => $trucks->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 30])->all()])
            ->assertCreated();

        // Export: the client's layout, one row per open leg, hidden IDs in column Y.
        $path = tempnam(sys_get_temp_dir(), 'trk') . '.xlsx';
        $book = app(TrackingWorkbook::class)->export(BookingTruck::orderBy('id')->get());
        $going = $book->getSheet(0);
        $this->assertSame('TUNDUMA', (string) $going->getCell('M1')->getValue());
        $this->assertSame(mb_strtoupper($trucks[0]->reg_no), $going->getCell('B3')->getValue());
        $this->assertFalse($going->getColumnDimension('Y')->getVisible());

        // Edit: a status and dates on row 3, a date typo on row 4.
        $going->setCellValue('G3', 'LOADED - WAITING FOR DOCUMENTS');
        $going->setCellValue('I3', '29.10.2026');
        $going->setCellValue('J3', '30,10,2026');
        $going->setCellValue('M3', '02.11.2026');
        $going->setCellValue('I4', '02.101.2026');
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $upload = fn (bool $apply) => $this->post('/api/tracking/import', ['file' => new UploadedFile($path, 'tracking.xlsx', null, null, true), 'apply' => $apply ? 1 : 0], ['Accept' => 'application/json']);

        $preview = $upload(false)->assertOk()->json();
        $this->assertSame(['updated' => 1, 'unchanged' => 0, 'rejected' => 1], $preview['summary']);
        $this->assertNull(BookingTruck::orderBy('id')->first()->loading_date, 'preview saves nothing');
        $this->assertStringContainsString('"02.101.2026" is not a valid date', $preview['rows'][1]['errors'][0]);

        $upload(true)->assertOk()->assertJsonPath('summary.updated', 1);
        $leg = BookingTruck::orderBy('id')->first();
        $this->assertSame('loaded_waiting_documents', $leg->current_status);
        $this->assertSame('2026-10-30', \Carbon\Carbon::parse($leg->loading_date)->toDateString());
        $this->assertSame('2026-11-02', $leg->milestones()->first()->arrival_at->toDateString());

        // A fresh export imported unchanged changes nothing.
        IOFactory::createWriter(app(TrackingWorkbook::class)->export(BookingTruck::orderBy('id')->get()), 'Xlsx')->save($path);
        $this->assertSame(['updated' => 0, 'unchanged' => 2, 'rejected' => 0], $upload(false)->json('summary'));
    }

    /** Tracking req. 10, 13, 14: download exactly the legs on screen (also Offloaded) and reject retired status names. */
    public function test_download_of_chosen_legs_including_offloaded_and_old_status_names(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(FleetSeeder::class);
        $this->seed(CheckpointSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@makuruma.local')->firstOrFail());

        $client = Client::create(['company_name' => 'Copper Co', 'short_code' => 'CPR']);
        $trucks = Truck::orderBy('reg_no')->take(3)->get();
        $this->postJson('/api/bookings', ['client_id' => $client->id, 'direction' => 'go', 'trucks' => $trucks->map(fn ($t) => ['truck_id' => $t->id, 'tons' => 30])->all()])
            ->assertCreated();
        [$offloaded, $open] = BookingTruck::orderBy('id')->take(2)->get()->all();
        $this->putJson("/api/tracking/{$offloaded->id}/status", ['current_status' => 'offloaded'])->assertOk();

        $response = $this->post('/api/tracking/export-excel', ['ids' => [$offloaded->id, $open->id]], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('MAKURUMA TRACKING ' . now()->format('d.m.Y') . '.xlsx', (string) $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'trk') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $going = IOFactory::load($path)->getSheet(0);
        $this->assertSame(['OFFLOADED', 'ON ROUTE TO LOADING'], [$going->getCell('G3')->getValue(), $going->getCell('G4')->getValue()]);
        $this->assertNull($going->getCell('B5')->getValue(), 'only the chosen legs');

        $upload = fn (string $file, bool $apply = false) => $this->post('/api/tracking/import', ['file' => new UploadedFile($file, 'tracking.xlsx', null, null, true), 'apply' => $apply ? 1 : 0], ['Accept' => 'application/json']);

        // Downloaded as-is, uploaded as-is: nothing to change, Offloaded rows included.
        $this->assertSame(['updated' => 0, 'unchanged' => 2, 'rejected' => 0], $upload($path)->json('summary'));

        // Old names are rejected with the new ones; new names are applied, also on an Offloaded leg.
        $book = IOFactory::load($path);
        $book->getSheet(0)->setCellValue('G3', 'COMPLETED');
        $book->getSheet(0)->setCellValue('G4', 'AT BORDER');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $rows = $upload($path)->json('rows');
        $this->assertSame('CURRENT STATUS: "COMPLETED" is no longer used. Write OFFLOADED.', $rows[0]['errors'][0]);
        $this->assertSame('CURRENT STATUS: "AT BORDER" is no longer used. Write CLEARING or WAITING TO CROSS.', $rows[1]['errors'][0]);

        $book->getSheet(0)->setCellValue('G3', 'WAITING TO OFFLOAD');
        $book->getSheet(0)->setCellValue('G4', 'WAITING TO CROSS');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $upload($path, true)->assertOk()->assertJsonPath('summary.updated', 2);
        $this->assertSame(['waiting_to_offload', 'waiting_to_cross'], [$offloaded->fresh()->current_status, $open->fresh()->current_status]);

        // A row moved to the wrong sheet says where its hidden ID belongs.
        $book = IOFactory::load($path);
        $book->getSheet(1)->fromArray([[1, $open->truck->reg_no]], null, 'A3');
        $book->getSheet(1)->setCellValue('Y3', $open->id);
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $returnRow = collect($upload($path)->json('rows'))->firstWhere('sheet', 'RETURN');
        $this->assertStringContainsString("GOING leg", $returnRow['errors'][0]);
        $this->assertStringContainsString('row is on the RETURN sheet', $returnRow['errors'][0]);

        // GET still downloads every open leg.
        $this->get('/api/tracking/export-excel')->assertOk();
    }
}
