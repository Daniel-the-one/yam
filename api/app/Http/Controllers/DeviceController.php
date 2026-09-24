<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registre d'appareils — rend les identifiants et contacts stables
 * même quand le domaine change (tunnels jetables trycloudflare).
 *
 * GET  /api/v1/devices            → liste des appareils connus
 * POST /api/v1/devices/register   → enregistre/met à jour un appareil (upsert par label)
 * DELETE /api/v1/devices/{id}     → supprime un appareil par device_id
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('devices')->orderByDesc('last_seen_at');

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $devices = $query->get(['id', 'label', 'device_id', 'platform', 'last_seen_at']);

        return response()->json(['devices' => $devices]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label'                 => 'required|string|min:1|max:60',
            'device_id'             => 'required|string|max:100',
            'platform'              => 'nullable|string|in:web,ios,android',
            'fcm_token'             => 'nullable|string|max:500',
            'web_push_subscription' => 'nullable|array',
            'vapid_public_key'      => 'nullable|string|max:200',
        ]);

        $user = $request->user();
        $userId = $user?->id;
        $platform = $validated['platform'] ?? 'web';

        // Élimine les doublons de devices web pour le même compte utilisateur
        if ($userId && $platform === 'web') {
            DB::table('devices')
                ->where('user_id', $userId)
                ->where('platform', 'web')
                ->where('device_id', '!=', $validated['device_id'])
                ->delete();
        }

        // La subscription Web Push est stockée en JSON (objet navigateur).
        $webPush = $validated['web_push_subscription'] ?? null;

        DB::table('devices')->updateOrInsert(
            ['device_id' => $validated['device_id']],
            [
                'user_id'               => $userId,
                'label'                 => $validated['label'],
                'platform'              => $platform,
                'fcm_token'             => $validated['fcm_token'] ?? null,
                'web_push_subscription' => $webPush !== null ? json_encode($webPush) : null,
                'vapid_public_key'      => $validated['vapid_public_key'] ?? null,
                'last_seen_at'          => now(),
                'updated_at'            => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('devices')->where('device_id', $deviceId);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $query->delete();
        return response()->json(['ok' => true]);
    }
}
