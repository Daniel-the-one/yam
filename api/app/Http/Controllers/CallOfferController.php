<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/**
 * Récupération différée d'une offre SDP WebRTC.
 *
 * GET /api/v1/call/{call_id}/offer
 *
 * Permet au destinataire d'un appel de récupérer l'offre SDP qui lui a été
 * adressée, même s'il n'était pas connecté en temps réel au moment de
 * l'émission (ex. app en arrière-plan, reconnexion après un tunnel).
 *
 * L'offre est stockée par SignalController lors du broadcast d'un
 * type=offer avec un call_id, et expirée après 90 s.
 */
class CallOfferController extends Controller
{
    /**
     * Récupère l'offre SDP différée pour un appel donné.
     *
     * Query params :
     *   device_id (obligatoire, string, max 100) — identifiant du
     *   destinataire demandant l'offre.
     *
     * Réponses :
     *   200 : {ok: true, call_id, from_device_id, to_device_id,
     *          type: "offer", payload: {sdp, type}, expires_at}
     *   403 : {ok: false, error: "forbidden_device"} si device_id ≠ to_device_id
     *   404 : {ok: false, error: "offer_not_found"} si aucune offre
     *   410 : {ok: false, error: "offer_expired"} si expires_at dépassée
     *   422 : format Laravel si device_id manquant/invalide
     */
    public function show(Request $request, string $callId): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:100',
        ]);

        $deviceId = $validated['device_id'];

        $offer = DB::table('call_offers')
            ->where('call_id', $callId)
            ->first();

        if ($offer === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'offer_not_found',
            ], 404);
        }

        if ($offer->to_user_id !== null) {
            // Modèle multi-appareils : l'offre est routée vers un utilisateur.
            // Le device demandeur doit appartenir à cet utilisateur cible.
            $device = DB::table('devices')
                ->where('device_id', $deviceId)
                ->first();

            if ($device === null || (int) $device->user_id !== (int) $offer->to_user_id) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'forbidden_device',
                ], 403);
            }
        } elseif ($offer->to_device_id !== $deviceId) {
            // Rétrocompatibilité device-to-device : match exact sur device_id.
            return response()->json([
                'ok'    => false,
                'error' => 'forbidden_device',
            ], 403);
        }

        if ($offer->expires_at !== null && strtotime($offer->expires_at) < time()) {
            return response()->json([
                'ok'    => false,
                'error' => 'offer_expired',
            ], 410);
        }

        $payload = json_decode($offer->sdp, true);
        if (! is_array($payload)) {
            Log::warning('Call offer has invalid sdp payload', [
                'call_id' => $callId,
            ]);
            $payload = [];
        }

        return response()->json([
            'ok'             => true,
            'call_id'        => $callId,
            'from_device_id' => $offer->from_device_id,
            'to_device_id'   => $offer->to_device_id,
            'type'           => 'offer',
            'payload'        => $payload,
            'expires_at'     => $offer->expires_at,
        ]);
    }
}
