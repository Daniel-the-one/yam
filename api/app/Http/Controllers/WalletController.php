<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\CinetPayService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    /**
     * GET /api/v1/wallet
     *
     * Récupère le wallet de l'utilisateur connecté : solde, infos membre,
     * stats du mois, dernières transactions, épargnes et assurances.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(
            app(WalletService::class)->getWalletData($request->user())
        );
    }

    /**
     * POST /api/v1/wallet/recharge
     *
     * Initie une recharge via Mobile Money (CinetPay). Crée une transaction
     * "En cours" et retourne la payment_url vers laquelle rediriger
     * l'utilisateur. Le solde n'est crédité qu'à la confirmation CinetPay.
     */
    public function recharge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|numeric|min:100|max:1000000',
            'phone_number' => 'required|string|max:20',
        ]);

        $data = app(WalletService::class)->initierRecharge(
            $request->user(),
            (float) $validated['montant'],
            $validated['phone_number'],
        );

        return response()->json($data, 201);
    }

    /**
     * POST /api/v1/wallet/transfert
     *
     * Transfère des fonds vers un wallet interne (destinataire_wallet_id) ou
     * un numéro Mobile Money (phone_number).
     */
    public function transfert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|numeric|min:1|max:1000000',
            'destinataire_wallet_id' => 'nullable|string|max:32',
            'phone_number' => 'nullable|string|max:20',
        ]);

        if (empty($validated['destinataire_wallet_id']) && empty($validated['phone_number'])) {
            return response()->json([
                'error' => [
                    'code' => 'destinataire_requis',
                    'message' => 'Un destinataire (destinataire_wallet_id ou phone_number) est requis.',
                ],
            ], 422);
        }

        $data = app(WalletService::class)->effectuerTransfert(
            $request->user(),
            (float) $validated['montant'],
            $validated['destinataire_wallet_id'] ?? null,
            $validated['phone_number'] ?? null,
        );

        return response()->json($data, 201);
    }

    /**
     * POST /api/v1/wallet/recharge/notify
     *
     * Webhook de notification CinetPay (AUCUNE authentification utilisateur :
     * l'appel vient de CinetPay, pas du client). La sécurité ne repose donc
     * PAS sur le statut HTTP de l'appelant, mais sur des VÉRIFICATIONS.
     *
     * Règle : on ne crédite jamais un solde sur la foi du corps de la requête.
     * 1) la transaction doit exister en base, en attente, et rattachée au
     *    prestataire ;
     * 2) le montant crédité est celui de la base, pas celui annoncé ;
     * 3) le site_id et le montant, s'ils sont présents, doivent correspondre ;
     * 4) l'opération est idempotente (verrou de ligne) donc un rejeu est sans
     *    effet.
     *
     * Réponse 200 dans tous les cas traitables : CinetPay retente en cas
     * d'erreur HTTP, et un rejeu d'un payload définitivement invalide
     * provoquerait une tempête de notifications. Les rejets sont journalisés.
     */
    public function notifyRecharge(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Journalise la STRUCTURE des clés reçues (jamais les valeurs) : permet
        // d'aligner le contrat sans exposer de donnée sensible dans les logs.
        Log::info('CinetPay notification reçue', ['cles' => array_keys($payload)]);

        $transactionId = (string) ($payload['transaction_id'] ?? $payload['merchant_transaction_id'] ?? '');

        if ($transactionId === '') {
            Log::warning('CinetPay notification sans transaction_id', ['cles' => array_keys($payload)]);

            return $this->reponseWebhook(false, 'transaction_id_manquant');
        }

        $recharge = Transaction::where('provider_transaction_id', $transactionId)
            ->where('type', Transaction::TYPE_TOPUP)
            ->first();

        if (! $recharge) {
            Log::warning('CinetPay notification inconnue', ['transaction_id' => $transactionId]);

            return $this->reponseWebhook(false, 'recharge_introuvable');
        }

        // site_id : s'il est fourni, il doit être le nôtre.
        $siteIdRecu = $payload['site_id'] ?? null;
        if ($siteIdRecu !== null && (string) $siteIdRecu !== (string) config('wallet.cinetpay.site_id')) {
            Log::warning('CinetPay notification : site_id inattendu', [
                'transaction_id' => $transactionId,
            ]);

            return $this->reponseWebhook(false, 'site_id_invalide');
        }

        // Montant attendu : total arrondi au multiple de 5 (comme à l'init).
        $montantAttendu = CinetPayService::arrondirMontantCinetPay(
            (float) $recharge->amount_raw + (float) $recharge->fees_raw
        );
        if (isset($payload['amount']) && (int) $payload['amount'] !== $montantAttendu) {
            Log::warning('CinetPay notification : montant inattendu', [
                'transaction_id' => $transactionId,
                'recu' => (int) $payload['amount'],
                'attendu' => $montantAttendu,
            ]);

            return $this->reponseWebhook(false, 'montant_invalide');
        }

        $reussi = $this->notificationDeclareeReussie($payload);

        if (! $reussi) {
            app(WalletService::class)->echouerRecharge($transactionId);

            return $this->reponseWebhook(false, 'paiement_non_confirme');
        }

        $resultat = app(WalletService::class)->confirmerRecharge($transactionId);

        return $this->reponseWebhook($resultat['credit'], $resultat['raison']);
    }

    /**
     * La notification déclare-t-elle un paiement RÉUSSI ?
     *
     * Faute de contrat de notification vérifiable, on accepte les conventions
     * connues du Checkout API : `code` à 200, ou `status` valant SUCCESS /
     * SUCCEEDED / completed. Toute autre valeur (ou l'absence des deux clés)
     * est traitée comme un échec — jamais comme un succès.
     */
    private function notificationDeclareeReussie(array $payload): bool
    {
        if (isset($payload['code'])) {
            return (int) $payload['code'] === 200;
        }

        if (isset($payload['status'])) {
            return in_array(
                strtoupper((string) $payload['status']),
                ['SUCCESS', 'SUCCEEDED', 'SUCCESSFUL', 'COMPLETED', '200'],
                true
            );
        }

        return false;
    }

    private function reponseWebhook(bool $credit, string $raison): JsonResponse
    {
        return response()->json([
            'status' => $credit ? '000' : '999',
            'credit' => $credit,
            'raison' => $raison,
        ]);
    }

    /**
     * GET /api/v1/wallet/transactions
     *
     * Liste paginée des transactions du wallet, groupées par date.
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $data = app(WalletService::class)->listTransactions(
            $request->user(),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['limit'] ?? 20),
        );

        return response()->json($data);
    }
}