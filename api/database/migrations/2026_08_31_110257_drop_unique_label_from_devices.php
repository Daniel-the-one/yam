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
        // Avec le modèle multi-appareils, plusieurs devices peuvent partager
        // le même label (ex: deux "Web"). On retire la contrainte UNIQUE.
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_label_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unique('label');
        });
    }
};
