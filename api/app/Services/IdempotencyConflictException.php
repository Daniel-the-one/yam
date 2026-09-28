<?php

namespace App\Services;

use RuntimeException;

/**
 * Levée quand une clé d'idempotence est réutilisée de façon incohérente :
 * corps de requête différent sous la même clé, ou requête identique encore
 * en cours de traitement.
 *
 * Le contrôleur la convertit en 409. Utiliser une exception dédiée permet au
 * client de distinguer ce cas d'une vraie erreur métier.
 */
class IdempotencyConflictException extends RuntimeException
{
}
