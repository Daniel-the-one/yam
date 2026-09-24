<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1) Ajouter la colonne user_id (FK nullable vers users) sur devices.
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete()
                ->index();
        });

        // 2) Migrer les données : lier les users existants (qui ont un device_id
        //    sur la table users) à un device dans la table devices.
        $users = DB::table('users')
            ->whereNotNull('device_id')
            ->get();

        foreach ($users as $user) {
            $existing = DB::table('devices')
                ->where('device_id', $user->device_id)
                ->first();

            if ($existing) {
                // Le device existe déjà : on le lie au user.
                DB::table('devices')
                    ->where('id', $existing->id)
                    ->update(['user_id' => $user->id]);
            } else {
                // Créer un device pour ce user, avec un label unique.
                $baseLabel = 'Appareil de ' . $user->username;
                $label = $baseLabel;
                $suffix = 2;
                while (DB::table('devices')->where('label', $label)->exists()) {
                    $label = $baseLabel . ' (' . $suffix . ')';
                    $suffix++;
                }

                DB::table('devices')->insert([
                    'label'       => $label,
                    'device_id'   => $user->device_id,
                    'platform'    => $user->platform ?? 'web',
                    'user_id'     => $user->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
