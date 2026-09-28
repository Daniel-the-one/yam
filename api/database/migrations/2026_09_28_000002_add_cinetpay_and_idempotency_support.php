<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support de l'intégration CinetPay (recharge Mobile Money).
 *
 * 1) `transactions` : traçabilité du dialogue avec le prestataire.
 *    - provider                 : 'cinetpay' (null tant que l'intégration n'est pas configurée)
 *    - provider_transaction_id  : transaction_id envoyé à CinetPay = NOTRE référence interne.
 *                                 C'est la clé de rapprochement du webhook : UNIQUE donc un
 *                                 webhook rejoué ne peut pas viser une autre recharge.
 *    - provider_payment_token   : token renvoyé par CinetPay, pour la vérification
 *                                 du statut côté serveur.
 *    Sans ces colonnes, le webhook ne peut identifier AUCUNE recharge en cours :
 *    la confirmation arriving plus tard n'aurait aucun moyen de la rattacher.
 *
 * 2) `idempotency_keys` : protection contre les doubles envois.
 *    Une API qui déplace de l'argent doit être idempotente : un double-clic ou
 *    un retry réseau sur POST /wallet/recharge ou /wallet/transfert ne doit pas
 *    produire deux mouvements. La réponse d'origine est conservée et rejouée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('devise');
            $table->string('provider_transaction_id', 64)->nullable()->after('provider');
            $table->string('provider_payment_token', 128)->nullable()->after('provider_transaction_id');
        });

        // Index unique partiel : uniquement les lignes qui portent un identifiant
        // prestataire doivent être uniques. MySQL et PostgreSQL n'ont pas d'index
        // partiel, donc l'unicité est portée par une colonne nullable UNIQUE
        // (NULL n'est jamais considéré comme doublon en SQL).
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('provider_transaction_id', 'transactions_provider_txid_unique');
            $table->index(['provider', 'status'], 'transactions_provider_status_index');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idem_key', 128);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 64);
            // Empreinte de la requête : si la même clé est réemployée avec un
            // corps différent, c'est une erreur de client (409) et non un rejeu.
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->default(201);
            $table->longText('response_body');
            $table->timestamps();

            $table->unique(['user_id', 'endpoint', 'idem_key'], 'idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_provider_status_index');
            $table->dropUnique('transactions_provider_txid_unique');
            $table->dropColumn(['provider', 'provider_transaction_id', 'provider_payment_token']);
        });
    }
};
