<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index de performance pour le flux d'appels.
 *
 * `devices.device_id` est la colonne la plus requêtée du signaling
 * (SignalController, CallController, CallOfferController, DeviceController) :
 * sans index, chaque lookup fait un full table scan.
 *
 * `call_sessions.from_device_id` et `to_user_id` sont filtrés par
 * CallController::cancel() et les requêtes de session.
 *
 * Migration additive : n'altère aucune donnée, rollback = drop des index.
 *
 * NB : cette migration contenait à l'origine la création de la table
 * `appels` et l'ajout de `solde` (fourre-tout). Ces opérations ont été
 * déplacées dans leurs propres migrations (2026_09_14_000000 et
 * 2026_09_14_000001) — les garder ici cassait les bases fraîches
 * (RefreshDatabase) : colonne `solde` ajoutée deux fois, table `appels`
 * créée deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->index('device_id', 'idx_devices_device_id');
        });

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->index('from_device_id', 'idx_call_sessions_from_device_id');
            $table->index('to_user_id', 'idx_call_sessions_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('idx_devices_device_id');
        });

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_call_sessions_from_device_id');
            $table->dropIndex('idx_call_sessions_to_user_id');
        });
    }
};