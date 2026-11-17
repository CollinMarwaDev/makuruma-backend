<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense UAT req. 7–10: fuel lines keep the driver's name and may wait for a price; vendors have a default
 * payment mode; every price change is kept with its reason; pending-price alerts are sent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->string('driver_name')->nullable()->after('booking_truck_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->enum('payment_mode', ['on_spot', 'bill'])->default('bill')->after('vendor_type');
        });

        Schema::table('expense_orders', function (Blueprint $table) {
            $table->timestamp('price_alerted_at')->nullable()->after('usd_total');
        });

        Schema::create('expense_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_label');
            $table->decimal('old_unit_rate', 10, 2)->nullable();
            $table->decimal('new_unit_rate', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_price_changes');
        Schema::table('expense_orders', fn (Blueprint $table) => $table->dropColumn('price_alerted_at'));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn('payment_mode'));
        Schema::table('expense_lines', fn (Blueprint $table) => $table->dropColumn('driver_name'));
    }
};
