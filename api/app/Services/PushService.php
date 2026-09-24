<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi de notifications push pour réveiller les appareils hors application.
 *
 * Canal principal : Firebase Cloud Messaging (FCM) via l'API HTTP v1,
 * authentifié par le service account (fichier storage/firebase/service-account.json).
 * Fonctionne pour Android/iOS ET le web (token FCM via SDK Firebase Messaging).
 *
 * Fallback : Web Push natif (VAPID) si un device web n'a pas de token FCM.
 *
 * Si le service account est absent, l'envoi est simplement journalisé
 * (aucun crash) : le WebSocket reste le canal principal.
 */
class PushService
{
    /** Chemin du fichier service account Firebase (clé privée). */
    protected const SERVICE_ACCOUNT_PATH = __DIR__ . '/../../storage/firebase/service-account.json';

    /** Cache du token d'accès OAuth2 (valide ~1h). */
    protected ?string $accessToken = null;
    protected int $accessTokenExpiresAt = 0;

    /** Cache du service account parsé (évite double lecture par notification). */
    protected ?array $serviceAccountCache = null;

    /**
     * Envoie une notification push à un appareil ciblé.
     *
     * @param array $device  Ligne de la table `devices` (device_id, platform,
     *                       fcm_token, web_push_subscription, vapid_public_key).
     * @param array $payload Données de l'appel (call_id, from_device_id,
     *                       from_username, type).
     */
    public function notifyDevice(array $device, array $payload): void
    {
        if (!config('yam.push.enabled', false)) {
            Log::debug('[push] Push notifications désactivées (mode local ou PUSH_ENABLED=false)');
            return;
        }

        $platform = $device['platform'] ?? 'web';

        // FCM (Firebase Cloud Messaging) : mobile ET web (le client web
        // enregistre un token FCM via le SDK Firebase Messaging).
        if (!empty($device['fcm_token'])) {
            $this->sendFcm($device, $payload);
            return;
        }

        // Web Push natif (VAPID) : fallback si pas de token FCM.
        if ($platform === 'web') {
            $this->sendWebPush($device, $payload);
        }
    }

