<?php

namespace App\Services;

use App\Models\DemoAccount;
use App\Models\DemoWalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DemoWalletService
{
    public function walletData(DemoAccount $account): array
    {
        $account = $this->refreshAccount($account);
        $transactions = $account->transactions()->latest('id')->limit(5)->get();
        $month = $account->transactions()
            ->where('status', 2)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);

        return [
            'demo' => true,
            'status' => '000',
            'message' => 'Portefeuille virtuel de démonstration.',
            'wallet' => $this->formatWallet($account),
            'membre' => [
                'nom' => $account->name,
                'username' => $account->username ?? '',
                'email' => '',
                'kyc_valide' => 0,
                'avatar' => '',
            ],
            'stats_mois' => [
                'periode' => now()->format('F Y'),
                'depenses' => $this->formatAmount((int) (clone $month)->where('direction', 'debit')->sum('amount')).' Fcfa',
                'depenses_raw' => (int) (clone $month)->where('direction', 'debit')->sum('amount'),
                'recus' => $this->formatAmount((int) (clone $month)->where('direction', 'credit')->sum('amount')).' Fcfa',
                'recus_raw' => (int) (clone $month)->where('direction', 'credit')->sum('amount'),
                'nb_transactions' => (clone $month)->count(),
            ],
            'dernieres_transactions' => $transactions->map(fn (DemoWalletTransaction $transaction) => $this->formatTransaction($transaction))->all(),
            'epargnes' => [],
            'nb_epargnes' => 0,
            'assurances' => [],
            'nb_assurances' => 0,
        ];
    }

    public function listTransactions(DemoAccount $account, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $query = $account->transactions();
        $total = (clone $query)->count();
        $items = (clone $query)->latest('id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $grouped = [];
        foreach ($items as $transaction) {
            $grouped[$transaction->created_at->format('d/m/Y')][] = $this->formatTransaction($transaction);
        }

        return [
            'demo' => true,
            'status' => '000',
            'message' => 'Opération effectuée avec succès',
            'wallet' => [
                'wallet_id' => $account->wallet_id,
                'solde' => $this->formatAmount((int) $account->balance).' Fcfa',
                'solde_raw' => (int) $account->balance,
                'devise' => 'XOF',
                'devise_symbol' => 'Fcfa',
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
                'has_next' => $page < max(1, (int) ceil($total / $limit)),
            ],
            'transactions' => $grouped,
        ];
    }

    public function recharge(DemoAccount $account, int $amount, string $phone): array
    {
        $fees = (int) round($amount * (float) config('wallet.frais_recharge', 0));
        $transaction = $account->transactions()->create([
            'reference' => $this->reference(),
            'type' => 'TOPUP',
            'type_name' => 'Recharge démo',
            'direction' => 'credit',
            'amount' => $amount,
            'fees' => $fees,
            'status' => 1,
            'counterpart_type' => 'external',
            'counterpart_label' => 'Mobile Money — '.$phone,
            'description' => 'Recharge virtuelle en attente de confirmation',
        ]);

        return [
            'demo' => true,
            'status' => '000',
            'message' => 'Recharge virtuelle créée. Confirmez-la avec /wallet/recharge/notify.',
            'information' => [
                'reference' => $transaction->reference,
                'status' => 1,
                'status_show' => 'En cours',
                'amount' => '+'.$this->formatAmount($amount).' Fcfa',
                'fees' => $this->formatAmount($fees).' Fcfa',
                'total_amount' => $this->formatAmount($amount + $fees).' Fcfa',
                'new_balance' => '-',
                'devise' => 'XOF',
                'payment' => [
                    'mode' => 'demo',
                    'provider' => 'demo',
                    'payment_token' => '',
                    'payment_url' => '',
                    'must_be_redirected' => false,
                ],
            ],
        ];
    }

    public function transfer(
        DemoAccount $account,
        int $amount,
        ?string $recipientWalletId,
        ?string $phone
    ): array {
        $recipient = $recipientWalletId
            ? DemoAccount::where('wallet_id', $recipientWalletId)->first()
            : ($phone ? DemoAccount::where('phone_number', $this->normalizePhone($phone))->first() : null);

        if ($recipientWalletId && ! $recipient) {
            throw ValidationException::withMessages([
                'destinataire_wallet_id' => 'Wallet de démonstration introuvable.',
            ]);
        }
        if ($recipient && $recipient->is($account)) {
            throw ValidationException::withMessages([
                'destinataire_wallet_id' => 'Impossible de se transférer à soi-même.',
            ]);
        }

        $fees = (int) round($amount * (float) config('wallet.frais_transfert', 0));
        $total = $amount + $fees;
        $reference = $this->reference();

        if (! $recipient) {
            $transaction = $account->transactions()->create([
                'reference' => $reference,
                'type' => 'TRANSFER',
                'type_name' => 'Transfert démo',
                'direction' => 'debit',
                'amount' => $amount,
                'fees' => $fees,
                'status' => 1,
                'counterpart_type' => 'mobile_money',
                'counterpart_label' => 'Mobile Money — '.$phone,
                'description' => 'Transfert virtuel en attente de confirmation',
            ]);

            return $this->transferResponse($transaction, $account, $amount, $fees, false, $phone);
        }

        return DB::transaction(function () use ($account, $recipient, $amount, $fees, $total, $reference) {
            $ids = [$account->id, $recipient->id];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = DemoAccount::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            $sender = $locked[$account->id];
            $receiver = $locked[$recipient->id];

            if ((int) $sender->balance < $total) {
                throw ValidationException::withMessages([
                    'montant' => 'Solde insuffisant pour effectuer ce transfert.',
                ])->status(402);
            }

            $sender->balance -= $total;
            $receiver->balance += $amount;
            $sender->save();
            $receiver->save();

            $debit = $sender->transactions()->create([
                'recipient_account_id' => $receiver->id,
                'reference' => $reference,
                'type' => 'TRANSFER',
                'type_name' => 'Transfert démo',
                'direction' => 'debit',
                'amount' => $amount,
                'fees' => $fees,
                'balance_after' => $sender->balance,
                'status' => 2,
                'counterpart_type' => 'internal',
                'counterpart_label' => $receiver->name,
                'counterpart_wallet_id' => $receiver->wallet_id,
                'description' => 'Transfert virtuel vers '.$receiver->name,
                'date_complete' => now(),
            ]);

            $receiver->transactions()->create([
                'recipient_account_id' => $sender->id,
                'reference' => $reference.'-C',
                'type' => 'TRANSFER',
                'type_name' => 'Transfert reçu démo',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $receiver->balance,
                'status' => 2,
                'counterpart_type' => 'internal',
                'counterpart_label' => $sender->name,
                'counterpart_wallet_id' => $sender->wallet_id,
                'description' => 'Transfert virtuel reçu de '.$sender->name,
                'date_complete' => now(),
            ]);

            return $this->transferResponse($debit, $sender, $amount, $fees, true, $receiver->wallet_id, $receiver);
        });
    }

    /**
     * Retrait virtuel (POST /api/demo/v1/wallet/withdraw).
     *
     * Débit immédiat sous verrou (cohérent avec le transfert interne) et
     * solde insuffisant → 402, exactement comme l'API réelle. Aucune valeur
     * réelle : la transaction sort simplement de la balance virtuelle.
     */
    public function withdraw(DemoAccount $account, int $amount, ?string $phone): array
    {
        return DB::transaction(function () use ($account, $amount, $phone) {
            $locked = DemoAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->balance < $amount) {
                throw ValidationException::withMessages([
                    'montant' => 'Solde insuffisant pour effectuer ce retrait.',
                ])->status(402);
            }

            $locked->balance -= $amount;
            $locked->save();

            $transaction = $locked->transactions()->create([
                'reference' => $this->reference(),
                'type' => 'WITHDRAW',
                'type_name' => 'Retrait démo',
                'direction' => 'debit',
                'amount' => $amount,
                'fees' => 0,
                'balance_after' => $locked->balance,
                'status' => 2,
                'counterpart_type' => 'mobile_money',
                'counterpart_label' => $phone ? 'Mobile Money — '.$phone : 'Retrait démo',
                'description' => 'Retrait virtuel'.($phone ? ' vers '.$phone : ''),
                'date_complete' => now(),
            ]);

            return [
                'demo' => true,
                'status' => '000',
                'message' => 'Retrait virtuel effectué.',
                'information' => [
                    'reference' => $transaction->reference,
                    'status' => $transaction->status,
                    'status_show' => 'Réussie',
                    'amount' => '-'.$this->formatAmount($amount).' Fcfa',
                    'fees' => $this->formatAmount(0).' Fcfa',
                    'new_balance' => $this->formatAmount((int) $locked->balance).' Fcfa',
                    'devise' => 'XOF',
                    'description' => $transaction->description,
                ],
            ];
        });
    }

    public function notifyRecharge(DemoAccount $account, string $reference, bool $succeeded): array
    {
        $result = DB::transaction(function () use ($account, $reference, $succeeded) {
            $transaction = DemoWalletTransaction::where('demo_account_id', $account->id)
                ->where('reference', $reference)
                ->where('type', 'TOPUP')
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return ['credit' => false, 'raison' => 'recharge_introuvable'];
            }
            if ($transaction->status !== 1) {
                return ['credit' => false, 'raison' => 'deja_traitee'];
            }
            if (! $succeeded) {
                $transaction->update(['status' => 3, 'date_complete' => now()]);

                return ['credit' => false, 'raison' => 'paiement_non_confirme'];
            }

            $freshAccount = DemoAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $freshAccount->balance += $transaction->amount;
            $freshAccount->save();
            $transaction->update([
                'status' => 2,
                'balance_after' => $freshAccount->balance,
                'date_complete' => now(),
            ]);

            return ['credit' => true, 'raison' => 'solde_credite'];
        });

        return ['demo' => true, 'status' => $result['credit'] ? '000' : '999'] + $result;
    }

    public function createEncaissement(DemoAccount $account, int $patientId, int $amount, string $motif): array
    {
        $transaction = $account->transactions()->create([
            'reference' => $this->reference(),
            'type' => 'CASH_IN',
            'type_name' => 'Encaissement démo',
            'direction' => 'credit',
            'amount' => $amount,
            'status' => 1,
            'counterpart_type' => 'external',
            'counterpart_label' => 'Patient démo #'.$patientId,
            'description' => $motif !== '' ? $motif : 'Encaissement virtuel en attente de confirmation',
        ]);

        return [
            'demo' => true,
            'ok' => true,
            'message' => 'Encaissement virtuel créé — aucun paiement réel.',
            'transaction' => [
                'reference' => $transaction->reference,
                'patient' => 'Patient démo #'.$patientId,
                'montant' => $amount,
                'motif' => $motif,
                'statut' => 'en_attente',
            ],
            'qr_data' => 'KONDJIPAY:DEMO:'.$transaction->reference.':'.$amount,
        ];
    }

    public function confirmEncaissement(DemoAccount $account, string $reference): array
    {
        $result = DB::transaction(function () use ($account, $reference) {
            $transaction = DemoWalletTransaction::where('demo_account_id', $account->id)
                ->where('reference', $reference)
                ->where('type', 'CASH_IN')
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return ['ok' => false, 'message' => 'Encaissement introuvable.'];
            }
            if ($transaction->status !== 1) {
                return ['ok' => false, 'message' => 'Cet encaissement a déjà été traité.'];
            }

            $freshAccount = DemoAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $freshAccount->balance += $transaction->amount;
            $freshAccount->save();
            $transaction->update([
                'status' => 2,
                'balance_after' => $freshAccount->balance,
                'date_complete' => now(),
            ]);

            return ['ok' => true, 'message' => 'Encaissement virtuel confirmé.'];
        });

        return ['demo' => true] + $result;
    }

    private function transferResponse(
        DemoWalletTransaction $transaction,
        DemoAccount $sender,
        int $amount,
        int $fees,
        bool $internal,
        ?string $recipient,
        ?DemoAccount $recipientAccount = null
    ): array {
        return [
            'demo' => true,
            'status' => '000',
            'message' => $internal ? 'Transfert virtuel effectué.' : 'Transfert virtuel créé en attente.',
            'information' => [
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'status_show' => $transaction->status === 2 ? 'Réussie' : 'En cours',
                'amount' => '-'.$this->formatAmount($amount).' Fcfa',
                'fees' => $this->formatAmount($fees).' Fcfa',
                'new_balance' => $this->formatAmount((int) $sender->balance).' Fcfa',
                'devise' => 'XOF',
                'is_internal' => $internal ? 1 : 0,
                'description' => $transaction->description,
                'destinataire' => [
                    'nom' => $recipientAccount?->name ?? (string) $recipient,
                    'wallet_id' => $recipientAccount?->wallet_id ?? '',
                    'montant_recu' => $this->formatAmount($amount).' Fcfa',
                ],
            ],
        ];
    }

    private function formatWallet(DemoAccount $account): array
    {
        return [
            'wallet_id' => $account->wallet_id,
            'key_wallet' => '',
            'solde' => $this->formatAmount((int) $account->balance).' Fcfa',
            'solde_raw' => (int) $account->balance,
            'devise' => 'XOF',
            'devise_symbol' => 'Fcfa',
            'devise_wallet' => 'XOF',
            'symbole_wallet' => 'Fcfa',
            'is_default' => 1,
            'date_create' => $account->created_at?->format('d/m/Y') ?? now()->format('d/m/Y'),
        ];
    }

    private function formatTransaction(DemoWalletTransaction $transaction): array
    {
        $credit = $transaction->direction === 'credit';
        $status = match ($transaction->status) {
            2 => ['Réussie', '0E6E0E'],
            3 => ['Échouée', 'EE343B'],
            default => ['En cours', 'CB9000'],
        };
        $color = $credit ? '1DA971' : 'EE343B';
        $icon = $transaction->type === 'TOPUP'
            ? 'wallet_topup'
            : ($credit ? 'wallet_receive' : 'wallet_send');

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'type_name' => $transaction->type_name,
            'type_icon' => $icon,
            'type_icon_color' => $color,
            'type_icon_bg_color' => $color.'26',
            'sens' => $transaction->direction,
            'amount' => ($credit ? '+' : '-').$this->formatAmount($transaction->amount).' Fcfa',
            'amount_raw' => $transaction->amount,
            'fees' => $this->formatAmount($transaction->fees).' Fcfa',
            'devise' => 'XOF',
            'devise_symbol' => 'Fcfa',
            'new_balance' => $transaction->balance_after === null ? '-' : $this->formatAmount($transaction->balance_after).' Fcfa',
            'status' => $transaction->status,
            'status_show' => $status[0],
            'status_color' => $status[1],
            'description' => $transaction->description,
            'counterpart' => [
                'type' => $transaction->counterpart_type,
                'label' => $transaction->counterpart_label,
                'wallet_id' => $transaction->counterpart_wallet_id ?? '',
                'nom' => $transaction->counterpart_label,
            ],
            'date_transaction' => $transaction->created_at->format('d/m/Y'),
            'heure_transaction' => $transaction->created_at->format('H:i'),
            'date_complete' => $transaction->date_complete?->format('d/m/Y H:i') ?? '',
        ];
    }

    private function reference(): string
    {
        return 'DEMO-'.strtoupper((string) Str::ulid());
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    private function refreshAccount(DemoAccount $account): DemoAccount
    {
        return DemoAccount::findOrFail($account->id);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 8) {
            $digits = '228'.$digits;
        }

        return '+'.$digits;
    }
}
