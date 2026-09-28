<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exécution idempotente d'une opération d'écriture.
 *
 * PROBLÈME CONCRET (observé en test HTTP réel sur l'API) :
 * un rejeu du même POST /wallet/transfert créait un SECOND transfert, donc un
 * second débit et un second crédit. Sur mobile, en 4G togolaise, un double
 * appui ou un retry de couche réseau suffit à provoquer ça : l'argent part deux
 * fois alors que l'utilisateur n'a fait qu'un seul geste.
 *
 * MÉCANIQUE — l'en-tête `Idempotency-Key` (optionnel) :
 *  - absent          -> comportement historique, aucune mémoire conservée ;
 *  - nouveau         -> la clé est réservée, l'opération s'exécute, puis la
 *                       réponse complète est stockée ;
 *  - rejoué          -> la réponse stockée est renvoyée à l'identique, SANS
 *                       réexécuter l'opération, avec l'en-tête
 *                       `Idempotent-Replay: true` ;
 *  - rejoué avec un corps différent -> 409, on ne mélange jamais deux
 *                       intentions sous la même clé ;
 *  - rejoué pendant le traitement -> 409, la première requête est en cours.
 *
 * Si l'opération échoue, la réservation est LIBÉRÉE : le client peut réessayer
 * avec la même clé. Réserver une clé pour l'éternité après un échec
 * empêcherait tout retry legitimate.
 */
class IdempotencyService
{
    public const HEADER_ENTREE = 'Idempotency-Key';

    /** Signale au client que la réponse est rejouée depuis le cache. */
    public const HEADER_SORTIE = 'Idempotent-Replay';

    private const STATUS_EN_COURS = 'in_progress';

    private const STATUS_TERMINE = 'completed';

    /**
     * Exécute $callback au plus une fois pour une clé donnée.
     *
     * @param  callable():JsonResponse  $callback
     * @return array{response: JsonResponse, rejoue: bool}
     */
    public function execute(string $endpoint, int $userId, ?string $key, array $payload, callable $callback): array
    {
        $key = $key === null ? null : trim($key);

        if ($key === null || $key === '') {
            // Pas de clé : on ne mémorise rien (comportement inchangé).
            return ['response' => $callback(), 'rejoue' => false];
        }

        $this->validerCle($key, $endpoint);

        $empreinte = $this->empreinte($payload);

        // Phase 1 : réserver la clé, ou décider qu'il s'agit d'un rejeu.
        $rejeu = $this->reserver($userId, $endpoint, $key, $empreinte);

        if ($rejeu !== null) {
            return ['response' => $rejeu, 'rejoue' => true];
        }

        // Phase 2 : l'opération réelle, puis mémorisation de la réponse.
        try {
            $reponse = DB::transaction(fn () => $callback());
        } catch (\Throwable $e) {
            // Échec : on libère la clé pour autoriser un nouvel essai.
            $this->liberer($userId, $endpoint, $key);

            throw $e;
        }

        $this->memoriser($userId, $endpoint, $key, $reponse);

        return ['response' => $reponse, 'rejoue' => false];
    }

    /**
     * Réserve la clé. Renvoie la réponse rejouée, ou null si l'on obtient
     * le droit d'exécuter l'opération.
     */
    private function reserver(int $userId, string $endpoint, string $key, string $empreinte): ?JsonResponse
    {
        try {
            return DB::transaction(function () use ($userId, $endpoint, $key, $empreinte) {
                // Le verrou est pris AVANT l'insertion : deux requêtes
                // simultanées avec la même clé se sérialisent au lieu de
                // s'écraser au niveau du INSERT.
                $existant = IdempotencyKey::where('user_id', $userId)
                    ->where('endpoint', $endpoint)
                    ->where('idem_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existant === null) {
                    try {
                        IdempotencyKey::create([
                            'user_id'      => $userId,
                            'endpoint'     => $endpoint,
                            'idem_key'     => $key,
                            'request_hash' => $empreinte,
                            'status'       => self::STATUS_EN_COURS,
                        ]);

                        return null;
                    } catch (UniqueConstraintViolationException) {
                        // Perte de course : on relit sous verrou et on applique
                        // les mêmes règles qu'un rejeu.
                        $existant = IdempotencyKey::where('user_id', $userId)
                            ->where('endpoint', $endpoint)
                            ->where('idem_key', $key)
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if ($existant === null) {
                    throw new IdempotencyConflictException(
                        "Cle d'idempotence indisponible, reessayez."
                    );
                }

                if (! hash_equals((string) $existant->request_hash, $empreinte)) {
                    throw new IdempotencyConflictException(
                        "Cette cle d'idempotence a deja ete utilisee avec une requete differente."
                    );
                }

                if ($existant->status !== self::STATUS_TERMINE || $existant->response_body === null) {
                    throw new IdempotencyConflictException(
                        'Une requete identique est deja en cours de traitement.'
                    );
                }

                return response()
                    ->json(json_decode($existant->response_body, true), (int) $existant->response_status)
                    ->header(self::HEADER_SORTIE, 'true');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new IdempotencyConflictException(
                "Cle d'idempotence en cours d'enregistrement, reessayez."
            );
        }
    }

    private function memoriser(int $userId, string $endpoint, string $key, JsonResponse $reponse): void
    {
        IdempotencyKey::where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->where('idem_key', $key)
            ->update([
                'status'          => self::STATUS_TERMINE,
                'response_status' => $reponse->getStatusCode(),
                'response_body'   => $reponse->getContent(),
                'updated_at'      => now(),
            ]);
    }

    private function liberer(int $userId, string $endpoint, string $key): void
    {
        IdempotencyKey::where('user_id', $userId)
            ->where('endpoint', $endpoint)
            ->where('idem_key', $key)
            ->delete();
    }

    /**
     * Empreinte canonique du corps : les clés sont triées, donc l'ordre
     * JSONEnvoyé n'a aucune incidence sur l'égalité des requêtes.
     */
    private function empreinte(array $payload): string
    {
        return hash('sha256', json_encode($this->trier($payload), JSON_UNESCAPED_UNICODE));
    }

    private function trier(array $valeurs): array
    {
        ksort($valeurs);

        foreach ($valeurs as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeurs[$cle] = $this->trier($valeur);
            }
        }

        return $valeurs;
    }

    /**
     * Refuse une clé absurde : on refuse de stocker des clés de 10 Mo dans la
     * base, et on n'accepte que des caractères sûrs.
     */
    private function validerCle(string $key, string $endpoint): void
    {
        $longueur = Str::length($key);

        if ($longueur < 8 || $longueur > 200) {
            throw new IdempotencyConflictException(
                "L'en-tête " . self::HEADER_ENTREE . " doit contenir entre 8 et 200 caractères (endpoint {$endpoint})."
            );
        }

        if (! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw new IdempotencyConflictException(
                "L'en-tête " . self::HEADER_ENTREE . " ne doit contenir que des lettres, chiffres et . _ : -"
            );
        }
    }
}
