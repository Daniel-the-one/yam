<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class DemoApiController extends Controller
{
    public function wallet(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun solde réel.',
            'wallet' => [
                'wallet_id' => 'DEMO-WALLET-001',
                'solde' => '50 000 Fcfa',
                'solde_raw' => 50000,
                'devise' => 'XOF',
                'devise_symbol' => 'Fcfa',
                'is_default' => true,
            ],
            'membre' => [
                'nom' => 'Utilisateur Démo',
                'username' => 'demo-user',
                'email' => '',
                'kyc_valide' => false,
                'avatar' => '',
            ],
            'stats_mois' => [
                'periode' => 'Période de démonstration',
                'depenses' => '1 000 Fcfa',
                'depenses_raw' => 1000,
                'recus' => '2 000 Fcfa',
                'recus_raw' => 2000,
                'nb_transactions' => 3,
            ],
            'dernieres_transactions' => [
                [
                    'id' => 1,
                    'reference' => 'DEMO-TXN-001',
                    'type' => 'TRANSFER',
                    'type_name' => 'Transfert démo',
                    'amount' => '-1 000 Fcfa',
                    'amount_raw' => 1000,
                    'fees' => '100 Fcfa',
                    'devise' => 'XOF',
                    'status' => 2,
                    'status_show' => 'Démo',
                    'description' => 'Transaction fictive — aucun argent déplacé',
                    'date_transaction' => '30/09/2026',
                ],
            ],
            'epargnes' => [],
            'nb_epargnes' => 0,
            'assurances' => [],
            'nb_assurances' => 0,
        ]);
    }

    public function transactions(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'pagination' => [
                'current_page' => 1,
                'per_page' => 5,
                'total' => 2,
                'last_page' => 1,
            ],
            'transactions' => [
                '30/09/2026' => [
                    [
                        'id' => 1,
                        'reference' => 'DEMO-TXN-001',
                        'type' => 'TRANSFER',
                        'amount' => '-1 000 Fcfa',
                        'amount_raw' => 1000,
                        'status_show' => 'Démo',
                        'description' => 'Transaction fictive — aucun argent déplacé',
                    ],
                    [
                        'id' => 2,
                        'reference' => 'DEMO-TXN-002',
                        'type' => 'RECHARGE',
                        'amount' => '+2 000 Fcfa',
                        'amount_raw' => 2000,
                        'status_show' => 'Démo',
                        'description' => 'Recharge fictive — aucun paiement effectué',
                    ],
                ],
            ],
        ]);
    }

    public function recharge(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun paiement effectué.',
            'information' => [
                'reference' => 'DEMO-RECHARGE-001',
                'type_transaction_name' => 'Recharge démo',
                'status' => 1,
                'status_show' => 'Démo',
                'amount' => '+2 000 Fcfa',
                'fees' => '200 Fcfa',
                'total_amount' => '2 200 Fcfa',
                'devise' => 'XOF',
                'new_balance' => '-',
                'description' => 'Recharge fictive — aucun paiement effectué',
                'payment' => [
                    'mode' => 'demo',
                    'payment_token' => '',
                    'payment_url' => '',
                    'must_be_redirected' => false,
                ],
            ],
        ], 201);
    }

    public function transfert(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '000',
            'message' => 'Réponse de démonstration statique — aucun transfert effectué.',
            'information' => [
                'reference' => 'DEMO-TRANSFER-001',
                'status' => 2,
                'status_show' => 'Démo',
                'amount' => '-1 000 Fcfa',
                'fees' => '100 Fcfa',
                'new_balance' => '-',
                'devise' => 'XOF',
                'is_internal' => true,
                'description' => 'Transfert fictif — aucun argent déplacé',
                'destinataire' => [
                    'nom' => 'Destinataire Démo',
                    'wallet_id' => 'DEMO-WALLET-002',
                    'montant_recu' => '1 000 Fcfa',
                ],
            ],
        ], 201);
    }

    public function webhook(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'status' => '999',
            'credit' => false,
            'raison' => 'demo_only',
            'message' => 'Webhook de démonstration — aucune transaction vérifiée ni créditée.',
        ]);
    }

    public function initierAppel(): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Appel de démonstration — aucun appel ni facturation réelle.',
            'data' => [
                'appel_id' => 9001,
                'status' => 'initie',
                'tarif_par_minute' => 100,
            ],
        ], 201);
    }

    public function actionAppel(string $appel, string $action): JsonResponse
    {
        $statuts = [
            'lancer' => 'sonne',
            'decrocher' => 'decroche',
            'heartbeat' => 'decroche',
            'terminer' => 'termine',
        ];

        return response()->json([
            'demo' => true,
            'message' => 'Appel de démonstration — aucun appel ni facturation réelle.',
            'data' => [
                'appel_id' => ctype_digit($appel) ? (int) $appel : 9001,
                'status' => $statuts[$action],
                'solde_consomme' => 0,
                'duree_secondes' => 60,
                'tarif_par_minute' => 100,
                'raison_fin' => $action === 'terminer' ? 'demo' : null,
            ],
        ]);
    }

    public function afficherAppel(string $appel): JsonResponse
    {
        return response()->json([
            'demo' => true,
            'message' => 'Détail d’appel fictif — aucune facturation réelle.',
            'data' => [
                'appel_id' => ctype_digit($appel) ? (int) $appel : 9001,
                'status' => 'termine',
                'duree_secondes' => 60,
                'tarif_par_minute' => 100,
                'solde_consomme' => 0,
                'raison_fin' => 'demo',
            ],
        ]);
    }
}
