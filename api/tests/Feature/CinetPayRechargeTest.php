<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\CinetPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests de l'intégration CinetPay (recharge Mobile Money).
 *
 * Le dialogue HTTP est simulé avec Http::fake() : le code d'initialisation,
 * la persistance des identifiants prestataire, le webhook et son caractère
 * idempotent sont réellement exécutés, sans exiger de clé CinetPay réelle.
 *
 * Les tests qui touchent au WEBHOOK sont les plus importants : c'est le seul
 * endroit où une requête FORGÉE pourrait créditer un solde.
 */
class CinetPayRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Intégration active pour ce test ; chaque test repart de zéro.
        config([
            'wallet.cinetpay.api_key'  => 'cle-de-test',
            'wallet.cinetpay.site_id'  => 'site-de-test',
            'wallet.cinetpay.notify_url' => 'https://yam.test/api/v1/wallet/recharge/notify',
            'wallet.cinetpay.return_url' => 'https://yam.test/',
        ]);
    }

    private function createUser(float $solde = 0): User
    {
        return User::factory()->create([
            'username'     => 'patient_' . Str::lower(Str::random(6)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
            'role'         => 'patient',
            'solde'        => $solde,
        ]);
    }

    private function fakeCinetPayAccepte(string $token = 'tok_abc123', string $url = 'https://secure.cinetpay.net/checkout/tok_abc123'): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'payment_token'       => $token,
                    'payment_url'         => $url,
                    'must_be_redirected'  => true,
                ],
                'message' => 'OK',
            ], 200),
        ]);
    }

    /** Recharge via l'API, renvoie la réponse JSON. */
    private function recharger(User $user, float $montant = 2000, string $phone = '22891112233')
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/wallet/recharge', [
            'montant'      => $montant,
            'phone_number' => $phone,
        ]);
    }

    // ---------------------------------------------------------------
    // Initialisation
    // ---------------------------------------------------------------

    public function test_recharge_appelle_cinetpay_avec_le_contrat_documente(): void
    {
        $this->fakeCinetPayAccepte();
        $user = $this->createUser();

        $reponse = $this->recharger($user, 2000)->assertCreated();

        Http::assertSent(function (Request $request) {
            $corps = $request->data();

            // Champs requis par la Checkout API v2.
            foreach (['apikey', 'site_id', 'transaction_id', 'amount', 'currency', 'description', 'notify_url', 'return_url', 'channels'] as $champ) {
                if (! array_key_exists($champ, $corps)) {
                    return false;
                }
            }

            return $corps['apikey'] === 'cle-de-test'
                && $corps['site_id'] === 'site-de-test'
                && $corps['currency'] === 'XOF'
                && is_int($corps['amount'])
                // Exigence CinetPay : multiple de 5.
                && $corps['amount'] % 5 === 0
                // Description sans les caractères refusés (#, /, $, _, &).
                && ! preg_match('~[/$_&#]~', $corps['description']);
        });

        $this->assertSame('reel', $reponse->json('information.payment.mode'));
        $this->assertTrue($reponse->json('information.payment.must_be_redirected'));
        $this->assertSame('https://secure.cinetpay.net/checkout/tok_abc123', $reponse->json('information.payment.payment_url'));
    }

    public function test_le_montant_envoye_est_un_multiple_de_5_arrondi_au_dessus(): void
    {
        // 1003,00 + 10 % de frais = 1103,30 -> arrondi au multiple de 5 supérieur = 1105.
        $this->assertSame(1105, CinetPayService::arrondirMontantCinetPay(1103.30));
        $this->assertSame(2200, CinetPayService::arrondirMontantCinetPay(2200));
        $this->assertSame(100, CinetPayService::arrondirMontantCinetPay(99.9));

        $this->fakeCinetPayAccepte();
        $reponse = $this->recharger($this->createUser(), 1003)->assertCreated();

        $this->assertSame(1105, $reponse->json('information.payment.amount'));
    }

    public function test_les_identifiants_prestataire_sont_persistes_en_base(): void
    {
        $this->fakeCinetPayAccepte();
        $user = $this->createUser();

        $reponse = $this->recharger($user)->assertCreated();
        $reference = $reponse->json('information.reference');

        $this->assertDatabaseHas('transactions', [
            'user_id'                  => $user->id,
            'type'                     => Transaction::TYPE_TOPUP,
            'status'                   => Transaction::STATUS_EN_COURS,
            'reference'                => $reference,
            'provider'                 => 'cinetpay',
            'provider_transaction_id'  => $reference,
            'provider_payment_token'   => 'tok_abc123',
        ]);
    }

    public function test_sans_configuration_il_ny_a_pas_d_url_de_paiement_inventee(): void
    {
        // Regression : la recharge fabricquait une URL de checkout avec un token
        // aleatoire. Le client etait redirige vers une page inexistante tout en
        // affichant une confirmation de paiement.
        config(['wallet.cinetpay.api_key' => '', 'wallet.cinetpay.site_id' => '']);

        $reponse = $this->recharger($this->createUser())->assertCreated();

        $this->assertSame('simulation', $reponse->json('information.payment.mode'));
        $this->assertSame('', $reponse->json('information.payment.payment_url'));
        $this->assertFalse($reponse->json('information.payment.must_be_redirected'));

        // Aucun appel HTTP sortant ne doit partir sans configuration.
        Http::fake();
        $this->recharger($this->createUser())->assertCreated();
        Http::assertNothingSent();
    }

    public function test_un_echec_de_cinetpay_ne_laisse_pas_de_recharge_fantome(): void
    {
        Http::fake(['*' => Http::response(['message' => 'refus'], 500)]);

        $user = $this->createUser();
        $this->recharger($user)->assertStatus(500);

        // L'appel a echoue AVANT l'ecriture : aucune recharge "En cours" ne doit
        // traîner, sinon le client verrait une recharge en attente inexistante.
        $this->assertSame(0, Transaction::count());
    }

    public function test_une_reponse_cinetpay_incomplete_est_refusee(): void
    {
        Http::fake(['*' => Http::response(['data' => ['message' => 'ok']], 200)]);

        $this->recharger($this->createUser())->assertStatus(500);
        $this->assertSame(0, Transaction::count());
    }

    // ---------------------------------------------------------------
    // Webhook de confirmation
    // ---------------------------------------------------------------

    private function pendingRecharge(User $user, float $montant = 2000): Transaction
    {
        $this->fakeCinetPayAccepte();
        $reponse = $this->recharger($user, $montant)->assertCreated();

        return Transaction::where('reference', $reponse->json('information.reference'))->firstOrFail();
    }

    public function test_webhook_reussi_credite_le_solde_exactement_une_fois(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'site_id'        => 'site-de-test',
            'amount'         => 2200, // 2000 + 200 de frais
            'code'           => 200,
        ])->assertOk()->assertJson(['credit' => true]);

        $this->assertSame(2500.00, (float) $user->fresh()->solde);

        $ligne = $recharge->fresh();
        $this->assertSame(Transaction::STATUS_REUSSIE, $ligne->status);
        $this->assertSame(2500.00, (float) $ligne->new_balance_raw);
        $this->assertNotNull($ligne->date_complete);
    }

    public function test_webhook_rejoue_ne_credite_pas_deux_fois(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        $payload = [
            'transaction_id' => $recharge->provider_transaction_id,
            'site_id'        => 'site-de-test',
            'amount'         => 2200,
            'code'           => 200,
        ];

        $this->postJson('/api/v1/wallet/recharge/notify', $payload)->assertOk();
        $this->postJson('/api/v1/wallet/recharge/notify', $payload)->assertOk()
            ->assertJson(['credit' => false, 'raison' => 'deja_traitee']);
        $this->postJson('/api/v1/wallet/recharge/notify', $payload)->assertOk();

        $this->assertSame(2500.00, (float) $user->fresh()->solde, 'Un rejeu de webhook a credite le solde plusieurs fois.');
        $this->assertSame(1, Transaction::where('status', Transaction::STATUS_REUSSIE)->count());
    }

    public function test_webhook_avec_un_montant_forge_est_rejete_sans_crediter(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        // Le client annonce 2200 au moment de l'init, puis un attaquant rejoue
        // la notification en annonçant 1 : c'est le MONTANT EN BASE qui fait foi.
        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'amount'         => 1,
            'code'           => 200,
        ])->assertOk()->assertJson(['credit' => false, 'raison' => 'montant_invalide']);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
        $this->assertSame(Transaction::STATUS_EN_COURS, $recharge->fresh()->status);
    }

    public function test_webhook_avec_un_site_id_inconnu_est_rejete(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'site_id'        => 'site-dun-attaquant',
            'amount'         => 2200,
            'code'           => 200,
        ])->assertOk()->assertJson(['credit' => false, 'raison' => 'site_id_invalide']);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
    }

    public function test_webhook_sans_transaction_id_est_rejete(): void
    {
        $user = $this->createUser(solde: 500);
        $this->pendingRecharge($user, 2000);

        $this->postJson('/api/v1/wallet/recharge/notify', ['code' => 200])
            ->assertOk()
            ->assertJson(['credit' => false, 'raison' => 'transaction_id_manquant']);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
    }

    public function test_webhook_pour_une_recharge_inconnue_ne_credite_rien(): void
    {
        $user = $this->createUser(solde: 500);

        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => 'TXN_reference_qui_nexiste_pas',
            'code'           => 200,
        ])->assertOk()->assertJson(['credit' => false, 'raison' => 'recharge_introuvable']);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
    }

    public function test_webhook_en_echec_passe_la_transaction_en_echouee_sans_crediter(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'amount'         => 2200,
            'code'           => 401, // paiement refuse
        ])->assertOk()->assertJson(['credit' => false, 'raison' => 'paiement_non_confirme']);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
        $this->assertSame(Transaction::STATUS_ECHOUEE, $recharge->fresh()->status);
    }

    public function test_un_webhook_sans_code_ni_status_nest_jamais_succes(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        // Ni `code` ni `status` : on ne devine pas, on refuse (fail-closed).
        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'amount'         => 2200,
        ])->assertOk()->assertJson(['credit' => false]);

        $this->assertSame(500.00, (float) $user->fresh()->solde);
    }

    public function test_le_webhook_ne_demande_pas_de_jeton_utilisateur(): void
    {
        $user = $this->createUser(solde: 500);
        $recharge = $this->pendingRecharge($user, 2000);

        // Le webhook vient de CinetPay : il n'a pas de jeton. C'est la
        // verification en base qui fait la securite, pas l'authentification.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/wallet/recharge/notify', [
            'transaction_id' => $recharge->provider_transaction_id,
            'amount'         => 2200,
            'code'           => 200,
        ])->assertOk()->assertJson(['credit' => true]);

        $this->assertSame(2500.00, (float) $user->fresh()->solde);
    }
}
