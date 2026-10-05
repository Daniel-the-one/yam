<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create([
            'username' => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
            'role' => 'patient',
        ]);
    }

    public function test_contacts_are_saved_listed_and_removed_for_the_authenticated_user(): void
    {
        $owner = $this->createUser();
        $contact = $this->createUser();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/contacts', ['contact_user_id' => $contact->id])
            ->assertCreated()
            ->assertJsonPath('ok', true);
        $this->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonPath('contacts.0.user_id', $contact->id);

        $this->deleteJson('/api/v1/contacts/' . $contact->id)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(0, 'contacts');
    }

    public function test_user_cannot_add_themselves_as_a_contact(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/contacts', ['contact_user_id' => $user->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_user_id');
    }

    public function test_adding_the_same_contact_twice_is_idempotent(): void
    {
        $owner = $this->createUser();
        $contact = $this->createUser();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/contacts', ['contact_user_id' => $contact->id])->assertCreated();
        $this->postJson('/api/v1/contacts', ['contact_user_id' => $contact->id])->assertCreated();

        $this->assertDatabaseCount('user_contacts', 1);
    }
}
