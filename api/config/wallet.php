<?php

return [
    'devise' => 'XOF',
    'devise_symbol' => 'Fcfa',

    // Taux de frais (en fraction du montant)
    'frais_transfert' => 0.10, // 10 %
    'frais_recharge' => 0.10,  // 10 %

    // Intégration CinetPay (Checkout API) — pilotée par l'environnement.
    //
    // TANT QUE LES DEUX IDENTIFIANTS SONT VIDES, le wallet reste en mode
    // simulation : la recharge crée une transaction « En cours » et NE renvoie
    // AUCUNE URL de paiement (inventer une URL de checkout qui n'existe pas
    // enverrait le client vers une 404 en lui affichant une confirmation).
    //
    // Le montant envoyé doit être un ENTIER MULTIPLE DE 5 (exigence CinetPay) :
    // WalletService l'arrondit au-dessus via CinetPayService::arrondirMontantCinetPay().
    'cinetpay' => [
        'api_key' => env('CINETPAY_API_KEY', ''),
        'site_id' => env('CINETPAY_SITE_ID', ''),

        // Checkout API : POST JSON https://api-checkout.cinetpay.com/v2/payment
        'init_url' => env('CINETPAY_INIT_URL', 'https://api-checkout.cinetpay.com/v2/payment'),

        'notify_url' => env(
            'CINETPAY_NOTIFY_URL',
            rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/') . '/api/v1/wallet/recharge/notify'
        ),
        'return_url' => env('CINETPAY_RETURN_URL', rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/') . '/'),

        'channels' => env('CINETPAY_CHANNELS', 'ALL'), // ALL | MOBILE_MONEY | CREDIT_CARD | WALLET
        'lang'     => env('CINETPAY_LANG', 'fr'),       // fr | en
        'timeout'  => 10,
    ],
];
