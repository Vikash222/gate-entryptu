<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50)->index();
            $table->string('module', 50)->index();
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->enum('status', ['SUCCESS', 'FAILED', 'WARNING'])->default('SUCCESS')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('server_timestamp')->index();
            $table->timestamps();

            $table->index(['action', 'server_timestamp']);
            $table->index(['module', 'server_timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
