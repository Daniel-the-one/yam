<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // username unique pour l'auth (pas d'email dans cette app)
            $table->string('username')->unique()->after('name');

            // device_id : identifiant unique de l'appareil (généré côté client)
            $table->string('device_id')->nullable()->after('username');

            // platform : "web", "ios", "android"
            $table->string('platform')->default('web')->after('device_id');

            // is_online : vrai quand l'appareil est connecté au WebSocket
            $table->boolean('is_online')->default(false)->after('platform');

            // api_token : token d'authentification simple (pas de Sanctum)
            $table->string('api_token', 64)->nullable()->unique()->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'device_id', 'platform', 'is_online']);
        });
    }
};
