<?php

namespace App\Console\Commands;

use App\Models\Appel;
use App\Models\Device;
use App\Models\IdempotencyKey;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée les comptes de démonstration nécessaires pour tester l'API à la main
 * (Postman, Insomnia, curl…).
 *
 * POURQUOI CE N'EST PAS FAIT PAR L'API :
 *   - `POST /auth/register` force `role = 'patient'` (choix volontaire). Il n'y a
 *     donc AUCUN moyen de créer un médecin par l'API, alors que 6 endpoints
 *     d'appels l'exigent comme destinataire.
 *   - Aucun endpoint ne crédite un solde. Or `appels/init` initiated par un
 *     patient refuse de démarrer sans solde, et `wallet/transfert` répond 402.
 *
 * Les comptes sont adressables par un numéro de téléphone FIXE : ils peuvent
 * donc être supprimés proprement, et surtout ils ne polluent pas les tests.
 * Ils sont marqués `phone_verified = true` pour ne pas bloquer un éventuel
 * contrôle de vérification.
 *
 * Refusé hors développement : ce n'est pas une commande de production.
 */
class SeedDemoAccounts extends Command
{
    protected $signature = 'yam:seed-demo
                            {--fresh : Supprimer puis recréer les comptes de démo}';

    protected $description = 'Crée un patient, un médecin et un patientApprovisionné pour les tests manuels de l\'API.';

    /** Numéros fixes : chaque compte reste adressable et supprimable. */
    private const COMPTES = [
        [
            'cle'         => 'patient',
            'phone'       => '+228900000001',
            'name'        => 'Demo Patient',
            'role'        => 'patient',
            'solde'       => 0,
        ],
        [
            'cle'         => 'medecin',
            'phone'       => '+228900000002',
            'name'        => 'Demo Medecin',
            'role'        => 'medecin',
            'solde'       => 0,
        ],
        [
            'cle'         => 'patient_approvisionne',
            'phone'       => '+228900000003',
            'name'        => 'Demo Patient Approvisionne',
            'role'        => 'patient',
            // Solde suffisant pour initier un appel facturé (tarif 100 F/min)
            // et pour faire un transfert de 1 000 F + frais.
            'solde'       => 50_000,
        ],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error("Refusé : cette commande crée des comptes de test. Environnement actuel : " . app()->environment() . '.');

            return self::FAILURE;
        }

        $motDePasse = (string) config('yam.demo_password', 'DemoPass123!');

        if ($this->option('fresh')) {
            $this->supprimer();
        }

        $this->info('Comptes de démonstration');
        $this->line('');

        foreach (self::COMPTES as $compte) {
            $user = $this->creerOumettreAJour($compte, $motDePasse);
            $this->line(sprintf(
                '  %-24s %-16s role=%-8s solde=%9s  id=%-4s wallet=%s',
                $compte['cle'],
                $user->phone_number,
                $user->role,
                number_format((float) $user->solde, 0, ',', ' '),
                $user->id,
                $user->wallet_id ?? '(créé au 1er GET /wallet)',
            ));
        }

        $this->line('');
        $this->info('Mot de passe commun : ' . $motDePasse);
        $this->comment('  Les numéros sont FIXES pour être supprimables. Pour repartir de zéro :');
        $this->comment('    php artisan yam:seed-demo --fresh');

        return self::SUCCESS;
    }

    private function creerOuMettreAJour(array $compte, string $motDePasse): User
    {
        return User::updateOrCreate(
            ['phone_number' => $compte['phone']],
            [
                'name'           => $compte['name'],
                'username'       => Str::slug($compte['name'], '_') . '_demo',
                'role'           => $compte['role'],
                'solde'          => $compte['solde'],
                'password'       => Hash::make($motDePasse),
                'phone_verified' => true,
                'device_id'      => 'demo-' . $compte['cle'],
                'platform'       => 'web',
                'is_online'      => false,
            ]
        );
    }

    /**
     * Tables enfants à purger avant les users, avec leurs colonnes de clé
     * étrangère réelle (requise depuis information_schema sur MySQL).
     */
    private const LIAISONS = [
        Appel::class         => ['patient_id', 'medecin_id'],
        Transaction::class   => ['user_id'],
        Device::class        => ['user_id'],
        IdempotencyKey::class => ['user_id'],
    ];

    private function supprimer(): void
    {
        $telephones = array_column(self::COMPTES, 'phone');

        // Les lignes enfants doivent partir AVORT les users : MySQL refuse le
        // delete du parent tant qu'une clé étrangère le référence (erreur 1451).
        // Tout est dans une transaction : soit le reset est complet, soit rien.
        DB::transaction(function () use ($telephones) {
            $ids = User::whereIn('phone_number', $telephones)->pluck('id');

            if ($ids->isEmpty()) {
                $this->line('  Aucun compte de démo à supprimer.');

                return;
            }

            $nettoyees = 0;
            foreach (self::LIAISONS as $classe => $colonnes) {
                $requete = $classe::query();
                foreach ($colonnes as $i => $colonne) {
                    // orWhere à partir de la 2e colonne : la 1re doit rester un
                    // where, sinon on effacerait les lignes des autres comptes.
                    $requete->{$i === 0 ? 'whereIn' : 'orWhereIn'}($colonne, $ids);
                }
                $nettoyees += $requete->delete();
            }

            $supprimes = User::whereIn('id', $ids)->delete();
            $this->line("  $supprimes compte(s) de démo supprimé(s), $nettoyees ligne(s) liée(s) nettoyée(s).");
        });
    }
}
