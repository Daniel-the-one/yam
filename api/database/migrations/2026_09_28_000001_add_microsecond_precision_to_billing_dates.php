<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Précision microseconde sur les horodatages de facturation.
 *
 * PROBLÈME
 * --------
 * Appel::$dateFormat = 'Y-m-d H:i:s.u' et les updates écrivent
 * 'Y-m-d H:i:s.u' (fix P3-arrondi, 16/09), et Carbon 3 renvoie un FLOAT de
 * diffInSeconds() — donc le calcul de coût est bien à la microseconde.
 *
 * MAIS en MySQL/MariaDB les colonnes étaient créées en `timestamp` (0 décimale
 * implicite) : la fraction de seconde est ARRONDIE À LA SECONDE au stockage.
 * Vérifié sur MariaDB 11.8 :
 *     '2026-09-28 12:34:56.987654' -> timestamp    = 2026-09-28 12:34:56
 *                                    -> timestamp(6) = 2026-09-28 12:34:56.987654
 *
 * Conséquence : sous MySQL la facturation perd jusqu'à ±1 s de précision,
 * soit ±1,67 F par appel à 100 F/min. Le bug est INVISIBLE depuis les tests
 * car la suite tourne sur SQLite (:memory:) où le µs est conservé tel quel en
 * TEXT — c'est aussi pour cela que le fix P3 était validé en prod (base SQLite).
 *
 * PORTABILITÉ
 * -----------
 * SQLite n'a pas de notion de précision sur les colonnes temporelles et
 * conserve déjà la chaîne complète : la migration est donc un no-op sur
 * SQLite et PostgreSQL (timestamp(0) y stocke aussi les microsecondes).
 * Seuls MySQL/MariaDB sont modifiés.
 */
return new class extends Migration
{
    /** Colonnes de `appels` qui portent la facturation. */
    private const APPELS_COLONNES = ['date_sonnerie', 'date_decroche', 'date_fin'];

    /** Colonnes de `transactions` qui portent l'horodatage de finalisation. */
    private const TRANSACTIONS_COLONNES = ['date_complete', 'created_at', 'updated_at'];

    public function up(): void
    {
        $this->appliquerPrecision(6);
    }

    public function down(): void
    {
        $this->appliquerPrecision(0);
    }

    /**
     * @param  int  $precision  6 (microsecondes) ou 0 (seconde, état antérieur)
     */
    private function appliquerPrecision(int $precision): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return; // SQLite / PostgreSQL : rien à faire.
        }

        // `DEFAULT NULL` explicite : évite tout attachement implicite
        // ON UPDATE CURRENT_TIMESTAMP sur ces colonnes.
        foreach (self::APPELS_COLONNES as $colonne) {
            if (! Schema::hasColumn('appels', $colonne)) {
                continue;
            }
            DB::statement(sprintf(
                'ALTER TABLE `appels` MODIFY `%s` TIMESTAMP(%d) NULL DEFAULT NULL',
                $colonne,
                $precision
            ));
        }

        foreach (self::TRANSACTIONS_COLONNES as $colonne) {
            if (! Schema::hasColumn('transactions', $colonne)) {
                continue;
            }
            $type = $colonne === 'date_complete' ? 'DATETIME' : 'TIMESTAMP';
            DB::statement(sprintf(
                'ALTER TABLE `transactions` MODIFY `%s` %s(%d) NULL DEFAULT NULL',
                $colonne,
                $type,
                $precision
            ));
        }
    }
};
