<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoAccessToken extends Model
{
    protected $fillable = ['demo_account_id', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DemoAccount::class, 'demo_account_id');
    }
}
