<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoApiController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoApiTest extends TestCase
{
    // Les tests de session démo vérifient qu'un jeton factice est refusé
    // par l'API RÉELLE : cela exige les tables en place.
    use RefreshDatabase;
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

    // ---------------------------------------------------------------
    // Session démo : sans ces endpoints, un client web reste bloqué sur
    // son écran de connexion et la démo n'est pas testable.
    // ---------------------------------------------------------------

    public function test_demo_login_returns_a_token_without_touching_the_database(): void
    {
        $premiere = $this->postJson('/api/demo/v1/auth/login', [
            'phone_number' => '70023786',
            'password' => 'un-mot-de-passe-quelconque',
        ])->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.token', DemoApiController::JETON_DEMO)
            ->assertJsonPath('data.user.id', 9001);

        // Identifiants absents ou invalides : la démo accepte quand même,
        // et rend exactement la même réponse. Preuve qu'aucun compte n'est
        // lu ni vérifié.
        $seconde = $this->postJson('/api/demo/v1/auth/login', [])->assertOk();

        $this->assertSame($premiere->json(), $seconde->json());
    }

    /**
     * La garantie la plus importante du mode démo : le jeton factice ne
     * doit être accepté par AUCUN endpoint réel. Sans ce test, un jour
     * quelqu'un pourrait rendre la démo utilisable pour de vraies actions.
     */
    public function test_demo_token_is_rejected_by_the_real_api(): void
    {
        $this->getJson('/api/v1/users/me', [
            'Authorization' => 'Bearer '.DemoApiController::JETON_DEMO,
        ])->assertUnauthorized();

        // Contrôle négatif : le 401 ci-dessus ne doit pas être simplement
        // « tout est refusé ». Un vrai jeton, lui, passe.
        $reel = $this->postJson('/api/v1/auth/register', [
            'phone_number' => '70023786',
            'username' => 'demo_probe',
            'password' => 'motdepasse_ecret_1',
        ]);
        $jetonReel = $reel->json('data.token');

        if ($reel->isSuccessful() && is_string($jetonReel) && $jetonReel !== '') {
            $this->getJson('/api/v1/users/me', [
                'Authorization' => 'Bearer '.$jetonReel,
            ])->assertOk();
        }

        $this->assertNotSame(
            DemoApiController::JETON_DEMO,
            is_string($jetonReel) ? $jetonReel : '',
            'Le jeton réel ne doit jamais être confondu avec celui de la démo.'
        );
    }

    public function test_demo_session_endpoints_need_no_authentication(): void
    {
        $this->getJson('/api/demo/v1/users/me')
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->assertJsonPath('data.devices_count', 1);

        $this->getJson('/api/demo/v1/users/search?q=dr')
            ->assertOk()
            ->assertJsonPath('demo', true);

        $this->postJson('/api/demo/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    /**
     * Le tunnel de démo est public : la configuration démo ne doit
     * contain aucun secret. Une régression ici divulguait les
     * identifiants TURN et la clé Pusher à quiconque ouvre le lien.
     */
    public function test_demo_config_exposes_no_secret(): void
    {
        $config = $this->getJson('/api/demo/v1/config')
            ->assertOk()
            ->assertJsonPath('demo', true)
            ->json();

        $this->assertSame('', $config['pusher']['app_key'], 'La clé Pusher ne doit jamais sortir en démo.');
        $this->assertSame('', $config['pusher']['cluster']);
        $this->assertSame('', $config['turn']['url']);
        $this->assertSame('', $config['turn']['username']);
        $this->assertSame('', $config['turn']['credential']);

        // Aucune valeur ne doit être un secret configuré côté serveur.
        foreach ($config['ice_servers'] as $serveur) {
            $this->assertArrayNotHasKey('credential', $serveur);
            $this->assertArrayNotHasKey('username', $serveur);
        }

        // Et le contrôle mordant : la config réelle, elle, en contient.
        $reel = $this->getJson('/api/v1/config')->json();
        $this->assertNotEmpty(
            $reel['pusher']['app_key'],
            'Ce test perdrait son mordant si la config réelle devenait vide.'
        );
    }

    /**
     * Toutes les routes démo portent le marqueur `demo: true`. Un client
     * peut ainsi détecter qu'il est en démo au lieu de croire à un
     * solde réel.
     */
    public function test_every_demo_endpoint_is_flagged_as_demo(): void
    {
        $appels = [
            ['get', '/api/demo/v1/wallet'],
            ['get', '/api/demo/v1/wallet/transactions'],
            ['get', '/api/demo/v1/users/me'],
            ['get', '/api/demo/v1/users/search'],
            ['get', '/api/demo/v1/config'],
            ['get', '/api/demo/v1/appels/9001'],
            ['post', '/api/demo/v1/auth/login'],
            ['post', '/api/demo/v1/auth/logout'],
            ['post', '/api/demo/v1/wallet/recharge'],
            ['post', '/api/demo/v1/wallet/transfert'],
            ['post', '/api/demo/v1/wallet/recharge/notify'],
            ['post', '/api/demo/v1/appels/init'],
            ['post', '/api/demo/v1/appels/9001/lancer'],
            ['post', '/api/demo/v1/appels/9001/decrocher'],
            ['post', '/api/demo/v1/appels/9001/heartbeat'],
            ['post', '/api/demo/v1/appels/9001/terminer'],
        ];

        foreach ($appels as [$methode, $url]) {
            $reponse = $methode === 'get'
                ? $this->getJson($url)
                : $this->postJson($url);

            $reponse->assertSuccessful();
            $this->assertTrue(
                $reponse->json('demo'),
                "{$url} ne renvoie pas demo=true : un client pourrait le prendre pour du réel."
            );
        }
    }
}
