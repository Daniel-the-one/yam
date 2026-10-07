<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoAccount extends Model
{
    protected $fillable = [
        'name',
        'username',
        'phone_number',
        'password',
        'wallet_id',
        'balance',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(DemoAccessToken::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DemoWalletTransaction::class);
    }
}
