<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table des transactions du wallet.
     *
     * Les montants sont stockés en BRUT (amount_raw, fees_raw,
     * new_balance_raw) ; les champs dérivés (icônes, couleurs, statuts
     * affichés, montants formatés) sont calculés à la volée par
     * WalletService pour rester cohérents avec les formats de l'API externe.
     *
     * Le counterpart (contrepartie de la transaction) est stocké en colonnes
     * explicites (pas de JSON) : interrogeable et portable SQLite/PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('type', 20);          // TRANSFER, TOPUP, ...
            $table->string('type_name', 50);     // Transfert, Recharge, ...
            $table->string('sens', 10);          // credit | debit
            $table->decimal('amount_raw', 12, 2);
            $table->decimal('fees_raw', 12, 2)->default(0.00);
            $table->string('devise', 5)->default('XOF');
            $table->decimal('new_balance_raw', 12, 2)->nullable();
            $table->unsignedTinyInteger('status')->default(1); // 1=en cours, 2=réussie, 3=échouée
            $table->string('description')->nullable();
            $table->string('counterpart_type', 20)->nullable();    // internal | mobile_money | external
            $table->string('counterpart_label')->nullable();
            $table->string('counterpart_wallet_id', 32)->nullable();
            $table->string('counterpart_nom')->nullable();
            $table->date('date_transaction');
            $table->time('heure_transaction');
            $table->dateTime('date_complete')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date_transaction']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};