<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 2.1: loading point, offloading point and ETA are per truck (they can differ
 * between trucks on one booking), and a booking can be copied from another one.
 * Existing bookings keep their values: the booking-level fields are copied onto each truck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_trucks', function (Blueprint $table) {
            $table->string('loading_point')->nullable()->after('rate');
            $table->string('offloading_point')->nullable()->after('loading_point');
            $table->date('eta')->nullable()->after('offloading_point');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('source_booking_id')->nullable()->after('client_id')
                ->constrained('bookings')->nullOnDelete();
        });

        DB::table('booking_trucks')
            ->join('bookings', 'bookings.id', '=', 'booking_trucks.booking_id')
            ->update([
                'booking_trucks.loading_point' => DB::raw('bookings.loading_point'),
                'booking_trucks.offloading_point' => DB::raw('bookings.offloading_point'),
                'booking_trucks.eta' => DB::raw('bookings.eta'),
            ]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_booking_id');
        });

        Schema::table('booking_trucks', function (Blueprint $table) {
            $table->dropColumn(['loading_point', 'offloading_point', 'eta']);
        });
    }
};
