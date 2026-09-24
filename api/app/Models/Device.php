<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Appareil enregistré, rattaché à un compte utilisateur (relation 1-N).
 *
 * Un utilisateur peut posséder plusieurs appareils (web, iOS, Android).
 * Le device_id technique sert de clé de routing pour les canaux Reverb
 * (device.{device_id}) et pour les notifications push.
 */
class Device extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'device_id',
        'platform',
        'fcm_token',
        'web_push_subscription',
        'vapid_public_key',
        'last_seen_at',
    ];

    protected $hidden = [
        'fcm_token',
        'web_push_subscription',
    ];

    protected function casts(): array
    {
        return [
            'web_push_subscription' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * L'utilisateur propriétaire de cet appareil.
     * Nullable : les devices du registre public (non authentifiés)
     * n'ont pas de user rattaché.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
