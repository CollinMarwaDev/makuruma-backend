<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 2.2 D-5/6/7: documents (PODs) are soft-deleted with who deleted them, and users get
 * in-app notifications (Laravel database notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // The 5-day rules start on the day this release goes live. Legs already offloaded more than
        // 5 days ago are closed quietly, so the first scheduler run does not send an alert for every
        // past trip. Trucks are left as they are.
        $cutoff = now()->subDays(5)->toDateString();
        DB::table('booking_trucks')->whereNotNull('offloading_date')->whereDate('offloading_date', '<=', $cutoff)
            ->whereNull('pod_overdue_notified_at')->update(['pod_overdue_notified_at' => now()]);
        DB::table('booking_trucks')->whereNotNull('offloading_date')->whereDate('offloading_date', '<=', $cutoff)
            ->where('current_status', '!=', 'completed')
            ->update(['current_status' => 'completed', 'completed_at' => now(), 'auto_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
