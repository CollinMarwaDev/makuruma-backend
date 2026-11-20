<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UAT 20 Nov: the day the truck was filled (Mabena records it; Negelo does not), used by the vendor ledger. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->date('filled_at')->nullable()->after('driver_name');
        });
    }

    public function down(): void
    {
        Schema::table('expense_lines', function (Blueprint $table) {
            $table->dropColumn('filled_at');
        });
    }
};
