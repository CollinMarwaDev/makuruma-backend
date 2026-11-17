<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client request (17 Nov 2026), tracking req. 1–9: the client's own status names.
 * Loading → Waiting to load / Loaded – waiting for documents; At border → Clearing / Waiting to cross;
 * Offloading → Waiting to offload; Completed → Offloaded (the final status, no automatic completion).
 */
return new class extends Migration
{
    private const ORDER = [
        'pending', 'on_route_to_loading', 'waiting_to_load', 'loaded_waiting_documents', 'in_transit',
        'clearing', 'waiting_to_cross', 'in_crossing_queue', 'parked', 'delayed', 'breakdown',
    ];

    /**
     * Target statuses: [key, default label, colour for a new row, old keys it replaces].
     * Production already renamed some labels and added "Loaded, waiting for documents" by hand
     * (17 Nov 2026): rows are matched by key or by label, and labels/colours users chose are kept.
     */
    private const TARGETS = [
        ['waiting_to_load', 'Waiting to load', 'default', ['loading']],
        ['loaded_waiting_documents', 'Loaded – waiting for documents', 'warning', []],
        ['clearing', 'Clearing', 'warning', []],
        ['waiting_to_cross', 'Waiting to cross', 'warning', []],
        ['waiting_to_offload', 'Waiting to offload', 'warning', ['offloading']],
        ['offloaded', 'Offloaded', 'success', ['completed']],
    ];

    /** Labels shipped with the old keys; a label still equal to one of these is replaced. */
    private const OLD_LABELS = ['LOADING', 'OFFLOADING', 'COMPLETED'];

    public function up(): void
    {
        Schema::table('booking_trucks', function (Blueprint $table) {
            $table->timestamp('invoice_ready_notified_at')->nullable()->after('auto_completed_at');
        });

        $now = now();
        $words = fn (?string $s) => trim(preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper((string) $s)));
        $move = function (string $from, string $to) {
            DB::table('booking_trucks')->where('current_status', $from)->update(['current_status' => $to]);
            DB::table('trucks')->where('current_status', $from)->update(['current_status' => $to]);
        };

        // Legs still on Loading split on whether the truck has loaded (req. 5).
        DB::table('booking_trucks')->where('current_status', 'loading')->whereNotNull('loading_date')->update(['current_status' => 'loaded_waiting_documents']);

        foreach (self::TARGETS as [$key, $label, $color, $oldKeys]) {
            $rows = DB::table('tracking_statuses')->get()->filter(
                fn ($r) => in_array($r->key, [$key, ...$oldKeys], true) || $words($r->label) === $words($label)
            );
            $keep = $rows->sortBy(fn ($r) => $r->key === $key ? 0 : (in_array($r->key, $oldKeys, true) ? 1 : 2))->first();

            foreach ($rows as $row) {
                $move($row->key, $key);
                if ($row->id !== $keep?->id) {
                    DB::table('tracking_statuses')->where('id', $row->id)->delete();
                }
            }

            if ($keep) {
                DB::table('tracking_statuses')->where('id', $keep->id)->update([
                    'key' => $key,
                    'label' => in_array($words($keep->label), self::OLD_LABELS, true) ? $label : $keep->label,
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('tracking_statuses')->insert(['key' => $key, 'label' => $label, 'color' => $color, 'is_system' => true, 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $move('at_border', 'clearing');
        DB::table('tracking_statuses')->where('key', 'at_border')->delete();

        // A truck shows its newest leg's status.
        DB::statement("UPDATE trucks t JOIN booking_trucks bt ON bt.id = (SELECT MAX(id) FROM booking_trucks WHERE truck_id = t.id AND removed_at IS NULL)
            SET t.current_status = bt.current_status WHERE t.current_status = 'waiting_to_load' AND bt.current_status = 'loaded_waiting_documents'");

        $status = fn (string $key, array $values) => DB::table('tracking_statuses')->where('key', $key)->update($values + ['updated_at' => $now]);
        $i = 0;
        foreach (self::ORDER as $key) {
            $status($key, ['sort_order' => ++$i * 10]);
        }
        foreach (DB::table('tracking_statuses')->whereNotIn('key', [...self::ORDER, 'waiting_to_offload', 'offloaded'])->orderBy('sort_order')->pluck('key') as $key) {
            $status($key, ['sort_order' => ++$i * 10]);
        }
        $status('waiting_to_offload', ['sort_order' => 900]);
        $status('offloaded', ['sort_order' => 910]);

        // The 5-day "ready for invoicing" notice was already sent for legs that were auto-completed.
        DB::table('booking_trucks')->whereNotNull('auto_completed_at')->update(['invoice_ready_notified_at' => DB::raw('auto_completed_at')]);
        DB::table('booking_trucks')->whereNull('invoice_ready_notified_at')->whereNotNull('offloading_date')
            ->whereDate('offloading_date', '<=', now()->subDays(5)->toDateString())->update(['invoice_ready_notified_at' => $now]);
    }

    public function down(): void
    {
        $now = now();
        foreach (['waiting_to_load' => 'loading', 'loaded_waiting_documents' => 'loading', 'waiting_to_offload' => 'offloading', 'offloaded' => 'completed'] as $new => $old) {
            DB::table('booking_trucks')->where('current_status', $new)->update(['current_status' => $old]);
            DB::table('trucks')->where('current_status', $new)->update(['current_status' => $old]);
        }
        DB::table('tracking_statuses')->where('key', 'loaded_waiting_documents')->delete();
        DB::table('tracking_statuses')->where('key', 'waiting_to_load')->update(['key' => 'loading', 'label' => 'Loading']);
        DB::table('tracking_statuses')->where('key', 'waiting_to_offload')->update(['key' => 'offloading', 'label' => 'Offloading', 'sort_order' => 100]);
        DB::table('tracking_statuses')->where('key', 'offloaded')->update(['key' => 'completed', 'label' => 'Completed', 'sort_order' => 130]);
        DB::table('tracking_statuses')->whereIn('key', ['clearing', 'waiting_to_cross'])->update(['is_system' => false]);
        DB::table('tracking_statuses')->insertOrIgnore(['key' => 'at_border', 'label' => 'At Border', 'color' => 'warning', 'is_system' => true, 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now]);

        Schema::table('booking_trucks', fn (Blueprint $table) => $table->dropColumn('invoice_ready_notified_at'));
    }
};
