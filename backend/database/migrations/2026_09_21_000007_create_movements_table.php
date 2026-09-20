<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('movement_uuid')->unique();
            $table->string('verification_code', 20)->unique()->index();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('gate_id')->constrained('gates')->cascadeOnDelete();
            $table->foreignId('security_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['IN', 'OUT'])->index();
            $table->boolean('vehicle_present')->default(false);
            $table->string('vehicle_number', 50)->nullable();
            $table->string('destination')->nullable();
            $table->string('destination_other')->nullable();
            $table->string('purpose')->nullable();
            $table->string('purpose_other')->nullable();
            $table->string('client_request_id', 100)->nullable()->unique();
            $table->timestamp('server_timestamp')->index();
            $table->timestamps();

            $table->index(['student_id', 'server_timestamp']);
            $table->index(['gate_id', 'server_timestamp']);
            $table->index(['type', 'server_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
