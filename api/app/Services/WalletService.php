<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Service métier du wallet (API externe).
 *
 * Le wallet est DÉRIVÉ de l'utilisateur : users.solde reste la source unique
 * de vérité (utilisée par AppelController pour la facturation des appels).
 * wallet_id / key_wallet sont générés à la première consultation.
 *
 * Formats de réponse alignés sur les fichiers de référence :
 *   api/api externe/recup_wallet.json
 *   api/api externe/recharge.json
 *   api/api externe/transfert.json
 *   api/api externe/listes des transactions.json
 */
class WalletService
{
    // ---------------------------------------------------------------
    // Wallet
    // ---------------------------------------------------------------

    /**
     * Garantit que l'utilisateur possède un wallet (wallet_id + key_wallet).
     */
    public function ensureWallet(User $user): User
    {
        if ($user->wallet_id && $user->key_wallet) {
            return $user;
        }

        $user->wallet_id = $this->genererWalletId();
        $user->key_wallet = Str::random(32);
        $user->save();

        return $user->fresh();
    }

    /**
     * Données complètes du wallet (GET /api/v1/wallet).
     */
    public function getWalletData(User $user): array
    {
        $user = $this->ensureWallet($user);

        $dernieres = Transaction::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $t) => $this->formatTransaction($t))
            ->values()
            ->all();

        return [
            'status' => '000',
            'message' => 'Opération effectuée avec succès',
            'wallet' => $this->formatWallet($user),
            'membre' => [
                'nom' => $user->name,
                'username' => $user->username,
                'email' => $user->email ?? '',
                'kyc_valide' => 0,
                'avatar' => '',
            ],
            'stats_mois' => $this->statsMois($user),
            'dernieres_transactions' => $dernieres,
            'epargnes' => [],
            'nb_epargnes' => 0,
            'assurances' => [],
            'nb_assurances' => 0,
        ];
    }

    /**
     * Liste paginée des transactions groupées par date (GET /api/v1/wallet/transactions).
     */
    public function listTransactions(User $user, int $page = 1, int $limit = 20): array
    {
        $user = $this->ensureWallet($user);
        $page = max($page, 1);
        $limit = min(max($limit, 1), 100);

        $query = Transaction::where('user_id', $user->id);
        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $limit));

        $items = (clone $query)
            ->orderByDesc('id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $grouped = [];
        foreach ($items as $t) {
            $date = $t->date_transaction->format('d/m/Y');
            $grouped[$date][] = $this->formatTransaction($t);
        }

        return [
            'status' => '000',
            'message' => 'Opération effectuée avec succès',
            'wallet' => [
                'wallet_id' => $user->wallet_id,
                'solde' => $this->formatMontant((float) $user->solde) . ' ' . $this->deviseSymbol(),
                'solde_raw' => (int) round((float) $user->solde),
                'devise' => config('wallet.devise'),
                'devise_symbol' => $this->deviseSymbol(),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
            ],
            'transactions' => $grouped,
        ];
    }

    // ---------------------------------------------------------------
    // Recharge (initiation — confirmation par webhook CinetPay ensuite)
    // ---------------------------------------------------------------

    /**
     * Initie une recharge via Mobile Money (POST /api/v1/wallet/recharge).
     *
     * Crée une transaction status=1 (En cours) SANS créditer le solde : le
     * crédit n'interviendra qu'à la confirmation CinetPay (webhook). Tant que
     * les clés CinetPay ne sont pas configurées, la payment_url est générée
     * localement (checkout CinetPay) sans appel réel.
     */
    public function initierRecharge(User $user, float $montant, string $phone): array
    {
        $user = $this->ensureWallet($user);

        $fees = round($montant * (float) config('wallet.frais_recharge'), 2);
        $total = $montant + $fees;
        $reference = $this->genererReference();
        $ressourceKey = Str::random(32);
        $paymentToken = bin2hex(random_bytes(32));
        $paymentUrl = config('wallet.cinetpay.checkout_url') . $paymentToken;

        $this->creerTransaction($user, [
            'reference' => $reference,
            'type' => Transaction::TYPE_TOPUP,
            'type_name' => 'Recharge',
            'sens' => Transaction::SENS_CREDIT,
            'amount_raw' => $montant,
            'fees_raw' => $fees,
            'new_balance_raw' => null, // créditée seulement après confirmation
            'status' => Transaction::STATUS_EN_COURS,
            'description' => 'Recharge de ' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol() . ' via Mobile Money',
            'counterpart_type' => Transaction::COUNTERPART_EXTERNAL,
            'counterpart_label' => 'Recharge via ' . $phone,
            'counterpart_wallet_id' => null,
            'counterpart_nom' => 'Mobile Money',
        ]);

        return [
            'status' => '000',
            'message' => 'Recharge initiée avec succès',
            'information' => [
                'type_transaction' => 2,
                'type_transaction_name' => 'Recharge',
                'type_transaction_name_icon' => '',
                'ressource_key' => $ressourceKey,
                'reference' => $reference,
                'wallet_id' => $user->wallet_id,
                'status' => Transaction::STATUS_EN_COURS,
                'status_show' => 'En cours',
                'status_color' => 'CB9000',
                'amount' => '+' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                'fees' => $this->formatMontant($fees) . ' ' . $this->deviseSymbol(),
                'total_amount' => $this->formatMontant($total) . ' ' . $this->deviseSymbol(),
                'devise' => config('wallet.devise'),
                'devise_symbol' => $this->deviseSymbol(),
                'new_balance' => '-',
                'description' => 'Recharge de ' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol() . ' via Mobile Money',
                'bill_url' => '',
                'phone_number' => $phone,
                'add_information' => '',
                'is_third_party' => false,
                'date_transaction' => now()->format('d/m/Y'),
                'heure_transaction' => now()->format('H:i'),
                'montant_affiche' => $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                'devise_affiche' => config('wallet.devise'),
                'symbole_affiche' => $this->deviseSymbol(),
                'payment' => [
                    'payment_token' => $paymentToken,
                    'payment_url' => $paymentUrl,
                    'must_be_redirected' => true,
                ],
                'destinataire' => [
                    'nom' => '',
                    'wallet_id' => '',
                    'devise' => '',
                    'symbole' => '',
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Transfert
    // ---------------------------------------------------------------

    /**
     * Effectue un transfert (POST /api/v1/wallet/transfert).
     *
     * - destinataire_wallet_id : transfert INTERNE (débit + crédit atomiques,
     *   statut immédiatement "Réussie").
     * - phone_number : transfert Mobile Money. Si le numéro appartient à un
     *   utilisateur enregistré → transfert interne. Sinon → transaction
     *   status=1 (En cours) SANS mouvement de fonds, en attente de l'API
     *   Mobile Money externe (pas de "machine à imprimer").
     */
    public function effectuerTransfert(User $user, float $montant, ?string $destinataireWalletId = null, ?string $phone = null): array
    {
        $user = $this->ensureWallet($user);

        $fees = round($montant * (float) config('wallet.frais_transfert'), 2);
        $totalDebit = $montant + $fees;

        // Déterminer le destinataire
        $destinataire = null;
        $isInternal = false;

        if ($destinataireWalletId) {
            $destinataire = User::where('wallet_id', $destinataireWalletId)->first();
            if (! $destinataire) {
                throw ValidationException::withMessages([
                    'destinataire_wallet_id' => 'Wallet destinataire introuvable.',
                ])->status(422);
            }
            if ($destinataire->id === $user->id) {
                throw ValidationException::withMessages([
                    'destinataire_wallet_id' => 'Impossible de se transférer à soi-même.',
                ])->status(422);
            }
            $isInternal = true;
        } elseif ($phone) {
            $destinataire = User::where('phone_number', $phone)->first();
            $isInternal = $destinataire !== null && $destinataire->id !== $user->id;
        }

        $reference = $this->genererReference();

        // Transfert interne : débit + crédit atomiques, statut "Réussie".
        if ($isInternal) {
            DB::transaction(function () use ($user, $destinataire, $montant, $fees, $totalDebit, $reference) {
                $sender = User::whereKey($user->id)->lockForUpdate()->first();

                // Contrôle du solde SOUS verrou (atomique avec le débit) : deux
                // demandes concurrentes ne peuvent pas passer toutes deux le
                // contrôle sur un solde identique avant le débit.
                if ((float) $sender->solde < $totalDebit) {
                    throw ValidationException::withMessages([
                        'montant' => 'Solde insuffisant pour effectuer ce transfert.',
                    ])->status(402);
                }

                $sender->solde = max(0.0, (float) $sender->solde - $totalDebit);
                $sender->save();

                $recipient = User::whereKey($destinataire->id)->lockForUpdate()->first();
                $recipient->solde = (float) $recipient->solde + $montant;
                $recipient->save();

                $this->creerTransaction($sender, [
                    'reference' => $reference,
                    'type' => Transaction::TYPE_TRANSFER,
                    'type_name' => 'Transfert',
                    'sens' => Transaction::SENS_DEBIT,
                    'amount_raw' => $montant,
                    'fees_raw' => $fees,
                    'new_balance_raw' => (float) $sender->solde,
                    'status' => Transaction::STATUS_REUSSIE,
                    'description' => 'Transfert de ' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol() . ' vers ' . $destinataire->name,
                    'counterpart_type' => Transaction::COUNTERPART_INTERNAL,
                    'counterpart_label' => $destinataire->name,
                    'counterpart_wallet_id' => $destinataire->wallet_id,
                    'counterpart_nom' => $destinataire->name,
                ]);

                $this->creerTransaction($recipient, [
                    // Chaque transaction porte sa propre référence (UNIQUE en base).
                    // Celle du crédit est dérivée de la référence de groupe pour
                    // préserver la traçabilité du binôme débit/crédit.
                    'reference' => $reference . '-C',
                    'type' => Transaction::TYPE_TRANSFER,
                    'type_name' => 'Transfert',
                    'sens' => Transaction::SENS_CREDIT,
                    'amount_raw' => $montant,
                    'fees_raw' => 0,
                    'new_balance_raw' => (float) $recipient->solde,
                    'status' => Transaction::STATUS_REUSSIE,
                    'description' => 'Transfert de ' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol() . ' vers ' . $recipient->name,
                    'counterpart_type' => Transaction::COUNTERPART_INTERNAL,
                    'counterpart_label' => $user->name,
                    'counterpart_wallet_id' => $user->wallet_id,
                    'counterpart_nom' => $user->name,
                ]);
            });

            $user->refresh();

            return [
                'status' => '000',
                'message' => 'Transfert effectué avec succès',
                'information' => [
                    'reference' => $reference,
                    'status' => Transaction::STATUS_REUSSIE,
                    'status_show' => 'Réussie',
                    'status_color' => '0E6E0E',
                    'amount' => '-' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                    'fees' => $this->formatMontant($fees) . ' ' . $this->deviseSymbol(),
                    'new_balance' => $this->formatMontant((float) $user->solde) . ' ' . $this->deviseSymbol(),
                    'devise' => config('wallet.devise'),
                    'is_internal' => 1,
                    'date_transaction' => now()->format('d/m/Y'),
                    'heure_transaction' => now()->format('H:i'),
                    'destinataire' => [
                        'nom' => $destinataire->name,
                        'wallet_id' => $destinataire->wallet_id,
                        'montant_recu' => $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                    ],
                ],
            ];
        }

        // Transfert Mobile Money : transaction "En cours", aucun mouvement de
        // fonds tant que l'API Mobile Money externe n'a pas confirmé.
        $this->creerTransaction($user, [
            'reference' => $reference,
            'type' => Transaction::TYPE_TRANSFER,
            'type_name' => 'Transfert',
            'sens' => Transaction::SENS_DEBIT,
            'amount_raw' => $montant,
            'fees_raw' => $fees,
            'new_balance_raw' => null,
            'status' => Transaction::STATUS_EN_COURS,
            'description' => 'Transfert de ' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol() . ' vers ' . $phone . ' via Mobile Money',
            'counterpart_type' => Transaction::COUNTERPART_MOBILE_MONEY,
            'counterpart_label' => 'Mobile Money — ' . $phone,
            'counterpart_wallet_id' => null,
            'counterpart_nom' => $phone,
        ]);

        return [
            'status' => '000',
            'message' => 'Transfert Mobile Money initié avec succès',
            'information' => [
                'reference' => $reference,
                'status' => Transaction::STATUS_EN_COURS,
                'status_show' => 'En cours',
                'status_color' => 'CB9000',
                'amount' => '-' . $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                'fees' => $this->formatMontant($fees) . ' ' . $this->deviseSymbol(),
                'new_balance' => $this->formatMontant((float) $user->solde) . ' ' . $this->deviseSymbol(),
                'devise' => config('wallet.devise'),
                'is_internal' => 0,
                'date_transaction' => now()->format('d/m/Y'),
                'heure_transaction' => now()->format('H:i'),
                'destinataire' => [
                    'nom' => $phone,
                    'wallet_id' => '',
                    'montant_recu' => $this->formatMontant($montant) . ' ' . $this->deviseSymbol(),
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Formate une transaction au format de l'API externe (champs dérivés
     * calculés à la volée : icônes, couleurs, statuts, montants formatés).
     */
    private function formatTransaction(Transaction $t): array
    {
        $sens = $t->sens;
        $signe = $sens === Transaction::SENS_CREDIT ? '+' : '-';
        $icone = $t->type === Transaction::TYPE_TOPUP
            ? 'wallet_topup'
            : ($sens === Transaction::SENS_CREDIT ? 'wallet_receive' : 'wallet_send');
        $couleur = $sens === Transaction::SENS_CREDIT ? '1DA971' : 'EE343B';
        $statut = $this->statutTransaction($t->status);

        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'type' => $t->type,
            'type_name' => $t->type_name,
            'type_icon' => $icone,
            'type_icon_color' => $couleur,
            'type_icon_bg_color' => $couleur . '26',
            'sens' => $sens,
            'amount' => $signe . $this->formatMontant((float) $t->amount_raw) . ' ' . $this->deviseSymbol(),
            'amount_raw' => (int) round((float) $t->amount_raw),
            'fees' => $this->formatMontant((float) $t->fees_raw) . ' ' . $this->deviseSymbol(),
            'devise' => $t->devise,
            'devise_symbol' => $this->deviseSymbol(),
            'new_balance' => $t->new_balance_raw !== null
                ? $this->formatMontant((float) $t->new_balance_raw) . ' ' . $this->deviseSymbol()
                : '',
            'status' => $t->status,
            'status_show' => $statut['show'],
            'status_color' => $statut['color'],
            'description' => $t->description,
            'counterpart' => [
                'type' => $t->counterpart_type,
                'label' => $t->counterpart_label,
                'wallet_id' => $t->counterpart_wallet_id ?? '',
                'nom' => $t->counterpart_nom,
            ],
            'date_transaction' => $t->date_transaction->format('d/m/Y'),
            'heure_transaction' => substr((string) $t->heure_transaction, 0, 5),
            'date_complete' => $t->date_complete
                ? $t->date_complete->format('d/m/Y H:i')
                : '',
        ];
    }

    private function formatWallet(User $user): array
    {
        return [
            'wallet_id' => $user->wallet_id,
            'key_wallet' => $user->key_wallet,
            'solde' => $this->formatMontant((float) $user->solde) . ' ' . $this->deviseSymbol(),
            'solde_raw' => (int) round((float) $user->solde),
            'devise' => config('wallet.devise'),
            'devise_symbol' => $this->deviseSymbol(),
            'devise_wallet' => config('wallet.devise'),
            'symbole_wallet' => $this->deviseSymbol(),
            'is_default' => 1,
            'date_create' => $user->created_at?->format('d/m/Y') ?? now()->format('d/m/Y'),
        ];
    }

    private function statsMois(User $user): array
    {
        $debut = now()->startOfMonth();
        $fin = now()->endOfMonth();

        $depenses = Transaction::where('user_id', $user->id)
            ->where('sens', Transaction::SENS_DEBIT)
            ->where('status', Transaction::STATUS_REUSSIE)
            ->whereBetween('created_at', [$debut, $fin])
            ->sum('amount_raw');

        $recus = Transaction::where('user_id', $user->id)
            ->where('sens', Transaction::SENS_CREDIT)
            ->where('status', Transaction::STATUS_REUSSIE)
            ->whereBetween('created_at', [$debut, $fin])
            ->sum('amount_raw');

        $nb = Transaction::where('user_id', $user->id)
            ->whereBetween('created_at', [$debut, $fin])
            ->count();

        return [
            'periode' => now()->format('F Y'),
            'depenses' => $this->formatMontant((float) $depenses) . ' ' . $this->deviseSymbol(),
            'depenses_raw' => (int) round((float) $depenses),
            'recus' => $this->formatMontant((float) $recus) . ' ' . $this->deviseSymbol(),
            'recus_raw' => (int) round((float) $recus),
            'nb_transactions' => $nb,
        ];
    }

    private function statutTransaction(int $status): array
    {
        return match ($status) {
            Transaction::STATUS_EN_COURS => ['show' => 'En cours', 'color' => 'CB9000'],
            Transaction::STATUS_REUSSIE => ['show' => 'Réussie', 'color' => '0E6E0E'],
            Transaction::STATUS_ECHOUEE => ['show' => 'Échouée', 'color' => 'E41927'],
            default => ['show' => 'Inconnu', 'color' => '000000'],
        };
    }

    private function creerTransaction(User $user, array $data): Transaction
    {
        $status = $data['status'] ?? Transaction::STATUS_EN_COURS;

        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'devise' => config('wallet.devise'),
            'date_transaction' => now()->toDateString(),
            'heure_transaction' => now()->format('H:i:s'),
            'date_complete' => $status === Transaction::STATUS_REUSSIE ? now() : null,
        ], $data));
    }

    private function formatMontant(float $montant): string
    {
        return number_format(round($montant), 0, ',', ' ');
    }

    private function deviseSymbol(): string
    {
        return config('wallet.devise_symbol', 'Fcfa');
    }

    private function genererReference(): string
    {
        // Référence unique exigée par transactions.reference (UNIQUE) : retry
        // en cas de collision, comme genererWalletId, car 2 références sont
        // tirées par transfert interne (débit d'origine + dérivée -C).
        for ($i = 0; $i < 5; $i++) {
            $reference = 'TXN_' . uniqid('', true);
            if (! Transaction::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('Impossible de générer une référence unique.');
    }

    private function genererWalletId(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $id = 'TGW' . now()->format('ymd') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (! User::where('wallet_id', $id)->exists()) {
                return $id;
            }
        }

        throw new \RuntimeException('Impossible de générer un wallet_id unique.');
    }
}