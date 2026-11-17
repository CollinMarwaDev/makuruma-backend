<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client request (17 Nov 2026): a Truck expense order can cover several trucks, picked per line like
 * Trip lines. Each line now names its truck, so every truck's annual report gets only its own costs.
 * Existing Truck orders had one truck on the order: their lines get that truck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->foreignId('truck_id')->nullable()->after('booking_truck_id')->constrained()->nullOnDelete();
        });

        DB::statement("UPDATE expense_lines l JOIN expense_orders o ON o.id = l.expense_order_id
            SET l.truck_id = o.truck_id WHERE o.category = 'truck' AND o.truck_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('expense_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('truck_id'));
    }
};
