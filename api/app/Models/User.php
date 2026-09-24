<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone_number',
        'phone_verified',
        'role',
        'is_online',
        'api_token',
        'solde',
        'wallet_id',
        'key_wallet',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_online' => 'boolean',
            'phone_verified' => 'boolean',
            'solde' => 'decimal:2',
        ];
    }

    /**
     * Un utilisateur possède plusieurs appareils (relation 1-N).
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Un utilisateur peut avoir une session d'appel active.
     */
    public function activeCall(): HasOne
    {
        return $this->hasOne(CallSession::class)
            ->whereNull('ended_at')
            ->whereIn('status', ['ringing', 'active']);
    }

    public function appelsAsPatient(): HasMany
    {
        return $this->hasMany(Appel::class, 'patient_id');
    }

    public function appelsAsMedecin(): HasMany
    {
        return $this->hasMany(Appel::class, 'medecin_id');
    }
}