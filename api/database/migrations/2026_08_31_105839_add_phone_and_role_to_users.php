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
        Schema::table('users', function (Blueprint $table) {
            // Numéro de téléphone : identifiant principal du compte (E.164).
            // Nullable temporairement : les users existants n'ont pas de téléphone.
            $table->string('phone_number')->nullable()->unique();

            // Vérification SMS prévue mais pas implémentée pour l'instant.
            $table->boolean('phone_verified')->default(false);

            // Rôle : "user" (défaut) ou "doctor" (extensible).
            $table->string('role')->default('user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'phone_verified', 'role']);
        });
    }
};
