<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 2.2 D-3: Sakania is an alternate border, not a further checkpoint.
 * Route A (kasumbalesa): Tunduma → Nakonde → Kasumbalesa Zambia → Kasumbalesa DRC → Whisky.
 * Route B (sakania):     Tunduma → Nakonde → Sakania Zambia → Sakania DRC → Whisky.
 * Checkpoints with no route are on both. Return legs run the same list in reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->enum('route', ['kasumbalesa', 'sakania'])->nullable()->after('sequence_order');
        });

        DB::table('checkpoints')->whereIn('name', ['Kasumbalesa Zambia', 'Kasumbalesa DRC'])->update(['route' => 'kasumbalesa']);
        DB::table('checkpoints')->where('name', 'Sakania DRC')->update(['route' => 'sakania', 'sequence_order' => 4]);
        DB::table('checkpoints')->where('name', 'Whisky')->update(['sequence_order' => 5]);

        // Existing installs only; a fresh install gets the full list from CheckpointSeeder.
        $hasCheckpoints = DB::table('checkpoints')->where('name', 'Kasumbalesa Zambia')->exists();
        if ($hasCheckpoints && !DB::table('checkpoints')->where('name', 'Sakania Zambia')->exists()) {
            DB::table('checkpoints')->insert([
                'name' => 'Sakania Zambia', 'sequence_order' => 3, 'route' => 'sakania',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('checkpoints')->where('name', 'Sakania DRC')->update(['sequence_order' => 6]);
        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropColumn('route');
        });
    }
};
