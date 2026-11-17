<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Expense UAT req. 22: extra costs printed after the route's subtotal on form 165 (e.g. pulling entry card). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->boolean('is_additional')->default(false)->after('is_flat');
        });
    }

    public function down(): void
    {
        Schema::table('expense_lines', fn (Blueprint $table) => $table->dropColumn('is_additional'));
    }
};
