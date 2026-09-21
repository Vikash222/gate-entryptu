<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('movement_source');
            $table->date('late_window_date')->nullable()->after('is_late');

            $table->index('is_late');
            $table->index('late_window_date');
            $table->index(['is_late', 'server_timestamp']);
            $table->index(['late_window_date', 'is_late']);
            $table->index(['student_id', 'is_late']);
            $table->index(['gate_id', 'is_late']);
        });

        // Historical Backfill: evaluate every existing movement authoritatively in Asia/Kolkata
        // without altering the original server_timestamp.
        DB::table('movements')->orderBy('id')->chunk(200, function ($records) {
            foreach ($records as $record) {
                if (empty($record->server_timestamp)) {
                    continue;
                }

                $timestamp = Carbon::parse($record->server_timestamp)->setTimezone('Asia/Kolkata');
                $timeStr = $timestamp->format('H:i:s');

                // Late Entry Rule: 21:00:00 <= time < 04:00:00
                $isLate = ($timeStr >= '21:00:00' || $timeStr < '04:00:00');

                $lateWindowDate = null;
                if ($isLate) {
                    if ($timeStr >= '21:00:00') {
                        $lateWindowDate = $timestamp->format('Y-m-d');
                    } else {
                        // Overnight: 00:00:00 to 03:59:59 belongs to the previous calendar day's late window
                        $lateWindowDate = $timestamp->copy()->subDay()->format('Y-m-d');
                    }
                }

                DB::table('movements')->where('id', $record->id)->update([
                    'is_late' => $isLate,
                    'late_window_date' => $lateWindowDate,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex(['gate_id', 'is_late']);
            $table->dropIndex(['student_id', 'is_late']);
            $table->dropIndex(['late_window_date', 'is_late']);
            $table->dropIndex(['is_late', 'server_timestamp']);
            $table->dropIndex(['late_window_date']);
            $table->dropIndex(['is_late']);

            $table->dropColumn(['late_window_date', 'is_late']);
        });
    }
};
