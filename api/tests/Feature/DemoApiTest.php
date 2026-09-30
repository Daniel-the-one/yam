<?php

namespace Tests\Feature;

use Tests\TestCase;

class DemoApiTest extends TestCase
{
    public function test_wallet_demo_returns_static_fixture_independent_of_input(): void
    {
        $premiere = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 1000,
            'phone_number' => '228900000001',
        ])->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('information.payment.payment_url', '');

        $seconde = $this->postJson('/api/demo/v1/wallet/recharge', [
            'montant' => 900000,
            'phone_number' => '228900000002',
        ])->assertCreated()
            ->assertJsonPath('demo', true);

        $this->assertSame($premiere->json(), $seconde->json());
    }

    public function test_wallet_demo_never_credits_or_transfers_money(): void
    {
        $this->getJson('/api/demo/v1/wallet')
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('wallet.solde_raw', 50000);

        $this->postJson('/api/demo/v1/wallet/transfert', [
            'montant' => 1000000,
            'destinataire_wallet_id' => 'DEMO-WALLET-002',
        ])->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('information.new_balance', '-');

        $this->postJson('/api/demo/v1/wallet/recharge/notify', [
            'transaction_id' => 'DEMO-RECHARGE-001',
            'code' => 200,
        ])->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('credit', false);
    }

    public function test_paid_call_demo_returns_static_response_without_authentication(): void
    {
        $this->postJson('/api/demo/v1/appels/init', ['destination_user_id' => 123])
            ->assertCreated()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.appel_id', 9001);

        $this->postJson('/api/demo/v1/appels/9001/terminer', ['raison' => 'anything'])
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.solde_consomme', 0);

        $this->getJson('/api/demo/v1/appels/9001')
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.solde_consomme', 0);
    }
}
