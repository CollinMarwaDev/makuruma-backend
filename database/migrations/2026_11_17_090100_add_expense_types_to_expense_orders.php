<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense UAT (Nov 2026) req. 1–4, 8, 15–27: an order has one expense type with its own number series,
 * links one or more bookings, and carries the form-specific header fields. Existing order numbers stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_orders', function (Blueprint $table) {
            $table->enum('expense_type', ['fuel', 'vibali_tunduma', 'vibali_congo', 'mengine'])->nullable()->after('category');
            $table->string('form', 20)->nullable()->after('expense_type');
            $table->unsignedInteger('serial')->nullable()->after('order_number');
            $table->enum('payment_mode', ['on_spot', 'bill'])->default('bill')->after('total_amount');
            $table->foreignId('payment_account_id')->nullable()->after('payment_mode')->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('payment_account_id')->constrained()->nullOnDelete();
            $table->foreignId('other_subcategory_id')->nullable()->after('vendor_id')->constrained('expense_subcategories')->nullOnDelete();
            $table->foreignId('parent_order_id')->nullable()->after('other_subcategory_id')->constrained('expense_orders')->nullOnDelete();
            $table->string('outbound_border', 30)->nullable()->after('parent_order_id');
            $table->string('return_border', 30)->nullable()->after('outbound_border');
            $table->decimal('zmk_rate', 12, 4)->nullable()->after('return_border');
            $table->decimal('usd_rate', 12, 4)->nullable()->after('zmk_rate');
            $table->decimal('usd_total', 12, 2)->default(0)->after('usd_rate');
            $table->unique(['expense_type', 'serial']);
        });

        Schema::create('expense_order_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['expense_order_id', 'booking_id']);
        });

        Schema::table('expense_lines', function (Blueprint $table) {
            $table->decimal('multiplier', 10, 2)->nullable()->after('unit_rate');
            $table->string('amount_description')->nullable()->after('multiplier');
            $table->boolean('is_flat')->default(false)->after('amount_description');
            $table->enum('payee', ['driver', 'agent'])->nullable()->after('is_flat');
        });

        // Backfill: type = the order's most used line category; legacy mixed orders keep their lines.
        foreach (DB::table('expense_orders')->pluck('id') as $orderId) {
            $type = DB::table('expense_lines')->where('expense_order_id', $orderId)
                ->select('line_category', DB::raw('COUNT(*) as n'))->groupBy('line_category')
                ->orderByDesc('n')->value('line_category');
            DB::table('expense_orders')->where('id', $orderId)->update(['expense_type' => $type ?? 'mengine']);
        }

        $now = now();
        DB::table('expense_orders')->whereNotNull('booking_id')->orderBy('id')
            ->each(fn ($o) => DB::table('expense_order_bookings')->insert([
                'expense_order_id' => $o->id, 'booking_id' => $o->booking_id, 'created_at' => $now, 'updated_at' => $now,
            ]));

        // Free-text payment accounts become list entries.
        $names = DB::table('expense_orders')->whereNotNull('payment_account')->where('payment_account', '!=', '')->distinct()->pluck('payment_account');
        foreach ($names as $name) {
            $id = DB::table('payment_accounts')->where('name', $name)->value('id')
                ?? DB::table('payment_accounts')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('expense_orders')->where('payment_account', $name)->update(['payment_account_id' => $id]);
        }

        Schema::table('expense_orders', function (Blueprint $table) {
            $table->dropColumn('payment_account');
        });
        DB::statement("ALTER TABLE expense_orders MODIFY expense_type ENUM('fuel','vibali_tunduma','vibali_congo','mengine') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('expense_orders', function (Blueprint $table) {
            $table->string('payment_account')->nullable()->after('total_amount');
        });
        foreach (DB::table('payment_accounts')->get() as $account) {
            DB::table('expense_orders')->where('payment_account_id', $account->id)->update(['payment_account' => $account->name]);
        }

        Schema::table('expense_lines', function (Blueprint $table) {
            $table->dropColumn(['multiplier', 'amount_description', 'is_flat', 'payee']);
        });

        Schema::dropIfExists('expense_order_bookings');

        Schema::table('expense_orders', function (Blueprint $table) {
            $table->dropUnique(['expense_type', 'serial']);
            $table->dropConstrainedForeignId('payment_account_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('other_subcategory_id');
            $table->dropConstrainedForeignId('parent_order_id');
            $table->dropColumn(['expense_type', 'form', 'serial', 'payment_mode', 'outbound_border', 'return_border', 'zmk_rate', 'usd_rate', 'usd_total']);
        });
    }
};
