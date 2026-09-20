<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_token')->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('gate_id')->constrained('gates')->cascadeOnDelete();
            $table->enum('status', ['PENDING', 'CONSUMED', 'EXPIRED'])->default('PENDING')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_sessions');
    }
};
