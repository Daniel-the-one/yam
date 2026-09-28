<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Transaction;
use App\Models\User;
use App\Services\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Idempotence des opérations qui déplacent de l'argent.
 *
 * Ces tests répondent à un défaut constaté en test HTTP RÉEL sur l'API : le
 * rejeu d'un même POST /wallet/transfert exécutait un second transfert, donc
 * un second débit et un second crédit. L'utilisateur n'avait fait qu'un seul
 * geste — un double appui ou un retry réseau suffisait.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'patient'): User
    {
        return User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
            'role'         => $role,
            'solde'        => 10_000,
        ]);
    }

    /**
     * Crée le wallet par le même chemin que la production (GET /wallet), sinon
     * le test ne validerait pas le vrai mécanisme de création.
     */
    private function avecWallet(User $user): User
    {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/wallet')->assertOk();

        return $user->fresh();
    }

    /** @return array{0: User, 1: string, 2: string} [alice, walletAlice, walletBob] */
    private function wallets(): array
    {
        $alice = $this->avecWallet($this->user());
        $bob   = $this->avecWallet($this->user());

        return [$alice, $alice->wallet_id, $bob->wallet_id];
    }

    /**
     * Nombre de transferts LOGIQUIQUES émis par un utilisateur.
     *
     * Chaque transfert écrit deux lignes : une côté DÉBIT (user_id = émetteur,
     * sens = DEBIT) et une côté CRÉDIT (user_id = destinataire, référence
     * suffixée `-C`). Compter toutes les lignes compterait deux fois. On ne
     * retient donc que les lignes de débit de cet émetteur : une par transfert.
     */
    private function nbTransferts(User $emetteur): int
    {
        return Transaction::where('type', Transaction::TYPE_TRANSFER)
            ->where('user_id', $emetteur->id)
            ->where('sens', Transaction::SENS_DEBIT)
            ->count();
    }

    /**
     * @param  string|null  $corps  Surcharge le corps complet (test de l'ordre des clés JSON).
     */
    private function transfert(string $cle, string $destinataire, float $montant = 1000, ?string $corps = null)
    {
        $donnees = $corps !== null
            ? $corps
            : ['montant' => $montant, 'destinataire_wallet_id' => $destinataire];

        return $this->postJson(
            '/api/v1/wallet/transfert',
            // postJson attend un tableau ou un objet : on décode une chaîne JSON.
            is_string($donnees) ? json_decode($donnees, true) : $donnees,
            $cle === '' ? [] : ['Idempotency-Key' => $cle]
        );
    }

    // ---------------------------------------------------------------
    // Transfert
    // ---------------------------------------------------------------

    public function test_un_transfert_rejoue_ne_debite_et_ne_credite_quune_seule_fois(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        $soldeAlice = (float) $alice->fresh()->solde;
        $soldeBob   = (float) User::where('wallet_id', $wb)->value('solde');

        $premiere = $this->transfert('rejeu-transfert-0001', $wb)->assertCreated();
        $reference = $premiere->json('information.reference');

        // Trois rejeux, comme le ferait une couche réseau qui réessaie.
        foreach (range(1, 3) as $ignoré) {
            $rejeu = $this->transfert('rejeu-transfert-0001', $wb)
                ->assertCreated()
                ->assertHeader('Idempotent-Replay', 'true');

            $this->assertSame($reference, $rejeu->json('information.reference'),
                'Le rejeu a produit une nouvelle reference : le transfert a été exécuté deux fois.');
        }

        $this->assertSame(1, $this->nbTransferts($alice),
            'Le rejeu a exécuté un second transfert.');

        // Alice : 10 000 - 1 000 - 10 % de frais = 8 900.
        // Bob est crédité du MONTANT NOMINAL, frais exclus (ils restent chez la plateforme).
        $this->assertSame($soldeAlice - 1100.0, (float) $alice->fresh()->solde);
        $this->assertSame($soldeBob + 1000.00, (float) User::where('wallet_id', $wb)->value('solde'));
    }

    public function test_sans_cle_didempotence_le_comportement_historique_est_conserve(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        // Deux requêtes SANS clé = deux intentions distinctes = deux transferts.
        // C'est le comportement nominal : on ne fige pas les clients existants.
        $this->transfert('', $wb)->assertCreated();
        $this->transfert('', $wb)->assertCreated();

        $this->assertSame(2, $this->nbTransferts($alice));
        $this->assertSame(0, IdempotencyKey::count(), 'Aucune clé ne doit être mémorisée sans en-tête.');
    }

    public function test_la_meme_cle_avec_un_corps_different_est_refusee_en_409(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        $this->transfert('corps-different-0001', $wb, 1000)->assertCreated();

        $this->transfert('corps-different-0001', $wb, 5000)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        // Le 409 ne doit avoir touché à AUCUN solde : le seul débit est celui
        // du premier appel (10 000 - 1 000 - 10 % de frais).
        $this->assertSame(8_900.00, (float) $alice->fresh()->solde);
        $this->assertSame(1, $this->nbTransferts($alice));
    }

    public function test_l_ordre_des_cles_json_ne_change_pas_l_empreinte(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        $this->transfert('ordre-json-000001', $wb)->assertCreated();

        // Mêmes données, clés JSON dans l'autre ordre : c'est la MÊME requête.
        $this->transfert('ordre-json-000001', $wb, 1000, json_encode([
            'destinataire_wallet_id' => $wb,
            'montant'               => 1000,
        ]))->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, $this->nbTransferts($alice));
    }

    public function test_une_cle_trop_courte_ou_caracteres_invalides_est_refusee(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        $this->transfert('court', $wb)->assertStatus(409);
        $this->transfert(str_repeat('a', 201), $wb)->assertStatus(409);
        // Tentative d'injection via l'en-tête.
        $this->transfert("id invalide avec espaces", $wb)->assertStatus(409);

        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_un_echec_metier_libere_la_cle_pour_permettre_un_retry(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        $alice->update(['solde' => 100]); // pas assez pour 1 000

        // Échec métier attendu : la réservation doit être libérée, sinon le
        // client ne pourrait plus jamais réessayer avec cette clé.
        $this->transfert('echec-metier-0001', $wb)
            ->assertStatus(402)
            ->assertJsonValidationErrors('montant');

        $this->assertSame(0, IdempotencyKey::count(), 'La clé aurait dû être libérée après un échec.');

        // Le client rejoue la MÊME clé après avoir viré des fonds : ça marche.
        $alice->update(['solde' => 10_000]);
        $this->transfert('echec-metier-0001', $wb)->assertCreated();
        $this->assertSame(1, IdempotencyKey::count());
    }

    public function test_une_cle_en_cours_de_traitement_refuse_un_rejeu_concurrent(): void
    {
        [$alice, , $wb] = $this->wallets();
        Sanctum::actingAs($alice);

        // Simulation d'une requête déjà reserved mais pas encore terminée
        // (cas réel quand le client double-envoie pendant que le premier
        // appel est encore chez CinetPay).
        IdempotencyKey::create([
            'user_id'      => $alice->id,
            'endpoint'     => 'wallet.transfert',
            'idem_key'     => 'en-cours-de-traitement',
            'request_hash' => hash('sha256', json_encode(['destinataire_wallet_id' => $wb, 'montant' => 1000])),
            'status'       => IdempotencyKey::STATUS_EN_COURS,
        ]);

        $this->transfert('en-cours-de-traitement', $wb)->assertStatus(409);

        $this->assertSame(10_000.00, (float) $alice->fresh()->solde, 'Aucun débit ne doit avoir eu lieu.');
    }

    public function test_une_cle_est_scopee_par_utilisateur(): void
    {
        [$alice, , $wb] = $this->wallets();
        $carol = $this->avecWallet($this->user());

        Sanctum::actingAs($alice);
        $this->transfert('cle-partagee-00001', $wb)->assertCreated();

        // Même clé, autre utilisateur : ce n'est PAS un rejeu, c'est sa propre
        // opération. Sans ce scope, Carol verrait la réponse d'Alice.
        Sanctum::actingAs($carol);
        $this->transfert('cle-partagee-00001', $wb)->assertCreated()
            ->assertHeaderMissing('Idempotent-Replay');

        $this->assertSame(1, $this->nbTransferts($alice), 'Alice doit avoir 1 seul transfert.');
        $this->assertSame(1, $this->nbTransferts($carol), 'Carol doit avoir son propre transfert.');
    }

    // ---------------------------------------------------------------
    // Recharge
    // ---------------------------------------------------------------

    public function test_une_recharge_rejouee_ne_cree_quune_seule_transaction(): void
    {
        config([
            'wallet.cinetpay.api_key' => 'cle-de-test',
            'wallet.cinetpay.site_id' => 'site-de-test',
        ]);

        // Sans cela l'initialisation partirait en HTTP RÉEL vers CinetPay.
        Http::fake(['*' => Http::response([
            'data' => ['payment_token' => 'tok_test', 'payment_url' => 'https://secure.cinetpay.test/tok_test', 'must_be_redirected' => true],
        ], 200)]);

        $alice = $this->user();
        Sanctum::actingAs($alice);

        $donnees = ['montant' => 2000, 'phone_number' => '22891112233'];

        $premiere = $this->postJson('/api/v1/wallet/recharge', $donnees, ['Idempotency-Key' => 'recharge-rejeu-01'])
            ->assertCreated();
        $reference = $premiere->json('information.reference');

        $this->postJson('/api/v1/wallet/recharge', $donnees, ['Idempotency-Key' => 'recharge-rejeu-01'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('information.reference', $reference);

        $this->assertSame(1, Transaction::where('type', Transaction::TYPE_TOPUP)->count(),
            'Le rejeu a créé une seconde recharge.');
    }

    public function test_la_reponse_rejouee_est_identique_pour_le_client(): void
    {
        config([
            'wallet.cinetpay.api_key' => 'cle-de-test',
            'wallet.cinetpay.site_id' => 'site-de-test',
        ]);

        // Sans cela l'initialisation partirait en HTTP RÉEL vers CinetPay.
        Http::fake(['*' => Http::response([
            'data' => ['payment_token' => 'tok_test', 'payment_url' => 'https://secure.cinetpay.test/tok_test', 'must_be_redirected' => true],
        ], 200)]);

        $alice = $this->user();
        Sanctum::actingAs($alice);
        $donnees = ['montant' => 2000, 'phone_number' => '22891112233'];

        $original = $this->postJson('/api/v1/wallet/recharge', $donnees, ['Idempotency-Key' => 'reponse-identique'])
            ->assertCreated();
        $rejoue = $this->postJson('/api/v1/wallet/recharge', $donnees, ['Idempotency-Key' => 'reponse-identique'])
            ->assertCreated();

        // Le client ne doit pas voir son corps de réponse changer d'une fois à
        // l'autre, sinon il ne peut pas faire confiance au rejeu.
        $this->assertSame($original->json(), $rejoue->json());
        $this->assertSame($original->getStatusCode(), $rejoue->getStatusCode());
    }

    public function test_les_entetes_de_la_reponse_sont_bien_lues(): void
    {
        // Garde-fou : le service lit l'en-tête par son nom, pas par une
        // chaîne en dur. Si le nom change, ce test casse.
        $this->assertSame('Idempotency-Key', IdempotencyService::HEADER_ENTREE);
        $this->assertSame('Idempotent-Replay', IdempotencyService::HEADER_SORTIE);
    }
}
