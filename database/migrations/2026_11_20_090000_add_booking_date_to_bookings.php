<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** UAT 20 Nov: a booking's month = the average ETA of its trucks (else the day it was created). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('booking_date')->nullable()->after('eta')->index();
        });

        DB::table('bookings')->orderBy('id')->each(function ($booking) {
            $etas = DB::table('booking_trucks')->where('booking_id', $booking->id)->whereNull('removed_at')->pluck('eta');
            $date = \App\Services\BookingTruckService::averageDate($etas, $booking->created_at);
            DB::table('bookings')->where('id', $booking->id)->update(['booking_date' => $date->toDateString()]);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('booking_date');
        });
    }
};
