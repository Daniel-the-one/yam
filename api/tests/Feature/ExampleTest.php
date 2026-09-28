<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contrat de la racine web.
 *
 * Depuis la bascule KondjiPro (17/09), la racine "/" n'est plus la SPA
 * d'appels WebRTC : elle redirige vers le portail KondjiPro /contact.php.
 * Voir routes/web.php.
 *
 * Ce test verrouille ce comportement (il échouait auparavant en attendant
 * un 200 sur "/", comportement devenu caduc).
 */
class ExampleTest extends TestCase
{
    public function test_root_redirects_to_kondjipro_contact_page(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertRedirect('/contact.php');
    }
}
