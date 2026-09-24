<?php

namespace Tests\Feature;

use App\Models\Appel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests du module d'appels facturés (init/lancer/decrocher/heartbeat/
 * terminer) — le code qui manipule l'argent.
 */
class AppelsFacturesTest extends TestCase
{
    use RefreshDatabase;

    private function createPatient(float $solde = 500): User
    {
        $uniq = Str::lower(Str::random(6));
        return User::factory()->create([
            'username'     => 'patient_' . $uniq,
            'phone_number' => '+2289' . rand(1000000, 9999999),
            'role'  => 'patient',
            'solde' => $solde,
        ]);
    }

    private function createMedecin(): User
    {
        $uniq = Str::lower(Str::random(6));
        return User::factory()->create([
            'username'     => 'medecin_' . $uniq,
            'phone_number' => '+2289' . rand(1000000, 9999999),
            'role'  => 'medecin',
            'solde' => 0,
        ]);
    }

    /** Crée une relation appel terminé entre patient et médecin. */
    private function createRelation(User $patient, User $medecin): Appel
    {
        return Appel::create([
            'patient_id'       => $patient->id,
            'medecin_id'       => $medecin->id,
            'initie_par'       => 'patient',
            'status'           => Appel::STATUS_TERMINE,
            'tarif_par_minute' => 100.00,
            'solde_consomme'   => 0.00,
            'date_sonnerie'    => now()->subMinutes(5),
            'date_decroche'    => now()->subMinutes(5),
            'date_fin'         => now()->subMinutes(4),
            'raison_fin'       => 'raccroche_manuel',
        ]);
    }

    public function test_init_patient_sans_solde_renvoie_402(): void
    {
        $patient = $this->createPatient(0);
        $medecin = $this->createMedecin();
        $this->createRelation($patient, $medecin);

        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/appels/init', ['destination_user_id' => $medecin->id])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'solde_insuffisant');
    }

    public function test_init_medecin_sans_relation_renvoie_403(): void
    {
        $patient = $this->createPatient();
        $medecin = $this->createMedecin();

        Sanctum::actingAs($medecin);

        $this->postJson('/api/v1/appels/init', ['destination_user_id' => $patient->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'relation_requise');
    }

    public function test_init_medecin_avec_relation_ok(): void
    {
        $patient = $this->createPatient();
        $medecin = $this->createMedecin();
        $this->createRelation($patient, $medecin);

        Sanctum::actingAs($medecin);

        $this->postJson('/api/v1/appels/init', ['destination_user_id' => $patient->id])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'initie');
    }

    public function test_init_appel_actif_renvoie_409(): void
    {
        $patient = $this->createPatient();
        $medecin = $this->createMedecin();

        Appel::create([
            'patient_id'       => $patient->id,
            'medecin_id'       => $medecin->id,
            'initie_par'       => 'patient',
            'status'           => Appel::STATUS_DECROCHE,
            'tarif_par_minute' => 100.00,
            'solde_consomme'   => 0.00,
        ]);

        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/appels/init', ['destination_user_id' => $medecin->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'appel_en_cours');
    }

    public function test_heartbeat_debite_patient_et_coupe_a_zero(): void
    {
        $patient = $this->createPatient(10); // solde faible → coupure rapide
        $medecin = $this->createMedecin();

        $appel = Appel::create([
            'patient_id'       => $patient->id,
            'medecin_id'       => $medecin->id,
            'initie_par'       => 'patient',
            'status'           => Appel::STATUS_DECROCHE,
            'tarif_par_minute' => 100.00,
            'solde_consomme'   => 0.00,
            'date_decroche'    => now()->subSeconds(30), // déjà 30 s → 50 F dus
        ]);

        Sanctum::actingAs($patient);

        $res = $this->postJson('/api/v1/appels/' . $appel->id . '/heartbeat')
            ->assertOk();

        // 30 s × 100/60 = 50 F, mais solde = 10 → coupure à 0.
        // Le calcul se fait à l'instant now() (≈ 30+ secondes), d'où une
        // légère marge (50.00–50.20 F) — on vérifie la coupure et la fourchette.
        $this->assertFalse($res->json('data.continuer'));
        $this->assertEquals(0, $res->json('data.solde_restant'));

        $this->assertDatabaseHas('appels', [
            'id'             => $appel->id,
            'status'         => Appel::STATUS_TERMINE,
            'raison_fin'     => 'solde_epuise',
        ]);
        $appelDb = \App\Models\Appel::find($appel->id);
        $this->assertGreaterThanOrEqual(50.00, $appelDb->solde_consomme);
        $this->assertLessThanOrEqual(51.50, $appelDb->solde_consomme);
        $this->assertDatabaseHas('users', [
            'id'    => $patient->id,
            'solde' => 0,
        ]);
    }

