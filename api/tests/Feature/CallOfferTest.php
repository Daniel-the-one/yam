<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mécanisme de récupération d'offre SDP différée.
 *
 * Couvre :
 *  - le stockage d'une offre (POST /call/signal type=offer + call_id)
 *  - la récupération (GET /call/{call_id}/offer)
 *  - les erreurs 403 / 404 / 410 / 422
 *  - l'invalidation par type=bye
 *  - le rafraîchissement du TTL à l'upsert
 */
class CallOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * POST /api/v1/call/signal authentifié (route protégée par Sanctum).
     */
    protected function signalPost(array $data): \Illuminate\Testing\TestResponse
    {
        $uniq = \Illuminate\Support\Str::random(6);
        $caller = \App\Models\User::factory()->create([
            'name'     => 'Alice ' . $uniq,
            'username' => 'alice_' . $uniq,
        ]);
        Sanctum::actingAs($caller);
        return $this->postJson('/api/v1/call/signal', $data);
    }

    public function test_stores_offer_and_retrieves_it(): void
    {
        $callId = 'test-call-1';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'v=0\r\no=alice', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")
            ->assertOk()
            ->assertJson([
                'ok'             => true,
                'call_id'        => $callId,
                'from_device_id' => 'dev-alice',
                'to_device_id'   => 'dev-bob',
                'type'           => 'offer',
                'payload'        => ['sdp' => 'v=0\r\no=alice', 'type' => 'offer'],
            ])
            ->assertJsonStructure(['expires_at']);
    }

    public function test_forbidden_device_returns_403(): void
    {
        $callId = 'test-call-2';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-eve")
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => 'forbidden_device']);
    }

    public function test_offer_routed_by_user_id_is_retrievable_by_target_device(): void
    {
        // Modèle multi-appareils : l'offre est routée par to_user_id (sans
        // to_device_id). Le device demandeur doit appartenir à l'utilisateur
        // cible pour récupérer l'offre.
        $callId = 'test-call-user-route';

        $target = \App\Models\User::factory()->create([
            'name'     => 'Bob ' . \Illuminate\Support\Str::random(6),
            'username' => 'bob_' . \Illuminate\Support\Str::random(6),
        ]);
        \App\Models\Device::create([
            'user_id'   => $target->id,
            'device_id' => 'dev-bob-1',
            'label'     => 'Web',
            'platform'  => 'web',
        ]);

        $this->signalPost([
            'to_user_id'     => $target->id,
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'v=0\r\no=alice', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk()->assertJson(['ok' => true]);

        // Le device de l'utilisateur cible peut récupérer l'offre.
        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob-1")
            ->assertOk()
            ->assertJson([
                'ok'             => true,
                'call_id'        => $callId,
                'from_device_id' => 'dev-alice',
                'type'           => 'offer',
                'payload'        => ['sdp' => 'v=0\r\no=alice', 'type' => 'offer'],
            ]);

        // Un device d'un autre utilisateur est refusé.
        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-eve")
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => 'forbidden_device']);
    }

    public function test_missing_offer_returns_404(): void
    {
        $this->getJson('/api/v1/call/unknown-call/offer?device_id=dev-bob')
            ->assertStatus(404)
            ->assertJson(['ok' => false, 'error' => 'offer_not_found']);
    }

    public function test_stores_nested_rtc_session_description_offer(): void
    {
        $target = \App\Models\User::factory()->create([
            'name' => 'Bob',
            'username' => 'bob_' . \Illuminate\Support\Str::random(6),
        ]);
        \App\Models\Device::create([
            'user_id' => $target->id,
            'device_id' => 'dev-bob-nested',
            'label' => 'Web',
            'platform' => 'web',
        ]);

        $callId = 'test-call-nested-sdp';
        $this->signalPost([
            'to_user_id' => $target->id,
            'from_device_id' => 'dev-alice',
            'type' => 'offer',
            'payload' => ['sdp' => ['type' => 'offer', 'sdp' => 'v=0\\r\\no=alice']],
            'call_id' => $callId,
        ])->assertOk();

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob-nested")
            ->assertOk()
            ->assertJsonPath('payload.sdp.sdp', 'v=0\\r\\no=alice');
    }

    public function test_expired_offer_returns_410(): void
    {
        $callId = 'test-call-expired';

        DB::table('call_offers')->insert([
            'call_id'        => $callId,
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'sdp'            => json_encode(['sdp' => 'sdp', 'type' => 'offer']),
            'expires_at'     => now()->subSeconds(1),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")
            ->assertStatus(410)
            ->assertJson(['ok' => false, 'error' => 'offer_expired']);
    }

    public function test_missing_device_id_returns_422(): void
    {
        $this->getJson('/api/v1/call/some-call/offer')
            ->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_bye_invalidates_offer(): void
    {
        $callId = 'test-call-bye';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")->assertOk();

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'bye',
            'call_id'        => $callId,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")
            ->assertStatus(404)
            ->assertJson(['ok' => false, 'error' => 'offer_not_found']);
    }

    public function test_offer_without_sdp_is_not_stored(): void
    {
        $callId = 'test-call-no-sdp';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['type' => 'offer'], // pas de sdp
            'call_id'        => $callId,
        ])->assertOk();

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")
            ->assertStatus(404);
    }

    public function test_answer_with_call_id_is_not_stored(): void
    {
        $callId = 'test-call-answer';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'answer',
            'payload'        => ['sdp' => 'answer-sdp'],
            'call_id'        => $callId,
        ])->assertOk();

        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob")
            ->assertStatus(404);
    }

    public function test_upsert_refreshes_expires_at(): void
    {
        $callId = 'test-call-upsert';

        // Contrôle du temps pour vérifier le rafraîchissement du TTL.
        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:00');

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'v1', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        $first = DB::table('call_offers')->where('call_id', $callId)->first();
        $this->assertSame('2026-01-01 10:01:30', $first->expires_at); // +90 s

        // Avance de 10 s puis nouvel upsert : le TTL doit être rafraîchi.
        \Illuminate\Support\Carbon::setTestNow('2026-01-01 10:00:10');

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'v2', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        $second = DB::table('call_offers')->where('call_id', $callId)->first();

        $this->assertSame(1, DB::table('call_offers')->where('call_id', $callId)->count());
        $this->assertSame('v2', json_decode($second->sdp, true)['sdp']);
        $this->assertSame('2026-01-01 10:01:40', $second->expires_at); // rafraîchi à +90 s

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_call_id_too_long_returns_422(): void
    {
        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => str_repeat('a', 65),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('call_id');
    }

    public function test_storage_failure_does_not_break_signal(): void
    {
        // Simule une panne de stockage en pointant vers une table inexistante
        // via un mock partiel du contrôleur.
        $controller = \Mockery::mock(\App\Http\Controllers\SignalController::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('handleDeferredOffer')
            ->andThrow(new \RuntimeException('db down'));

        $request = \Illuminate\Http\Request::create(
            '/api/v1/call/signal',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'to_device_id'   => 'dev-bob',
                'from_device_id' => 'dev-alice',
                'type'           => 'offer',
                'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
                'call_id'        => 'test-call-fail',
            ])
        );
        $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag([
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => 'test-call-fail',
        ]));

        $response = $controller->signal($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->getData(true));
    }

    public function test_offer_routed_by_user_is_retrievable_by_any_device_of_target_user(): void
    {
        // Crée un utilisateur cible avec deux appareils.
        $user = \App\Models\User::factory()->create(['name' => 'Bob', 'username' => 'bob']);
        \App\Models\Device::create([
            'user_id'   => $user->id,
            'device_id' => 'dev-bob-1',
            'label'     => 'Web',
            'platform'  => 'web',
        ]);
        \App\Models\Device::create([
            'user_id'   => $user->id,
            'device_id' => 'dev-bob-2',
            'label'     => 'Android',
            'platform'  => 'android',
        ]);

        $callId = 'test-call-user-routed';

        // L'appelant envoie l'offre routée par to_user_id (to_device_id peut
        // être quelconque, le routage se fait par l'utilisateur).
        $this->signalPost([
            'to_device_id'   => 'dev-bob-1',
            'to_user_id'     => $user->id,
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp-user', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        // Le device 1 de l'utilisateur cible peut récupérer l'offre.
        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob-1")
            ->assertOk()
            ->assertJson([
                'ok'      => true,
                'payload' => ['sdp' => 'sdp-user', 'type' => 'offer'],
            ]);

        // Le device 2 (même utilisateur) peut aussi la récupérer.
        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-bob-2")
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_offer_routed_by_user_rejects_device_of_another_user(): void
    {
        $target = \App\Models\User::factory()->create(['name' => 'Bob', 'username' => 'bob']);
        \App\Models\Device::create([
            'user_id'   => $target->id,
            'device_id' => 'dev-bob',
            'label'     => 'Web',
            'platform'  => 'web',
        ]);
        $other = \App\Models\User::factory()->create(['name' => 'Eve', 'username' => 'eve']);
        \App\Models\Device::create([
            'user_id'   => $other->id,
            'device_id' => 'dev-eve',
            'label'     => 'Web',
            'platform'  => 'web',
        ]);

        $callId = 'test-call-user-routed-forbidden';

        $this->signalPost([
            'to_device_id'   => 'dev-bob',
            'to_user_id'     => $target->id,
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => $callId,
        ])->assertOk();

        // Le device d'un AUTRE utilisateur ne peut pas récupérer l'offre.
        $this->getJson("/api/v1/call/{$callId}/offer?device_id=dev-eve")
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'error' => 'forbidden_device']);
    }

    public function test_ring_and_signal_require_authentication(): void
    {
        // Sans token → 401 sur ring.
        $this->postJson('/api/v1/call/ring', [
            'to_user_id'     => 1,
            'from_device_id' => 'dev-alice',
            'type'           => 'audio',
        ])->assertStatus(401);

        // Sans token → 401 sur signal.
        $this->postJson('/api/v1/call/signal', [
            'to_device_id'   => 'dev-bob',
            'from_device_id' => 'dev-alice',
            'type'           => 'offer',
            'payload'        => ['sdp' => 'sdp', 'type' => 'offer'],
            'call_id'        => 'test-call-auth',
        ])->assertStatus(401);
    }
}
