<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     *
     * Crée un compte utilisateur identifié par numéro de téléphone,
     * enregistre le premier appareil et retourne un token Sanctum.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number'          => ['required', 'string', 'max:30', 'regex:/[0-9]/'],
            'password'              => 'required|string|min:8',
            'name'                  => ['required', 'string', 'min:1', 'max:50', 'regex:/^[^<>&"\'`]+$/'],
            'username'              => 'nullable|string|min:2|max:30|unique:users,username',
            'device_id'             => 'required|string|max:100',
            'platform'              => 'nullable|string|in:web,ios,android',
            'label'                 => 'nullable|string|max:60',
            'fcm_token'             => 'nullable|string|max:500',
            'web_push_subscription' => 'nullable|array',
            'vapid_public_key'      => 'nullable|string|max:200',
        ]);

        // Normalise le numéro en E.164 (indicatif +228 par défaut) pour que
        // web et mobile utilisent toujours le même format en base.
        $validated['phone_number'] = $this->normalizePhone($validated['phone_number']);

        // Vérifie l'unicité APRÈS normalisation (sinon +22870023786 et
        // 70023786 seraient deux comptes distincts).
        if (User::where('phone_number', $validated['phone_number'])->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => ['Ce numéro de téléphone est déjà utilisé.'],
            ]);
        }

        $platform = $validated['platform'] ?? 'web';
        $label = $validated['label'] ?? $this->defaultLabel($platform);

        // Username : si non fourni ou déjà pris, on génère un suffixe unique
        // pour éviter la violation de contrainte unique (erreur 500).
        $username = $validated['username'] ?? null;
        if ($username === null || $username === '') {
            $username = $validated['name'];
        }
        if (User::where('username', $username)->exists()) {
            $username = $username . '-' . Str::lower(Str::random(5));
        }

        $user = User::create([
            'name'           => $validated['name'],
            'username'       => $username,
            'phone_number'   => $validated['phone_number'],
            'phone_verified' => false,
            'role'           => 'patient',
            'password'       => Hash::make($validated['password']),
            'device_id'      => $validated['device_id'],
            'platform'       => $platform,
            'is_online'      => true,
        ]);

        $device = $this->upsertDevice($user, $validated, $platform, $label);

        $token = $user->createToken($label)->plainTextToken;

        return response()->json([
            'data' => [
                'token'  => $token,
                'user'   => $this->userPayload($user),
                'device' => $this->devicePayload($device),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     *
     * Connecte un utilisateur par numéro de téléphone + mot de passe.
     * Retourne un nouveau token Sanctum et enregistre/met à jour le device.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number'          => 'required|string|max:30',
            'password'              => 'required|string',
            'device_id'             => 'nullable|string|max:100',
            'platform'              => 'nullable|string|in:web,ios,android',
            'label'                 => 'nullable|string|max:60',
            'fcm_token'             => 'nullable|string|max:500',
            'web_push_subscription' => 'nullable|array',
            'vapid_public_key'      => 'nullable|string|max:200',
        ]);

        // Normalise le numéro pour matcher le format stocké en base (E.164).
        $validated['phone_number'] = $this->normalizePhone($validated['phone_number']);

        $user = User::where('phone_number', $validated['phone_number'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone_number' => ['Identifiants incorrects.'],
            ]);
        }

        $device = null;

        // Si un device_id est fourni, l'enregistrer/met à jour pour ce user.
        if (!empty($validated['device_id'])) {
            $platform = $validated['platform'] ?? 'web';
            $label = $validated['label'] ?? $this->defaultLabel($platform);
            $device = $this->upsertDevice($user, $validated, $platform, $label);
        }

        $user->update(['is_online' => true]);

        $token = $user->createToken($label ?? 'web')->plainTextToken;

        return response()->json([
            'data' => [
                'token'  => $token,
                'user'   => $this->userPayload($user),
                'device' => $device ? $this->devicePayload($device) : null,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Révoque le token Sanctum courant (déconnexion de l'appareil actuel).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * GET /api/v1/users/me
     *
     * Retourne le profil de l'utilisateur authentifié.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                ...$this->userPayload($user),
                'devices_count' => $user->devices()->count(),
            ],
        ]);
    }

    /**
     * Crée ou met à jour un device pour un user (upsert par device_id).
     */
    protected function upsertDevice(User $user, array $validated, string $platform, string $label): Device
    {
        $webPush = $validated['web_push_subscription'] ?? null;

        // Élimine les doublons de devices web pour le même utilisateur :
        // un utilisateur sur le web n'a besoin que de son device actif actuel
        if ($platform === 'web') {
            Device::where('user_id', $user->id)
                ->where('platform', 'web')
                ->where('device_id', '!=', $validated['device_id'])
                ->delete();
        }

        return Device::updateOrCreate(
            ['device_id' => $validated['device_id']],
            [
                'user_id'               => $user->id,
                'label'                 => $label,
                'platform'              => $platform,
                'fcm_token'             => $validated['fcm_token'] ?? null,
                'web_push_subscription' => $webPush !== null ? json_encode($webPush) : null,
                'vapid_public_key'      => $validated['vapid_public_key'] ?? null,
                'last_seen_at'          => now(),
            ]
        );
    }

    protected function defaultLabel(string $platform): string
    {
        return match ($platform) {
            'ios'     => 'iPhone',
            'android' => 'Android',
            default   => 'Web',
        };
    }

    protected function userPayload(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'username'       => $user->username,
            'phone_number'   => $user->phone_number,
            'phone_verified' => (bool) $user->phone_verified,
            'role'           => $user->role,
        ];
    }

    protected function devicePayload(Device $device): array
    {
        return [
            'id'          => $device->id,
            'device_id'   => $device->device_id,
            'label'       => $device->label,
            'platform'    => $device->platform,
            'last_seen_at'=> $device->last_seen_at,
        ];
    }

    /**
     * Normalise un numéro de téléphone en format E.164.
     *
     * Règles :
     *  - retire espaces, tirets, parenthèses, points ;
     *  - "+22870023786" → "+22870023786" (déjà E.164) ;
     *  - "0022870023786" → "+22870023786" (00 → +) ;
     *  - "70023786" → "+22870023786" (ajoute l'indicatif +228 par défaut).
     *
     * @param string $phone
     * @return string
     */
    protected function normalizePhone(string $phone): string
    {
        // Ne garde que les chiffres et le '+' éventuel.
        $clean = preg_replace('/[^\d+]/', '', $phone) ?? '';

        // Numéro invalide (aucun chiffre) : on refuse proprement.
        if ($clean === '') {
            throw ValidationException::withMessages([
                'phone_number' => ['Numéro de téléphone invalide.'],
            ]);
        }

        // Déjà au format international avec '+'.
        if (str_starts_with($clean, '+')) {
            return $clean;
        }

        // Format international avec préfixe "00" (ex: 00228...).
        if (str_starts_with($clean, '00')) {
            return '+' . substr($clean, 2);
        }

        // Numéro local sans indicatif → on ajoute +228 (Togo).
        return '+228' . $clean;
    }
}
