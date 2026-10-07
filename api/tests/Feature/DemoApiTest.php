<?php

namespace Tests\Feature;

use App\Models\DemoAccount;
use App\Models\DemoWalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_index_exposes_shareable_api_and_postman_links(): void
    {
        $this->getJson('/api/demo/v1')
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('wallet.initial_balance_xof', 50000)
            ->assertJsonPath('authentication.header', 'Authorization: Bearer <token>')
            ->assertJsonPath('postman_collection_path', '/Yam-Demo-API.postman_collection.json')
            ->assertJsonPath('authenticated_routes.19', 'GET /appels/{appel}')
            ->assertJsonPath('endpoints.0.url', url('/api/demo/v1'))
            ->assertJsonPath('endpoints.1.url', url('/api/demo/v1/config'))
            ->assertJsonPath('endpoints.3.requires_auth', false)
            ->assertJsonPath('endpoints.16.url', url('/api/demo/v1/wallet'))
            ->assertJsonPath('endpoints.16.requires_auth', true)
            ->assertJsonPath('endpoints.21.path', '/api/demo/v1/wallet/withdraw')
            ->assertJsonPath('endpoints.30.method', 'GET');
    }

    public function test_guest_login_creates_an_isolated_persistent_wallet_and_session(): void
    {
        $first = $this->postJson('/api/demo/v1/auth/login', [])->assertOk()->json('data');
        $second = $this->postJson('/api/demo/v1/auth/login', [])->assertOk()->json('data');

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($first['user']['wallet_id'], $second['user']['wallet_id']);
        $this->assertDatabaseCount('demo_accounts', 2);
        $this->assertDatabaseCount('demo_access_tokens', 2);

        $this->getJson('/api/demo/v1/wallet', $this->auth($first['token']))
            ->assertOk()
            ->assertJsonPath('wallet.solde_raw', 50000);
        $this->getJson('/api/demo/v1/wallet', $this->auth($second['token']))
            ->assertOk()
            ->assertJsonPath('wallet.solde_raw', 50000);
    }

    public function test_register_login_and_logout_use_real_demo_credentials_without_real_auth_access(): void
    {
        $registered = $this->postJson('/api/demo/v1/auth/register', [
            'name' => 'Compte Test',
            'phone_number' => '+22890000001',
            'username' => 'compte-test',
            'password' => 'DemoPass123',
            'device_id' => 'postman-device',
        ])->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.user.name', 'Compte Test');

        $token = $registered->json('data.token');
        $account = DemoAccount::firstOrFail();
        $this->assertNotSame('DemoPass123', $account->password);
        $this->assertTrue(Hash::check('DemoPass123', $account->password));
        $this->assertDatabaseHas('demo_access_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('demo_access_tokens', ['token_hash' => $token]);

        $login = $this->postJson('/api/demo/v1/auth/login', [
            'phone_number' => '90000001',
            'password' => 'DemoPass123',
        ])->assertOk();
        $loginToken = $login->json('data.token');

        $this->getJson('/api/demo/v1/users/me', $this->auth($loginToken))
            ->assertOk()
            ->assertJsonPath('data.name', 'Compte Test');
        $this->getJson('/api/v1/users/me', $this->auth($loginToken))->assertUnauthorized();

        $this->postJson('/api/demo/v1/auth/logout', [], $this->auth($loginToken))
            ->assertOk()
            ->assertJsonPath('data.ok', true);
        $this->getJson('/api/demo/v1/users/me', $this->auth($loginToken))->assertUnauthorized();
    }

    public function test_invalid_demo_login_and_registration_payloads_are_rejected(): void
    {
        $this->postJson('/api/demo/v1/auth/login', [
            'phone_number' => '+22890000099',
            'password' => 'incorrect',
        ])->assertUnauthorized();

        $this->postJson('/api/demo/v1/auth/register', [
            'name' => 'Compte Test',
            'phone_number' => '+22890000001',
            'password' => 'DemoPass123',
        ])->assertCreated();

        $this->postJson('/api/demo/v1/auth/register', [
            'name' => 'Doublon',
            'phone_number' => '90000001',
            'password' => 'DemoPass123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_personal_demo_endpoints_require_a_valid_demo_token(): void
    {
        $this->getJson('/api/demo/v1/wallet')->assertUnauthorized();
        $this->getJson('/api/demo/v1/users/me')->assertUnauthorized();
        $this->getJson('/api/demo/v1/wallet/transactions')->assertUnauthorized();
        $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 100,
            'destinataire_wallet_id' => 'TGWNONEXISTENT',
        ])->assertUnauthorized();
        $this->getJson('/api/demo/v1/wallet', ['Authorization' => 'Bearer forged-token'])->assertUnauthorized();
    }

    public function test_wallet_is_user_scoped_and_matches_live_api_response_contract(): void
    {
        $one = $this->createDemoAccount('+22890000011', 'Client Un');
        $two = $this->createDemoAccount('+22890000012', 'Client Deux');

        $this->getJson('/api/demo/v1/wallet', $this->auth($one['token']))
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('wallet.wallet_id', $one['user']['wallet_id'])
            ->assertJsonPath('wallet.solde_raw', 50000)
            ->assertJsonPath('wallet.is_default', 1)
            ->assertJsonPath('membre.nom', 'Client Un')
            ->assertJsonPath('membre.kyc_valide', 0)
            ->assertJsonPath('dernieres_transactions', []);

        $this->getJson('/api/demo/v1/wallet', $this->auth($two['token']))
            ->assertJsonPath('wallet.wallet_id', $two['user']['wallet_id'])
            ->assertJsonPath('wallet.solde_raw', 50000);
    }

    public function test_internal_transfer_debits_and_credits_both_demo_wallets_atomically(): void
    {
        $sender = $this->createDemoAccount('+22890000021', 'Émetteur');
        $recipient = $this->createDemoAccount('+22890000022', 'Destinataire');

        $result = $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 1000,
            'destinataire_wallet_id' => $recipient['user']['wallet_id'],
        ], $this->auth($sender['token']))
            ->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('information.status', 2)
            ->assertJsonPath('information.is_internal', 1);

        $this->getJson('/api/demo/v1/wallet', $this->auth($sender['token']))
            ->assertJsonPath('wallet.solde_raw', 48900);
        $this->getJson('/api/demo/v1/wallet', $this->auth($recipient['token']))
            ->assertJsonPath('wallet.solde_raw', 51000);

        $reference = $result->json('information.reference');
        $this->assertDatabaseHas('demo_wallet_transactions', [
            'demo_account_id' => DemoAccount::where('wallet_id', $sender['user']['wallet_id'])->value('id'),
            'reference' => $reference,
            'direction' => 'debit',
            'amount' => 1000,
            'balance_after' => 48900,
            'status' => 2,
        ]);
        $this->assertDatabaseHas('demo_wallet_transactions', [
            'demo_account_id' => DemoAccount::where('wallet_id', $recipient['user']['wallet_id'])->value('id'),
            'reference' => $reference.'-C',
            'direction' => 'credit',
            'amount' => 1000,
            'balance_after' => 51000,
            'status' => 2,
        ]);
    }

    public function test_transfer_rejects_self_unknown_wallet_and_insufficient_balance(): void
    {
        $sender = $this->createDemoAccount('+22890000031', 'Émetteur');
        $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 10,
            'destinataire_wallet_id' => $sender['user']['wallet_id'],
        ], $this->auth($sender['token']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destinataire_wallet_id');

        $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 10,
            'destinataire_wallet_id' => 'TGWUNKNOWN',
        ], $this->auth($sender['token']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destinataire_wallet_id');

        $recipient = $this->createDemoAccount('+22890000032', 'Destinataire');
        $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 50000,
            'destinataire_wallet_id' => $recipient['user']['wallet_id'],
        ], $this->auth($sender['token']))->assertStatus(402);

        $this->getJson('/api/demo/v1/wallet', $this->auth($sender['token']))
            ->assertJsonPath('wallet.solde_raw', 50000);
        $this->assertDatabaseCount('demo_wallet_transactions', 0);
    }

    /**
     * POST /api/demo/v1/wallet/withdraw — retrait virtuel.
     *
     * Contrat aligné sur l'API réelle : 201 + débit immédiat, 402 si le solde
     * ne couvre pas le montant, 422 si `montant` manque, 401 sans jeton.
     */
    public function test_withdraw_debits_the_demo_balance_and_rejects_missing_amount_or_insufficient_funds(): void
    {
        $account = $this->createDemoAccount('+22890000041', 'Retrait');

        // Route dans le groupe demo.auth : sans jeton → 401.
        $this->postJson('/api/demo/v1/wallet/withdraw', ['montant' => 1000])
            ->assertUnauthorized();

        $this->postJson('/api/demo/v1/wallet/withdraw', [
            'montant'      => 2000,
            'phone_number' => '+228900000099',
        ], $this->auth($account['token']))
            ->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('status', '000')
            ->assertJsonPath('information.status', 2);

        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 48000);

        $this->assertDatabaseHas('demo_wallet_transactions', [
            'demo_account_id' => DemoAccount::where('wallet_id', $account['user']['wallet_id'])->value('id'),
            'type'            => 'WITHDRAW',
            'direction'       => 'debit',
            'amount'          => 2000,
            'balance_after'   => 48000,
            'status'          => 2,
        ]);

        // Solde insuffisant → 402, et surtout RIEN de débité en plus.
        $this->postJson('/api/demo/v1/wallet/withdraw', [
            'montant' => 49000,
        ], $this->auth($account['token']))->assertStatus(402);

        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 48000);
        $this->assertSame(1, DemoWalletTransaction::where('type', 'WITHDRAW')->count(),
            'Le rejet 402 a malgré tout écrit une transaction.');

        // `montant` absent → 422 (erreur de formulaire, pas d'erreur financière).
        $this->postJson('/api/demo/v1/wallet/withdraw', [
            'phone_number' => '+228900000099',
        ], $this->auth($account['token']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('montant');
    }

    public function test_recharge_is_pending_until_confirmation_then_credits_once(): void
    {
        $account = $this->createDemoAccount('+22890000041', 'Recharge');
        $recharge = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 2000,
            'phone_number' => '+22890000042',
        ], $this->auth($account['token']))
            ->assertCreated()
            ->assertJsonPath('information.payment.mode', 'demo')
            ->assertJsonPath('information.payment.must_be_redirected', false)
            ->assertJsonPath('information.status', 1);

        $reference = $recharge->json('information.reference');
        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 50000);

        $this->postJson('/api/demo/v1/wallet/recharge/notify', [
            'transaction_id' => $reference,
            'amount' => 9999999,
            'code' => 200,
        ], $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('credit', true);
        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 52000);

        $this->postJson('/api/demo/v1/wallet/recharge/notify', [
            'transaction_id' => $reference,
            'code' => 200,
        ], $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('credit', false)
            ->assertJsonPath('raison', 'deja_traitee');
        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 52000);
    }

    public function test_recharge_confirmation_cannot_credit_another_demo_account(): void
    {
        $owner = $this->createDemoAccount('+22890000051', 'Owner');
        $other = $this->createDemoAccount('+22890000052', 'Other');
        $reference = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 500,
            'phone_number' => '+22890000053',
        ], $this->auth($owner['token']))->assertCreated()->json('information.reference');

        $this->postJson('/api/demo/v1/wallet/recharge/notify', [
            'transaction_id' => $reference,
            'code' => 200,
        ], $this->auth($other['token']))
            ->assertOk()
            ->assertJsonPath('credit', false)
            ->assertJsonPath('raison', 'recharge_introuvable');
        $this->getJson('/api/demo/v1/wallet', $this->auth($owner['token']))
            ->assertJsonPath('wallet.solde_raw', 50000);
    }

    public function test_transactions_are_scoped_complete_and_paginated(): void
    {
        $account = $this->createDemoAccount('+22890000061', 'Historique');
        $first = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 500,
            'phone_number' => '+22890000000',
        ], $this->auth($account['token']))->assertCreated()->json('information.reference');
        $second = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 600,
            'phone_number' => '+22890000000',
        ], $this->auth($account['token']))->assertCreated()->json('information.reference');
        $other = $this->createDemoAccount('+22890000062', 'Autre');
        $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 700,
            'phone_number' => '+22890000000',
        ], $this->auth($other['token']))->assertCreated();

        $response = $this->getJson('/api/demo/v1/wallet/transactions?page=1&limit=1', $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.limit', 1)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonPath('pagination.has_next', true);

        $transaction = array_values($response->json('transactions'))[0][0];
        $this->assertSame($second, $transaction['reference']);
        $this->assertArrayHasKey('type_icon', $transaction);
        $this->assertArrayHasKey('counterpart', $transaction);

        $this->getJson('/api/demo/v1/wallet/transactions?page=2&limit=1', $this->auth($account['token']))
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.has_next', false);
        $this->assertNotSame($first, $second);
    }

    public function test_kondjipro_encaissement_uses_same_wallet_and_credits_only_after_confirmation(): void
    {
        $account = $this->createDemoAccount('+22890000071', 'Médecin');
        $this->getJson('/api/demo/v1/kondjipro/solde', $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('solde', 50000);

        $encaissement = $this->postJson('/api/demo/v1/kondjipro/encaissement', [
            'patient_id' => 9001,
            'montant' => 1500,
            'motif' => 'Consultation démo',
        ], $this->auth($account['token']))
            ->assertCreated()
            ->assertJsonPath('transaction.statut', 'en_attente');
        $reference = $encaissement->json('transaction.reference');

        $this->postJson("/api/demo/v1/kondjipro/encaissement/{$reference}/confirm", [], $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->getJson('/api/demo/v1/wallet', $this->auth($account['token']))
            ->assertJsonPath('wallet.solde_raw', 51500);
        $this->postJson("/api/demo/v1/kondjipro/encaissement/{$reference}/confirm", [], $this->auth($account['token']))
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_demo_configuration_never_exposes_server_secrets(): void
    {
        $config = $this->getJson('/api/demo/v1/config')->assertOk()->json();

        $this->assertTrue($config['demo']);
        $this->assertSame('', $config['pusher']['app_key']);
        $this->assertSame('', $config['turn']['credential']);
        foreach ($config['ice_servers'] as $server) {
            $this->assertArrayNotHasKey('credential', $server);
            $this->assertArrayNotHasKey('username', $server);
        }
    }

    public function test_demo_api_contracts_and_call_routes_are_callable_with_a_session(): void
    {
        $account = $this->createDemoAccount('+22890000081', 'Client');
        $headers = $this->auth($account['token']);

        $this->getJson('/api/demo/v1/users/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.wallet_id', $account['user']['wallet_id']);
        $this->getJson('/api/demo/v1/users/search', $headers)->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/demo/v1/contacts', $headers)->assertOk()->assertJsonPath('demo', true);
        $this->postJson('/api/demo/v1/contacts', [], $headers)->assertCreated()->assertJsonPath('demo', true);
        $this->deleteJson('/api/demo/v1/contacts/9002', [], $headers)->assertOk()->assertJsonPath('demo', true);
        $this->getJson('/api/demo/v1/devices', $headers)->assertOk()->assertJsonPath('demo', true);
        $this->postJson('/api/demo/v1/devices/register', [], $headers)->assertCreated()->assertJsonPath('demo', true);
        $this->deleteJson('/api/demo/v1/devices/demo-device-2', [], $headers)->assertOk()->assertJsonPath('demo', true);

        foreach (['ring', 'signal', 'cancel'] as $action) {
            $this->postJson("/api/demo/v1/call/{$action}", [], $headers)
                ->assertOk()
                ->assertJsonPath('demo', true)
                ->assertJsonPath('action', $action);
        }
        $this->postJson('/api/demo/v1/appels/init', [], $headers)
            ->assertCreated()
            ->assertJsonPath('data.appel_id', 9001);
        $this->postJson('/api/demo/v1/appels/9001/terminer', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.solde_consomme', 0);
        $this->getJson('/api/demo/v1/appels/9001', $headers)->assertOk()->assertJsonPath('demo', true);
        foreach (['lancer', 'decrocher', 'heartbeat'] as $action) {
            $this->postJson("/api/demo/v1/appels/9001/{$action}", [], $headers)
                ->assertOk()
                ->assertJsonPath('demo', true);
        }
    }

    private function createDemoAccount(string $phone, string $name): array
    {
        $response = $this->postJson('/api/demo/v1/auth/register', [
            'name' => $name,
            'phone_number' => $phone,
            'password' => 'DemoPass123',
            'device_id' => 'test-device',
        ])->assertCreated();

        return [
            'user' => $response->json('data.user'),
            'token' => $response->json('data.token'),
        ];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
