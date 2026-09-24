<?php

namespace Tests\Feature;

use App\Events\CallSignal;
use App\Events\IncomingCall;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAndCallFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $attrs = []): User
    {
        $uniq = Str::lower(Str::random(6));
        return User::factory()->create(array_merge([
            'name'         => 'User ' . $uniq,
            'username'     => 'user_' . $uniq,
            'phone_number' => '+2289' . rand(1000000, 9999999),
        ], $attrs));
    }

    public function test_protected_routes_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/users/me')->assertStatus(401);
        $this->getJson('/api/v1/users/search?q=test')->assertStatus(401);
        $this->getJson('/api/v1/contacts')->assertStatus(401);
        $this->getJson('/api/v1/devices')->assertStatus(401);
        $this->postJson('/api/v1/devices/register', ['label' => 'test', 'device_id' => 'dev-1'])->assertStatus(401);
        $this->deleteJson('/api/v1/devices/dev-1')->assertStatus(401);
        $this->postJson('/api/v1/call/ring', ['to_user_id' => 1, 'from_device_id' => 'dev-1'])->assertStatus(401);
        $this->postJson('/api/v1/call/signal', ['type' => 'bye', 'from_device_id' => 'dev-1'])->assertStatus(401);
    }

    public function test_authenticated_user_can_access_me_and_contacts(): void
    {
        $user = $this->createUser(['name' => 'Test User', 'username' => 'testuser']);
        $otherUser = $this->createUser(['name' => 'Other Contact', 'username' => 'othercontact']);

        Sanctum::actingAs($user);

        // GET /users/me
        $meRes = $this->getJson('/api/v1/users/me')->assertOk();
        $this->assertEquals($user->id, $meRes->json('data.id'));

        // GET /contacts - n'inclut pas l'utilisateur lui-même
        $contactsRes = $this->getJson('/api/v1/contacts')->assertOk();
        $contacts = $contactsRes->json('contacts');
        $this->assertCount(1, $contacts);
        $this->assertEquals($otherUser->id, $contacts[0]['user_id']);
    }

    public function test_ring_broadcasts_incoming_call_without_duplicates(): void
    {
        Event::fake([IncomingCall::class]);

        $caller = $this->createUser(['name' => 'Caller Alice']);
        $callee = $this->createUser(['name' => 'Callee Bob']);

        // Créer 2 devices pour Bob
        Device::create([
            'user_id'   => $callee->id,
            'device_id' => 'bob-dev-1',
            'label'     => 'Bob Web',
            'platform'  => 'web',
        ]);
        Device::create([
            'user_id'   => $callee->id,
            'device_id' => 'bob-dev-2',
            'label'     => 'Bob Phone',
            'platform'  => 'android',
        ]);

        Sanctum::actingAs($caller);

        $response = $this->postJson('/api/v1/call/ring', [
            'to_user_id'     => $callee->id,
            'from_device_id' => 'alice-dev-1',
            'from_username'  => 'Caller Alice',
            'type'           => 'audio',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.ok'));
        $this->assertEquals(2, $response->json('data.target_devices_notified'));

        Event::assertDispatched(IncomingCall::class, 2);
    }

    public function test_bye_signal_routes_immediately_to_correct_device(): void
    {
        Event::fake([CallSignal::class]);

        $alice = $this->createUser(['name' => 'Alice']);
        $bob = $this->createUser(['name' => 'Bob']);

        Device::create(['user_id' => $bob->id, 'device_id' => 'bob-dev-unique', 'label' => 'Bob Web', 'platform' => 'web']);

        Sanctum::actingAs($alice);

        $res = $this->postJson('/api/v1/call/signal', [
            'to_device_id'   => 'bob-dev-unique',
            'from_device_id' => 'alice-dev-unique',
            'type'           => 'bye',
            'call_id'        => 'uuid-test-123',
        ]);

        $res->assertOk();
        Event::assertDispatched(CallSignal::class, function ($e) {
            return $e->toDeviceId === 'bob-dev-unique' && $e->type === 'bye';
        });
    }

    public function test_user_search_deduplicates_and_excludes_current_user(): void
    {
        $current = $this->createUser(['name' => 'Jean Marc', 'username' => 'jmarc']);
        $target1 = $this->createUser(['name' => 'Marc Dupont', 'username' => 'mdupont']);

        Sanctum::actingAs($current);

        $res = $this->getJson('/api/v1/users/search?q=Marc')->assertOk();
        $results = $res->json('data');

        $this->assertCount(1, $results);
        $this->assertEquals($target1->id, $results[0]['id']);
    }
}
