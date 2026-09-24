<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 'transactions';

    // Statuts (alignés sur l'API externe)
    public const STATUS_EN_COURS = 1;
    public const STATUS_REUSSIE = 2;
    public const STATUS_ECHOUEE = 3;

    // Types
    public const TYPE_TRANSFER = 'TRANSFER';
    public const TYPE_TOPUP = 'TOPUP';

    // Sens
    public const SENS_CREDIT = 'credit';
    public const SENS_DEBIT = 'debit';

    // Types de contrepartie
    public const COUNTERPART_INTERNAL = 'internal';
    public const COUNTERPART_MOBILE_MONEY = 'mobile_money';
    public const COUNTERPART_EXTERNAL = 'external';

    protected $fillable = [
        'user_id',
        'reference',
        'type',
        'type_name',
        'sens',
        'amount_raw',
        'fees_raw',
        'devise',
        'new_balance_raw',
        'status',
        'description',
        'counterpart_type',
        'counterpart_label',
        'counterpart_wallet_id',
        'counterpart_nom',
        'date_transaction',
        'heure_transaction',
        'date_complete',
    ];

    protected function casts(): array
    {
        return [
            'amount_raw' => 'decimal:2',
            'fees_raw' => 'decimal:2',
            'new_balance_raw' => 'decimal:2',
            'status' => 'integer',
            'date_transaction' => 'date',
            'date_complete' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}