<?php

namespace Tests\Feature;

use App\Http\Controllers\DebugController;
use Tests\TestCase;

/**
 * Sécurité de DebugController (/api/v1/debug/logs).
 *
 * La route n'est enregistrée que si APP_DEBUG=true, mais ce « seulement
 * si » ne protège personne : un APP_DEBUG=true oublié en local derrière
 * un tunnel de démonstration suffit à rendre les secrets lisibles par
 * quiconque. D'où ce test, qui vérifie le masquage au niveau du
 * contrôleur — indépendant de l'enregistrement de la route.
 */
class DebugRouteSecretLeakTest extends TestCase
{
    public function test_debug_route_is_not_registered_when_debug_is_off(): void
    {
        // Contrôle mordant : sans ceci, la suite ne prouverait rien.
        // Si APP_DEBUG passait à true sans qu'on le remarque, ce test
        // échouerait — c'est exactement le signal recherché.
        $this->assertFalse(config('app.debug'), 'APP_DEBUG devrait être false pendant les tests.');

        $this->getJson('/api/v1/debug/logs')->assertNotFound();
    }

    public function test_debug_controller_never_returns_secret_values(): void
    {
        // Secrets réalistes : le test doit échouer si ces chaînes
        // apparaissent, pas seulement si le masque est présent.
        $_ENV['APP_KEY'] = 'base64:AAAACHERCHEQUEJAMAISPUBLIE';
        $_ENV['REVERB_APP_SECRET'] = 'secret-reverb-a-ne-pas-publier';
        $_ENV['REVERB_APP_KEY'] = 'cle-reverb-a-ne-pas-publier';

        $reponse = app(DebugController::class)->logs();
        $brut = $reponse->getContent();

        $this->assertStringNotContainsString('AAAACHERCHEQUEJAMAISPUBLIE', $brut);
        $this->assertStringNotContainsString('secret-reverb-a-ne-pas-publier', $brut);
        $this->assertStringNotContainsString('cle-reverb-a-ne-pas-publier', $brut);

        // L'information utile au diagnostic reste là : on retire le
        // secret, pas la présence d'un problème de configuration.
        $env = $reponse->getData(true)['env'];
        $this->assertSame('(défini, masqué)', $env['APP_KEY']);
        $this->assertSame('(défini, masqué)', $env['REVERB_APP_SECRET']);
        $this->assertSame('(défini, masqué)', $env['REVERB_APP_KEY']);

        // Une variable anodine reste lisible en clair.
        $this->assertSame('testing', $env['APP_ENV']);
    }
}