<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests du wallet (API externe KondjiPay) — le code qui déplace de l'argent.
 *
 * Ces tests avaient été exécutés le 23/09 (3 bugs réels détectés : référence
 * UNIQUE dupliquée sur le binôme débit/crédit, coquille de test, pagination)
 * mais N'AVAIENT JAMAIS été commités : aucune couverture n'existait pour
 * WalletService. Toute régression sur ce module était donc invisible.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'patient', float $solde = 0): User
    {
        return User::factory()->create([
            'username'     => $role . '_' . Str::lower(Str::random(6)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
            'role'         => $role,
            'solde'        => $solde,
        ]);
    }

    /** Force la création du wallet sans passer par l'API. */
    private function avecWallet(User $user): User
    {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/wallet')->assertOk();

        return $user->fresh();
    }

    // ---------------------------------------------------------------
    // Création du wallet
    // ---------------------------------------------------------------

    public function test_wallet_cree_automatiquement_au_premier_appel(): void
    {
        $user = $this->createUser();

        $this->assertNull($user->wallet_id, 'Précondition : pas encore de wallet.');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/wallet')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->wallet_id);
        $this->assertNotNull($user->key_wallet);
        $this->assertSame(32, strlen((string) $user->key_wallet));

        // Format imposé par le contrat : TGW + aammjj + 6 chiffres (TGW260622001252).
        $this->assertMatchesRegularExpression(
            '/^TGW\d{12}$/',
            (string) $user->wallet_id,
            'wallet_id hors du format du contrat (TGW260622001252).'
        );
        $this->assertSame($user->wallet_id, $response->json('wallet.wallet_id'));
    }

    public function test_wallet_id_reste_stable_entre_deux_appels(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/wallet')->assertOk();
        $premier = $user->fresh()->wallet_id;

        $this->getJson('/api/v1/wallet')->assertOk();

        $this->assertSame($premier, $user->fresh()->wallet_id);
    }

    public function test_wallet_est_cree_aussi_par_la_premiere_recharge(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/wallet/recharge', [
            'montant'      => 5000,
            'phone_number' => '22891112233',
        ])->assertCreated();

        $this->assertNotNull($user->fresh()->wallet_id);
    }

    // ---------------------------------------------------------------
    // Recharge
    // ---------------------------------------------------------------

    public function test_recharge_cree_une_transaction_en_cours_sans_crediter_le_solde(): void
    {
        $user = $this->createUser(solde: 1000);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/wallet/recharge', [
            'montant'      => 2000,
            'phone_number' => '22891112233',
        ])->assertCreated()
            ->assertJsonPath('status', '000')
            ->assertJsonPath('information.status', Transaction::STATUS_EN_COURS)
            ->assertJsonPath('information.sens', null);

        // Le solde ne bouge PAS tant que CinetPay n'a pas confirmé.
        $this->assertSame(1000.00, (float) $user->fresh()->solde);

        $this->assertDatabaseHas('transactions', [
            'user_id'  => $user->id,
            'type'     => Transaction::TYPE_TOPUP,
            'sens'     => Transaction::SENS_CREDIT,
            'status'   => Transaction::STATUS_EN_COURS,
            'amount_raw' => 2000.00,
            'new_balance_raw' => null,
        ]);

        // Frais de 10 % + total refacturés.
        $this->assertSame('+2 000 Fcfa', $response->json('information.amount'));
        $this->assertSame('200 Fcfa', $response->json('information.fees'));
        $this->assertSame('2 200 Fcfa', $response->json('information.total_amount'));
        $this->assertSame('-', $response->json('information.new_balance'));
    }

    public function test_recharge_refuse_un_montant_inferieur_au_minimum(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/recharge', [
            'montant'      => 99,
            'phone_number' => '22891112233',
        ])->assertStatus(422)->assertJsonValidationErrors('montant');

        $this->postJson('/api/v1/wallet/recharge', [
            'montant'      => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');

        $this->assertSame(0, Transaction::count(), 'Une recharge refusée ne doit créer aucune transaction.');
    }

    // ---------------------------------------------------------------
    // Transfert interne
    // ---------------------------------------------------------------

    public function test_transfert_interne_debite_l_emetteur_et_credite_le_destinataire(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));
        $bob = $this->avecWallet($this->createUser());

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'               => 1000,
            'destinataire_wallet_id' => $bob->wallet_id,
        ])->assertCreated()
            ->assertJsonPath('status', '000')
            ->assertJsonPath('information.is_internal', 1)
            ->assertJsonPath('information.status', Transaction::STATUS_REUSSIE);

        // 1000 F + 10 % de frais => l'émetteur perd 1100, le receveur gagne 1000.
        $this->assertSame(3900.00, (float) $alice->fresh()->solde);
        $this->assertSame(1000.00, (float) $bob->fresh()->solde);

        $this->assertSame(2, Transaction::count());
    }

    /**
     * RÉGRESSION : la référence renvoyée par l'API doit être celle réellement
     * écrite en base. Une référence générée à l'intérieur d'une boucle de
     * réessai mais renvoyée depuis l'extérieur diverge silencieusement, et le
     * client ne retrouve plus sa transaction.
     */
    public function test_reference_renvoyee_est_bien_celle_stockee_en_base(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));
        $bob = $this->avecWallet($this->createUser());

        Sanctum::actingAs($alice);
        $reference = $this->postJson('/api/v1/wallet/transfert', [
            'montant'                => 1000,
            'destinataire_wallet_id' => $bob->wallet_id,
        ])->assertCreated()->json('information.reference');

        $this->assertDatabaseHas('transactions', [
            'user_id'   => $alice->id,
            'reference' => $reference,
            'sens'      => Transaction::SENS_DEBIT,
        ]);

        // Le crédit porte la référence dérivée, elle aussi réellement en base.
        $this->assertDatabaseHas('transactions', [
            'user_id'   => $bob->id,
            'reference' => $reference . '-C',
            'sens'      => Transaction::SENS_CREDIT,
        ]);
    }

    /**
     * RÉGRESSION : chaque transaction porte une référence DISTINCTE. La
     * colonne est UNIQUE ; un binôme débit/crédit partageant la même
     * référence faisait échouer le transfert (bug corrigé le 23/09, sans test).
     */
    public function test_le_binome_debit_credit_a_deux_references_differentes(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));
        $bob = $this->avecWallet($this->createUser());

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'                => 1000,
            'destinataire_wallet_id' => $bob->wallet_id,
        ])->assertCreated();

        $references = Transaction::pluck('reference')->all();
        $this->assertCount(2, $references);
        $this->assertCount(2, array_unique($references), 'Les deux transactions partagent la même référence.');
    }

    public function test_transfert_interne_refuse_si_solde_insuffisant_sans_rien_creer(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 500));
        $bob = $this->avecWallet($this->createUser());

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'                => 1000, // 1000 + 100 de frais > 500
            'destinataire_wallet_id' => $bob->wallet_id,
        ])->assertStatus(402);

        $this->assertSame(500.00, (float) $alice->fresh()->solde);
        $this->assertSame(0.00, (float) $bob->fresh()->solde);
        $this->assertSame(0, Transaction::count());
    }

    public function test_transfert_vers_soi_meme_est_refuse(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'                => 100,
            'destinataire_wallet_id' => $alice->wallet_id,
        ])->assertStatus(422)->assertJsonValidationErrors('destinataire_wallet_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_transfert_vers_un_wallet_inconnu_renvoie_422(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'                => 100,
            'destinataire_wallet_id' => 'TGW999999999999',
        ])->assertStatus(422)->assertJsonValidationErrors('destinataire_wallet_id');

        $this->assertSame(0, Transaction::count());
    }

    // ---------------------------------------------------------------
    // Transfert Mobile Money (externe)
    // ---------------------------------------------------------------

    public function test_transfert_mobile_money_vers_numero_inconnu_ne_bouge_aucun_fonds(): void
    {
        $alice = $this->createUser(solde: 5000);

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'      => 1000,
            'phone_number' => '22890998877',
        ])->assertCreated()
            ->assertJsonPath('information.is_internal', 0)
            ->assertJsonPath('information.status', Transaction::STATUS_EN_COURS);

        // Aucun débit tant que l'API Mobile Money n'a pas confirmé.
        $this->assertSame(5000.00, (float) $alice->fresh()->solde);
        $this->assertDatabaseHas('transactions', [
            'user_id'          => $alice->id,
            'sens'             => Transaction::SENS_DEBIT,
            'status'           => Transaction::STATUS_EN_COURS,
            'new_balance_raw'  => null,
        ]);
    }

    public function test_transfert_mobile_money_vers_un_numero_connu_reste_interne(): void
    {
        $alice = $this->avecWallet($this->createUser(solde: 5000));
        $bob = $this->avecWallet($this->createUser());

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', [
            'montant'      => 1000,
            'phone_number' => $bob->phone_number,
        ])->assertCreated()
            ->assertJsonPath('information.is_internal', 1);

        $this->assertSame(3900.00, (float) $alice->fresh()->solde);
        $this->assertSame(1000.00, (float) $bob->fresh()->solde);
    }

    public function test_transfert_exige_un_destinataire(): void
    {
        $alice = $this->createUser(solde: 5000);

        Sanctum::actingAs($alice);
        $this->postJson('/api/v1/wallet/transfert', ['montant' => 100])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'destinataire_requis');
    }

    // ---------------------------------------------------------------
    // Consultation
    // ---------------------------------------------------------------

    public function test_liste_des_transactions_paginee_et_groupee_par_date(): void
    {
        $alice = $this->createUser(solde: 100000);

        Sanctum::actingAs($alice);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/wallet/recharge', [
                'montant'      => 1000,
                'phone_number' => '22891112233',
            ])->assertCreated();
        }

        $response = $this->getJson('/api/v1/wallet/transactions?page=1&limit=2')->assertOk();

        $this->assertSame(2, $response->json('pagination.limit'));
        $this->assertSame(3, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.total_pages'));
        $this->assertTrue($response->json('pagination.has_next'));

        // Les transactions sont groupées par date : 1 groupe pour la journée.
        $this->assertCount(1, $response->json('transactions'));
        $this->assertCount(2, $response->json('transactions.' . now()->format('d/m/Y')));
    }

    public function test_transactions_et_wallet_exigent_authentification(): void
    {
        $this->getJson('/api/v1/wallet')->assertStatus(401);
        $this->getJson('/api/v1/wallet/transactions')->assertStatus(401);
        $this->postJson('/api/v1/wallet/recharge', ['montant' => 5000, 'phone_number' => '22891112233'])
            ->assertStatus(401);
        $this->postJson('/api/v1/wallet/transfert', ['montant' => 100, 'destinataire_wallet_id' => 'TGW1'])
            ->assertStatus(401);
    }

    public function test_le_solde_du_wallet_ne_fuit_pas_vers_un_autre_utilisateur(): void
    {
        $alice = $this->createUser(solde: 5000);
        $bob = $this->createUser(solde: 777);

        Sanctum::actingAs($bob);
        $reponse = $this->getJson('/api/v1/wallet')->assertOk();

        // Le wallet retourné est celui de l'appelant, jamais celui d'un autre.
        $this->assertSame($bob->fresh()->wallet_id, $reponse->json('wallet.wallet_id'));
        $this->assertSame('777 Fcfa', $reponse->json('wallet.solde'));
    }
}