    /**
     * Envoie une notification FCM (Data message, priorité haute) via l'API v1.
     */
    protected function sendFcm(array $device, array $payload): void
    {
        $token = $device['fcm_token'] ?? null;
        if (empty($token)) {
            Log::debug('[push] Aucun token FCM pour ' . ($device['device_id'] ?? '?'));
            return;
        }

        $accessToken = $this->getAccessToken();
        if (empty($accessToken)) {
            Log::debug('[push] Service account Firebase absent, envoi FCM ignoré pour ' . $device['device_id']);
            return;
        }

        $projectId = $this->getProjectId();
        if (empty($projectId)) {
            Log::debug('[push] project_id Firebase absent');
            return;
        }

        try {
            $response = Http::timeout(2)->withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'data'  => [
                        'type'           => (string) ($payload['type'] ?? 'incoming'),
                        'call_id'        => (string) $payload['call_id'],
                        'from_device_id' => (string) $payload['from_device_id'],
                        'from_username'  => (string) $payload['from_username'],
                        'from_user_id'   => (string) ($payload['from_user_id'] ?? ''),
                        'media'          => (string) ($payload['media'] ?? 'audio'),
                        'click_action'   => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                    'android' => [
                        'priority' => 'high',
                    ],
                    'webpush' => [
                        'headers' => [
                            'TTL' => '86400',
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                // Ne pas logger le body complet (peut contenir des détails
                // de config Firebase / tokens) — juste le statut et l'erreur.
                $error = $response->json('error.message') ?? $response->json('error.status') ?? 'inconnue';
                Log::warning('[push] FCM v1 échec HTTP ' . $response->status() . ' : ' . $error);
            } else {
                Log::info('[push] FCM v1 envoyé à ' . $device['device_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[push] FCM v1 exception : ' . $e->getMessage());
        }
    }

    /**
     * Génère (ou réutilise) un token d'accès OAuth2 à partir du service account.
     */
    protected function getAccessToken(): ?string
    {
        // Réutilise le token si encore valide (marge de 60 s).
        if ($this->accessToken && time() < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $sa = $this->loadServiceAccount();
        if ($sa === null) {
            return null;
        }

        try {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss'   => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => $sa['token_uri'],
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));

            $unsignedToken = $header . '.' . $claims;

            // Signe le JWT avec la clé privée RSA du service account.
            $signature = '';
            openssl_sign($unsignedToken, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);

            $jwt = $unsignedToken . '.' . $this->base64Url($signature);

            // Échange le JWT contre un access token.
            $response = Http::timeout(2)->asForm()->post($sa['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if (!$response->successful()) {
                Log::warning('[push] Échec obtention access token : ' . $response->body());
                return null;
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'] ?? null;
            $this->accessTokenExpiresAt = $now + (int) ($data['expires_in'] ?? 3600);

            return $this->accessToken;
        } catch (\Throwable $e) {
            Log::warning('[push] Exception obtention access token : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Charge et parse le fichier service account (avec cache).
     *
     * Sources, dans l'ordre :
     *  1. Config `yam.push.firebase_service_account_json` (JSON brut ou
     *     base64) — utilisée en production (secret serveur o2switch) car le
     *     fichier storage/firebase/ est gitignoré et donc absent du déploiement.
     *     ⚠️ Toujours via config() et jamais env() : env() retourne null
     *     quand le config est caché (php artisan config:cache en prod).
     *  2. Fichier local storage/firebase/service-account.json (développement).
     */
    protected function loadServiceAccount(): ?array
    {
        if ($this->serviceAccountCache !== null) {
            return $this->serviceAccountCache;
        }

        // 1. Variable d'environnement via config (déploiement serveur o2switch).
        $envJson = config('yam.push.firebase_service_account_json', '');
        if (!empty($envJson)) {
            // Supporte le base64 (plus sûr pour les retours à la ligne dans les secrets).
            $decoded = base64_decode($envJson, true);
            if ($decoded !== false && str_contains($decoded, 'private_key')) {
                $envJson = $decoded;
            }

            $sa = json_decode($envJson, true);
            if (is_array($sa) && !empty($sa['client_email']) && !empty($sa['private_key'])) {
                return $this->serviceAccountCache = $sa;
            }

            Log::warning('[push] FIREBASE_SERVICE_ACCOUNT_JSON invalide');
            return $this->serviceAccountCache = null;
        }

        // 2. Fichier local (développement).
        if (!file_exists(self::SERVICE_ACCOUNT_PATH)) {
            return $this->serviceAccountCache = null;
        }

        $json = file_get_contents(self::SERVICE_ACCOUNT_PATH);
        $sa = json_decode($json, true);

        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            Log::warning('[push] Service account Firebase invalide');
            return $this->serviceAccountCache = null;
        }

        return $this->serviceAccountCache = $sa;
    }

    /**
     * Retourne le project_id Firebase.
     */
    protected function getProjectId(): ?string
    {
        $sa = $this->loadServiceAccount();
        return $sa['project_id'] ?? null;
    }

    /**
     * Envoie une notification Web Push (VAPID) à un navigateur.
     */
    protected function sendWebPush(array $device, array $payload): void
    {
        $subscriptionJson = $device['web_push_subscription'] ?? null;
        if (empty($subscriptionJson)) {
            Log::debug('[push] Aucune subscription Web Push pour ' . ($device['device_id'] ?? '?'));
            return;
        }

        $subscription = json_decode($subscriptionJson, true);
        if (!is_array($subscription) || empty($subscription['endpoint'])) {
            Log::debug('[push] Subscription Web Push invalide pour ' . $device['device_id']);
            return;
        }

        $vapidPublic  = config('yam.vapid.public_key', '');
        $vapidPrivate = config('yam.vapid.private_key', '');
        if (empty($vapidPublic) || empty($vapidPrivate)) {
            Log::debug('[push] Clés VAPID non configurées, envoi ignoré pour ' . $device['device_id']);
            return;
        }

        try {
            $auth = $this->buildVapidAuth(
                $subscription['endpoint'],
                $vapidPublic,
                $vapidPrivate,
                config('yam.vapid.subject', 'mailto:admin@yam.app')
            );

            // Le chiffrement RFC 8291 n'est pas implémenté (stub) : envoyer
            // un corps vide ferait rejeter la requête par le push service.
            // On journalise et on abandonne plutôt que d'envoyer du vide.
            $encrypted = $this->encryptPayload($subscription, json_encode([
                'type'           => $payload['type'] ?? 'incoming',
                'call_id'        => $payload['call_id'],
                'from_device_id' => $payload['from_device_id'],
                'from_username'  => $payload['from_username'],
                'media'          => $payload['media'] ?? 'audio',
            ]));
            if ($encrypted === '') {
                Log::debug('[push] Web Push ignoré : chiffrement RFC 8291 non implémenté pour ' . $device['device_id']);
                return;
            }

            $response = Http::timeout(2)->withHeaders([
                'Authorization' => 'WebPush ' . $auth,
                'TTL'           => '86400',
                'Content-Type'  => 'application/octet-stream',
            ])->withBody(
                $encrypted,
                'application/octet-stream'
            )->post($subscription['endpoint']);

            if (!$response->successful()) {
                Log::warning('[push] Web Push échec HTTP ' . $response->status() . ' : ' . $response->body());
            } else {
                Log::info('[push] Web Push envoyé à ' . $device['device_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('[push] Web Push exception : ' . $e->getMessage());
        }
    }

    /**
     * Construit l'en-tête Authorization VAPID (JWT + signature ECDSA P-256).
     */
    protected function buildVapidAuth(string $endpoint, string $publicKey, string $privateKey, string $subject): string
    {
        $header = $this->base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $now = time();
        $payload = $this->base64Url(json_encode([
            'aud' => parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST),
            'exp' => $now + 12 * 3600,
            'sub' => $subject,
        ]));

        $unsignedToken = $header . '.' . $payload;

        // Signature ECDSA P-256 (SHA-256) avec la clé privée VAPID.
        $signature = '';
        openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $unsignedToken . '.' . $this->base64Url($signature);
    }

    /**
     * Chiffre le payload Web Push (RFC 8291) — implémentation minimale.
     * Pour une implémentation complète, utiliser une librairie dédiée
     * (ex. minishlink/web-push). Ici on journalise l'échec.
     */
    protected function encryptPayload(array $subscription, string $payload): string
    {
        Log::debug('[push] Chiffrement Web Push non implémenté (utiliser minishlink/web-push). Payload ignoré.');
        return '';
    }

    protected function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
