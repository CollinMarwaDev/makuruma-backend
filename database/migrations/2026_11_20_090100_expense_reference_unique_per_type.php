<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UAT 20 Nov: the reference number typed on the form is the form number, numbered per expense type
 * (Fuel, Vibali Tunduma, Vibali Congo, Other), so the same number may appear once in each type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_orders', function (Blueprint $table) {
            $table->dropUnique(['reference_no']);
            $table->unique(['expense_type', 'reference_no']);
        });
    }

    public function down(): void
    {
        Schema::table('expense_orders', function (Blueprint $table) {
            $table->dropUnique(['expense_type', 'reference_no']);
            $table->unique('reference_no');
        });
    }
};
