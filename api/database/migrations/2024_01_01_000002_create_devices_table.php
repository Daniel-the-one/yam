<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre d'appareils côté serveur.
 *
 * Les tunnels trycloudflare changent de domaine à chaque relance :
 * le localStorage des clients est alors vidé (nouvelle origine) et les
 * identifiants/contacts disparaissent. Ce registre permet aux clients
 * de retrouver leurs appareils et contacts quel que soit le domaine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();   // nom lisible ("Téléphone de Dan")
            $table->string('device_id');         // identifiant technique actuel
            $table->string('platform')->default('web');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
