<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stockage différé des offres SDP WebRTC.
 *
 * Lorsqu'un appelant envoie une offre (type=offer) via /call/signal, le
 * payload SDP complet est conservé ici avec un TTL court (90 s). Le
 * destinataire peut ensuite récupérer l'offre différée via
 * GET /call/{call_id}/offer, même s'il n'était pas connecté en temps réel
 * au moment de l'émission (ex. app en arrière-plan, reconnexion).
 *
 *  - call_id      : identifiant unique de l'appel (UUID)
 *  - to_device_id : destinataire de l'offre (celui qui la récupérera)
 *  - from_device_id : émetteur de l'offre
 *  - sdp          : payload complet de l'offre (JSON {sdp, type})
 *  - expires_at   : TTL = 90 s (60 s timeout appelant + 30 s marge),
 *                   rafraîchi à chaque upsert
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_offers', function (Blueprint $table) {
            $table->uuid('call_id')->primary();
            $table->string('to_device_id')->index();
            $table->string('from_device_id');
            $table->text('sdp'); // JSON : {sdp, type}
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_offers');
    }
};
