<?php

namespace App\Http\Controllers;

use App\Events\CallSignal;
use App\Models\Device;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SignalController extends Controller
{
    /**
     * POST /api/v1/call/signal
     *
     * Reçoit un message de signaling WebRTC (offer, answer, candidate)
     * et le diffuse en temps réel au device ciblé via Reverb.
     *
     * Body attendu :
     * {
     *   "to_device_id": "uuid-du-recepteur",
     *   "from_device_id": "uuid-de-l-emetteur",
     *   "type": "offer" | "answer" | "candidate" | "bye",
     *   "payload": { ... }  // SDP ou ICE candidate
     *   "call_id": "uuid-de-l-appel" (optionnel, pour le stockage différé)
     *   "to_user_id": 42 (optionnel, pour router l'offre vers tous les
     *                     appareils d'un utilisateur cible)
     * }
     */
    public function signal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_device_id'   => 'nullable|string',
            'from_device_id' => 'required|string',
            'type'           => 'required|string|in:offer,answer,candidate,candidates_batch,bye',
            'payload'        => 'nullable|array',
            'call_id'        => 'nullable|string|max:64',
            'to_user_id'     => 'nullable|integer|exists:users,id',
        ]);

        $user = $request->user();
        $type = $validated['type'];
        $callId = $validated['call_id'] ?? null;
        $fromDeviceId = $validated['from_device_id'];

        // Met à jour la présence de l'émetteur
        if ($user) {
            Device::where('device_id', $fromDeviceId)->update(['last_seen_at' => now()]);
        }

        // 1. RÉSOUDRE LES DESTINATAIRES (avec recherche bidirectionnelle si bye avec call_id)
        $toDeviceIds = $this->resolveTargetDeviceIds($validated);

        Log::info('CallSignal reçu', [
            'type'           => $type,
            'from_device_id' => $fromDeviceId,
            'to_user_id'     => $validated['to_user_id'] ?? null,
            'to_device_id'   => $validated['to_device_id'] ?? null,
            'call_id'        => $callId,
            'target_devices' => $toDeviceIds,
        ]);

        // 2. DIFFUSION WEBSOCKET IMMÉDIATE (0ms de latence, prioritaire absolue)
        // `candidates_batch` est dé-batché côté serveur : le gain HTTP du
        // batching (1 POST au lieu de 10-30) est conservé, mais chaque
        // candidat est re-broadcast en `candidate` unitaire pour rester
        // compatible avec TOUS les clients (mobile Flutter et ancien web
        // ne gèrent pas `candidates_batch`).
        if ($type === 'candidates_batch') {
            $candidates = $validated['payload']['candidates'] ?? [];
            if (!is_array($candidates)) {
                $candidates = [];
            }
            foreach ($candidates as $candidate) {
                foreach ($toDeviceIds as $toDeviceId) {
                    try {
                        broadcast(new CallSignal(
                            toDeviceId:   $toDeviceId,
                            fromDeviceId: $fromDeviceId,
                            type:         'candidate',
                            payload:      ['candidate' => $candidate],
                            callId:       $callId,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('Failed to broadcast CallSignal', [
                            'error'        => $e->getMessage(),
                            'to_device_id' => $toDeviceId,
                        ]);
                    }
                }
            }
        } else {
            foreach ($toDeviceIds as $toDeviceId) {
                try {
                    broadcast(new CallSignal(
                        toDeviceId:   $toDeviceId,
                        fromDeviceId: $fromDeviceId,
                        type:         $type,
                        payload:      $validated['payload'] ?? [],
                        callId:       $callId,
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Failed to broadcast CallSignal', [
                        'error'        => $e->getMessage(),
                        'to_device_id' => $toDeviceId,
                    ]);
                }
            }
        }

        // 3. TRAITEMENTS POST-BROADCAST
        if ($type === 'bye') {
            // Lire l'offre avant de l'invalider afin de pouvoir déterminer
            // le sens du bye et notifier l'autre partie.
            $this->notifyCallEnd($validated);
            if (!empty($callId)) {
                try {
                    DB::table('call_offers')->where('call_id', $callId)->delete();
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete call offer on bye', ['error' => $e->getMessage()]);
                }
            }
        } elseif ($type === 'offer') {
            // Stockage différé de l'offre SDP
            try {
                $this->handleDeferredOffer($validated);
            } catch (\Throwable $e) {
                Log::warning('Failed to handle deferred call offer', [
                    'error'   => $e->getMessage(),
                    'call_id' => $callId,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Envoie une notification push (reject / cancel) à l'autre partie quand
     * un `bye` est reçu.
     *
     * Le type est déterminé par le sens du bye :
     *  - le `bye` vient de l'appelant (from_device_id de l'offre différée)
     *    → `cancel` : l'appelant a annulé avant réponse ;
     *  - le `bye` vient du destinataire → `reject` : il a refusé l'appel.
     *
     * La push est envoyée à l'appareil de l'autre partie (celui qui n'a pas
     * envoyé le bye) pour qu'il arrête de sonner / soit informé.
     */
    protected function notifyCallEnd(array $validated): void
    {
        $callId = $validated['call_id'] ?? null;
        $fromDeviceId = $validated['from_device_id'];
        if ($callId === null || $callId === '') {
            return;
        }

        try {
            // Récupère l'offre différée pour connaître l'appelant et le
            // destinataire de cet appel.
            $offer = DB::table('call_offers')
                ->where('call_id', $callId)
                ->first();

            if ($offer === null) {
                // Pas d'offre différée : on ne peut pas déterminer le sens.
                return;
            }

            $callerDeviceId = $offer->from_device_id;
            $isCaller = ($fromDeviceId === $callerDeviceId);

            // Détermine le device de l'autre partie (celui qui doit être prévenu).
            $targetDevice = null;
            if ($isCaller) {
                // L'appelant annule → prévenir le destinataire (to_user_id).
                $targetDevice = Device::where('user_id', $offer->to_user_id)
                    ->where('device_id', '!=', $fromDeviceId)
                    ->first();
            } else {
                // Le destinataire refuse → prévenir l'appelant (from_device_id).
                $targetDevice = Device::where('device_id', $callerDeviceId)->first();
            }

            if ($targetDevice === null) {
                return;
            }

            $pushType = $isCaller ? 'cancel' : 'reject';

            Log::info('CallSignal bye → push ' . $pushType, [
                'call_id'        => $callId,
                'from_device_id' => $fromDeviceId,
                'to_device_id'   => $targetDevice->device_id,
            ]);

            $data = $targetDevice->makeVisible(['fcm_token', 'web_push_subscription'])->toArray();

            // Nom de l'appelant : depuis le device appelant (via son user).
            $callerName = 'Inconnu';
            $callerDevice = Device::where('device_id', $callerDeviceId)->first();
            if ($callerDevice?->user_id) {
                $callerName = \App\Models\User::where('id', $callerDevice->user_id)->value('name') ?? 'Inconnu';
            }

            // Push différée après la réponse HTTP : ne bloque pas le signal.
            // La closure est protégée par try/catch interne : une exception
            // levée pendant l'exécution (dans Kernel::terminate, hors du
            // try/catch de notifyCallEnd) ne doit pas remonter en erreur PHP.
            $pushPayload = [
                'type'           => $pushType,
                'call_id'        => $callId,
                'from_device_id' => $fromDeviceId,
                'from_username'  => $callerName,
            ];
            dispatch(function () use ($data, $pushPayload) {
                try {
                    app(PushService::class)->notifyDevice($data, $pushPayload);
                } catch (\Throwable $e) {
                    Log::warning('Failed to notify call end (afterResponse)', [
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            Log::warning('Failed to notify call end (reject/cancel)', [
                'error'   => $e->getMessage(),
                'call_id' => $callId,
            ]);
        }
    }

    /**
     * Résout la liste des device_id cibles pour la diffusion du signal.
     *
     *  - to_user_id présent → tous les devices de cet utilisateur ;
     *  - sinon to_device_id exact (rétrocompatibilité device-to-device).
     */
    protected function resolveTargetDeviceIds(array $validated): array
    {
        $deviceIds = [];

        if (!empty($validated['to_user_id'])) {
            $userDevices = Device::where('user_id', $validated['to_user_id'])
                ->pluck('device_id')
                ->map(fn ($d) => (string) $d)
                ->all();
            $deviceIds = array_merge($deviceIds, $userDevices);
        }

        if (!empty($validated['to_device_id'])) {
            $deviceIds[] = (string) $validated['to_device_id'];
        }

        // Si le signal est un bye et qu'un call_id est présent, retrouver l'autre partie
        // dans call_offers pour garantir la coupure même si le device de l'autre était inconnu
        if ($validated['type'] === 'bye' && !empty($validated['call_id'])) {
            $offer = DB::table('call_offers')->where('call_id', $validated['call_id'])->first();
            if ($offer) {
                $fromDeviceId = (string) $validated['from_device_id'];
                if ($fromDeviceId === (string) $offer->from_device_id) {
                    // C'est l'appelant qui raccroche : diffuser à l'appelé (to_device_id ou to_user_id)
                    if (!empty($offer->to_device_id)) {
                        $deviceIds[] = (string) $offer->to_device_id;
                    }
                    if (!empty($offer->to_user_id)) {
                        $targetUserDevices = Device::where('user_id', $offer->to_user_id)
                            ->pluck('device_id')
                            ->map(fn ($d) => (string) $d)
                            ->all();
                        $deviceIds = array_merge($deviceIds, $targetUserDevices);
                    }
                } else {
                    // C'est l'appelé qui raccroche ou refuse : diffuser à l'appelant
                    $deviceIds[] = (string) $offer->from_device_id;
                }
            }
        }

        // Ne jamais renvoyer à soi-même et dédupliquer strictement
        $senderDeviceId = (string) $validated['from_device_id'];
        return array_values(array_unique(array_filter($deviceIds, fn ($id) => !empty($id) && $id !== $senderDeviceId)));
    }

    /**
     * Gère le stockage / l'invalidation de l'offre SDP différée.
     *
     *  - type=offer + call_id + payload.sdp non vide → upsert (TTL 90 s)
     *  - type=bye + call_id → suppression (invalidation immédiate)
     *  - autres cas → aucun stockage
     *
     * L'offre est routée vers l'utilisateur cible (to_user_id) quand il est
     * fourni, ce qui permet à TOUS les appareils de cet utilisateur de la
     * récupérer (modèle multi-appareils). Sinon, elle reste routée vers le
     * to_device_id exact (rétrocompatibilité device-to-device).
     *
     * Toute erreur est attrapée et loggée en warning : le stockage ne doit
     * jamais faire échouer la requête de signaling.
     */
    protected function handleDeferredOffer(array $validated): void
    {
        $callId = $validated['call_id'] ?? null;

        if ($callId === null || $callId === '') {
            return;
        }

        try {
            if ($validated['type'] === 'bye') {
                DB::table('call_offers')
                    ->where('call_id', $callId)
                    ->delete();
                return;
            }

            if ($validated['type'] !== 'offer') {
                return;
            }

            $payload = $validated['payload'] ?? [];
            $sdp = $payload['sdp'] ?? null;

            // Les clients historiques envoient directement la chaîne SDP,
            // tandis que les frontends actuels préservent RTCSessionDescription
            // sous la forme {sdp: {type, sdp}}. Les deux formats doivent être
            // stockés afin que l'offre différée reste compatible.
            $sdpValue = is_array($sdp) ? ($sdp['sdp'] ?? null) : $sdp;
            if (! is_string($sdpValue) || $sdpValue === '') {
                return;
            }

            // Résout l'utilisateur cible : priorité à to_user_id, sinon on
            // remonte depuis le device cible (rétrocompatibilité).
            $toUserId = $validated['to_user_id'] ?? null;
            if ($toUserId === null && !empty($validated['to_device_id'])) {
                $device = Device::where('device_id', $validated['to_device_id'])->first();
                $toUserId = $device?->user_id;
            }

            $now = now();
            $expiresAt = $now->copy()->addSeconds(90);

            DB::table('call_offers')->updateOrInsert(
                ['call_id' => $callId],
                [
                    'to_device_id'   => $validated['to_device_id'] ?? null,
                    'to_user_id'     => $toUserId,
                    'from_device_id' => $validated['from_device_id'],
                    'sdp'            => json_encode($payload),
                    'expires_at'     => $expiresAt,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to store/invalidate deferred call offer', [
                'error'   => $e->getMessage(),
                'call_id' => $callId,
                'type'    => $validated['type'] ?? null,
            ]);
        }
    }
}
