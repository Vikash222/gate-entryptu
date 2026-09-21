<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add student residency category to students table
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'category')) {
                $table->string('category', 20)->default('HOSTELER')->after('batch')->index();
            }
        });

        // 2. Add day_scholar_after_hours classification to movements table
        Schema::table('movements', function (Blueprint $table) {
            if (!Schema::hasColumn('movements', 'day_scholar_after_hours')) {
                $table->boolean('day_scholar_after_hours')->default(false)->after('late_window_date')->index();
                $table->index(['day_scholar_after_hours', 'server_timestamp'], 'movements_ds_after_hours_ts_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'day_scholar_after_hours')) {
                $table->dropIndex('movements_ds_after_hours_ts_idx');
                $table->dropColumn('day_scholar_after_hours');
            }
        });

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
