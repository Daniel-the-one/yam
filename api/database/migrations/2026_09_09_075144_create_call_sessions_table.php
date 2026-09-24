<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('call_id')->unique();
            $table->string('from_device_id');
            $table->string('to_device_id')->nullable();
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->enum('type', ['audio', 'video']);
            $table->enum('status', ['ringing', 'active', 'cancelled', 'ended'])->default('ringing');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
    // Added call sessions table for call management and cancellation
};
