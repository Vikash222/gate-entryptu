<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('student_id', 50)->unique();
            $table->string('roll_number', 50)->unique();
            $table->string('name')->index();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('email');
            $table->string('phone_number', 30)->nullable()->index();
            $table->string('program', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('batch', 50)->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->enum('current_status', ['INSIDE', 'OUTSIDE'])->default('INSIDE')->index();
            $table->foreignId('last_gate_id')->nullable()->constrained('gates')->nullOnDelete();
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
