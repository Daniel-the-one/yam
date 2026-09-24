<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CallSession extends Model
{
    use HasUuids;

    protected $table = 'call_sessions';

    protected $fillable = [
        'call_id',
        'from_device_id',
        'to_device_id',
        'from_user_id',
        'to_user_id',
        'type',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}