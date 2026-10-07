<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('username', 30)->nullable()->unique();
            $table->string('phone_number', 30)->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('wallet_id', 32)->unique();
            $table->unsignedBigInteger('balance')->default(50000);
            $table->timestamps();
        });

        Schema::create('demo_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_account_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('demo_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_account_id')->nullable()
                ->constrained('demo_accounts')->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('type', 20);
            $table->string('type_name', 50);
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fees')->default(0);
            $table->unsignedBigInteger('balance_after')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('counterpart_type', 20)->nullable();
            $table->string('counterpart_label')->nullable();
            $table->string('counterpart_wallet_id', 32)->nullable();
            $table->string('description')->nullable();
            $table->timestamp('date_complete')->nullable();
            $table->timestamps();

            $table->index(['demo_account_id', 'created_at']);
            $table->index(['demo_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_wallet_transactions');
        Schema::dropIfExists('demo_access_tokens');
        Schema::dropIfExists('demo_accounts');
    }
};
