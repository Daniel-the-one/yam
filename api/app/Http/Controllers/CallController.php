<?php

namespace App\Http\Controllers;

use App\Events\CallCancelled;
use App\Events\IncomingCall;
use App\Models\Device;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CallController extends Controller
{
    /**
     * POST /api/v1/call/ring
     *
     * Déclenche la sonnerie chez TOUS les appareils d'un utilisateur cible :
     *  - enregistre la session d'appel en base (call_sessions) ;
     *  - broadcast WebSocket (Pusher) sur chaque canal device.{device_id} ;
     *  - notification push (FCM / Web Push) pour réveiller chaque appareil
     *    hors application.
     *
     * Le premier appareil qui décroche gagne (géré côté client via le
     * signaling device-to-device).
     */
    public function ring(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_user_id'          => 'nullable|integer|exists:users,id',
            'to_phone_number'     => 'nullable|string|max:30',
            'target_phone_number' => 'nullable|string|max:30',
            'to_device_id'        => 'nullable|string|max:100',
            'from_device_id'      => 'required|string|max:100',
            'from_username'       => 'nullable|string|max:50',
            'type'                => 'in:audio,video',
        ]);

        $type = $validated['type'] ?? 'audio';
        $callId = (string) Str::uuid();
        $caller = $request->user();

        // Nom de l'appelant : résolu depuis le token Sanctum si disponible,
        // sinon depuis le champ from_username (rétrocompatibilité clients).
        $callerName = $caller?->name ?? ($validated['from_username'] ?? 'Inconnu');

        // Résoudre l'utilisateur cible.
        $targetUserId = null;
        if (!empty($validated['to_user_id'])) {
            $targetUserId = (int) $validated['to_user_id'];
        } elseif (!empty($validated['to_phone_number']) || !empty($validated['target_phone_number'])) {
            $phone = $validated['to_phone_number'] ?? $validated['target_phone_number'];
            $targetUser = User::where('phone_number', $phone)->first();
            $targetUserId = $targetUser?->id;
        } elseif (!empty($validated['to_device_id'])) {
            // Rétrocompatibilité : to_device_id → user du device.
            $device = Device::where('device_id', $validated['to_device_id'])->first();
            $targetUserId = $device?->user_id;
        }

        if ($targetUserId === null) {
            return response()->json([
                'error' => [
                    'code'    => 'user_not_found',
                    'message' => 'Utilisateur cible introuvable.',
                ],
            ], 404);
        }

        // Interdire d'appeler son propre compte.
        if ($caller && $targetUserId === $caller->id) {
            return response()->json([
                'error' => [
                    'code'    => 'cannot_call_self',
                    'message' => 'Vous ne pouvez pas vous appeler vous-même.',
                ],
            ], 400);
        }

        // Associer ou mettre à jour le device émetteur pour l'appelant connecté.
        if ($caller) {
            Device::updateOrCreate(
                ['device_id' => $validated['from_device_id']],
                [
                    'user_id'      => $caller->id,
                    'label'        => $callerName,
                    'last_seen_at' => now(),
                ]
            );
        }

        // Enregistrer la session d'appel (id uuid + call_id uuid public).
        try {
            DB::table('call_sessions')->insert([
                'id'             => (string) Str::uuid(),
                'call_id'        => $callId,
                'from_device_id' => $validated['from_device_id'],
                'to_device_id'   => $validated['to_device_id'] ?? null,
                'from_user_id'   => $caller?->id,
                'to_user_id'     => $targetUserId,
                'type'           => $type,
                'status'         => 'ringing',
                'started_at'     => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // La session est un plus, pas un prérequis : on continue même si
            // l'insertion échoue (table absente en dev, etc.).
            Log::warning('Failed to create call session', [
                'error'   => $e->getMessage(),
                'call_id' => $callId,
            ]);
        }

        // Tous les appareils de l'utilisateur cible (dédupliqués, sans le device émetteur).
        $targetDevices = Device::where('user_id', $targetUserId)
            ->where('device_id', '!=', $validated['from_device_id'])
            ->get()
            ->unique('device_id');

        // 1. DIFFUSION WEBSOCKET (sonnerie instantanée) — exécutée APRÈS la
        //    réponse HTTP (afterResponse) : un broadcast Pusher.com coûte
        //    ~2 s par device (latence réseau SaaS). En synchrone, le ring
        //    bloquerait l'appelant 2 s × nb devices avant qu'il puisse
        //    envoyer son offre WebRTC → course avec l'appelé qui accepte.
        //    En afterResponse, le ring répond en ~50 ms et l'offre part
        //    immédiatement ; l'appelé reçoit la sonnerie et l'offre quasi
        //    simultanément (le SPA réessaie l'offre différée si besoin).
        foreach ($targetDevices as $device) {
            dispatch(fn () => broadcast(new IncomingCall(
                callId:       $callId,
                toDeviceId:   $device->device_id,
                fromDeviceId: $validated['from_device_id'],
                fromUsername: $callerName,
                type:         $type,
                fromUserId:   $caller?->id,
            )))->afterResponse();
        }

        // 2. NOTIFICATIONS PUSH EN ARRIÈRE-PLAN (réveiller les appareils
        //    hors ligne sans bloquer la sonnerie). Exécutées APRÈS l'envoi
        //    de la réponse HTTP (afterResponse) : le ring répond immédiatement
        //    au lieu d'attendre chaque appel FCM (jusqu'à 2 s × nb devices).
        foreach ($targetDevices as $device) {
            $pushPayload = [
                'type'           => 'incoming',
                'call_id'        => $callId,
                'from_device_id' => $validated['from_device_id'],
                'from_username'  => $callerName,
                'from_user_id'   => $caller?->id,
                'media'          => $type, // audio | video
            ];
            dispatch(fn () => $this->sendPushNotification($device, $pushPayload))->afterResponse();
        }

        return response()->json([
            'data' => [
                'ok'                      => true,
                'call_id'                 => $callId,
                'target_devices_notified' => $targetDevices->count(),
            ],
        ]);
    }

    /**
     * POST /api/v1/call/cancel
     *
     * Annule un appel en cours depuis le client.
     * Utilisé pour remplacer le timeout frontend par un arrêt gracieux :
     *  - marque la session d'appel comme annulée ;
     *  - diffuse l'événement CallCancelled aux autres appareils.
     */
    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'call_id'        => 'required|string|max:100',
            'from_device_id' => 'required|string|max:100',
        ]);

        $callId = $validated['call_id'];
        $deviceId = $validated['from_device_id'];

        // Marquer l'appel comme annulé dans la base de données.
        $updated = DB::table('call_sessions')
            ->where('call_id', $callId)
            ->where('from_device_id', $deviceId)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'status' => 'cancelled']);

        if ($updated) {
            // Notifier les appareils du destinataire qu'un appel a été annulé
            // (arrêt de la sonnerie). On diffuse sur CHAQUE appareil cible,
            // comme IncomingCall, car les canaux device.{id} sont publics.
            $session = DB::table('call_sessions')
                ->where('call_id', $callId)
                ->first();

            $targetDevices = collect();
            if ($session?->to_user_id) {
                $targetDevices = Device::where('user_id', $session->to_user_id)
                    ->where('device_id', '!=', $deviceId)
                    ->get()
                    ->unique('device_id');
            } elseif ($session?->to_device_id) {
                $targetDevices = collect([(object) ['device_id' => $session->to_device_id]]);
            }

            foreach ($targetDevices as $device) {
                try {
                    broadcast(new CallCancelled(
                        callId:       $callId,
                        toDeviceId:   $device->device_id,
                        fromDeviceId: $deviceId,
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Failed to broadcast CallCancelled', [
                        'error'        => $e->getMessage(),
                        'to_device_id' => $device->device_id,
                    ]);
                }
            }

            return response()->json(['data' => ['ok' => true]], 200);
        }

        return response()->json([
            'error' => [
                'code'    => 'call_not_found',
                'message' => 'Appel non trouvé ou déjà terminé.',
            ],
        ], 404);
    }

    /**
     * Envoie la notification push à un appareil.
     */
    protected function sendPushNotification(Device $device, array $payload): void
    {
        try {
            // `fcm_token` et `web_push_subscription` sont dans `$hidden` du
            // modèle Device (pour ne pas fuiter via l'API). On les rend
            // visibles ici pour que PushService puisse envoyer la notification.
            $data = $device->makeVisible(['fcm_token', 'web_push_subscription'])->toArray();
            app(PushService::class)->notifyDevice($data, $payload);
        } catch (\Throwable $e) {
            Log::warning('[push] Erreur envoi notification : ' . $e->getMessage());
        }
    }
}