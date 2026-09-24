<?php

/**
 * Configuration runtime Yam (exposée via GET /api/v1/config).
 *
 * Centralise les valeurs utilisées par ConfigController via config()
 * au lieu de env() directement, ce qui permet d'activer config:cache
 * en production sans casser le runtime.
 */
return [

    'app' => [
        'name' => env('APP_NAME', 'Yam'),
    ],

    'reverb' => [
        'app_key' => env('REVERB_APP_KEY', 'local'),
        'host' => env('REVERB_HOST'),
        'port' => (int) env('REVERB_PORT', 6001),
        'scheme' => env('REVERB_SCHEME', 'ws'),
    ],

    'turn' => [
        'url' => env('TURN_URL', 'turn:openrelay.metered.ca:80,turn:openrelay.metered.ca:443?transport=tcp,turns:openrelay.metered.ca:443?transport=tcp'),
        'username' => env('TURN_USERNAME', 'openrelay'),
        'credential' => env('TURN_CREDENTIAL', env('TURN_PASSWORD', 'openrelay')),
    ],

    'push' => [
        'enabled' => env('PUSH_ENABLED', false),
        // Service account Firebase (JSON brut ou base64) pour la prod
        // (secret serveur o2switch) — le fichier storage/firebase/ est gitignoré.
        'firebase_service_account_json' => env('FIREBASE_SERVICE_ACCOUNT_JSON', ''),
    ],

    'vapid' => [
        'public_key'  => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
        'subject'     => env('VAPID_SUBJECT', 'mailto:admin@yam.app'),
    ],

];