    public function test_heartbeat_medecin_ne_voit_pas_le_solde(): void
    {
        $patient = $this->createPatient(500);
        $medecin = $this->createMedecin();

        $appel = Appel::create([
            'patient_id'       => $patient->id,
            'medecin_id'       => $medecin->id,
            'initie_par'       => 'patient',
            'status'           => Appel::STATUS_DECROCHE,
            'tarif_par_minute' => 100.00,
            'solde_consomme'   => 0.00,
            'date_decroche'    => now()->subSeconds(10),
        ]);

        Sanctum::actingAs($medecin);

        $res = $this->postJson('/api/v1/appels/' . $appel->id . '/heartbeat')
            ->assertOk();

        $this->assertTrue($res->json('data.continuer'));
        $this->assertNull($res->json('data.solde_restant'));
    }

    public function test_terminer_double_appel_transmet_une_seule_fois(): void
    {
        // M1 revue : temps FIGÉ pour une durée d'appel EXACTE (10 s) →
        // coût exact 16.67 F, sans fourchette fragile dépendante de la
        // vitesse d'exécution du test (CI lente, machine chargée...).
        Carbon::setTestNow('2026-09-16 12:00:00');

        try {
            $patient = $this->createPatient(500);
            $medecin = $this->createMedecin();

            $appel = Appel::create([
                'patient_id'       => $patient->id,
                'medecin_id'       => $medecin->id,
                'initie_par'       => 'patient',
                'status'           => Appel::STATUS_DECROCHE,
                'tarif_par_minute' => 100.00,
                'solde_consomme'   => 0.00,
                'date_decroche'    => now()->subSeconds(10),
            ]);

            // Premier terminer (patient) → OK.
            Sanctum::actingAs($patient);
            $this->postJson('/api/v1/appels/' . $appel->id . '/terminer', ['raison' => 'raccroche_manuel'])
                ->assertOk();

            // Second terminer (médecin, concurrent) → 409, pas de double transmission.
            Sanctum::actingAs($medecin);
            $this->postJson('/api/v1/appels/' . $appel->id . '/terminer', ['raison' => 'raccroche_manuel'])
                ->assertStatus(409);

            // Règlement final EXACT : 10 s × 100/60 = 16.67 F.
            $this->assertDatabaseHas('appels', [
                'id'     => $appel->id,
                'status' => Appel::STATUS_TERMINE,
            ]);
            $appelDb = \App\Models\Appel::find($appel->id);
            $this->assertEqualsWithDelta(16.67, (float) $appelDb->solde_consomme, 0.01);
            $this->assertDatabaseHas('users', [
                'id'    => $patient->id,
                'solde' => 500 - $appelDb->solde_consomme,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_appel_non_participant_renvoie_403(): void
    {
        $patient = $this->createPatient();
        $medecin = $this->createMedecin();
        $intrus = $this->createPatient();

        $appel = $this->createRelation($patient, $medecin);

        Sanctum::actingAs($intrus);

        $this->getJson('/api/v1/appels/' . $appel->id)
            ->assertStatus(403);
    }

    public function test_init_termine_appel_orphelin_decroche(): void
    {
        $patient = $this->createPatient(500);
        $medecin = $this->createMedecin();
        $this->createRelation($patient, $medecin);

        // Appel décroché mais ORPHELIN : plus de 60 s sans heartbeat.
        $appel = Appel::create([
            'patient_id'       => $patient->id,
            'medecin_id'       => $medecin->id,
            'initie_par'       => 'patient',
            'status'           => Appel::STATUS_DECROCHE,
            'tarif_par_minute' => 100.00,
            'solde_consomme'   => 0.00,
            'date_decroche'    => now()->subSeconds(120),
        ]);
        // Force updated_at dans le passé pour le rendre "orphelin"
        // (updated_at n'est pas dans $fillable → forceFill contourne la
        // protection de mass assignment ; le $dateFormat µs est appliqué).
        $appel->forceFill(['updated_at' => now()->subSeconds(120)])->save();

        Sanctum::actingAs($patient);

        // init() nettoie les orphelins AVANT le contrôle d'appel actif :
        // sans ce nettoyage, le couple serait bloqué en 409 pour toujours.
        $this->postJson('/api/v1/appels/init', ['destination_user_id' => $medecin->id])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'initie');

        // L'orphelin est auto-terminé avec raison_fin=timeout.
        $this->assertDatabaseHas('appels', [
            'id'         => $appel->id,
            'status'     => Appel::STATUS_TERMINE,
            'raison_fin' => 'timeout',
        ]);

        // Règlement final appliqué : ~120 s × 100/60 ≈ 200 F débités.
        $appelDb = \App\Models\Appel::find($appel->id);
        $this->assertGreaterThanOrEqual(200.00, (float) $appelDb->solde_consomme);
        $this->assertLessThanOrEqual(201.00, (float) $appelDb->solde_consomme);
        $this->assertDatabaseHas('users', [
            'id'    => $patient->id,
            'solde' => 500 - $appelDb->solde_consomme,
        ]);
    }
}
