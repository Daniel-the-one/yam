<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoWalletTransaction extends Model
{
    protected $fillable = [
        'demo_account_id',
        'recipient_account_id',
        'reference',
        'type',
        'type_name',
        'direction',
        'amount',
        'fees',
        'balance_after',
        'status',
        'counterpart_type',
        'counterpart_label',
        'counterpart_wallet_id',
        'description',
        'date_complete',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fees' => 'integer',
            'balance_after' => 'integer',
            'status' => 'integer',
            'date_complete' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DemoAccount::class, 'demo_account_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(DemoAccount::class, 'recipient_account_id');
    }
}
