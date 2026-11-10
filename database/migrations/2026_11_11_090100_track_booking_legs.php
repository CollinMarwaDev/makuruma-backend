<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 2.2 D-1: tracking moves from the truck to each booking leg (Go / Return), so a truck's
 * Go leg stays editable after it is booked on a Return trip. Existing truck status, location and
 * checkpoint dates are copied onto each truck's latest leg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_trucks', function (Blueprint $table) {
            $table->string('current_status', 50)->default('pending')->after('eta');
            $table->string('current_location')->nullable()->after('current_status');
            $table->enum('route', ['kasumbalesa', 'sakania'])->default('kasumbalesa')->after('current_location');
            $table->timestamp('completed_at')->nullable()->after('offloading_date');
            $table->timestamp('auto_completed_at')->nullable()->after('completed_at');
            $table->timestamp('pod_overdue_notified_at')->nullable()->after('auto_completed_at');
        });

        Schema::table('truck_milestones', function (Blueprint $table) {
            $table->foreignId('booking_truck_id')->nullable()->after('truck_id')->constrained()->cascadeOnDelete();
        });

        // Latest active leg per truck receives the truck's current tracking data.
        $latestLegs = DB::table('booking_trucks')
            ->whereNull('removed_at')
            ->select('truck_id', DB::raw('MAX(id) as id'))
            ->groupBy('truck_id')
            ->pluck('id', 'truck_id');

        foreach ($latestLegs as $truckId => $legId) {
            $truck = DB::table('trucks')->find($truckId);
            DB::table('booking_trucks')->where('id', $legId)->update([
                'current_status' => $truck->current_status ?? 'pending',
                'current_location' => $truck->current_location,
            ]);
            DB::table('truck_milestones')->where('truck_id', $truckId)->update(['booking_truck_id' => $legId]);
        }

        // Older legs: finished if they were offloaded, otherwise left pending for the team to review.
        DB::table('booking_trucks')->whereNotIn('id', $latestLegs->values())->whereNotNull('offloading_date')
            ->update(['current_status' => 'completed', 'completed_at' => now()]);

        // Milestones of trucks that were never booked keep booking_truck_id = null (kept, not deleted).

        Schema::table('truck_milestones', function (Blueprint $table) {
            $table->index('truck_id'); // the truck_id foreign key used the old unique index
            $table->dropUnique(['truck_id', 'checkpoint_id']);
            $table->unique(['booking_truck_id', 'checkpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::table('truck_milestones', function (Blueprint $table) {
            $table->dropForeign(['booking_truck_id']);
            $table->dropUnique(['booking_truck_id', 'checkpoint_id']);
        });
        // Keep only the newest milestone per truck/checkpoint so the old unique index fits again.
        DB::statement('DELETE m1 FROM truck_milestones m1 JOIN truck_milestones m2
            ON m1.truck_id = m2.truck_id AND m1.checkpoint_id = m2.checkpoint_id AND m1.id < m2.id');
        Schema::table('truck_milestones', function (Blueprint $table) {
            $table->unique(['truck_id', 'checkpoint_id']);
            $table->dropIndex(['truck_id']);
            $table->dropColumn('booking_truck_id');
        });
        Schema::table('booking_trucks', function (Blueprint $table) {
            $table->dropColumn(['current_status', 'current_location', 'route', 'completed_at', 'auto_completed_at', 'pod_overdue_notified_at']);
        });
    }
};
