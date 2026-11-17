<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense UAT req. 12–13: route templates users edit without a developer (Vibali Tunduma form 123,
 * Vibali Congo form 165). An order copies the template's lines; later template edits never change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_templates', function (Blueprint $table) {
            $table->id();
            $table->enum('expense_type', ['vibali_tunduma', 'vibali_congo']);
            $table->string('name');
            $table->string('outbound_border', 30)->nullable();
            $table->string('return_border', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['expense_type', 'name']);
        });

        Schema::create('route_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('segment', 30)->nullable();
            $table->string('description');
            $table->enum('currency', ['TZS', 'USD', 'ZMK'])->default('TZS');
            $table->decimal('unit_amount', 12, 2);
            $table->decimal('multiplier', 10, 2)->nullable();
            $table->boolean('is_flat')->default(false);
            $table->timestamps();
        });

        Schema::table('expense_orders', function (Blueprint $table) {
            $table->foreignId('route_template_id')->nullable()->after('form')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('route_template_id'));
        Schema::dropIfExists('route_template_lines');
        Schema::dropIfExists('route_templates');
    }
};
