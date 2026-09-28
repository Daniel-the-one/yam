<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mémorisation des réponses d'opérations d'écriture sensibles.
 *
 * Une ligne = une clé d'idempotence réservée par un client. Tant que `status`
 * vaut `in_progress`, l'opération est en cours et un rejeu doit être refusé
 * (409). Une fois `completed`, `response_body` contient la réponse exacte à
 * renvoyer au client si la même clé revient.
 *
 * @property int         $id
 * @property string      $idem_key
 * @property int         $user_id
 * @property string      $endpoint
 * @property string      $request_hash
 * @property string      $status
 * @property int|null    $response_status
 * @property string|null $response_body
 */
class IdempotencyKey extends Model
{
    /** La ligne existe et l'opération correspondante tourne encore. */
    public const STATUS_EN_COURS = 'in_progress';

    /** L'opération est finie, la réponse est mémorisée. */
    public const STATUS_TERMINE = 'completed';

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'user_id',
        'endpoint',
        'idem_key',
        'request_hash',
        'status',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'user_id'         => 'integer',
            'response_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
