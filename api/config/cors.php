<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    |
    | Here you may add headers to the exposed set that you let the user agent
    | access when it responds to a CORS request.
    |
    */

    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in seconds) the browser may cache the
    | CORS preflight (OPTIONS) response. La valeur par défaut du framework
    | (0) force un préflight OPTIONS avant CHAQUE requête cross-origin, ce
    | qui double le nombre de requêtes pour le web. 86400 = 24 h : le
    | navigateur ne renvoie plus de préflight pour les requêtes suivantes.
    |
    */

    'max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | This specifies whether the response to the request can be exposed when
    | the credentials mode is set to "include". If you are using Sanctum
    | with the SPA authentication flow, you may need to set this to true.
    |
    */

    'supports_credentials' => false,

];