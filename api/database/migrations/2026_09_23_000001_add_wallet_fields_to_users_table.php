<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs wallet à la table users.
     *
     * Le wallet est DÉRIVÉ de l'utilisateur : users.solde reste la source
     * unique de vérité (utilisée par AppelController pour la facturation des
     * appels). wallet_id / key_wallet sont générés à la première consultation
     * du wallet (WalletService::ensureWallet).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wallet_id', 32)->nullable()->unique()->after('solde');
            $table->string('key_wallet', 64)->nullable()->after('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['wallet_id']);
            $table->dropColumn(['wallet_id', 'key_wallet']);
        });
    }
};