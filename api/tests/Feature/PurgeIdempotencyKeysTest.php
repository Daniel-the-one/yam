<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purge des clés d'idempotence.
 *
 * Ces clés protègent l'argent : les effacer trop tôt rouvre la fenêtre de double
 * débit. Les tests ci-dessous verrouillent donc le comportement de RÉTENTION
 * autant que le comportement de suppression.
 */
class PurgeIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    private function creerCle(User $user, string $ageEnJours, string $status = IdempotencyKey::STATUS_TERMINE): IdempotencyKey
    {
        $cle = IdempotencyKey::create([
            'user_id'        => $user->id,
            'endpoint'       => 'wallet/transfert',
            'idem_key'       => Str::random(24) . '-' . Str::random(4),
            'request_hash'   => hash('sha256', Str::random(32)),
            'status'         => $status,
            'response_status' => $status === IdempotencyKey::STATUS_TERMINE ? 200 : null,
            'response_body'  => $status === IdempotencyKey::STATUS_TERMINE ? '{"ok":true}' : null,
        ]);

        // `created_at` pilote la purge : on le recule explicitement plutôt que de
        // dépendre du temps de l'horloge du test.
        $cle->forceFill(['created_at' => now()->subDays($ageEnJours)])->save();

        return $cle->fresh();
    }

    public function test_purge_supprime_les_cles_ages_et_conserve_les_recentes(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        $vieille = $this->creerCle($user, 45);
        $tresVieille = $this->creerCle($user, 400);
        $recente = $this->creerCle($user, 2);

        $this->artisan('yam:purge-idempotency-keys')
            ->assertExitCode(0);

        $restantes = IdempotencyKey::pluck('id')->all();

        $this->assertNotContains($vieille->id, $restantes, 'Une clé de 45 jours doit être purgée.');
        $this->assertNotContains($tresVieille->id, $restantes, 'Une clé de 400 jours doit être purgée.');
        $this->assertContains($recente->id, $restantes, 'Une clé de 2 jours doit être conservée.');
    }

    public function test_dry_run_ne_supprime_rien(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        $vieille = $this->creerCle($user, 90);
        $avant = IdempotencyKey::count();

        $this->artisan('yam:purge-idempotency-keys', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame($avant, IdempotencyKey::count(), '--dry-run ne doit écrire aucune ligne.');
        $this->assertTrue(IdempotencyKey::whereKey($vieille->id)->exists(), '--dry-run doit conserver la clé.');
    }

    public function test_retention_desactive_la_protection_dargent_est_refusee(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        $vieille = $this->creerCle($user, 60);
        $avant = IdempotencyKey::count();

        // --days=1 est un entier valide mais SOUS le plancher de 7 jours : c'est
        // ce cas qui doit être refusé. (--days=0 tomberait dans le contrôle
        // « non positif » et ne testerait donc pas le plancher.)
        $this->artisan('yam:purge-idempotency-keys', ['--days' => 1])
            ->assertExitCode(1);

        $this->assertSame($avant, IdempotencyKey::count(), 'Une rétention refusée ne doit rien supprimer.');
        $this->assertTrue(IdempotencyKey::whereKey($vieille->id)->exists());
    }

    public function test_retention_nulle_ou_negative_est_refusee(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        $vieille = $this->creerCle($user, 60);
        $avant = IdempotencyKey::count();

        // --days=0 purgeait TOUTE la table et rouvrait le double débit.
        $this->artisan('yam:purge-idempotency-keys', ['--days' => 0])->assertExitCode(1);
        $this->artisan('yam:purge-idempotency-keys', ['--days' => -5])->assertExitCode(1);

        $this->assertSame($avant, IdempotencyKey::count(), 'Une rétention refusée ne doit rien supprimer.');
        $this->assertTrue(IdempotencyKey::whereKey($vieille->id)->exists());
    }

    public function test_retention_non_numerique_est_refusee(): void
    {
        $this->artisan('yam:purge-idempotency-keys', ['--days' => 'beaucoup'])
            ->assertExitCode(1);
    }

    public function test_option_days_respecte_la_retention_demandee(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        // De part et d'autre du seuil --days=10, avec une marge : `created_at` est
        // un DATETIME à la seconde (migration 000002), donc une clé créée dans la
        // SEULE seconde du seuil s'y compare comme égale et n'est pas purgée.
        // Ce biais est volontaire — conserver est plus sûr que supprimer.
        $horsDelai = $this->creerCle($user, 11);
        $dansDelai = $this->creerCle($user, 9);

        $this->artisan('yam:purge-idempotency-keys', ['--days' => 10])->assertExitCode(0);

        $restantes = IdempotencyKey::pluck('id')->all();
        $this->assertNotContains($horsDelai->id, $restantes, '11 jours > rétention de 10 : purgée.');
        $this->assertContains($dansDelai->id, $restantes, '9 jours < rétention de 10 : conservée.');
    }

    public function test_les_reservations_en_cours_anterieures_sont_purgees(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        // Une réservation in_progress oubliée bloquerait sa clé indéfiniment
        // (tout rejeu rendrait 409). Passé la rétention, elle doit partir.
        $bloquante = $this->creerCle($user, 60, IdempotencyKey::STATUS_EN_COURS);
        $bloquanteRecente = $this->creerCle($user, 1, IdempotencyKey::STATUS_EN_COURS);

        $this->artisan('yam:purge-idempotency-keys')->assertExitCode(0);

        $restantes = IdempotencyKey::pluck('id')->all();
        $this->assertNotContains($bloquante->id, $restantes);
        $this->assertContains(
            $bloquanteRecente->id,
            $restantes,
            'Une réservation en cours de la veille doit rester : l\'opération peut toujours tourner.',
        );
    }

    public function test_purge_par_lots_traite_plus_de_une_taille_de_lot(): void
    {
        $user = User::factory()->create([
            'username'     => 'user_' . Str::lower(Str::random(8)),
            'phone_number' => '+2289' . random_int(1000000, 9999999),
        ]);

        // Plus que la taille d'un lot (500) : prouve que la boucle de lots
        // va au bout au lieu de ne traiter que la première tranche.
        for ($i = 0; $i < 520; $i++) {
            $this->creerCle($user, 90);
        }

        $this->assertSame(520, IdempotencyKey::count());

        $this->artisan('yam:purge-idempotency-keys')->assertExitCode(0);

        $this->assertSame(0, IdempotencyKey::count(), 'Toutes les clés anciennes doivent partir, tous lots confondus.');
    }
}