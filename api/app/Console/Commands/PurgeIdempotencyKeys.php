<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Purge les clés d'idempotence trop anciennes.
 *
 * POURQUOI CELA EXISTE :
 *   `idempotency_keys` mémorise la réponse de chaque POST qui déplace de l'argent
 *   afin qu'un rejeu réseau ne déclenche pas un second mouvement. Cette garantie
 *   n'a de sens que le temps pendant laquelle un client peut encore renvoyer la
 *   requête. Passé ce délai, la ligne n'est plus qu'un coût de stockage : la table
 *   grossirait indéfiniment.
 *   L'index `idempotency_created_index` (migration 2026_09_28_000002) a été créé
 *   dans cette seule intention : sans lui, ce DELETE serait un full scan.
 *
 * ⚠️ POURQUOI LA RÉTENTION EST LONGUE ET VERROUILLÉE :
 *   Supprimer une clé Rend l'idempotence INACTIVE pour cette clé. Si le client
 *   renvoyait encore la requête après la purge, le transfert serait exécuté une
 *   SECONDE fois. La fenêtre de risque n'est donc pas « le temps d'un retry » mais
 *   « le temps pendant lequel un client hors ligne peut réapparaître ».
 *   D'où :
 *     - une rétention par défaut généreuse (30 jours) ;
 *     - un plancher dur (MIN_JOURS) refuse si l'on passe --days=0, qui
 *       désactiverait la protection sur toute la table d'un coup.
 *   Le risque résiduel est assumé et documenté : c'est le compromis habituel
 *   entre rétention d'idempotence et volume. Ne pas diminuer la rétention sans
 *   vérifier la durée de vie hors ligne des clients.
 */
class PurgeIdempotencyKeys extends Command
{
    protected $signature = 'yam:purge-idempotency-keys
                            {--days= : Rétention en jours (défaut : 30)}
                            {--dry-run : Compter les lignes concernées sans les supprimer}';

    protected $description = 'Supprime les clés d\'idempotence dépassant la rétention.';

    /** Rétention par défaut, en jours. */
    private const JOURS_DEFAUT = 30;

    /**
     * Plancher de rétention. En dessous, on accepterait de rouvrir la fenêtre
     * de double mouvement de l'argent. Une clé n'a de sens que pour un rejeu ;
     * 7 jours couvrent largement un client mobile hors ligne.
     */
    private const MIN_JOURS = 7;

    /** Taille des lots : évite un lock long sur une table volumineuse. */
    private const TAILLE_LOT = 500;

    public function handle(): int
    {
        $jours = $this->resoudreRetention();

        if ($jours === null) {
            return self::FAILURE;
        }

        $seuil = now()->subDays($jours);
        $secDryRun = (bool) $this->option('dry-run');

        $requete = IdempotencyKey::query()->where('created_at', '<', $seuil);

        $total = (clone $requete)->count();

        if ($total === 0) {
            $this->info("Aucune clé d'idempotence antérieure au " . $seuil->toDateTimeString() . '.');

            return self::SUCCESS;
        }

        if ($secDryRun) {
            $this->line(sprintf(
                '[dry-run] %d clé(s) seraient supprimées (créées avant le %s). Rien n\'a été écrit.',
                $total,
                $seuil->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        $supprimees = 0;

        // Suppression par lots : sur une table en croissance, un DELETE unique
        // tiendrait un verrou bien plus longtemps que nécessaire.
        do {
            $lot = (clone $requete)->orderBy('id')->limit(self::TAILLE_LOT)->delete();
            $supprimees += $lot;
        } while ($lot > 0);

        $this->info(sprintf(
            '%d clé(s) d\'idempotence supprimée(s) (créées avant le %s, rétention %d j).',
            $supprimees,
            $seuil->toDateTimeString(),
            $jours,
        ));

        // Reste de la table : donne l'ordre de grandeur du report.
        $restant = IdempotencyKey::query()->count();
        $this->line("  $restant clé(s) conservée(s).");

        return self::SUCCESS;
    }

    /**
     * Lit --days et contrôle qu'elle ne désactive pas la protection.
     *
     * @return int|null Rétention validée, ou null si loption est refusée.
     */
    private function resoudreRetention(): ?int
    {
        $brut = $this->option('days');

        if ($brut === null) {
            return self::JOURS_DEFAUT;
        }

        // Un --days non numérique ou <= 0 est une erreur de saisie, pas une
        // intention : on refuse au lieu de purger toute la table.
        if (! is_numeric($brut) || (int) $brut <= 0) {
            $this->error("--days doit être un entier positif (reçu : '$brut').");

            return null;
        }

        $jours = (int) $brut;

        if ($jours < self::MIN_JOURS) {
            $this->error(sprintf(
                'Rétention refusée : %d jour(s) est sous le plancher de %d jours.' . PHP_EOL
                . 'En dessous, un client hors ligne rejouerait un transfert APRÈS la purge,' . PHP_EOL
                . 'et le montant serait débité deux fois. Aucune clé n\'a été supprimée.',
                $jours,
                self::MIN_JOURS,
            ));

            return null;
        }

        return $jours;
    }
}