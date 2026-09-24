<?php

return [
    'devise' => 'XOF',
    'devise_symbol' => 'Fcfa',

    // Taux de frais (en fraction du montant)
    'frais_transfert' => 0.10, // 10 %
    'frais_recharge' => 0.10,  // 10 %

    // Intégration CinetPay — pilotée par config.
    // Tant que CINETPAY_API_KEY / CINETPAY_SITE_ID sont vides, la recharge
    // génère une payment_url de checkout sans appel réel à CinetPay.
    'cinetpay' => [
        'api_key' => env('CINETPAY_API_KEY', ''),
        'site_id' => env('CINETPAY_SITE_ID', ''),
        'checkout_url' => 'https://secure.cinetpay.net/checkout/',
        'notify_url' => env('APP_URL', 'http://127.0.0.1:8000') . '/api/v1/wallet/recharge/notify',
    ],
];