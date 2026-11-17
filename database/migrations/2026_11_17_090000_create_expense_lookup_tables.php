<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense UAT (Nov 2026) req. 18, 26–28: lists users manage without a developer —
 * payment accounts, vendor types, Other-expense subcategories and USD→TZS rates per period.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payment_accounts', 'vendor_types', 'expense_subcategories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                if ($name === 'vendor_types') {
                    $table->string('slug', 50)->unique();
                }
                $table->string('name')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::create('usd_rates', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('rate', 12, 4);
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        $now = now();
        $rows = fn (array $names) => array_map(fn ($n) => ['name' => $n, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $names);
        DB::table('payment_accounts')->insert($rows(['Cash', 'M-Pesa', 'CRDB']));
        DB::table('expense_subcategories')->insert($rows(['Weighbridge (Mizani)', 'GCLA', 'ECIL', 'Salaries', 'Driver mileage', 'Agent allowance', 'Truck maintenance']));
        DB::table('vendor_types')->insert([
            ['slug' => 'fuel', 'name' => 'Fuel', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'ecil', 'name' => 'ECIL', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Vendor type becomes a slug from vendor_types; existing e-seal vendors are ECIL.
        DB::statement('ALTER TABLE vendors MODIFY vendor_type VARCHAR(50) NOT NULL');
        DB::table('vendors')->where('vendor_type', 'e-seal')->update(['vendor_type' => 'ecil']);
    }

    public function down(): void
    {
        DB::table('vendors')->whereNotIn('vendor_type', ['fuel', 'ecil'])->update(['vendor_type' => 'fuel']);
        DB::table('vendors')->where('vendor_type', 'ecil')->update(['vendor_type' => 'e-seal']);
        DB::statement("ALTER TABLE vendors MODIFY vendor_type ENUM('fuel','e-seal') NOT NULL");

        Schema::dropIfExists('usd_rates');
        Schema::dropIfExists('expense_subcategories');
        Schema::dropIfExists('vendor_types');
        Schema::dropIfExists('payment_accounts');
    }
};
