<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client CinetPay (Checkout API).
 *
 * CONTRAT UTILISE
 * ---------------
 * Initialisation du paiement (POST JSON) :
 *     https://api-checkout.cinetpay.com/v2/payment
 * Champs requis : apikey, site_id, transaction_id, amount, currency,
 * description, notify_url, return_url, channels.
 *     - `amount` doit être un ENTIER multiple de 5
 *     - `description` ne doit pas contenir de caractère spécial (#, /, $, _, &)
 *     - `currency` : XOF, XAF, CDF, GNF, USD
 * Réponse : `data.payment_token` et `data.payment_url` (ainsi que
 * `data.must_be_redirected`).
 *
 * ⚠️ CE QUI RESTE À CONFIRMER AVEC LE SUPPORT CINETPAY
 * ---------------------------------------------------
 * Le contrat de la NOTIFICATION (le POST envoyé sur notify_url quand la
 * transaction atteint un statut final) n'est PAS documenté de façon vérifiable
 * ici. Pour ne jamais créditer un solde sur la foi d'un corps de requête
 * forgé par un tiers, la vérification est fail-closed :
 *   - le code d'erreur `code` doit valoir 200 (succès) ;
 *   - le `site_id` reçu doit correspondre au nôtre ;
 *   - le `transaction_id` doit correspondre à une recharge EN COURS en base ;
 *   - le `amount` reçu doit être EXACTEMENT le montant attendu.
 * Tout écart est enregistré et rejeté : aucun crédit. Voir
 * confirmerRecharge() dans WalletService, qui porte le verrou.
 *
 * AUCUNE CLÉ N'EST STOCKÉE DANS CE FICHIER : tout vient de config/wallet.php,
 * lui-même alimenté par l'environnement.
 */
class CinetPayService
{
    /**
     * L'intégration n'est active que si les deux identifiants sont fournis.
     * Sans eux, le wallet reste en mode simulation (voir WalletService).
     */
    public function estConfigure(): bool
    {
        return $this->apiKey() !== '' && $this->siteId() !== '';
    }

    /**
     * Initialise un paiement chez CinetPay et renvoie le token + l'URL de
     * redirection.
     *
     * @param  string  $transactionId  NOTRE identifiant (unique), renvoyé tel quel
     *                                  dans la notification : c'est la clé de rapprochement.
     * @param  int     $montant        Montant TOTAL débité au client, multiple de 5.
     * @throws RuntimeException si CinetPay refuse ou est injoignable.
     */
    public function initialiserPaiement(string $transactionId, int $montant, string $description): array
    {
        if (! $this->estConfigure()) {
            throw new RuntimeException('CinetPay n\'est pas configuré (CINETPAY_API_KEY / CINETPAY_SITE_ID manquants).');
        }

        $payload = [
            'apikey'         => $this->apiKey(),
            'site_id'        => $this->siteId(),
            'transaction_id' => $transactionId,
            'amount'         => $montant,
            'currency'       => config('wallet.devise', 'XOF'),
            // Caractères #, /, $, _, & refusés par CinetPay.
            'description'    => $this->assainirDescription($description),
            'notify_url'     => config('wallet.cinetpay.notify_url'),
            'return_url'     => config('wallet.cinetpay.return_url'),
            'channels'       => config('wallet.cinetpay.channels', 'ALL'),
            'lang'           => config('wallet.cinetpay.lang', 'fr'),
        ];

        try {
            $reponse = Http::timeout(config('wallet.cinetpay.timeout', 10))
                ->acceptJson()
                ->asJson()
                ->post(config('wallet.cinetpay.init_url'), $payload);
        } catch (ConnectionException $e) {
            // On journalise la cause mais on ne renvoie jamais le détail au client.
            Log::error('CinetPay injoignable', ['exception' => $e->getMessage()]);

            throw new RuntimeException('Le service de paiement est momentanément indisponible.', previous: $e);
        }

        if ($reponse->failed()) {
            Log::warning('CinetPay a refuse l\'initialisation', [
                'http_status' => $reponse->status(),
                'transaction_id' => $transactionId,
            ]);

            throw new RuntimeException('Le service de paiement a refusé la transaction.');
        }

        $donnees = $reponse->json('data') ?? [];

        $paymentToken = $donnees['payment_token'] ?? null;
        $paymentUrl = $donnees['payment_url'] ?? null;

        if (! $paymentToken || ! $paymentUrl) {
            Log::warning('CinetPay : reponse sans payment_token/payment_url', [
                'transaction_id' => $transactionId,
            ]);

            throw new RuntimeException('Le service de paiement a renvoyé une réponse incomplète.');
        }

        return [
            'payment_token'       => (string) $paymentToken,
            'payment_url'         => (string) $paymentUrl,
            'must_be_redirected'  => (bool) ($donnees['must_be_redirected'] ?? true),
        ];
    }

    /**
     * Arrondit un montant à l'entier multiple de 5 exigé par CinetPay, en
     * arrondissant AU-DESSUS pour ne jamais facturer moins que prévu.
     *
     * 2200 -> 2200 · 1103,30 -> 1105
     */
    public static function arrondirMontantCinetPay(float $montant): int
    {
        $entier = (int) ceil($montant);

        return $entier + ((5 - ($entier % 5)) % 5);
    }

    private function apiKey(): string
    {
        return (string) config('wallet.cinetpay.api_key', '');
    }

    private function siteId(): string
    {
        return (string) config('wallet.cinetpay.site_id', '');
    }

    /** CinetPay refuse #, /, $, _ et & dans la description. */
    private function assainirDescription(string $description): string
    {
        return trim(str_replace(['#', '/', '$', '_', '&'], ' ', $description));
    }
}
