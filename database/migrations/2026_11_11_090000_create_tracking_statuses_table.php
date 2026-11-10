<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 2.2 D-4: tracking statuses become data an admin can extend (Parked, Clearing, …).
 * System statuses drive rules (pending, offloading, completed, …) and cannot be removed.
 * The status columns on trucks change from ENUM to string so custom keys fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label');
            $table->enum('color', ['default', 'warning', 'danger', 'success', 'disabled'])->default('default');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        $now = now();
        $statuses = [
            ['pending', 'Pending', 'disabled', true],
            ['on_route_to_loading', 'On Route to Loading', 'default', true],
            ['loading', 'Loading', 'default', true],
            ['in_transit', 'In Transit', 'warning', true],
            ['at_border', 'At Border', 'warning', true],
            ['waiting_to_cross', 'Waiting to Cross', 'warning', false],
            ['in_crossing_queue', 'In Crossing Queue', 'warning', false],
            ['clearing', 'Clearing', 'warning', false],
            ['parked', 'Parked', 'disabled', false],
            ['offloading', 'Offloading', 'warning', true],
            ['delayed', 'Delayed', 'danger', true],
            ['breakdown', 'Breakdown', 'danger', true],
            ['completed', 'Completed', 'success', true],
        ];
        DB::table('tracking_statuses')->insert(array_map(fn ($s, $i) => [
            'key' => $s[0], 'label' => $s[1], 'color' => $s[2], 'is_system' => $s[3],
            'sort_order' => ($i + 1) * 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $statuses, array_keys($statuses)));

        DB::statement("ALTER TABLE trucks MODIFY current_status VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE trucks SET current_status = 'pending' WHERE current_status NOT IN ('pending','on_route_to_loading','loading','in_transit','at_border','offloading','delayed','breakdown','completed')");
        DB::statement("ALTER TABLE trucks MODIFY current_status ENUM('pending','on_route_to_loading','loading','in_transit','at_border','offloading','delayed','breakdown','completed') DEFAULT 'pending'");
        Schema::dropIfExists('tracking_statuses');
    }
};
