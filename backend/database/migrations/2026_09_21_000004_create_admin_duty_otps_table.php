<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_duty_otps', function (Blueprint $table) {
            $table->id();
            $table->string('otp_hash');
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('security_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gate_id')->nullable()->constrained('gates')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_duty_otps');
    }
};
