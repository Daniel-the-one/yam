<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le support des notifications push (FCM mobile + Web Push navigateur)
 * au registre d'appareils.
 *
 *  - fcm_token              : token Firebase Cloud Messaging (Android/iOS)
 *  - web_push_subscription  : subscription Web Push (JSON) pour le navigateur
 *  - vapid_public_key       : clé VAPID publique utilisée par ce client web
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('fcm_token')->nullable()->after('platform');
            $table->text('web_push_subscription')->nullable()->after('fcm_token');
            $table->string('vapid_public_key')->nullable()->after('web_push_subscription');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['fcm_token', 'web_push_subscription', 'vapid_public_key']);
        });
    }
};
