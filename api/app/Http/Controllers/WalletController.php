<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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