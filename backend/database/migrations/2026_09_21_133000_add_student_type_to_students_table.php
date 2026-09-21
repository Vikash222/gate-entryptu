<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'student_type')) {
                $table->string('student_type', 20)->nullable()->after('category')->index();
            }
        });

        // Backfill student_type from category if category exists
        if (Schema::hasColumn('students', 'category')) {
            DB::table('students')
                ->whereNull('student_type')
                ->whereNotNull('category')
                ->update([
                    'student_type' => DB::raw("CASE WHEN category = 'HOSTELER' THEN 'HOSTELLER' ELSE category END")
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'student_type')) {
                $table->dropColumn('student_type');
            }
        });
    }
};
