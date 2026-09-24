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
        Schema::table('call_offers', function (Blueprint $table) {
            // Les offres routées par utilisateur n'ont pas de device cible
            // unique : ce champ doit donc accepter NULL.
            $table->string('to_device_id')->nullable()->change();

            // Permet de router l'offre vers tous les appareils d'un utilisateur
            // cible (modèle multi-appareils), en plus du to_device_id exact.
            // Nullable : les offres legacy (device-to-device) n'ont pas de user.
            $table->unsignedBigInteger('to_user_id')->nullable()->index()->after('to_device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_offers', function (Blueprint $table) {
            $table->dropColumn('to_user_id');
        });
    }
};
